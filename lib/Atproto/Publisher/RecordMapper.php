<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DocumentService;
use Throwable;

/**
 * What a Social post or profile becomes as a Bluesky record.
 *
 * This is the one place that decides what leaves for Bluesky: a public
 * post's text with facets, cut to fit and linked back when it does not;
 * `CW: …` and a `!warn` self-label for a content warning; a poll as its
 * question and options; the first four pictures as blobs; a reply to a
 * post that is itself on Bluesky as a reply there, any other reply or
 * quote as a link. Nothing else is ever written.
 */
class RecordMapper {
	public const POST = 'app.bsky.feed.post';
	public const PROFILE = 'app.bsky.actor.profile';
	public const PROFILE_RKEY = 'self';

	public function __construct(
		private TextMapper $text,
		private PictureService $pictures,
		private DocumentService $documents,
		private IdentityService $identities,
		private RepositoryService $repositories,
	) {
	}

	/**
	 * @return array{record: array<string, mixed>, truncated: bool}
	 */
	public function post(Stream $post, Identity $identity, Person $author): array {
		$mapped = $this->text->fromHtml($post->getContent(), $this->mentionResolver());
		$text = $mapped['text'];
		$facets = $mapped['facets'];
		$extra = [];
		$labels = [];

		$isPoll = $post instanceof Question;
		if ($isPoll) {
			$poll = self::pollText($post);
			$text = $text === '' ? $poll : $text . "\n\n" . $poll;
			$facets = array_merge($facets, self::shifted(TextMapper::facetsOf($poll), strlen($text) - strlen($poll)));
		}

		$warning = trim($post->getSpoilerText());
		if ($warning !== '') {
			$prefix = 'CW: ' . $warning . "\n\n";
			$text = $prefix . $text;
			$facets = self::shifted($facets, strlen($prefix));
			$labels[] = ['val' => '!warn'];
		}

		$quote = trim($post->getQuote());
		if ($quote !== '' && $this->onBluesky($quote) === null) {
			$extra[] = $quote;
		}
		$parent = trim($post->getInReplyTo());
		$reply = $parent === '' ? null : $this->replyRefs($parent);
		if ($parent !== '' && $reply === null) {
			$extra[] = $parent;
		}

		$images = [];
		$dropped = 0;
		foreach ($this->pictureDocuments($post) as $i => $document) {
			if ($i >= PictureService::MAX_PER_POST) {
				$dropped++;
				continue;
			}
			$picture = $this->pictures->blobFor($identity, $author, $document);
			if ($picture === null) {
				continue;
			}
			$image = ['image' => $picture['blob']->toRecordValue(), 'alt' => $document->getDescription()];
			if ($picture['width'] > 0 && $picture['height'] > 0) {
				$image['aspectRatio'] = ['width' => $picture['width'], 'height' => $picture['height']];
			}
			$images[] = $image;
		}
		if ($dropped > 0) {
			$extra[] = '+' . $dropped . ' more ' . ($dropped === 1 ? 'picture' : 'pictures');
		}
		if ($images !== [] && $post->isSensitive() && $warning === '') {
			$labels[] = ['val' => 'graphic-media'];
		}

		$extra = array_values(array_filter($extra, static fn (string $line): bool => $line !== ''));
		$fit = $this->text->fit($text, $facets, $post->pageUrl(), $extra, $isPoll);
		foreach ($extra as $line) {
			if (preg_match('#^https?://#', $line) === 1) {
				$at = strrpos($fit['text'], $line);
				if ($at !== false && $at < strlen($fit['text']) - strlen($post->pageUrl())) {
					$fit['facets'][] = ['index' => ['byteStart' => $at, 'byteEnd' => $at + strlen($line)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $line]]];
				}
			}
		}
		usort($fit['facets'], static fn (array $a, array $b): int => $a['index']['byteStart'] <=> $b['index']['byteStart']);

		$record = [
			'$type' => self::POST,
			'text' => $fit['text'],
			'createdAt' => Syntax::datetime(self::publishedAt($post)),
		];
		if ($fit['facets'] !== []) {
			$record['facets'] = array_values($fit['facets']);
		}
		if (Syntax::isLanguage($post->getLanguage())) {
			$record['langs'] = [$post->getLanguage()];
		}
		if ($reply !== null) {
			$record['reply'] = $reply;
		}
		if ($images !== []) {
			$record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
		}
		if ($labels !== []) {
			$record['labels'] = ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => $labels];
		}

		return ['record' => $record, 'truncated' => $fit['truncated']];
	}

	/**
	 * The profile record of a local actor.
	 */
	public function profile(Person $actor, Identity $identity): array {
		$record = ['$type' => self::PROFILE];
		$name = trim($actor->getDisplayName());
		if ($name !== '') {
			$record['displayName'] = self::clip($name, 64, 640);
		}
		$bio = $this->text->fromHtml($actor->getDescription(), static fn (): ?string => null)['text'];
		if ($bio !== '') {
			$record['description'] = self::clip($bio, 256, 2560);
		}
		$avatar = $this->actorPicture($identity, $actor, $actor->getIconId());
		if ($avatar !== null) {
			$record['avatar'] = $avatar;
		}
		if ($actor->getCreation() > 0) {
			$record['createdAt'] = Syntax::datetime($actor->getCreation());
		}

		return $record;
	}

	/**
	 * The strong reference of a local post that is on Bluesky, or of a
	 * Bluesky post, or null.
	 *
	 * @return array{uri: string, cid: string}|null
	 */
	public function onBluesky(string $postId): ?array {
		foreach ($this->repositories->getRecordsByLocalId($postId) as $record) {
			if ($record->collection === self::POST) {
				return ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
			}
		}

		return null;
	}

	/**
	 * `reply.root` and `reply.parent` for a reply to a post that is on
	 * Bluesky: the parent's own root when it has one, else the parent.
	 *
	 * @return array{root: array{uri: string, cid: string}, parent: array{uri: string, cid: string}}|null
	 */
	private function replyRefs(string $parentId): ?array {
		$parent = null;
		foreach ($this->repositories->getRecordsByLocalId($parentId) as $record) {
			if ($record->collection === self::POST) {
				$parent = $record;
				break;
			}
		}
		if ($parent === null) {
			return null;
		}
		$ref = ['uri' => $parent->uri(), 'cid' => $parent->cid->toString()];
		$value = $parent->value();
		$root = isset($value['reply']['root']['uri'], $value['reply']['root']['cid'])
			? ['uri' => $value['reply']['root']['uri'], 'cid' => $value['reply']['root']['cid']]
			: $ref;

		return ['root' => $root, 'parent' => $ref];
	}

	/**
	 * A mention's DID: a local actor's own identity. Anybody else is linked
	 * to their profile instead.
	 *
	 * @return callable(string, string): ?string
	 */
	private function mentionResolver(): callable {
		return function (string $text, string $href): ?string {
			if ($href === '') {
				return null;
			}
			try {
				$identity = $this->identities->getByActorId($href);
			} catch (AtprotoIdentityNotFoundException) {
				return null;
			}

			return $identity->isActive() ? $identity->did : null;
		};
	}

	/**
	 * @return Document[] the post's pictures, in order
	 */
	private function pictureDocuments(Stream $post): array {
		$ids = [];
		foreach ($post->getAttachments() as $attachment) {
			if ($attachment->getType() === 'image') {
				$ids[] = $attachment->getId();
			}
		}
		if ($ids === []) {
			return [];
		}
		try {
			$documents = $this->documents->getMediaFromArray($ids);
		} catch (Throwable) {
			return [];
		}
		$byId = [];
		foreach ($documents as $document) {
			$byId[(string)$document->getNid()] = $document;
		}
		$ordered = [];
		foreach ($ids as $id) {
			if (isset($byId[$id])) {
				$ordered[] = $byId[$id];
			}
		}

		return $ordered;
	}

	private function actorPicture(Identity $identity, Person $actor, string $documentId): ?array {
		if ($documentId === '') {
			return null;
		}
		try {
			$document = $this->documents->getDocumentById($documentId);
		} catch (Throwable) {
			return null;
		}
		$picture = $this->pictures->blobFor($identity, $actor, $document);

		return $picture === null ? null : $picture['blob']->toRecordValue();
	}

	private static function publishedAt(Stream $post): int {
		if ($post->getPublishedTime() > 0) {
			return $post->getPublishedTime();
		}
		$parsed = strtotime($post->getPublished());

		return $parsed === false || $parsed <= 0 ? time() : $parsed;
	}

	private static function pollText(Question $poll): string {
		$options = array_map(static fn (array $option): string => trim((string)($option['title'] ?? '')), $poll->getOptions());

		return 'Poll: ' . implode(' / ', array_filter($options, static fn (string $o): bool => $o !== ''));
	}

	private static function shifted(array $facets, int $by): array {
		return array_map(static function (array $facet) use ($by): array {
			$facet['index']['byteStart'] += $by;
			$facet['index']['byteEnd'] += $by;

			return $facet;
		}, $facets);
	}

	/** at most $graphemes characters and $bytes bytes, which the profile lexicon asks */
	private static function clip(string $text, int $graphemes, int $bytes): string {
		preg_match_all('/\X/u', $text, $matches);
		$clusters = array_slice($matches[0], 0, $graphemes);
		$out = implode('', $clusters);
		while (strlen($out) > $bytes && $clusters !== []) {
			array_pop($clusters);
			$out = implode('', $clusters);
		}

		return $out;
	}
}
