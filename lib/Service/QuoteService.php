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
use OCA\Social\Model\InstancePath;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An author's control over who quotes their posts.
 *
 * This app has answered a `QuoteRequest` since quotes landed, but the answer
 * was derived from one thing — whether the post was public — and there was no
 * way to change it, to see who had taken you up on it, or to change your mind.
 * Mastodon 4.5 has all three, and they are the half of quoting that makes it
 * bearable: the reason to be able to quote somebody is also the reason to be
 * able to stop them.
 *
 * Three things, then: the policy (written on the post, see
 * `Stream::QUOTE_POLICIES`), the list of who has quoted it, and taking one
 * back.
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
	 * Sets who may quote one of the author's own posts.
	 *
	 * What Mastodon's `PUT /api/v1/statuses/{id}/interaction_policy` writes,
	 * and what a `quote_approval_policy` on a create or an edit writes too.
	 *
	 * **Only forward.** Changing the policy decides what happens to requests
	 * that arrive from now on; it does not reach back and withdraw the
	 * permissions already given, because a quote that has been published and
	 * read is not undone by a switch being flipped. Taking one back is
	 * `revoke()`, which says so out loud and tells the other server.
	 *
	 * @throws InvalidResourceException the post is not this account's own
	 */
	public function setPolicy(int|string $nid, Person $actor, string $policy): Stream {
		$post = $this->ownPost($nid, $actor);
		$post->setQuotePolicy($policy);

		// the wire object carries `interactionPolicy`, so the stored source is
		// re-snapshotted with it — the same path the quote's approval stamp
		// takes, and what makes every later delivery of the post say the same
		// thing this server says
		$post->setSource(json_encode($post, JSON_UNESCAPED_SLASHES));
		$this->streamRequest->update($post);

		// nothing is federated by the change on its own: `interactionPolicy`
		// is read off the post, and the post is re-sent when it is edited. A
		// peer that has an old copy asks before it quotes anyway, and the
		// answer is given here, now, by the policy as it now stands.

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
	 * FEP-044f revokes with a `Reject` naming the `QuoteRequest` that was
	 * accepted — which is why the grant was written down when it was given.
	 * The quoting server then shows the quote as revoked, which is what this
	 * app does with an incoming one (`QuoteRequestInterface::activity()`).
	 *
	 * A **local** quoting post is revoked here directly: there is nobody to
	 * tell, and the stamp on it is one this instance issued to itself.
	 *
	 * A **Bluesky** quote has no request to refuse: Bluesky asks nobody's
	 * permission. It is detached the way Bluesky detaches one, by the post's
	 * postgate listing it (`detachedEmbeddingUris`), which every AppView
	 * honours; here it is withdrawn at once.
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
			// a remote quote this instance never granted: the approval came
			// from somewhere else, or from before the grant was recorded.
			// There is no request to name, so the quote is refused with one
			// built from what the post itself says — the only two ids that
			// matter to the receiver are the same either way
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
		$quoting->setSource(json_encode($quoting, JSON_UNESCAPED_SLASHES));

		$this->streamRequest->update($quoting);
		$this->streamRequest->updateDetails($quoting);
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
		$this->streamRequest->updateDetails($post);
		try {
			$this->container?->get(Publisher::class)->updateGates($post);
		} catch (Throwable $e) {
			$this->logger->warning('Detached quote not written to Bluesky', ['post' => $post->getId(), 'quoting' => $quoting->getId(), 'exception' => $e]);
		}
	}

	/**
	 * The `Reject` that takes a remote quote back.
	 *
	 * The request is rebuilt rather than stored whole: what the receiver
	 * matches on is the pair of ids it names — the post being quoted and the
	 * post doing the quoting — and this app's own inbound handler matches on
	 * exactly those. The request's own id is used where it is known, so a
	 * receiver that keys on it finds what it is looking for.
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
