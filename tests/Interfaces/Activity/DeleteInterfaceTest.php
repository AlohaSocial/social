<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class DeleteInterfaceTest extends ActivityPubTestCase {
	private const BOB = self::REMOTE_URL . '/users/bob';
	private const NOTE = self::REMOTE_URL . '/notes/1';

	private DeleteInterface $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new DeleteInterface();
	}

	/** A Delete from bob's server, either naming its target by id or embedding it. */
	private function delete(string $objectId, ?ACore $object = null, ?string $origin = null): ACore {
		$delete = $this->incoming(Delete::TYPE, self::BOB . '#delete/1', self::BOB, $object, $origin);
		if ($object === null) {
			$delete->setObjectId($objectId);
		}

		return $delete;
	}

	public function testDeleteNamingAKnownNoteRemovesTheNote(): void {
		$note = $this->note(self::NOTE, self::BOB);
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($note);

		$this->noteInterface->expects($this->once())->method('delete')->with($this->identicalTo($note));
		$this->personInterface->expects($this->never())->method('getItemById');
		$this->personInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($this->delete(self::NOTE));
	}

	/**
	 * The origin check stops at the host; a stored note is removed only by its
	 * stored author, so another account on bob's server cannot delete bob's post.
	 */
	public function testDeleteNamingAPeersNoteIsRefused(): void {
		$note = $this->note(self::NOTE, self::REMOTE_URL . '/users/mallory');
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($note);

		$this->noteInterface->expects($this->never())->method('delete');
		$this->personInterface->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->delete(self::NOTE));
	}

	public function testDeleteEmbeddingATombstoneOfAPeersNoteIsRefused(): void {
		$note = $this->note(self::NOTE, self::REMOTE_URL . '/users/mallory');
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($note);

		$this->noteInterface->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest(
			$this->incomingDelete(['type' => 'Tombstone', 'id' => self::NOTE])
		);
	}

	/**
	 * PeerTube files a video under its channel and deletes it as the account
	 * that runs the channel, with a Tombstone; the channel names that account.
	 */
	public function testATombstoneOfAChannelsVideoFromTheAccountBehindItRemovesIt(): void {
		$channel = $this->channel(self::BOB);
		$video = $this->note(self::NOTE, $channel->getId());
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($video);
		$this->personInterface->method('getItemById')->with($channel->getId())->willReturn($channel);

		$this->noteInterface->expects($this->once())->method('delete')->with($this->identicalTo($video));

		$this->handler->processIncomingRequest(
			$this->incomingDelete(['type' => 'Tombstone', 'id' => self::NOTE])
		);
	}

	public function testATombstoneOfAChannelsVideoFromAnAccountItDoesNotNameIsRefused(): void {
		$channel = $this->channel(self::REMOTE_URL . '/users/mallory');
		$video = $this->note(self::NOTE, $channel->getId());
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($video);
		$this->personInterface->method('getItemById')->with($channel->getId())->willReturn($channel);

		$this->noteInterface->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest(
			$this->incomingDelete(['type' => 'Tombstone', 'id' => self::NOTE])
		);
	}

	private function channel(string $owner): Person {
		$channel = $this->person(self::REMOTE_URL . '/video-channels/films');
		$channel->setAttributedToActors([['type' => Person::TYPE, 'id' => $owner]]);

		return $channel;
	}

	public function testDeleteNamingAKnownActorRemovesTheActor(): void {
		$bob = $this->person(self::BOB);
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->with(self::BOB)->willReturn($bob);

		$this->personInterface->expects($this->once())->method('delete')->with($this->identicalTo($bob));
		$this->noteInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($this->delete(self::BOB));
	}

	/**
	 * The origin check stops at the host, so it said only that the Delete came
	 * from bob's server — every account on which could then remove any other.
	 * An actor is deleted by itself alone.
	 */
	public function testDeleteNamingANeighbouringActorIsRefused(): void {
		$bob = $this->person(self::BOB);
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->with(self::BOB)->willReturn($bob);

		$this->personInterface->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$mallory = self::REMOTE_URL . '/users/mallory';
		$delete = $this->incoming(Delete::TYPE, $mallory . '#delete/1', $mallory);
		$delete->setObjectId(self::BOB);

		$this->handler->processIncomingRequest($delete);
	}

	/** The same, arriving as a Tombstone naming the account. */
	public function testTombstoneOfANeighbouringActorIsRefused(): void {
		$bob = $this->person(self::BOB);
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->with(self::BOB)->willReturn($bob);

		$this->personInterface->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$mallory = self::REMOTE_URL . '/users/mallory';
		$delete = $this->ap->getItemFromData([
			'type' => Delete::TYPE,
			'id' => $mallory . '#delete/1',
			'actor' => $mallory,
			'object' => ['type' => 'Tombstone', 'id' => self::BOB],
		]);
		$delete->setOrigin(self::REMOTE_HOST, SignatureService::ORIGIN_HEADER, time());

		$this->handler->processIncomingRequest($delete);
	}

	public function testDeleteNamingSomethingUnknownDoesNothing(): void {
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->willThrowException(new ItemNotFoundException());

		$this->noteInterface->expects($this->never())->method('delete');
		$this->personInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($this->delete(self::REMOTE_URL . '/whatever/1'));
	}

	public function testDeleteEmbeddingANoteIsHandedToTheNoteInterface(): void {
		$note = $this->note(self::NOTE, self::BOB);
		$delete = $this->delete(self::NOTE, $note);

		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($delete), $this->identicalTo($note));
		$this->noteInterface->expects($this->never())->method('getItemById');

		$this->handler->processIncomingRequest($delete);
	}

	public function testDeleteEmbeddingAnActorIsHandedToThePersonInterface(): void {
		$bob = $this->person(self::BOB);
		$delete = $this->delete(self::BOB, $bob);

		$this->personInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($delete), $this->identicalTo($bob));

		$this->handler->processIncomingRequest($delete);
	}

	public function testDeleteOfSomethingHostedElsewhereIsRefused(): void {
		$this->noteInterface->expects($this->never())->method('getItemById');
		$this->personInterface->expects($this->never())->method('getItemById');

		$this->expectException(InvalidOriginException::class);

		// bob's server may only delete what lives on bob's server
		$this->handler->processIncomingRequest($this->delete('https://other.example/notes/1'));
	}

	public function testDeleteNotComingFromItsOwnOriginIsRefused(): void {
		$this->noteInterface->expects($this->never())->method('getItemById');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->delete(self::NOTE, null, 'evil.example'));
	}

	/**
	 * A Delete the way it arrives over the wire: parsed from the request data, so an
	 * `object` array becomes an embedded object carrying its own id — which is how
	 * Mastodon announces a deleted post, as a Tombstone.
	 *
	 * @param array<string, mixed>|string|null $object
	 */
	private function incomingDelete($object, ?string $origin = null): ACore {
		$data = [
			'type' => Delete::TYPE,
			'id' => self::BOB . '#delete/1',
			'actor' => self::BOB,
		];
		if ($object !== null) {
			$data['object'] = $object;
		}

		$delete = $this->ap->getItemFromData($data);
		$delete->setOrigin($origin ?? self::REMOTE_HOST, SignatureService::ORIGIN_HEADER, time());

		return $delete;
	}

	public function testDeleteEmbeddingATombstoneRemovesTheNoteItPointsAt(): void {
		$note = $this->note(self::NOTE, self::BOB);
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($note);

		$this->noteInterface->expects($this->once())->method('delete')->with($this->identicalTo($note));
		$this->personInterface->expects($this->never())->method('getItemById');

		$this->handler->processIncomingRequest(
			$this->incomingDelete(['type' => 'Tombstone', 'id' => self::NOTE])
		);
	}

	public function testDeleteEmbeddingATombstoneFallsThroughToTheActorLookup(): void {
		$bob = $this->person(self::BOB);
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->with(self::BOB)->willReturn($bob);

		$this->personInterface->expects($this->once())->method('delete')->with($this->identicalTo($bob));
		$this->noteInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest(
			$this->incomingDelete(['type' => 'Tombstone', 'id' => self::BOB])
		);
	}

	public function testDeleteEmbeddingATombstoneHostedElsewhereIsRefused(): void {
		$this->noteInterface->expects($this->never())->method('getItemById');
		$this->personInterface->expects($this->never())->method('getItemById');

		$this->expectException(InvalidOriginException::class);

		// the origin check applies to the id the embedded object carries
		$this->handler->processIncomingRequest(
			$this->incomingDelete(['type' => 'Tombstone', 'id' => 'https://other.example/notes/1'])
		);
	}

	public function testDeleteEmbeddingANoteParsedFromRequestDataIsStillHandedToTheNoteInterface(): void {
		$delete = $this->incomingDelete([
			'type' => 'Note',
			'id' => self::NOTE,
			'attributedTo' => self::BOB,
			'content' => 'gone',
		]);

		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($delete), $this->identicalTo($delete->getObject()));
		$this->noteInterface->expects($this->never())->method('getItemById');
		$this->noteInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($delete);
	}

	public function testDeleteWithoutAnyTargetDeletesNothing(): void {
		$this->expectNoInterfaceReceivesActivity();
		$this->noteInterface->expects($this->never())->method('getItemById');
		$this->noteInterface->expects($this->never())->method('delete');
		$this->personInterface->expects($this->never())->method('getItemById');
		$this->personInterface->expects($this->never())->method('delete');

		// A Delete naming nothing has no id to check the origin against, so the
		// empty id is what gets refused; either way nothing is looked up or removed.
		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->incomingDelete(null));
	}
}
