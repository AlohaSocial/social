<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Model\Client\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PeerTube's playlists, as this app's collections.
 *
 * They are the same thing: an ordered set of somebody's own posts, with a
 * title, a description and a visibility. PeerTube users organise everything
 * this way — a series, a course, "watch later" — and a channel's playlists are
 * half of what its page shows, so a Nextcloud that ingested the videos and
 * none of the playlists showed a channel's work as an undifferentiated pile.
 *
 * **Read-only, and deliberately.** A playlist that arrived from somewhere else
 * is somebody else's document: it is stored so it can be shown and it is
 * rebuilt from the wire whenever it changes there, and nothing here may add to
 * it or take from it. The one that goes *out* is the other direction — a
 * collection of ours, published as a `Playlist` on the channel that owns it.
 *
 * Only videos this instance already holds are put in one. A playlist naming
 * forty videos nobody here has ever seen is not a reason to fetch forty videos:
 * what arrives by following the channel is what the playlist shows, and it
 * fills in as the rest arrives.
 */
class PlaylistService {
	public const TYPE = 'Playlist';
	public const ELEMENT_TYPE = 'PlaylistElement';

	/** More than this is a catalogue, not a playlist. */
	private const MAX_ITEMS = 500;

	public function __construct(
		private CollectionsRequest $collectionsRequest,
		private StreamService $streamService,
		private LoggerInterface $logger,
		private CacheActorsRequest $cacheActorsRequest,
	) {
	}

	/**
	 * A `Playlist` that arrived, as a collection of the channel that owns it.
	 *
	 * Rebuilt rather than merged: the document is the whole truth about the
	 * playlist, and a video removed there has to go from here too. Matching an
	 * existing one by title on the same owner is what makes a redelivery an
	 * update instead of a duplicate — the wire gives a playlist an id, but this
	 * app's collections are keyed by a local row.
	 *
	 * @param array<string, mixed> $data the wire `Playlist`
	 * @param string $ownerId the playlist's `attributedTo`
	 * @param string $actorId the actor of the activity that carried it
	 *
	 * @return bool whether anything was stored
	 * @throws InvalidOriginException the actor may not write the owner's playlists
	 */
	public function receive(array $data, string $ownerId, string $actorId): bool {
		$title = trim((string)($data['name'] ?? ''));
		if ($title === '' || $ownerId === '') {
			return false;
		}

		$this->checkOwner($ownerId, $actorId);

		$items = $this->localItems($data);
		if ($items === []) {
			// nothing here to show: a playlist of videos this instance has
			// never seen is an empty page with a title on it
			return false;
		}

		try {
			$collection = $this->existing($ownerId, $title) ?? $this->make($ownerId, $title);
			$collection->setDescription(mb_substr(trim((string)($data['content'] ?? '')), 0, 500));
			$this->collectionsRequest->update($collection);

			// the document is the whole truth: anything no longer in it has
			// been taken out there, and has to go from here too
			$this->collectionsRequest->clearItems($collection);

			foreach ($items as $position => $streamId) {
				$this->collectionsRequest->addItem($collection, $streamId, $position);
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->notice('could not store a playlist', [
				'owner' => $ownerId, 'title' => $title, 'exception' => $e,
			]);

			return false;
		}
	}

	/**
	 * Whether the actor that sent a playlist may write the owner's collections.
	 *
	 * The collection is found and rebuilt by owner and title, so the owner is
	 * the whole of what decides whose page a received playlist lands on — and
	 * the origin check on the playlist's id says only which server sent it.
	 * The owner has to be the actor itself or, the way PeerTube publishes
	 * them, a channel on the actor's server that names the actor as its own.
	 * A local actor's collections are only ever written by that actor, here.
	 *
	 * @throws InvalidOriginException
	 */
	private function checkOwner(string $ownerId, string $actorId): void {
		try {
			$owner = $this->cacheActorsRequest->getFromId($ownerId);
		} catch (CacheActorDoesNotExistException $e) {
			throw new InvalidOriginException('PlaylistService::checkOwner - unknown owner: ' . $ownerId);
		}

		if ($owner->isLocal()) {
			throw new InvalidOriginException('PlaylistService::checkOwner - local owner: ' . $ownerId);
		}

		if ($actorId !== '' && $ownerId === $actorId) {
			return;
		}

		if ($owner->isRunBy($actorId)) {
			return;
		}

		throw new InvalidOriginException(
			'PlaylistService::checkOwner - owner: ' . $ownerId . ' - actor: ' . $actorId
		);
	}

	/**
	 * The videos of a playlist that this instance actually holds, in order.
	 *
	 * A playlist naming forty videos nobody here has seen is not a reason to
	 * fetch forty videos: what arrived by following the channel is what it
	 * shows, and it fills in as the rest arrives.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string[]
	 */
	private function localItems(array $data): array {
		$entries = $data['orderedItems'] ?? $data['items'] ?? [];
		if (!is_array($entries)) {
			return [];
		}

		$ids = [];
		foreach (array_slice($entries, 0, self::MAX_ITEMS) as $entry) {
			$id = $this->videoOf($entry);
			if ($id === '') {
				continue;
			}

			try {
				$ids[] = $this->streamService->getStreamById($id)->getId();
			} catch (Throwable $e) {
				// not here yet, which is the ordinary case
			}
		}

		return array_values(array_unique($ids));
	}

	/** The video a `PlaylistElement` names, however it names it. */
	private function videoOf(mixed $entry): string {
		if (is_string($entry)) {
			return $entry;
		}

		if (!is_array($entry)) {
			return '';
		}

		$object = $entry['object'] ?? $entry['url'] ?? $entry['id'] ?? '';
		if (is_array($object)) {
			$object = $object['id'] ?? $object['href'] ?? '';
		}

		return is_string($object) ? $object : '';
	}

	private function existing(string $ownerId, string $title): ?Collection {
		foreach ($this->collectionsRequest->getByActor($ownerId) as $collection) {
			if (strcasecmp($collection->getTitle(), $title) === 0) {
				return $collection;
			}
		}

		return null;
	}

	private function make(string $ownerId, string $title): Collection {
		$collection = new Collection();
		$collection->setOwnerId($ownerId)
			->setTitle(mb_substr($title, 0, 255))
			->setVisibility(Collection::VISIBILITY_PUBLIC);

		return $this->collectionsRequest->save($collection);
	}

	/**
	 * One of this instance's collections, as the `Playlist` PeerTube publishes.
	 *
	 * `attributedTo` is the channel rather than the account, the same way a
	 * video's is: a playlist belongs to the thing a reader subscribes to.
	 *
	 * @param string[] $itemIds the posts in it, in order
	 *
	 * @return array<string, mixed>
	 */
	public static function asPlaylist(
		Collection $collection,
		string $playlistId,
		string $channelId,
		array $itemIds,
	): array {
		$elements = [];
		foreach (array_values($itemIds) as $position => $itemId) {
			$elements[] = [
				'type' => self::ELEMENT_TYPE,
				'id' => $playlistId . '/items/' . ($position + 1),
				'position' => $position + 1,
				'object' => $itemId,
			];
		}

		return [
			'type' => self::TYPE,
			'id' => $playlistId,
			'name' => $collection->getTitle(),
			'content' => $collection->getDescription(),
			'attributedTo' => $channelId,
			'totalItems' => count($elements),
			'orderedItems' => $elements,
		];
	}
}
