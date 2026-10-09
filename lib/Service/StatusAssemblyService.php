<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\StatusParams;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The post a stored request becomes.
 *
 * Two things here keep a request instead of a post — one scheduled for later,
 * one held for a moderator — and both have to turn it back into exactly the
 * `Post` that `StatusApiController::statusNew()` assembles from a live request. A
 * note built by one path and federated by another drifts: the quote approval,
 * the language fallback and the source snapshot all happen inside
 * `PostService::createPost()`, and what reaches it has to be the same shape
 * whichever door the post came in by. One assembler, so there is one shape.
 */
class StatusAssemblyService {
	public function __construct(
		private DocumentService $documentService,
		private StreamService $streamService,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The stored request: Mastodon's keys, filled from what the client
	 * sent, and nothing that was not asked for.
	 *
	 * Built from the parsed `Status` rather than copied out of the raw body so
	 * that what is replayed later is what this app understood at the time —
	 * the same normalisation an immediate post gets, and not a second reading
	 * of the request by a second piece of code.
	 *
	 * `scheduled_at` stays out of `params`. A scheduled post's time lives on
	 * its own row, where `reschedule()` keeps it current; a copy in here would
	 * be the stale one the moment a post is moved, and a client cannot tell
	 * which of the two it is meant to believe.
	 *
	 * @return array<string, mixed>
	 */
	public function paramsOf(Status $status, string $visibility): array {
		return [
			'text' => $status->getStatus(),
			// strings, because every id Mastodon shows a client is a string,
			// and this array is echoed back verbatim
			'media_ids' => array_map('strval', $status->getMediaIds()),
			'poll' => $status->getPoll(),
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
	 * The same assembly an immediate post gets, reading from stored `params`
	 * instead of from the live request.
	 */
	public function fromParams(Person $actor, StatusParams $params): Post {
		$post = new Post($actor);
		$post->setContent($params->paramText());
		$post->setPoll($params->paramPoll());
		$post->setSpoilerText($params->paramString('spoiler_text'));
		$post->setSensitive($params->paramBool('sensitive'));
		$post->setType($params->paramString('visibility'));
		$post->setLanguage($params->paramString('language'));
		$post->setQuotedId($params->paramString('quoted_status_id'));
		$post->setReplyRule($params->paramString('reply_policy'));

		$mediaIds = $params->paramMediaIds();
		if ($mediaIds !== []) {
			$documents = $this->documentService->getMediaFromArray(
				$mediaIds, $actor->getPreferredUsername()
			);
			$this->scopeMediaToVisibility($documents, $post->getType());
			$post->setMedias(
				array_map(function (Document $document): MediaAttachment {
					return $document->convertToMediaAttachment(
						$this->urlGenerator, ACore::FORMAT_ACTIVITYPUB
					);
				}, $documents)
			);
		}

		$replyTo = (int)$params->paramString('in_reply_to_id');
		if ($replyTo > 0) {
			try {
				$post->setReplyTo($this->streamService->getStreamByNid($replyTo)->getId());
			} catch (Throwable $e) {
				// The post being replied to was deleted while this one waited,
				// which is likelier here than on an immediate post. The reply
				// still goes out, as a post of its own, rather than being lost
				// with it.
				$this->logger->debug('[StatusAssemblyService] the post ' . $replyTo . ' replied to is gone');
			}
		}

		return $post;
	}

	/**
	 * Records on a post's attachments whether the post itself is
	 * world-readable, which decides how the bytes may be cached on the way to a
	 * reader.
	 *
	 * The twin of `StatusApiController::scopeMediaToVisibility()`, which does this
	 * for an immediate post and is private to the controller. Both exist
	 * because the answer is only known when the post is created.
	 *
	 * @param Document[] $documents
	 */
	private function scopeMediaToVisibility(array $documents, string $visibility): void {
		$public = in_array($visibility, [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);

		foreach ($documents as $document) {
			if ($document->isPublic() === $public) {
				continue;
			}

			$document->setPublic($public);
			$this->cacheDocumentsRequest->update($document);
		}
	}
}
