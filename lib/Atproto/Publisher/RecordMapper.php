<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\PinService;
use Throwable;

/**
 * What a Social post or profile becomes as a Bluesky record.
 *
 * This is the one place that decides what leaves for Bluesky: a public
 * post's text with facets, cut to fit and linked back when it does not;
 * `CW: …` and a `!warn` self-label for a content warning; a poll as its
 * question and options; the first four pictures as blobs; a reply to or
 * quote of a post that is on Bluesky — a published local post or a
 * Bluesky post read here — as a reply or record embed there, any other as
 * a link; a link preview as an external card. Nothing else is ever written.
 */
class RecordMapper {
	public const POST = 'app.bsky.feed.post';
	public const PROFILE = 'app.bsky.actor.profile';
	public const PROFILE_RKEY = 'self';
	public const FOLLOW = 'app.bsky.graph.follow';
	public const LIKE = 'app.bsky.feed.like';
	public const REPOST = 'app.bsky.feed.repost';
	public const POSTGATE = 'app.bsky.feed.postgate';
	public const THREADGATE = 'app.bsky.feed.threadgate';

	public function __construct(
		private TextMapper $text,
		private PictureService $pictures,
		private DocumentService $documents,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private PostRefs $refs,
		private StreamCardsRequest $cards,
		private CardThumbnail $thumbnails,
		private CacheDocumentsRequest $cacheDocuments,
		private PinService $pins,
	) {
	}

	/**
	 * @return array{record: array<string, mixed>, truncated: bool}
	 */
	public function post(Stream $post, Identity $identity, Person $author, array $video = ['state' => 'none']): array {
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
		$quoted = $quote === '' ? null : $this->refs->strongRef($quote);
		if ($quote !== '' && $quoted === null) {
			$extra[] = $quote;
		}
		$parent = trim($post->getInReplyTo());
		$reply = $parent === '' ? null : $this->refs->replyRefs($parent);
		if ($parent !== '' && $reply === null) {
			$extra[] = $parent;
		}

		$images = [];
		$dropped = 0;
		$pictures = $this->pictureDocuments($post, $author);
		$videoEmbed = self::videoEmbed($video);
		if ($videoEmbed !== null) {
			// one media embed to a post: beside a video, the pictures are
			// what the link to the post here is for
			$dropped = count($pictures);
			$pictures = [];
		}
		foreach ($pictures as $i => $document) {
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
		if (($images !== [] || $videoEmbed !== null) && $post->isSensitive() && $warning === '') {
			$labels[] = ['val' => 'graphic-media'];
		}

		$extra = array_values(array_filter($extra, static fn (string $line): bool => $line !== ''));
		// a video that is not a Bluesky video is watched here
		$linksHere = $isPoll || $video['state'] === 'link';
		$fit = $this->text->fit($text, $facets, $post->pageUrl(), $extra, $linksHere);
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
		$embed = $this->embed($post, $identity, $author, $images, $quoted, $videoEmbed);
		if ($embed !== null) {
			$record['embed'] = $embed;
		}
		if ($labels !== []) {
			$record['labels'] = ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => $labels];
		}

		return ['record' => $record, 'truncated' => $fit['truncated']];
	}

	/**
	 * The postgate of a post whose author restricts quoting: Bluesky can
	 * only switch quoting off, so a followers-only quote policy is kept the
	 * stricter way rather than opened to everybody. Null when anybody may
	 * quote.
	 *
	 * @param string $postUri the post's `at://` URI
	 */
	/**
	 * Who may reply on Bluesky, as the author's reply rule says: a gate of
	 * the one rule, an empty one for nobody, and none for everybody.
	 */
	public function threadgate(Stream $post, string $postUri): ?array {
		$allow = match ($post->getReplyRule()) {
			Stream::REPLY_RULE_FOLLOWERS => [['$type' => self::THREADGATE . '#followerRule']],
			Stream::REPLY_RULE_FOLLOWING => [['$type' => self::THREADGATE . '#followingRule']],
			Stream::REPLY_RULE_MENTIONED => [['$type' => self::THREADGATE . '#mentionRule']],
			Stream::REPLY_RULE_NOBODY => [],
			default => null,
		};
		if ($allow === null) {
			return null;
		}

		return [
			'$type' => self::THREADGATE,
			'post' => $postUri,
			'allow' => $allow,
			'createdAt' => Syntax::datetime(self::publishedAt($post)),
		];
	}

	public function postgate(Stream $post, string $postUri): ?array {
		$closed = in_array($post->getQuotePolicy(), [Stream::QUOTE_POLICY_FOLLOWERS, Stream::QUOTE_POLICY_NOBODY], true);
		$detached = $post->getDetachedQuotes();
		if (!$closed && $detached === []) {
			return null;
		}

		return [
			'$type' => self::POSTGATE,
			'post' => $postUri,
		] + ($closed ? ['embeddingRules' => [['$type' => self::POSTGATE . '#disableRule']]] : [])
			+ ($detached === [] ? [] : ['detachedEmbeddingUris' => $detached])
			+ ['createdAt' => Syntax::datetime(self::publishedAt($post))];
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
		// the profile rows Bluesky has a place for (`Person`)
		$pronouns = $actor->getPronouns();
		if ($pronouns !== '') {
			$record['pronouns'] = self::clip($pronouns, 20, 200);
		}
		$website = $actor->getWebsite();
		if ($website !== '') {
			$record['website'] = $website;
		}
		$avatar = $this->pictures->avatarBlob($identity, $actor, PictureService::PROFILE_MAX_BYTES, PictureService::PROFILE_TYPES);
		if ($avatar !== null) {
			$record['avatar'] = $avatar['blob']->toRecordValue();
		}
		$banner = $this->bannerOf($identity, $actor);
		if ($banner !== null) {
			$record['banner'] = $banner;
		}
		$pinned = $this->pinnedOf($identity, $actor);
		if ($pinned !== null) {
			$record['pinnedPost'] = $pinned;
		}
		if ($actor->getCreation() > 0) {
			$record['createdAt'] = Syntax::datetime($actor->getCreation());
		}

		return $record;
	}

	/**
	 * Pictures, a quoted post, or the link preview — Bluesky takes one embed,
	 * or pictures with a quoted post together.
	 *
	 * @param list<array> $images
	 * @param array{uri: string, cid: string}|null $quoted
	 */
	private function embed(Stream $post, Identity $identity, Person $author, array $images, ?array $quoted, ?array $video = null): ?array {
		$pictures = $video ?? ($images === [] ? null : ['$type' => 'app.bsky.embed.images', 'images' => $images]);
		if ($quoted !== null) {
			$record = ['$type' => 'app.bsky.embed.record', 'record' => $quoted];

			return $pictures === null ? $record : ['$type' => 'app.bsky.embed.recordWithMedia', 'record' => $record, 'media' => $pictures];
		}
		if ($pictures !== null) {
			return $pictures;
		}

		return $this->linkCard($post, $identity, $author);
	}

	/**
	 * A post's video as an `app.bsky.embed.video`, when it is ready to be one.
	 *
	 * @param array{state: string, blob?: BlobRef, alt?: string, width?: int, height?: int} $video
	 */
	private static function videoEmbed(array $video): ?array {
		$blob = $video['blob'] ?? null;
		if ($video['state'] !== 'ready' || !$blob instanceof BlobRef) {
			return null;
		}
		$embed = ['$type' => 'app.bsky.embed.video', 'video' => $blob->toRecordValue()];
		$alt = trim((string)($video['alt'] ?? ''));
		if ($alt !== '') {
			$embed['alt'] = self::clip($alt, 1000, 10000);
		}
		if (($video['width'] ?? 0) > 0 && ($video['height'] ?? 0) > 0) {
			$embed['aspectRatio'] = ['width' => (int)$video['width'], 'height' => (int)$video['height']];
		}

		return $embed;
	}

	/**
	 * The post's link preview as an external card, when there is one with a
	 * title: the address, the title, the description, and the page's picture
	 * when it can be had.
	 */
	private function linkCard(Stream $post, Identity $identity, Person $author): ?array {
		try {
			$card = $this->cards->getByStreamId($post->getId());
		} catch (CardNotFoundException) {
			return null;
		}
		$uri = trim($card->getUrl());
		$title = trim($card->getTitle());
		if ($title === '' || preg_match('#^https?://#i', $uri) !== 1) {
			return null;
		}

		$external = [
			'uri' => $uri,
			'title' => self::clip($title, 300, 3000),
			'description' => self::clip(trim($card->getDescription()), 300, 3000),
		];
		$thumb = $this->thumbnails->blobFor($identity, $author, $card);
		if ($thumb !== null) {
			$external['thumb'] = $thumb->toRecordValue();
		}

		return ['$type' => 'app.bsky.embed.external', 'external' => $external];
	}

	/**
	 * A mention's DID: a local actor's own identity, or a Bluesky account's.
	 * Anybody else is linked to their profile instead.
	 *
	 * @return callable(string, string): ?string
	 */
	private function mentionResolver(): callable {
		return function (string $text, string $href): ?string {
			if ($href === '') {
				return null;
			}
			$did = BlueskyIds::didOf($href);
			if ($did !== '' && BlueskyIds::isActorId($href)) {
				return $did;
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
	private function pictureDocuments(Stream $post, Person $author): array {
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
			// the author's own uploads: the lookup matches the account, and
			// an empty one matches no stored document at all
			$documents = $this->documents->getMediaFromArray($ids, $author->getPreferredUsername());
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

	/**
	 * The banner, the stored document the actor's header names.
	 */
	private function bannerOf(Identity $identity, Person $actor): ?array {
		$url = trim($actor->getHeader());
		if ($url === '') {
			return null;
		}
		try {
			$document = $this->cacheDocuments->getByUrl($url);
		} catch (Throwable) {
			return null;
		}

		return $document->getLocalCopy() === '' ? null : $this->profilePicture($identity, $actor, $document);
	}

	/**
	 * A picture as the profile takes one: a megabyte at most, JPEG or PNG.
	 */
	private function profilePicture(Identity $identity, Person $actor, Document $document): ?array {
		$picture = $this->pictures->blobFor($identity, $actor, $document, PictureService::PROFILE_MAX_BYTES, PictureService::PROFILE_TYPES);

		return $picture === null ? null : $picture['blob']->toRecordValue();
	}

	/**
	 * The newest pin that is on Bluesky, as the profile's `pinnedPost`.
	 *
	 * @return array{uri: string, cid: string}|null
	 */
	private function pinnedOf(Identity $identity, Person $actor): ?array {
		foreach ($this->pins->getPinnedIds($actor->getId()) as $postId) {
			foreach ($this->repositories->getRecordsByLocalId($postId) as $record) {
				if ($record->collection === self::POST && $record->did === $identity->did) {
					return ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
				}
			}
		}

		return null;
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
