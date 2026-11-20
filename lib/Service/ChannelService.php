<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Channel;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Channels: what a video belongs to on PeerTube, which refuses a video
 * without a `Group` in its `attributedTo` that is in turn attributed to a
 * `Person`. A channel is an actor like any other, as `TeamService`'s teams
 * are; it is made for an account on its first video, named after it, and
 * used unless the person picks another. The video stays the author's: it is
 * attributed to both, filed under the channel.
 */
class ChannelService {
	/**
	 * The `user_id` a channel actor is stored under: a Nextcloud user id may
	 * not contain a slash, so it cannot collide with a real account.
	 */
	public const USER_PREFIX = 'channel/';

	/** What a derived channel handle has appended to it, as PeerTube does. */
	private const DEFAULT_SUFFIX = '_channel';

	/** A handle is a handle: the same ceiling `AccountService` holds one to. */
	private const MAX_HANDLE = 64;

	/** More than this and it is a directory, not a person's channels. */
	private const MAX_PER_ACCOUNT = 20;

	/** @var array<string, array<int, array{type: string, id: string}>> memoised for the request */
	private array $attributions = [];

	public function __construct(
		private ChannelsRequest $channelsRequest,
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether an account is a channel rather than somebody's own. */
	public static function isChannelUserId(string $userId): bool {
		return str_starts_with($userId, self::USER_PREFIX);
	}

	/** @return Channel[] the channels of one account, oldest first */
	public function forOwner(Person $owner): array {
		$channels = $this->channelsRequest->getByOwner($owner->getId());
		foreach ($channels as $channel) {
			$channel->setHandle($this->handleOf($channel));
		}

		return $channels;
	}

	/**
	 * The channel a video goes to when nobody chose, made on the account's
	 * first video if it has none.
	 */
	public function defaultFor(Person $owner): Channel {
		$channels = $this->channelsRequest->getByOwner($owner->getId());
		foreach ($channels as $channel) {
			if ($channel->isDefault()) {
				$channel->setHandle($this->handleOf($channel));

				return $channel;
			}
		}

		if ($channels !== []) {
			// channels exist but none is marked: the oldest is the default, so
			// that a row written by hand cannot leave an account without one
			$first = $channels[0];
			$first->setHandle($this->handleOf($first));

			return $first;
		}

		return $this->create(
			$owner,
			$this->deriveHandle($owner),
			$owner->getName() !== '' ? $owner->getName() : $owner->getPreferredUsername(),
			'',
			true
		);
	}

	/**
	 * Makes a channel, with an actor of its own.
	 *
	 * @throws InvalidResourceException the handle is taken, or is not one, or
	 *                                  this account already has as many
	 *                                  channels as anybody sensibly needs
	 */
	public function create(
		Person $owner,
		string $handle,
		string $name = '',
		string $description = '',
		bool $default = false,
	): Channel {
		$handle = ltrim(trim($handle), '@');
		if ($handle === '' || strlen($handle) > self::MAX_HANDLE) {
			throw new InvalidResourceException('a channel needs a handle of its own');
		}

		$existing = $this->channelsRequest->getByOwner($owner->getId());
		if (count($existing) >= self::MAX_PER_ACCOUNT) {
			throw new InvalidResourceException(
				'this account already has ' . self::MAX_PER_ACCOUNT . ' channels'
			);
		}

		try {
			// the actor is made first and the row second: a row pointing at an
			// actor that was never created would be a channel nothing can serve
			$this->accountService->createActor(
				self::USER_PREFIX . $handle, $handle, Group::TYPE
			);
		} catch (Throwable $e) {
			throw new InvalidResourceException($e->getMessage());
		}

		$actor = $this->accountService->getActor($handle);

		$channel = new Channel();
		$channel->setActorId($actor->getId())
			->setOwnerId($owner->getId())
			->setName($name !== '' ? $name : $handle)
			->setDescription($description)
			->setDefault($default || $existing === []);
		$this->channelsRequest->create($channel);

		// the actor was cached before its row existed, so what it says about
		// whose it is was written without an owner to name; built again now
		$this->accountService->cacheLocalActorByUsername($handle);

		$channel->setHandle($handle);

		return $channel;
	}

	/**
	 * One of this account's own channels, by the id a client names it with.
	 *
	 * @throws InvalidResourceException it is not one, or not theirs
	 */
	public function ownChannel(Person $owner, int $id): Channel {
		foreach ($this->forOwner($owner) as $channel) {
			if ($channel->getId() === $id) {
				return $channel;
			}
		}

		// somebody else's channel and one that does not exist are the same
		// answer: the pair of them apart would say which ids exist
		throw new InvalidResourceException('no such channel');
	}

	/** Renames one, or changes what it says it is about. */
	public function update(Person $owner, int $id, string $name, string $description): Channel {
		$channel = $this->ownChannel($owner, $id);
		$channel->setName($name)->setDescription($description);
		$this->channelsRequest->update($channel);

		try {
			// the actor row directly, not `AccountService::setDisplayName()`:
			// that one renames a *Nextcloud user*, and a channel has none
			$actor = $this->accountService->getFromId($channel->getActorId());
			$actor->setName($name);
			$actor->setSummary($description);
			$this->actorsRequest->update($actor);
			$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		} catch (Throwable $e) {
			// the row is what the picker reads; the actor document catching up
			// is worth a line in the log and not a failed request
			$this->logger->notice('a channel was renamed and its actor was not', [
				'channel' => $channel->getActorId(), 'exception' => $e,
			]);
		}

		return $channel;
	}

	/**
	 * The `attributedTo` a video carries: the channel, then the account, as
	 * typed objects, the order and shape PeerTube's `findOwner` reads.
	 *
	 * @return array<int, array{type: string, id: string}>
	 */
	public static function attribution(Channel $channel, Person $author): array {
		return [
			['type' => Group::TYPE, 'id' => $channel->getActorId()],
			['type' => Person::TYPE, 'id' => $author->getId()],
		];
	}

	/**
	 * The `attributedTo` a video by this account carries, or `[]` when the
	 * account has no channel. Read only, as it runs while serialising, and
	 * memoised for the request, as a post is serialised once per receiving
	 * instance.
	 *
	 * @return array<int, array{type: string, id: string}>
	 */
	public function attributionOf(string $authorId): array {
		if ($authorId === '') {
			return [];
		}

		if (array_key_exists($authorId, $this->attributions)) {
			return $this->attributions[$authorId];
		}

		$this->attributions[$authorId] = [];
		foreach ($this->channelsRequest->getByOwner($authorId) as $channel) {
			if ($channel->isDefault() || $this->attributions[$authorId] === []) {
				$this->attributions[$authorId] = [
					['type' => Group::TYPE, 'id' => $channel->getActorId()],
					['type' => Person::TYPE, 'id' => $authorId],
				];
			}
		}

		return $this->attributions[$authorId];
	}

	/** Who owns a channel, or '' when the id is not one of ours. */
	public function ownerOf(string $actorId): string {
		return $this->channelsRequest->ownerOf($actorId);
	}

	/** The handle half of a channel's address, which is its actor's username. */
	/**
	 * Every channel on this instance, with its handle filled in.
	 *
	 * @return Channel[]
	 */
	public function all(int $limit = 50): array {
		$channels = $this->channelsRequest->all($limit);
		foreach ($channels as $channel) {
			$channel->setHandle($this->handleOf($channel));
		}

		return $channels;
	}

	/**
	 * One channel by its handle, or null. The handle is the actor's
	 * `preferredUsername`, not stored on the channel row, so the actor is
	 * resolved first.
	 */
	public function byHandle(string $handle): ?Channel {
		$handle = trim($handle);
		// a client that was handed `alice_channel@cloud.example` sends it back
		if (str_contains($handle, '@')) {
			[$handle] = explode('@', $handle, 2);
		}

		if ($handle === '') {
			return null;
		}

		try {
			$actor = $this->accountService->getActor($handle);
		} catch (Throwable $e) {
			return null;
		}

		$channel = $this->channelsRequest->getByActorId($actor->getId());
		$channel?->setHandle($handle);

		return $channel;
	}

	private function handleOf(Channel $channel): string {
		try {
			return $this->accountService->getFromId($channel->getActorId())->getPreferredUsername();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * What to call an account's first channel: `alice_channel`, as PeerTube
	 * derives it, with a number after it where that is taken.
	 */
	private function deriveHandle(Person $owner): string {
		$base = substr($owner->getPreferredUsername(), 0, self::MAX_HANDLE - strlen(self::DEFAULT_SUFFIX) - 3);
		$candidate = $base . self::DEFAULT_SUFFIX;

		for ($suffix = 0; $suffix < 100; $suffix++) {
			$handle = ($suffix === 0) ? $candidate : $candidate . $suffix;
			try {
				$this->accountService->getActor($handle);
			} catch (Throwable $e) {
				return $handle;
			}
		}

		return $candidate . substr(md5($owner->getId()), 0, 6);
	}
}
