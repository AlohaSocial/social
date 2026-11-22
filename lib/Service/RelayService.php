<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RelayRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\Relay;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Relays: actors that rebroadcast the public posts of every subscribed
 * instance, filling a small instance's federated timeline and spreading its
 * own public posts.
 *
 * The subscription belongs to the instance: `Follow{object: as:Public}`
 * (what Mastodon and the relay software expect) is built by hand and signed
 * by the instance actor, outside `social_request_queue`
 * (`HttpSignatureService::signAsInstance()`).
 *
 * A relayed `Announce` is a pointer, not a boost: the post is fetched from
 * its author's server via `SearchService::resolveStatus()`. Announces are
 * taken only from relays this instance subscribed to and that accepted.
 */
class RelayService {
	/**
	 * Seconds to wait for a relay while the administrator waits; the same as the
	 * report forwarder's inline delivery.
	 */
	private const TIMEOUT = 5;

	public function __construct(
		private RelayRequest $relayRequest,
		private HttpSignatureService $httpSignatureService,
		private CurlService $curlService,
		private CacheActorService $cacheActorService,
		private SearchService $searchService,
		private InstanceActorService $instanceActorService,
		private LoggerInterface $logger,
	) {
	}

	/** @return Relay[] newest first */
	public function all(): array {
		return $this->relayRequest->getAll();
	}

	/**
	 * Subscribes to a relay.
	 *
	 * `$address` is the relay's actor (usually `https://relay.example/actor`),
	 * fetched first for its inbox, so an unresolvable relay is refused. The row
	 * is written before the Follow goes out, so an instant Accept finds it.
	 *
	 * @throws InvalidResourceException the address is not a relay this instance can reach
	 */
	public function subscribe(string $address): Relay {
		$address = trim($address);
		if (!str_starts_with($address, 'https://') && !str_starts_with($address, 'http://')) {
			throw new InvalidResourceException('a relay is named by its address, like https://relay.example/actor');
		}

		try {
			$actor = $this->cacheActorService->getFromId($address, true);
		} catch (Throwable $e) {
			throw new InvalidResourceException('that address did not answer with an actor: ' . $e->getMessage());
		}

		$inbox = $actor->getInbox();
		if ($inbox === '') {
			throw new InvalidResourceException('that actor publishes no inbox, so there is nowhere to subscribe');
		}

		$relay = new Relay();
		$relay->setActorId($actor->getId())
			->setInbox($inbox)
			->setStatus(Relay::STATUS_PENDING)
			->setFollowId($this->followId($actor->getId()));
		$this->relayRequest->save($relay);

		$this->send($relay, $this->followActivity($relay));

		return $this->relayRequest->getByActorId($actor->getId()) ?? $relay;
	}

	/**
	 * Unsubscribes and forgets the relay. A failed `Undo` does not keep the row:
	 * without it nothing more is taken from the relay.
	 */
	public function unsubscribe(int $id): bool {
		$relay = $this->relayRequest->getById($id);
		if ($relay === null) {
			return false;
		}

		try {
			$this->send($relay, $this->undoActivity($relay), false);
		} catch (Throwable $e) {
			$this->logger->info('a relay was not told it had been dropped', [
				'relay' => $relay->getActorId(), 'exception' => $e,
			]);
		}

		return $this->relayRequest->delete($id);
	}

	/**
	 * Takes in an activity a relay sent, and says whether it was one. Only
	 * actors with a subscription row count as relays; anything else is handled
	 * as what it is.
	 *
	 * @return bool whether this was a relay's activity and has been dealt with
	 */
	public function handleIncoming(ACore $activity): bool {
		$relay = $this->relayRequest->getByActorId($activity->getActorId());
		if ($relay === null) {
			return false;
		}

		switch ($activity->getType()) {
			case 'Accept':
				// The Accept must name our Follow, or any relay's Accept could enable a
				// subscription nobody asked for.
				if ($this->acceptsOurFollow($activity, $relay)) {
					$this->relayRequest->setStatus($relay->getActorId(), Relay::STATUS_ACCEPTED);
					$this->logger->info('a relay accepted the subscription', ['relay' => $relay->getActorId()]);
				}

				return true;
			case 'Reject':
				$this->relayRequest->setStatus(
					$relay->getActorId(), Relay::STATUS_REJECTED, 'the relay refused the subscription'
				);

				return true;
			case Announce::TYPE:
				$this->announced($relay, $activity->getObjectId());

				return true;
			case 'Undo':
				// A relay dropping us: the row stays so an administrator can see it.
				$this->relayRequest->setStatus(
					$relay->getActorId(), Relay::STATUS_REJECTED, 'the relay ended the subscription'
				);

				return true;
		}

		return false;
	}

	/**
	 * Where a local public post has to go besides its followers' inboxes.
	 *
	 * @return string[] the inboxes of the relays that accepted
	 */
	public function inboxes(): array {
		return $this->relayRequest->acceptedInboxes();
	}

	/**
	 * A post a relay pointed at, fetched from its author's server and required
	 * to claim its own address; one already held costs nothing.
	 */
	private function announced(Relay $relay, string $objectId): void {
		if ($objectId === '') {
			return;
		}

		if (!$relay->isAccepted()) {
			// A relay that has not accepted may not put posts in front of anybody.
			$this->logger->info('ignoring an Announce from a relay that has not accepted', [
				'relay' => $relay->getActorId(), 'object' => $objectId,
			]);

			return;
		}

		if ($this->searchService->resolveStatus($objectId) === null) {
			$this->logger->debug('could not take in a post a relay announced', [
				'relay' => $relay->getActorId(), 'object' => $objectId,
			]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function followActivity(Relay $relay): array {
		return [
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'id' => $relay->getFollowId(),
			'type' => 'Follow',
			'actor' => $this->instanceActorId(),
			// Asks the relay for everything public, as Mastodon does.
			'object' => ACore::CONTEXT_PUBLIC,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function undoActivity(Relay $relay): array {
		return [
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'id' => $relay->getFollowId() . '/undo',
			'type' => 'Undo',
			'actor' => $this->instanceActorId(),
			'object' => $this->followActivity($relay),
		];
	}

	/**
	 * Delivers one activity to the relay's inbox, signed as the instance.
	 *
	 * @param bool $record whether a failure is worth showing in the panel
	 */
	private function send(Relay $relay, array $activity, bool $record = true): void {
		$body = (string)json_encode($activity, JSON_UNESCAPED_SLASHES);
		$inbox = $relay->getInbox();

		try {
			$this->curlService->retrieveJson('post', $inbox, [
				'headers' => $this->httpSignatureService->signAsInstance($inbox, $body),
				'body' => $body,
				'timeout' => self::TIMEOUT,
			]);
		} catch (RequestResultNotJsonException $e) {
			// An inbox answers 202 with an empty body, which is a success.
		} catch (Throwable $e) {
			$this->logger->warning('could not reach a relay', [
				'relay' => $relay->getActorId(), 'inbox' => $inbox, 'exception' => $e,
			]);

			if ($record) {
				$this->relayRequest->setStatus(
					$relay->getActorId(), Relay::STATUS_REJECTED, $e->getMessage()
				);
			}
		}
	}

	/**
	 * Whether an `Accept` answers the Follow this instance sent: matched on the
	 * id when the Follow is embedded or named, and taken as such when the Accept
	 * has no object at all.
	 */
	private function acceptsOurFollow(ACore $activity, Relay $relay): bool {
		$objectId = $activity->getObjectId();
		if ($objectId === '' || $objectId === $relay->getFollowId()) {
			return true;
		}

		$this->logger->info('a relay accepted something this instance did not send', [
			'relay' => $relay->getActorId(), 'object' => $objectId, 'expected' => $relay->getFollowId(),
		]);

		return false;
	}

	/**
	 * The id of the Follow, derived from the instance actor and the relay, so
	 * resubscribing sends the same id.
	 */
	private function followId(string $actorId): string {
		return $this->instanceActorId() . '#relay/' . substr(md5($actorId), 0, 12);
	}

	private function instanceActorId(): string {
		return $this->instanceActorService->getId();
	}
}
