<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An author's control over who quotes their posts, as in Mastodon 4.5: the
 * policy (written on the post, see `Stream::QUOTE_POLICIES`), the list of who
 * has quoted it, and taking a quote back.
 */
class QuoteService {
	public function __construct(
		private StreamRequest $streamRequest,
		private QuoteGrantRequest $quoteGrantRequest,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * Sets who may quote one of the author's own posts, as Mastodon's
	 * `PUT /api/v1/statuses/{id}/interaction_policy` and a
	 * `quote_approval_policy` on create or edit do. It applies to future
	 * requests only; withdrawing given permissions is `revoke()`.
	 *
	 * @throws InvalidResourceException the post is not this account's own
	 */
	public function setPolicy(int|string $nid, Person $actor, string $policy): Stream {
		$post = $this->ownPost($nid, $actor);
		$post->setQuotePolicy($policy);

		// the stored source is re-snapshotted with the new `interactionPolicy`,
		// so later deliveries of the post carry it
		$post->setSource($post->wireSource());
		$this->streamRequest->update($post);

		// nothing is federated by the change itself: peers ask before they
		// quote, and are answered by the policy as it stands

		return $post;
	}

	/**
	 * The posts this instance holds that quote one post, newest first.
	 *
	 * @return Stream[]
	 */
	public function quotesOf(Stream $post, int $limit = 20, int|string $maxId = '0'): array {
		return $this->streamRequest->getQuotesOf($post->getId(), $limit, $maxId);
	}

	/**
	 * Takes one quote of one of the author's own posts back.
	 *
	 * A remote quote is revoked with a FEP-044f `Reject` of the accepted
	 * `QuoteRequest` (handled on our side by
	 * `QuoteRequestInterface::activity()`). A local one is revoked directly.
	 * A Bluesky quote is detached via the post's postgate
	 * (`detachedEmbeddingUris`) and withdrawn here at once.
	 *
	 * @return bool whether there was a quote to take back
	 * @throws InvalidResourceException the quoted post is not this account's own
	 */
	public function revoke(int|string $nid, Person $actor, int|string $quotingNid): bool {
		$post = $this->ownPost($nid, $actor);

		try {
			$quoting = $this->streamRequest->getStreamByNid($quotingNid);
		} catch (Throwable $e) {
			return false;
		}

		if ($quoting->getQuote() !== $post->getId()) {
			// the post named does not quote this one; nothing to take back,
			// and answering otherwise would say which ids exist
			return false;
		}

		$grant = $this->quoteGrantRequest->get($post->getId(), $quoting->getId());

		if ($quoting->isLocal()) {
			$this->withdrawLocally($quoting);
		} elseif (BlueskyIds::isPostId($quoting->getId())) {
			$this->detachOnBluesky($post, $quoting);
		} elseif ($grant !== null) {
			$this->tell($post, $quoting, $grant->getRequestId(), $grant->getActorId());
		} else {
			// a remote quote without a recorded grant: the Reject names a
			// request rebuilt from the two post ids, which is what receivers
			// match on
			$this->tell($post, $quoting, '', $quoting->getAttributedTo());
		}

		$this->quoteGrantRequest->delete($post->getId(), $quoting->getId());

		return true;
	}

	/**
	 * A local quote, taken back without telling anybody: the stamp on it is
	 * one this instance issued to itself, and there is no other server holding
	 * a copy of the permission.
	 */
	private function withdrawLocally(Stream $quoting): void {
		$quoting->setQuoteAuthorization('');
		$quoting->setQuoteState(Stream::QUOTE_REVOKED);
		$quoting->setSource($quoting->wireSource());

		$this->streamRequest->update($quoting);
		$this->streamRequest->updateDetails($quoting, Details::QUOTE_STATE);
	}

	/**
	 * A Bluesky quote, detached: listed in the quoted post's postgate, and
	 * withdrawn here. The postgate is written only for a post that is on
	 * Bluesky; one that is not cannot be quoted there with an embed anyway.
	 */
	private function detachOnBluesky(Stream $post, Stream $quoting): void {
		$this->withdrawLocally($quoting);
		$uri = (string)($quoting->getDetails(PostMapper::DETAIL)['uri'] ?? '');
		if ($uri === '') {
			return;
		}
		$post->addDetachedQuote($uri);
		$this->streamRequest->updateDetails($post, Details::DETACHED_QUOTES);
		try {
			$this->container?->get(Publisher::class)->updateGates($post);
		} catch (Throwable $e) {
			$this->logger->warning('Detached quote not written to Bluesky', ['post' => $post->getId(), 'quoting' => $quoting->getId(), 'exception' => $e]);
		}
	}

	/**
	 * The `Reject` that takes a remote quote back. The request is rebuilt from
	 * the quoted and quoting post ids, which receivers match on, with its own
	 * id where known.
	 */
	private function tell(Stream $post, Stream $quoting, string $requestId, string $askerId): void {
		$request = new QuoteRequest();
		if ($requestId !== '') {
			$request->setId($requestId);
		}
		$request->setActorId($askerId);
		$request->setObjectId($post->getId());
		$request->setInstrument($quoting->getId());

		$reject = new Reject();
		$reject->generateUniqueIdFromActor($post->getAttributedTo(), 'reject/quote-requests');
		$reject->setActorId($post->getAttributedTo());
		$reject->setObject($request);
		$reject->setToArray([$askerId]);

		$inbox = $this->inboxOf($askerId);
		if ($inbox === '') {
			$this->logger->notice('cannot tell a server a quote was taken back: no inbox', [
				'asker' => $askerId, 'quoting' => $quoting->getId(),
			]);

			return;
		}

		$reject->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP)
		);

		$this->activityService->request($reject);
	}

	private function inboxOf(string $actorId): string {
		try {
			return $this->cacheActorService->getFromId($actorId)->getInbox();
		} catch (Exception $e) {
			return '';
		}
	}

	/**
	 * The post, if it is this account's own.
	 *
	 * @throws InvalidResourceException
	 */
	private function ownPost(int|string $nid, Person $actor): Stream {
		try {
			$post = $this->streamRequest->getStreamByNid($nid);
		} catch (Throwable $e) {
			throw new InvalidResourceException('no such post');
		}

		if ($post->getAttributedTo() !== $actor->getId()) {
			// somebody else's post: who may quote it is their decision, and a
			// different answer here would say whose post it is
			throw new InvalidResourceException('no such post');
		}

		return $post;
	}
}
