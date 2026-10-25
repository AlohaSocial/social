<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Details;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\PinService;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Throwable;

/**
 * What a Social post or profile becomes as a Bluesky record.
 *
 * This is the one place that decides what leaves for Bluesky: a public
 * post's text with facets, split into thread replies when it does not fit;
 * a "Content warning: …" line and a `!warn` self-label for a content
 * warning; a poll as its question and options; up to ten pictures as
 * blobs, the words added for these in the post's language; a reply to or
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
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private ActorsRequest $actors,
	) {
	}

	/**
	 * @return array{record: array<string, mixed>, truncated: bool, continuations?: array<int, array{text: string, facets: array}>}
	 */
	public function post(Stream $post, Identity $identity, Person $author, array $video = ['state' => 'none']): array {
		$mapped = $this->text->fromHtml($post->getContent(), $this->mentionResolver());
		$text = $mapped['text'];
		$facets = $mapped['facets'];
		$extra = [];
		$labels = [];
		$l10n = $this->l10nOf($post, $author);

		$isPoll = $post instanceof Question;
		if ($isPoll) {
			$poll = self::pollText($post, $l10n);
			$text = $text === '' ? $poll : $text . "\n\n" . $poll;
			$facets = array_merge($facets, self::shifted(TextMapper::facetsOf($poll), strlen($text) - strlen($poll)));
		}

		$warning = trim($post->getSpoilerText());
		if ($warning !== '') {
			$prefix = $l10n->t('Content warning: %s', [$warning]) . "\n\n";
			$text = $prefix . $text;
			$facets = self::shifted($facets, strlen($prefix));
			$labels[] = ['val' => '!warn'];
		}

		$quote = trim($post->getQuote());
		$quoted = $quote === '' ? null : $this->refs->strongRef($quote);
		if ($quote !== '' && $quoted === null) {
			$extra[] = $quote;
		}
		$atproto = $post->getDetails(Details::ATPROTO);
		$rawRecordEmbed = $atproto[Details::ATPROTO_RECORD_EMBED] ?? null;
		$recordEmbed = null;
		if (is_array($rawRecordEmbed)
			&& is_string($rawRecordEmbed['uri'] ?? null)
			&& Syntax::isAtUri($rawRecordEmbed['uri'])
			&& is_string($rawRecordEmbed['cid'] ?? null)
			&& Cid::isValid($rawRecordEmbed['cid'])) {
			$recordEmbed = ['uri' => $rawRecordEmbed['uri'], 'cid' => Cid::parse($rawRecordEmbed['cid'])];
		}
		$parent = trim($post->getInReplyTo());
		$reply = $parent === '' ? null : $this->refs->replyRefs($parent);
		if ($parent !== '' && $reply === null) {
			$extra[] = $parent;
		}

		$images = [];
		$dropped = 0;
		$attachments = $post->getAttachments();
		$pictures = $this->pictureDocuments($post, $author);
		$imageAttachments = count(array_filter($attachments, static fn (MediaAttachment $attachment): bool => $attachment->getType() === 'image'));
		$dropped += max(0, $imageAttachments - count($pictures));
		$videoEmbed = self::videoEmbed($video);
		$videoCandidates = count(array_filter($attachments, static fn (MediaAttachment $attachment): bool => in_array($attachment->getType(), ['video', 'gifv'], true)
			|| str_starts_with($attachment->getMediaType(), 'video/')));
		$unsupportedAttachments = count(array_filter($attachments, static fn (MediaAttachment $attachment): bool => $attachment->getType() !== 'image'
			&& !in_array($attachment->getType(), ['video', 'gifv'], true)
			&& !str_starts_with($attachment->getMediaType(), 'video/')))
			+ ($videoEmbed === null ? 0 : max(0, $videoCandidates - 1));
		if ($unsupportedAttachments > 0) {
			$extra[] = $l10n->n('+%n more media attachment', '+%n more media attachments', $unsupportedAttachments);
		}
		if ($videoEmbed !== null) {
			// one media embed to a post: beside a video, the pictures are
			// what the link to the post here is for
			$dropped += count($pictures);
			$pictures = [];
		}
		foreach ($pictures as $i => $document) {
			if ($i >= PictureService::MAX_PER_POST) {
				$dropped++;
				continue;
			}
			$picture = $this->pictures->blobFor($identity, $author, $document);
			if ($picture === null) {
				$dropped++;
				continue;
			}
			$image = ['image' => $picture['blob']->toRecordValue(), 'alt' => $document->getDescription()];
			if ($picture['width'] > 0 && $picture['height'] > 0) {
				$image['aspectRatio'] = ['width' => $picture['width'], 'height' => $picture['height']];
			} elseif (count($pictures) > PictureService::MAX_PER_IMAGES_EMBED) {
				// Gallery items require an aspect ratio. Keep an unknown-size
				// image readable with a neutral square ratio instead of emitting
				// an invalid record or silently dropping it.
				$image['aspectRatio'] = ['width' => 1, 'height' => 1];
			}
			$images[] = $image;
		}
		if ($dropped > 0) {
			$extra[] = $l10n->n('+%n more picture', '+%n more pictures', $dropped);
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
		$continuations = [];
		if (isset($fit['threadParts'])) {
			$parts = $fit['threadParts'];
			$fit['text'] = $parts[0]['text'];
			$fit['facets'] = $parts[0]['facets'];
			$continuations = array_slice($parts, 1);
		}

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
		$embed = $this->embed($post, $identity, $author, $images, $quoted, $recordEmbed, $videoEmbed);
		if ($embed !== null) {
			$record['embed'] = $embed;
		}
		if ($labels !== []) {
			$record['labels'] = ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => $labels];
		}

		return ['record' => $record, 'truncated' => $fit['truncated']]
			+ ($continuations === [] ? [] : ['continuations' => $continuations]);
	}

	/** The post's text as Bluesky publication maps it, without fitting or writing media. */
	public function sourceText(Stream $post): string {
		return $this->text->fromHtml($post->getContent(), $this->mentionResolver())['text'];
	}

	/** The poll choices that must accompany the source body on Bluesky. */
	/** @return string[] */
	public function sourcePollOptions(Stream $post): array {
		if (!$post instanceof Question) {
			return [];
		}

		return array_values(array_filter(array_map(
			static fn (array $option): string => trim((string)($option['title'] ?? '')),
			$post->getOptions()
		), static fn (string $option): bool => $option !== ''));
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
	 * Who may reply on Bluesky, as the author's reply rule says — a gate of
	 * the one rule, an empty one for nobody — and the replies the author hid
	 * (`hiddenReplies`, those that are on Bluesky); none for everybody and
	 * nothing hidden.
	 */
	public function threadgate(Stream $post, string $postUri): ?array {
		$allow = $this->allowOf($post);
		$hidden = [];
		foreach ($post->getHiddenReplies() as $replyId) {
			$uri = $this->refs->strongRef($replyId)['uri'] ?? '';
			if ($uri !== '') {
				$hidden[] = $uri;
			}
		}
		if ($allow === null && $hidden === []) {
			return null;
		}

		return [
			'$type' => self::THREADGATE,
			'post' => $postUri,
		] + ($allow === null ? [] : ['allow' => $allow])
			+ ['createdAt' => Syntax::datetime(self::publishedAt($post))]
			+ ($hidden === [] ? [] : ['hiddenReplies' => $hidden]);
	}

	/**
	 * The threadgate's `allow` for a post's reply rule: one rule per part, a
	 * list as the Bluesky list it was published as (`BlueskyLists`); `[]`
	 * for nobody, null for everybody.
	 */
	private function allowOf(Stream $post): ?array {
		$parts = $post->getReplyRuleParts();
		if ($parts === []) {
			return null;
		}
		$allow = [];
		foreach ($parts as $part) {
			if (str_starts_with($part, Stream::REPLY_RULE_LIST)) {
				$uri = $this->listUri((int)substr($part, strlen(Stream::REPLY_RULE_LIST)));
				if ($uri !== '') {
					$allow[] = ['$type' => self::THREADGATE . '#listRule', 'list' => $uri];
				}
				continue;
			}
			$rule = match ($part) {
				Stream::REPLY_RULE_FOLLOWERS => 'followerRule',
				Stream::REPLY_RULE_FOLLOWING => 'followingRule',
				Stream::REPLY_RULE_MENTIONED => 'mentionRule',
				default => '',
			};
			if ($rule !== '') {
				$allow[] = ['$type' => self::THREADGATE . '#' . $rule];
			}
		}

		return $allow;
	}

	private function listUri(int $listId): string {
		foreach ($this->repositories->getRecordsByLocalId(BlueskyLists::localId($listId)) as $record) {
			if ($record->collection === BlueskyLists::LIST) {
				return $record->uri();
			}
		}

		return '';
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
		$name = self::displayNameOf($actor);
		if ($name !== '') {
			$record['displayName'] = $name;
		}
		$bio = $this->text->fromHtml($actor->getDescription(), static fn (): ?string => null)['text'];
		if ($bio !== '') {
			$record['description'] = self::clip($bio, 256, 2560);
		}
		// the profile rows Bluesky has a place for (`Person`)
		$pronouns = $actor->getPronouns();
		if ($pronouns !== '') {
			$record['pronouns'] = self::clipPronouns($pronouns);
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
	 * @param array{uri: string, cid: Cid}|null $recordEmbed
	 */
	private function embed(Stream $post, Identity $identity, Person $author, array $images, ?array $quoted, ?array $recordEmbed, ?array $video = null): ?array {
		$pictures = $video;
		if ($pictures === null && $images !== []) {
			if (count($images) > PictureService::MAX_PER_IMAGES_EMBED) {
				$pictures = ['$type' => 'app.bsky.embed.gallery', 'items' => array_map(
					static fn (array $image): array => [
						'$type' => 'app.bsky.embed.gallery#image',
						'image' => $image['image'],
						'alt' => $image['alt'],
						'aspectRatio' => $image['aspectRatio'] ?? ['width' => 1, 'height' => 1],
					],
					$images,
				)];
			} else {
				$pictures = ['$type' => 'app.bsky.embed.images', 'images' => $images];
			}
		}
		$recordRef = $quoted ?? $recordEmbed;
		if ($recordRef !== null) {
			$record = ['$type' => 'app.bsky.embed.record', 'record' => $recordRef];

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
			$records = array_values(array_filter(
				$this->repositories->getRecordsByLocalId($postId),
				static fn (StoredRecord $record): bool => $record->collection === self::POST && $record->did === $identity->did,
			));
			usort($records, static fn (StoredRecord $a, StoredRecord $b): int => strcmp($a->rkey, $b->rkey));
			if ($records !== []) {
				return ['uri' => $records[0]->uri(), 'cid' => $records[0]->cid->toString()];
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

	private static function pollText(Question $poll, IL10N $l10n): string {
		$options = array_map(static fn (array $option): string => trim((string)($option['title'] ?? '')), $poll->getOptions());

		return $l10n->t('Poll: %s', [implode(' / ', array_filter($options, static fn (string $o): bool => $o !== ''))]);
	}

	/**
	 * The app's translations in the language the post is written in, else
	 * in its author's Nextcloud language: the words a post gains on its way
	 * to Bluesky are read with the post.
	 */
	private function l10nOf(Stream $post, Person $author): IL10N {
		$language = Stream::normalizeLanguage($post->getLanguage());
		if ($language !== '') {
			// `pt-BR` is Nextcloud's `pt_BR` and `zh-Hant-TW` its `zh_TW`;
			// failing those, the language without its region
			$parts = explode('-', $language);
			$regional = count($parts) > 1 ? $parts[0] . '_' . end($parts) : $parts[0];
			foreach (array_unique([implode('_', $parts), $regional, $parts[0]]) as $candidate) {
				if ($this->l10nFactory->languageExists(Application::APP_ID, $candidate)) {
					return $this->l10nFactory->get(Application::APP_ID, $candidate);
				}
			}
		}
		// a cached actor does not carry its Nextcloud user: the account does
		$username = $author->getPreferredUsername();
		$userId = $author->getUserId() !== '' ? $author->getUserId() : ($this->actors->userIdsOf([$username])[$username] ?? '');
		$user = $userId === '' ? null : $this->userManager->get($userId);

		return $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
	}

	private static function shifted(array $facets, int $by): array {
		return array_map(static function (array $facet) use ($by): array {
			$facet['index']['byteStart'] += $by;
			$facet['index']['byteEnd'] += $by;

			return $facet;
		}, $facets);
	}

	/**
	 * The display name the account's profile record carries, '' for none:
	 * what a verification of the account names for it to hold.
	 */
	public static function displayNameOf(Person $actor): string {
		// `name` is the name the account federates (`AccountService` fills it
		// from Nextcloud as far as its privacy setting allows), as a Fediverse
		// server shows it
		$name = trim($actor->getName());

		return $name === '' ? '' : self::clip($name, 64, 640);
	}

	/** The pronouns the profile lexicon accepts. */
	public static function clipPronouns(string $pronouns): string {
		return self::clip($pronouns, 20, 200);
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
