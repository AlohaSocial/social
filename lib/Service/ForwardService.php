<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Inbox forwarding, ActivityPub §7.1.2.
 *
 * A reply to a local post is delivered only to us, and our followers do not
 * follow the replier, so it is passed on to that post's followers unchanged:
 * the activity carries its author's linked-data signature, which every
 * receiver can check. An activity we cannot pass on intact is not passed on.
 */
class ForwardService {
	/**
	 * Long enough to cover a sender retrying, short enough that this stays a
	 * guard against duplicates rather than a second source of truth.
	 */
	private const SEEN_TTL = 3600;

	private ICache $forwarded;

	public function __construct(
		private ActorsRequest $actorsRequest,
		private FollowsRequest $followsRequest,
		private StreamRequest $streamRequest,
		private RequestQueueService $requestQueueService,
		private CurlService $curlService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->forwarded = $cacheFactory->createDistributed('social.forwarded');
	}

	/**
	 * Passes an incoming reply on to the followers of the local post it
	 * replies to. Every reason not to forward is a silent return, since the
	 * delivery has already been accepted.
	 */
	public function forwardReply(ACore $activity, Stream $note): void {
		if ($note->getInReplyTo() === '') {
			return;
		}

		// Without the author's own signature over the document, a recipient
		// has only our word for who wrote it — which is exactly what §7.1.2
		// forwarding must not ask of anyone.
		if ($activity->getOriginSource() !== SignatureService::ORIGIN_SIGNATURE
			|| $activity->getSource() === '') {
			return;
		}

		if (!$this->distributable($note)) {
			return;
		}

		try {
			$parent = $this->streamRequest->getStreamById($note->getInReplyTo());
		} catch (StreamNotFoundException $e) {
			return;
		}

		// only the instance that holds the post owes its followers the thread
		if (!$parent->isLocal() || !$this->distributable($parent)) {
			return;
		}

		try {
			$author = $this->actorsRequest->getFromId($parent->getAttributedTo());
		} catch (ActorDoesNotExistException|\Exception $e) {
			return;
		}

		if (!$this->firstSighting($activity->getId())) {
			return;
		}

		$paths = $this->followerInboxes($author->getId(), $activity->getOrigin());
		if ($paths === []) {
			return;
		}

		$token = $this->requestQueueService->generateRequestQueueFromSource(
			$paths, $activity->getSource(), $author->getId()
		);

		$this->logger->debug('forwarding a reply to the followers of a local post', [
			'activity' => $activity->getId(),
			'inReplyTo' => $note->getInReplyTo(),
			'inboxes' => count($paths),
		]);

		// nothing about delivering to strangers belongs on the inbox request
		$this->curlService->asyncWithToken($token);
	}

	/**
	 * A post nobody was meant to be able to see is not forwarded, whichever
	 * end of the reply it sits at: a followers-only post has an audience its
	 * author chose, and a reply to it inherits that choice.
	 */
	private function distributable(Stream $stream): bool {
		return in_array($stream->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);
	}

	/**
	 * Whether this is the first time we are asked to forward this activity.
	 * A re-delivery normally finds the note stored and never gets here; this
	 * covers two copies arriving at once.
	 */
	private function firstSighting(string $activityId): bool {
		if ($activityId === '') {
			return false;
		}

		$key = hash('sha256', $activityId);
		if ($this->forwarded->get($key) !== null) {
			return false;
		}
		$this->forwarded->set($key, 1, self::SEEN_TTL);

		return true;
	}

	/**
	 * The shared inboxes of everyone following the local author, minus the
	 * instance that just sent us the activity (it has it) and our own (we do).
	 *
	 * @return InstancePath[]
	 */
	private function followerInboxes(string $actorId, string $origin): array {
		$skip = array_filter([strtolower($origin), $this->localHost()]);

		$paths = [];
		// the distinct inboxes, resolved in the database: the shared one where
		// the remote publishes one, its personal inbox otherwise
		foreach ($this->followsRequest->getFollowerInboxes($actorId) as $inbox) {
			$host = strtolower((string)parse_url($inbox, PHP_URL_HOST));
			if ($host === '' || in_array($host, $skip, true)) {
				continue;
			}

			$paths[] = new InstancePath($inbox, InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW);
		}

		return $paths;
	}

	private function localHost(): string {
		try {
			return strtolower((string)parse_url($this->configService->getCloudUrl(), PHP_URL_HOST));
		} catch (\Exception $e) {
			return '';
		}
	}
}
