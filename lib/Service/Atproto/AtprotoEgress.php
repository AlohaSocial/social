<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Model\Details;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a local public post into the Bluesky repository its author linked.
 *
 * This is deliberately a post mirror, not an ActivityPub delivery: the PDS
 * receives a protocol-native `app.bsky.feed.post` record using the linked
 * account's app-password session. ActivityPub delivery has already happened
 * before this service is called and cannot be held up by a PDS failure.
 */
class AtprotoEgress {
	/** Formats accepted by the native Bluesky image/video embed lexicons. */
	private const NATIVE_MEDIA_MIME_TYPES = [
		'image/jpeg' => 'image',
		'image/png' => 'image',
		'image/gif' => 'image',
		'image/webp' => 'image',
		'video/mp4' => 'video',
		'video/webm' => 'video',
	];
	/** The low ten bits that make two writes in the same microsecond distinct. */
	private int $clock = 0;

	public function __construct(
		private AtprotoClient $client,
		private AtprotoRequest $atprotoRequest,
		private ActorsRequest $actorsRequest,
		private ConfigService $configService,
		private LoggerInterface $logger,
		private ?AtprotoIdentity $identity = null,
		private ?DocumentService $documentService = null,
	) {
	}

	/**
	 * Writes a local, public post once. A returned mapping makes retries
	 * idempotent: if a listener is delivered twice, no duplicate Bluesky post
	 * is made.
	 *
	 * @throws AtprotoException for the listener to record and contain
	 */
	public function publish(Stream $post): void {
		if (!$this->shouldMirror($post)) {
			return;
		}

		if ($this->atprotoRequest->getLinkByLocalId($post->getId()) !== null) {
			return;
		}

		$account = $this->accountFor($post);
		if ($account === null) {
			return;
		}

		// PDS listRecords pages are ordered by record key. A timestamp ID keeps
		// newest writes together, which is what lets the ingress stop at the
		// first already-known page instead of repeatedly scanning a repository.
		$rkey = $this->tid();
		$record = $this->record($post, $account);
		$answer = $this->put($account, $rkey, $record);
		$this->saveLink($post, $account, $rkey, $answer);
	}

	/**
	 * Replaces the existing native record after a local post edit. An edit of a
	 * post that never mirrored remains local — links make that distinction
	 * explicit and prevent an edit from unexpectedly publishing old content.
	 *
	 * @throws AtprotoException for the listener to record and contain
	 */
	public function update(Stream $post): void {
		if (!$this->shouldMirror($post)) {
			return;
		}

		$link = $this->atprotoRequest->getLinkByLocalId($post->getId());
		if ($link === null || $link->getCollection() !== AtprotoIngress::COLLECTION) {
			return;
		}
		$account = $this->accountFor($post);
		if ($account === null || $link->getDid() !== $account->getDid()) {
			return;
		}

		$answer = $this->put($account, $link->getRkey(), $this->record($post, $account));
		$this->saveLink($post, $account, $link->getRkey(), $answer);
	}

	/** @return array<string, mixed> */
	private function record(Stream $post, ?AtprotoAccount $account = null): array {
		$text = $this->text($post->getContent());
		$record = [
			'$type' => 'app.bsky.feed.post',
			'text' => $text,
			'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(1, $post->getPublishedTime())),
		];
		if ($post->getLanguage() !== '') {
			$record['langs'] = [$post->getLanguage()];
		}
		$facets = $this->facets($post->getContent(), $text, $post);
		if ($facets !== []) {
			$record['facets'] = $facets;
		}
		$quoted = null;
		if ($post->getQuote() !== '') {
			$quoted = $this->atprotoRequest->getLinkByLocalId($post->getQuote());
		}
		if ($quoted !== null && $quoted->getAtUri() !== '' && $quoted->getCid() !== '') {
			// ATProto permits one embed. A native record quote is more useful
			// than a secondary external card and keeps the quoted post inside
			// the same repository graph.
			$record['embed'] = [
				'$type' => 'app.bsky.embed.record',
				'record' => [
					'uri' => $quoted->getAtUri(),
					'cid' => $quoted->getCid(),
				],
			];
		} elseif (($card = $post->getCard()) !== null && $card->getUrl() !== '') {
			$record['embed'] = [
				'$type' => 'app.bsky.embed.external',
				'external' => [
					'uri' => $card->getUrl(),
					'title' => $card->getTitle(),
					'description' => $card->getDescription(),
				],
			];
		}
		$media = $account === null ? null : $this->mediaEmbed($post, $account);
		if ($media !== null) {
			if (($record['embed']['$type'] ?? '') === 'app.bsky.embed.record') {
				$record['embed'] = [
					'$type' => 'app.bsky.embed.recordWithMedia',
					'media' => $media,
					'record' => $record['embed']['record'],
				];
			} else {
				$record['embed'] = $media;
			}
		}
		$parent = null;
		if ($post->getInReplyTo() !== '') {
			$parent = $this->atprotoRequest->getLinkByLocalId($post->getInReplyTo());
		}
		if ($parent !== null && $parent->getCollection() === AtprotoIngress::COLLECTION) {
			// A reply mirrored from an ActivityPub-only thread has no AT root;
			// the closest mapped parent is a valid root and keeps the reply in
			// the conversation readers can actually dereference.
			$record['reply'] = [
				'root' => ['uri' => $parent->getAtUri(), 'cid' => $parent->getCid()],
				'parent' => ['uri' => $parent->getAtUri(), 'cid' => $parent->getCid()],
			];
		}

		return $record;
	}

	/**
	 * Converts the HTML anchors and hashtags Social already stores into
	 * Bluesky's byte-based rich-text facets. The offsets are calculated against
	 * the final plain text, never the HTML, so UTF-8 text remains addressable.
	 *
	 * @return list<array{index: array{byteStart: int, byteEnd: int}, features: list<array<string, string>>}>
	 */
	private function facets(string $html, string $text, Stream $post): array {
		$facets = [];
		$occupied = [];
		$offset = 0;
		$pattern = '/<a\\b[^>]*href=["\\\']([^"\\\']+)["\\\'][^>]*>(.*?)<\\/a>/isu';
		if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) !== false) {
			foreach ($matches as $match) {
				$label = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				$start = $label === '' ? false : strpos($text, $label, $offset);
				if ($start === false) {
					continue;
				}
				$end = $start + strlen($label);
				$offset = $end;
				$occupied[] = [$start, $end];
				$facets[] = [
					'index' => ['byteStart' => $start, 'byteEnd' => $end],
					'features' => [[
						'$type' => 'app.bsky.richtext.facet#link',
						'uri' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
					]],
				];
			}
		}

		if (preg_match_all('/(?<![\\pL\\pN_])#([\\pL\\pN_]+)/u', $text, $tags, PREG_OFFSET_CAPTURE) !== false) {
			foreach ($tags[0] as $index => [$tag, $start]) {
				$end = $start + strlen($tag);
				$overlap = false;
				foreach ($occupied as [$from, $to]) {
					$overlap = $start < $to && $end > $from;
					if ($overlap) {
						break;
					}
				}
				if ($overlap) {
					continue;
				}
				$facets[] = [
					'index' => ['byteStart' => $start, 'byteEnd' => $end],
					'features' => [[
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag' => ltrim($tag, '#'),
					]],
				];
			}
		}
		if ($this->identity !== null) {
			foreach ($post->getTags('Mention') as $mention) {
				$name = trim((string)($mention['name'] ?? ''));
				$handle = ltrim($name, '@');
				if ($name === '' || !str_contains($handle, '.') || str_contains($handle, '@')) {
					continue;
				}
				$start = strpos($text, $name);
				if ($start === false) {
					continue;
				}
				$end = $start + strlen($name);
				$overlap = false;
				foreach ($occupied as [$occupiedStart, $occupiedEnd]) {
					if ($start < $occupiedEnd && $end > $occupiedStart) {
						$overlap = true;
						break;
					}
				}
				if ($overlap) {
					try {
						$did = $this->identity->resolve($handle)['did'];
					} catch (AtprotoException) {
						continue;
					}
					foreach ($facets as &$facet) {
						if ($facet['index']['byteStart'] === $start && $facet['index']['byteEnd'] === $end) {
							$facet['features'] = [[
								'$type' => 'app.bsky.richtext.facet#mention',
								'did' => $did,
							]];
							break;
						}
					}
					unset($facet);
					continue;
				}
				try {
					$did = $this->identity->resolve($handle)['did'];
				} catch (AtprotoException) {
					continue;
				}
				$occupied[] = [$start, $end];
				$facets[] = [
					'index' => ['byteStart' => $start, 'byteEnd' => $end],
					'features' => [[
						'$type' => 'app.bsky.richtext.facet#mention',
						'did' => $did,
					]],
				];
			}
		}

		usort($facets, static fn (array $left, array $right): int => $left['index']['byteStart'] <=> $right['index']['byteStart']);

		return $facets;
	}

	/** @return array<string, mixed> */
	private function put(\OCA\Social\Model\Atproto\AtprotoAccount $account, string $rkey, array $record): array {
		return $this->client->authedPost('com.atproto.repo.putRecord', [
			'repo' => $account->getDid(),
			'collection' => AtprotoIngress::COLLECTION,
			'rkey' => $rkey,
			'record' => $record,
		], $account, $account->getPds());
	}

	/** @param array<string, mixed> $answer */
	private function saveLink(Stream $post, \OCA\Social\Model\Atproto\AtprotoAccount $account, string $rkey, array $answer): void {
		$uri = (string)($answer['uri'] ?? '');
		$cid = (string)($answer['cid'] ?? '');
		if (!str_starts_with($uri, 'at://' . $account->getDid() . '/')) {
			throw new AtprotoException('the PDS accepted a post without its AT URI', 0);
		}

		$this->atprotoRequest->saveLink(
			(new AtprotoLink())
				->setLocalId($post->getId())
				->setAtUri($uri)
				->setCid($cid)
				->setDid($account->getDid())
				->setCollection(AtprotoIngress::COLLECTION)
				->setRkey($rkey)
				->setHandle($account->getHandle())
		);
	}

	/** Removes the mirrored record after its local post was deleted. */
	public function delete(Stream $post): void {
		if (($post->getDetailsAll()[Details::PUBLICATION_TARGET] ?? 'both') === 'fediverse') {
			return;
		}
		$link = $this->atprotoRequest->getLinkByLocalId($post->getId());
		if ($link === null) {
			return;
		}
		$account = $this->accountFor($post);
		if ($account === null || $link->getDid() !== $account->getDid()) {
			return;
		}

		try {
			$this->client->authedPost('com.atproto.repo.deleteRecord', [
				'repo' => $account->getDid(),
				'collection' => $link->getCollection(),
				'rkey' => $link->getRkey(),
			], $account, $account->getPds());
		} catch (AtprotoException $e) {
			if (!$this->isGone($e)) {
				throw $e;
			}
		}
		$this->atprotoRequest->deleteLinkByLocalId($post->getId());
	}

	/**
	 * Deletes a native record from the linked author's repository.
	 *
	 * This is the profile-page counterpart to delete(Stream): imported/native
	 * posts have no local ActivityPub delete lifecycle, so the controller must
	 * address the mapping directly while still proving that the signed-in
	 * account owns the DID that owns the record.
	 *
	 * @throws AtprotoException when the account is missing, the mapping is not
	 *                          known, or the PDS refuses the delete
	 */
	public function deleteOwn(string $userId, string $localId): void {
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null || $account->getState() !== AtprotoAccount::STATE_LINKED) {
			throw new AtprotoException('link a Bluesky account before deleting posts', 401);
		}
		$link = $this->linkForPostId($localId);
		if ($link === null || $link->getCollection() !== AtprotoIngress::COLLECTION) {
			throw new AtprotoException('this Bluesky post is no longer known here', 404);
		}
		if ($link->getDid() !== $account->getDid()) {
			throw new AtprotoException('you may only delete your own Bluesky posts', 403);
		}

		try {
			$this->client->authedPost('com.atproto.repo.deleteRecord', [
				'repo' => $account->getDid(),
				'collection' => $link->getCollection(),
				'rkey' => $link->getRkey(),
			], $account, $account->getPds());
		} catch (AtprotoException $e) {
			if (!$this->isGone($e)) {
				throw $e;
			}
		}
		$this->atprotoRequest->deleteLinkByLocalId($link->getLocalId());
	}

	/** Update the text of a native post owned by the linked account. */
	public function updateOwn(string $userId, string $localId, string $text): void {
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null || $account->getState() !== AtprotoAccount::STATE_LINKED) {
			throw new AtprotoException('link a Bluesky account before editing posts', 401);
		}
		$link = $this->linkForPostId($localId);
		if ($link === null || $link->getCollection() !== AtprotoIngress::COLLECTION) {
			throw new AtprotoException('this Bluesky post is no longer known here', 404);
		}
		if ($link->getDid() !== $account->getDid()) {
			throw new AtprotoException('you may only edit your own Bluesky posts', 403);
		}

		try {
			$current = $this->client->authedGet('com.atproto.repo.getRecord', [
				'repo' => $account->getDid(),
				'collection' => $link->getCollection(),
				'rkey' => $link->getRkey(),
			], $account, $account->getPds());
		} catch (AtprotoException $e) {
			if ($this->isGone($e)) {
				$this->atprotoRequest->deleteLinkByLocalId($link->getLocalId());
			}
			throw $e;
		}
		$record = is_array($current['value'] ?? null) ? $current['value'] : [];
		$record['$type'] = 'app.bsky.feed.post';
		$record['text'] = $this->nativeText($text);
		$facets = $this->facetsFromPlainText($record['text']);
		if ($facets === []) {
			unset($record['facets']);
		} else {
			$record['facets'] = $facets;
		}
		$answer = $this->client->authedPost('com.atproto.repo.putRecord', [
			'repo' => $account->getDid(),
			'collection' => $link->getCollection(),
			'rkey' => $link->getRkey(),
			'record' => $record,
			'swapRecord' => $link->getCid(),
		], $account, $account->getPds());
		if (($cid = (string)($answer['cid'] ?? '')) !== '') {
			$link->setCid($cid);
		}
		$this->atprotoRequest->saveLink($link);
	}

	/**
	 * Profile responses normally expose the stable local ActivityPub-shaped id.
	 * Older cached responses can still carry the native `at://` URI; accepting
	 * both keeps delete/edit reliable across an upgrade and a stale browser.
	 */
	private function linkForPostId(string $postId): ?AtprotoLink {
		$link = $this->atprotoRequest->getLinkByLocalId($postId);
		if ($link === null && str_starts_with($postId, 'at://')) {
			$link = $this->atprotoRequest->getLinkByAtUri($postId);
		}

		return $link;
	}

	private function isGone(AtprotoException $exception): bool {
		return in_array($exception->getStatus(), [404, 410], true);
	}

	private function shouldMirror(Stream $post): bool {
		return ($post->getDetailsAll()[Details::PUBLICATION_TARGET] ?? 'both') !== 'fediverse'
			&& $this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_ENABLED) === '1'
			&& $this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_EGRESS) === '1'
			&& $post->getAttributedTo() !== ''
			&& $post->getVisibility() === Stream::TYPE_PUBLIC;
	}

	/** The Nextcloud user behind the local ActivityPub actor's id. */
	private function accountFor(Stream $post): ?\OCA\Social\Model\Atproto\AtprotoAccount {
		try {
			$userId = $this->actorsRequest->getFromId($post->getAttributedTo())->getUserId();
		} catch (ActorDoesNotExistException $e) {
			$this->logger->warning('could not find the local author for an AT-Proto mirror', [
				'author' => $post->getAttributedTo(), 'exception' => $e,
			]);

			return null;
		}

		return $userId === '' ? null : $this->atprotoRequest->getAccount($userId);
	}

	/** Bluesky accepts text, not the ActivityPub HTML held in a local row. */
	private function text(string $html): string {
		$text = trim(html_entity_decode(strip_tags(preg_replace('/<br\\s*\\/?\\s*>/i', chr(10), $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		// The network's 300-grapheme maximum is part of its record contract;
		// cutting here is safer than an opaque PDS validation failure.
		if (function_exists('grapheme_substr') && grapheme_strlen($text) > 300) {
			return grapheme_substr($text, 0, 299) . '…';
		}

		return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
	}

	private function nativeText(string $text): string {
		$text = trim(preg_replace('/\r\n?/', "\n", $text) ?? '');
		if (function_exists('grapheme_substr') && grapheme_strlen($text) > 300) {
			return grapheme_substr($text, 0, 299) . '…';
		}

		return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
	}

	/**
	 * Rebuild facets when the profile editor supplies plain text rather than
	 * Social's stored HTML. Offsets from PCRE are byte offsets, as ATProto
	 * requires, so Unicode text remains addressable without splitting a glyph.
	 *
	 * @return list<array{index: array{byteStart: int, byteEnd: int}, features: list<array<string, string>>}>
	 */
	private function facetsFromPlainText(string $text): array {
		$facets = [];
		$occupied = [];
		$add = static function (int $start, int $end, array $feature) use (&$facets, &$occupied): void {
			foreach ($occupied as [$from, $to]) {
				if ($start < $to && $end > $from) {
					return;
				}
			}
			$occupied[] = [$start, $end];
			$facets[] = [
				'index' => ['byteStart' => $start, 'byteEnd' => $end],
				'features' => [$feature],
			];
		};

		if (preg_match_all('~https?://[^\s<>]+~u', $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
			foreach ($matches[0] as [$url, $start]) {
				$url = rtrim($url, '.,!?;:)]}');
				if ($url !== '') {
					$add($start, $start + strlen($url), [
						'$type' => 'app.bsky.richtext.facet#link',
						'uri' => $url,
					]);
				}
			}
		}

		if (preg_match_all('/(?<![\pL\pN_])#([\pL\pN_]+)/u', $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
			foreach ($matches[0] as $index => [$tag, $start]) {
				$add($start, $start + strlen($tag), [
					'$type' => 'app.bsky.richtext.facet#tag',
					'tag' => ltrim($tag, '#'),
				]);
			}
		}

		if ($this->identity !== null
			&& preg_match_all('/(?<![\pL\pN_])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z0-9.-]+)/u', $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
			foreach ($matches[0] as [$mention, $start]) {
				$handle = ltrim($mention, '@');
				try {
					$did = $this->identity->resolve($handle)['did'];
				} catch (AtprotoException) {
					continue;
				}
				$add($start, $start + strlen($mention), [
					'$type' => 'app.bsky.richtext.facet#mention',
					'did' => $did,
				]);
			}
		}

		usort($facets, static fn (array $left, array $right): int => $left['index']['byteStart'] <=> $right['index']['byteStart']);

		return $facets;
	}

	/** Upload local attachments and return one native image/video embed. */
	private function mediaEmbed(Stream $post, AtprotoAccount $account): ?array {
		if ($this->documentService === null) {
			return null;
		}
		$images = [];
		$video = null;
		foreach (array_slice($post->getAttachments(), 0, 4) as $attachment) {
			if (!$attachment instanceof Document
				|| !$attachment->isLocalUpload() || $attachment->isStreamed()) {
				continue;
			}
			$mime = strtolower(trim($attachment->getMimeType()));
			$mediaType = self::NATIVE_MEDIA_MIME_TYPES[$mime] ?? null;
			$limit = $mediaType === 'video' ? 50 * 1024 * 1024 : 1 * 1024 * 1024;
			if ($mediaType === null
				|| $attachment->getSizeBytes() < 1 || $attachment->getSizeBytes() > $limit) {
				continue;
			}
			try {
				$file = $this->documentService->getFromCache($attachment->getId(), $mime, true);
				$blob = $this->client->authedBlobPost($file->getContent(), $mime, $account, $account->getPds())['blob'] ?? null;
				if (!is_array($blob)) {
					continue;
				}
				$aspectRatio = $this->aspectRatio($attachment);
				if ($mediaType === 'video') {
					$video = ['$type' => 'app.bsky.embed.video', 'video' => $blob, 'alt' => $attachment->getDescription()];
					if ($aspectRatio !== null) {
						$video['aspectRatio'] = $aspectRatio;
					}
					break;
				}
				$image = ['image' => $blob, 'alt' => $attachment->getDescription()];
				if ($aspectRatio !== null) {
					$image['aspectRatio'] = $aspectRatio;
				}
				$images[] = $image;
			} catch (\Throwable $e) {
				$this->logger->info('could not upload an ATProto attachment', ['post' => $post->getId(), 'exception' => $e]);
			}
		}
		if ($video !== null) {
			return $video;
		}

		return $images === [] ? null : ['$type' => 'app.bsky.embed.images', 'images' => $images];
	}

	/** @return array{width: int, height: int}|null */
	private function aspectRatio(Document $attachment): ?array {
		$meta = $attachment->getMeta();
		$width = $meta?->getWidth();
		$height = $meta?->getHeight();
		if ((!is_int($width) || $width < 1) || (!is_int($height) || $height < 1)) {
			[$width, $height] = $attachment->getLocalCopySize();
		}

		return is_int($width) && is_int($height) && $width > 0 && $height > 0
			? ['width' => $width, 'height' => $height]
			: null;
	}

	/** A 13-character AT Protocol timestamp record key (TID). */
	private function tid(): string {
		$alphabet = '234567abcdefghijklmnopqrstuvwxyz';
		$now = new \DateTimeImmutable('now');
		$value = ((int)$now->format('U') * 1_000_000) + (int)$now->format('u');
		$key = '';
		for ($position = 0; $position < 11; $position++) {
			$key = $alphabet[$value % 32] . $key;
			$value = intdiv($value, 32);
		}

		$clock = $this->clock++ % 1024;

		return $key . $alphabet[intdiv($clock, 32)] . $alphabet[$clock % 32];
	}
}
