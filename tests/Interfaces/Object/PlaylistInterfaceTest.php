<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\PlaylistInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Playlist;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Service\PlaylistService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A received playlist is written onto its owner's page, so the owner is held
 * to whoever sent it: the actor itself, or a channel on the actor's server
 * that names the actor as its own — never a local account, and never a
 * neighbour on the same server.
 */
class PlaylistInterfaceTest extends TestCase {
	private const LOCAL = 'https://cloud.example/index.php/apps/social/@alice';
	private const LOCAL_VIDEO = 'https://cloud.example/index.php/apps/social/@alice/1234';
	private const ACCOUNT = 'https://peertube.example/accounts/carol';
	private const NEIGHBOUR = 'https://peertube.example/accounts/dave';
	private const CHANNEL = 'https://peertube.example/video-channels/carol_channel';
	private const VIDEO = 'https://peertube.example/videos/watch/one';

	private CollectionsRequest|MockObject $collections;
	private CacheActorsRequest|Stub $cacheActors;
	private PlaylistInterface $interface;

	/** @var array<string, Person> */
	private array $actors = [];

	protected function setUp(): void {
		$this->collections = $this->createMock(CollectionsRequest::class);
		$this->cacheActors = $this->createStub(CacheActorsRequest::class);
		$this->cacheActors->method('getFromId')->willReturnCallback(function (string $id): Person {
			return $this->actors[$id] ?? throw new CacheActorDoesNotExistException();
		});

		$streams = $this->createStub(StreamService::class);
		$streams->method('getStreamById')->willReturnCallback(static function (string $id): Note {
			if ($id !== self::LOCAL_VIDEO && $id !== self::VIDEO) {
				throw new StreamNotFoundException();
			}
			$note = new Note();
			$note->setId($id);

			return $note;
		});

		$this->interface = new PlaylistInterface(
			new PlaylistService($this->collections, $streams, new NullLogger(), $this->cacheActors)
		);

		$this->actor(self::LOCAL)->setLocal(true);
		$this->actor(self::ACCOUNT);
		$this->actor(self::NEIGHBOUR);
		$this->actor(self::CHANNEL)->setAttributedToActors([['type' => 'Person', 'id' => self::ACCOUNT]]);
	}

	private function actor(string $id): Person {
		$person = new Person();
		$person->setId($id);
		$this->actors[$id] = $person;

		return $person;
	}

	private function existing(string $ownerId): void {
		$collection = new Collection();
		$collection->setId(7)->setOwnerId($ownerId)->setTitle('My talks')
			->setVisibility(Collection::VISIBILITY_PUBLIC);
		$this->collections->method('getByActor')->willReturnCallback(
			static fn (string $id): array => $id === $ownerId ? [$collection] : []
		);
	}

	private function activity(ACore $activity, string $actorId, string $host): ACore {
		$activity->setId('https://' . $host . '/activities/1');
		$activity->setActorId($actorId);
		$activity->setOrigin($host, SignatureService::ORIGIN_HEADER, time());

		return $activity;
	}

	private function playlist(?ACore $parent, string $id, string|array $owner, string $video): Playlist {
		$playlist = new Playlist($parent);
		$playlist->setId($id);
		$playlist->setSource(json_encode([
			'type' => 'Playlist',
			'id' => $id,
			'name' => 'my talks',
			'attributedTo' => $owner,
			'orderedItems' => [$video],
		]));

		return $playlist;
	}

	private function expectUntouched(): void {
		$this->collections->expects($this->never())->method('clearItems');
		$this->collections->expects($this->never())->method('update');
		$this->collections->expects($this->never())->method('save');
		$this->expectException(InvalidOriginException::class);
	}

	public function testAPlaylistNamingALocalOwnerIsRefused(): void {
		$this->existing(self::LOCAL);
		$this->actor('https://evil.example/users/mallory');
		$this->expectUntouched();

		$create = $this->activity(new Create(), 'https://evil.example/users/mallory', 'evil.example');
		$this->interface->activity(
			$create,
			$this->playlist($create, 'https://evil.example/video-playlists/1', self::LOCAL, self::LOCAL_VIDEO)
		);
	}

	/** Even when the local account is the one the activity claims to be from. */
	public function testALocalOwnerIsRefusedWhateverTheActor(): void {
		$this->existing(self::LOCAL);
		$this->expectUntouched();

		$create = $this->activity(new Create(), self::LOCAL, 'cloud.example');
		$this->interface->activity(
			$create,
			$this->playlist($create, 'https://cloud.example/video-playlists/1', self::LOCAL, self::LOCAL_VIDEO)
		);
	}

	public function testAnActorsOwnPlaylistIsStored(): void {
		$this->existing(self::ACCOUNT);
		$this->collections->expects($this->once())->method('clearItems')
			->with($this->callback(static fn (Collection $c): bool => $c->getId() === 7));
		$this->collections->expects($this->once())->method('addItem')
			->with($this->anything(), self::VIDEO, 0);

		$create = $this->activity(new Create(), self::ACCOUNT, 'peertube.example');
		$this->interface->activity(
			$create,
			$this->playlist($create, 'https://peertube.example/video-playlists/1', self::ACCOUNT, self::VIDEO)
		);
	}

	/**
	 * PeerTube sends a playlist from the account and attributes it to the
	 * channel, which names the account as its owner.
	 */
	public function testAPlaylistOfTheActorsChannelIsStored(): void {
		$this->existing(self::CHANNEL);
		$this->collections->expects($this->once())->method('clearItems');

		$update = $this->activity(new Update(), self::ACCOUNT, 'peertube.example');
		$this->interface->activity(
			$update,
			$this->playlist($update, 'https://peertube.example/video-playlists/1', [self::CHANNEL], self::VIDEO)
		);
	}

	public function testANeighbourCannotRewriteAnotherActorsPlaylist(): void {
		$this->existing(self::ACCOUNT);
		$this->expectUntouched();

		$update = $this->activity(new Update(), self::NEIGHBOUR, 'peertube.example');
		$this->interface->activity(
			$update,
			$this->playlist($update, 'https://peertube.example/video-playlists/1', self::ACCOUNT, self::VIDEO)
		);
	}

	public function testANeighbourCannotRewriteAChannelThatIsNotTheirs(): void {
		$this->existing(self::CHANNEL);
		$this->expectUntouched();

		$update = $this->activity(new Update(), self::NEIGHBOUR, 'peertube.example');
		$this->interface->activity(
			$update,
			$this->playlist($update, 'https://peertube.example/video-playlists/1', [self::CHANNEL], self::VIDEO)
		);
	}

	/** A channel's word about its owner counts on its own server only. */
	public function testAChannelOnAnotherServerDoesNotVouchForTheActor(): void {
		$this->existing(self::CHANNEL);
		$this->actor('https://other.example/accounts/carol');
		$this->actors[self::CHANNEL]->setAttributedToActors([
			['type' => 'Person', 'id' => 'https://other.example/accounts/carol'],
		]);
		$this->expectUntouched();

		$create = $this->activity(new Create(), 'https://other.example/accounts/carol', 'other.example');
		$this->interface->activity(
			$create,
			$this->playlist($create, 'https://other.example/video-playlists/1', [self::CHANNEL], self::VIDEO)
		);
	}

	public function testAnOwnerNobodyHereKnowsIsRefused(): void {
		$this->collections->method('getByActor')->willReturn([]);
		$this->expectUntouched();

		$create = $this->activity(new Create(), 'https://unknown.example/users/x', 'unknown.example');
		$this->interface->activity(
			$create,
			$this->playlist($create, 'https://unknown.example/video-playlists/1', 'https://unknown.example/users/x', self::VIDEO)
		);
	}

	/** A playlist that arrived on its own has no actor to hold its owner to. */
	public function testAPlaylistWithoutAnActivityIsRefused(): void {
		$this->existing(self::ACCOUNT);
		$this->expectUntouched();

		$playlist = $this->playlist(null, 'https://peertube.example/video-playlists/1', self::ACCOUNT, self::VIDEO);
		$playlist->setOrigin('peertube.example', SignatureService::ORIGIN_HEADER, time());
		$this->interface->processIncomingRequest($playlist);
	}
}
