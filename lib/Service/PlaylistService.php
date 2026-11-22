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
 * PeerTube's playlists, as this app's collections: an ordered set of
 * somebody's own posts, with a title, a description and a visibility.
 *
 * A received playlist is somebody else's document, stored for display and
 * rebuilt from the wire when it changes; nothing here may edit it. Outbound,
 * a collection of ours is published as a `Playlist` on its channel. Only
 * videos this instance already holds are put in one; nothing is fetched for it.
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
	 * Rebuilt rather than merged, since the document is the whole truth.
	 * Matched by title on the same owner, since this app's collections are
	 * keyed by a local row, so a redelivery updates rather than duplicates.
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

			// the document is the whole truth: what is no longer in it goes
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
	 * Whether the actor that sent a playlist may write the owner's
	 * collections. The owner decides whose page it lands on, so it must be the
	 * actor or, as PeerTube publishes, a channel on the actor's server naming
	 * the actor as its own. Local collections are written only locally.
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
	 * One of this instance's collections, as the `Playlist` PeerTube publishes,
	 * attributed to the channel as a video is.
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
