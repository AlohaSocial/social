<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\StatusParams;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The post a stored request becomes. Scheduled and held posts are stored as
 * requests and must become exactly the `Post` that
 * `StatusApiController::statusNew()` assembles live, as
 * `PostService::createPost()` relies on that shape; one assembler keeps them
 * the same.
 */
class StatusAssemblyService {
	public function __construct(
		private DocumentService $documentService,
		private StreamService $streamService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The stored request: Mastodon's keys, filled from the parsed `Status` so a
	 * replay matches what this app understood at the time. `scheduled_at` stays
	 * out: it lives on the scheduled row, where `reschedule()` keeps it current.
	 *
	 * @return array<string, mixed>
	 */
	public function paramsOf(Status $status, string $visibility): array {
		return [
			'text' => $status->getStatus(),
			// Strings, as Mastodon shows every id, since this array is echoed back.
			'media_ids' => array_map('strval', $status->getMediaIds()),
			'poll' => $status->getPoll(),
			'event' => $status->getEvent(),
			'in_reply_to_id' => ($status->getInReplyToId() > 0)
				? (string)$status->getInReplyToId() : null,
			'quoted_status_id' => ($status->getQuotedId() !== '') ? $status->getQuotedId() : null,
			'sensitive' => $status->isSensitive(),
			'spoiler_text' => $status->getSpoilerText(),
			'visibility' => $visibility,
			'language' => $status->getLanguage(),
		] + ($status->getReplyRule() !== '' ? ['reply_policy' => $status->getReplyRule()] : []);
	}

	/**
	 * The stored request a `Post` is replayed from, as `fromParams()` reads it,
	 * so a held post is approved as written.
	 *
	 * @param string $inReplyToNid the nid of the post it answers, '' for none
	 *
	 * @return array<string, mixed>
	 */
	public static function paramsOfPost(Post $post, string $inReplyToNid = ''): array {
		$params = [
			'text' => $post->getContent(),
			'media_ids' => array_values(array_filter(array_map(
				static fn (MediaAttachment $media): string => $media->getId(), $post->getMedias()
			), static fn (string $id): bool => $id !== '')),
			'poll' => $post->getPoll(),
			'event' => $post->getEvent(),
			'in_reply_to_id' => ($inReplyToNid !== '') ? $inReplyToNid : null,
			'quoted_status_id' => ($post->getQuotedId() !== '') ? $post->getQuotedId() : null,
			'sensitive' => $post->isSensitive(),
			'spoiler_text' => $post->getSpoilerText(),
			'visibility' => $post->getType(),
			'language' => $post->getLanguage(),
		];
		if ($post->getReplyRule() !== '') {
			$params['reply_policy'] = $post->getReplyRule();
		}
		if ($post->getAtprotoRecordEmbed() !== null) {
			$params['atproto_record_embed'] = $post->getAtprotoRecordEmbed();
		}
		if ($post->getAtprotoSelfLabels() !== []) {
			$params['atproto_self_labels'] = $post->getAtprotoSelfLabels();
		}
		if ($post->getLinkCard() !== null) {
			$params['atproto_card'] = $post->getLinkCard();
		}

		return $params;
	}

	/**
	 * The same assembly an immediate post gets, reading from stored `params`
	 * instead of from the live request.
	 *
	 * @throws StreamNotFoundException the post it replies to is not one the
	 *                                 author can see any more
	 */
	public function fromParams(Person $actor, StatusParams $params): Post {
		$post = new Post($actor);
		$post->setContent($params->paramText());
		$post->setPoll($params->paramPoll());
		$post->setEvent($params->paramEvent());
		$post->setSpoilerText($params->paramString('spoiler_text'));
		$post->setSensitive($params->paramBool('sensitive'));
		$post->setType($params->paramString('visibility'));
		$post->setLanguage($params->paramString('language'));
		$post->setQuotedId($params->paramString('quoted_status_id'));
		$post->setReplyRule($params->paramString('reply_policy'));

		$recordEmbed = $params->paramArray('atproto_record_embed');
		$recordUri = $recordEmbed['uri'] ?? null;
		$recordCid = $recordEmbed['cid'] ?? null;
		if (is_string($recordUri) && Syntax::isAtUri($recordUri)
			&& is_string($recordCid) && Cid::isValid($recordCid)) {
			$post->setAtprotoRecordEmbed(['uri' => $recordUri, 'cid' => $recordCid]);
		}
		$labels = $params->paramArray('atproto_self_labels');
		if ($labels !== null) {
			$post->setAtprotoSelfLabels(array_values(array_filter(
				$labels, static fn (mixed $value): bool => is_string($value) && $value !== '' && mb_strlen($value) <= 128
			)));
		}

		$card = $params->paramArray('atproto_card');
		if ($card !== null
			&& is_string($card['url'] ?? null) && Stream::isExternalLink($card['url'])
			&& is_string($card['title'] ?? null)
			&& is_string($card['description'] ?? null)
			&& is_string($card['image'] ?? null)
			&& ($card['image'] === '' || Stream::isExternalLink($card['image']))
			&& is_string($card['provider'] ?? null)) {
			$post->setLinkCard([
				'url' => $card['url'],
				'title' => $card['title'],
				'description' => $card['description'],
				'image' => $card['image'],
				'provider' => $card['provider'],
			]);
		}

		$replyTo = (int)$params->paramString('in_reply_to_id');
		if ($replyTo > 0) {
			// Read as the author, since the cron and a moderator see a different
			// audience. A parent the author can no longer see, or that was deleted,
			// fails the post: without it a reply to a direct message would go to the
			// default audience.
			$this->streamService->setViewer($actor);
			try {
				$parent = $this->streamService->getStreamByNid($replyTo);
			} catch (Throwable $e) {
				$this->logger->debug('[StatusAssemblyService] the post ' . $replyTo . ' replied to is gone');

				throw new StreamNotFoundException(PostService::REPLY_PARENT_NOT_FOUND);
			}
			$post->setReplyTo($parent->getId());
			// before the media is scoped, as `statusNew()` does: a reply to a
			// direct message is direct, and so are its attachments
			$post->setType(PostService::visibilityOfReply($post->getType(), $parent));
		}

		$mediaIds = $params->paramMediaIds();
		if ($mediaIds !== []) {
			$post->setMedias($this->documentService->mediaAttachmentsForEdit(
				$mediaIds, $actor->getPreferredUsername(), $post->getType()
			));
		}

		return $post;
	}
}
