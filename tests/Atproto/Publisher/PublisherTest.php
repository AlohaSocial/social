<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which posts go, that a post goes once, what a delete and an edit do, and
 * what the reconcile pass picks up.
 */
#[AllowMockObjectsWithoutExpectations]
class PublisherTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const POST = 'https://social.test/@alice/1';

	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var RecordMapper&MockObject */
	private RecordMapper $mapper;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streamRequest;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;
	private Publisher $publisher;
	private Identity $identity;
	/** @var StoredRecord[] */
	private array $records = [];

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn($this->identity);
		$identities->method('getByDid')->willReturn($this->identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturnCallback(fn (string $id): array => array_values(array_filter($this->records, static fn (StoredRecord $r): bool => $r->localId === $id)));
		$this->repositories->method('getRecord')->willReturn(new StoredRecord(self::DID, RecordMapper::PROFILE, 'self', Cid::forRaw('p'), DagCbor::encode(['$type' => RecordMapper::PROFILE]), '', 0));
		$this->mapper = $this->createMock(RecordMapper::class);
		$this->mapper->method('post')->willReturn(['record' => ['$type' => RecordMapper::POST, 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z'], 'truncated' => false]);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$actors = $this->createMock(CacheActorService::class);
		$actor = new Person();
		$actor->setId('https://social.test/@alice');
		$actor->setLocal(true);
		$actors->method('getFromId')->willReturn($actor);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1760000000);
		$this->publisher = new Publisher($config, $identities, $this->repositories, $this->mapper, $this->streamRequest, $actors, $this->time, new NullLogger());
	}

	public function testAPublicPostIsWrittenOnce(): void {
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static function (array $writes): bool {
			return count($writes) === 1 && $writes[0]->action === RepoWrite::CREATE && $writes[0]->collection === RecordMapper::POST && $writes[0]->localId === self::POST;
		}))->willReturnCallback(function (string $did, PrivateKey $key, array $writes): CommitResult {
			$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $writes[0]->rkey, Cid::forRaw('r'), '', self::POST, 0);

			return $this->written();
		});

		$this->assertNotNull($this->publisher->publishPost($this->post()));
		$this->assertNull($this->publisher->publishPost($this->post()), 'already there');
	}

	public function testOnlyPublicLocalPostsGo(): void {
		$this->repositories->expects($this->never())->method('write');

		$followers = $this->post();
		$followers->setVisibility(Stream::TYPE_FOLLOWERS);
		$this->assertNull($this->publisher->publishPost($followers));

		$remote = $this->post();
		$remote->setLocal(false);
		$this->assertNull($this->publisher->publishPost($remote));

		$unlisted = $this->post();
		$unlisted->setVisibility(Stream::TYPE_UNLISTED);
		$this->assertNull($this->publisher->publishPost($unlisted));

		$this->assertFalse($this->publisher->goesToBluesky($followers));
		$this->assertTrue($this->publisher->goesToBluesky($this->post()));
	}

	public function testADeleteRemovesTheRecord(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->action === RepoWrite::DELETE && $writes[0]->rkey === '3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertTrue($this->publisher->deletePost(self::POST));
		$this->records = [];
		$this->assertFalse($this->publisher->deletePost(self::POST));
	}

	public function testAnEditWithinTheGraceReplacesAndLaterKeeps(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 2 && $writes[0]->action === RepoWrite::DELETE && $writes[1]->action === RepoWrite::CREATE))->willReturnCallback(fn (): CommitResult => $this->written());

		$fresh = $this->post(1760000000 - 60);
		$this->assertSame('replaced', $this->publisher->editPost($fresh));

		$old = $this->post(1760000000 - 3600);
		$this->assertSame('kept', $this->publisher->editPost($old));

		$this->records = [];
		$this->assertSame('none', $this->publisher->editPost($old));
	}

	public function testTheStatusForTheDeliveryDialog(): void {
		$this->assertSame(['state' => 'waiting', 'uri' => '', 'url' => ''], $this->publisher->statusOf($this->post()));
		$private = $this->post();
		$private->setVisibility(Stream::TYPE_DIRECT);
		$this->assertSame('not_applicable', $this->publisher->statusOf($private)['state']);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->assertSame(['state' => 'published', 'uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', 'url' => 'https://bsky.app/profile/alice.social.test/post/3kznmn7xqxl22'], $this->publisher->statusOf($this->post()));
	}

	public function testReconcilePublishesWhatHasNoRecord(): void {
		$published = $this->post();
		$missing = $this->post();
		$missing->setId('https://social.test/@alice/2');
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->streamRequest->method('getLocalPublicSince')->with(1760000000 - Publisher::RECONCILE_WINDOW, 100)->willReturn([$published, $missing]);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->localId === 'https://social.test/@alice/2'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame(1, $this->publisher->reconcile());
	}

	private function post(int $published = 1760000000 - 10): Note {
		$note = new Note();
		$note->setId(self::POST);
		$note->setLocal(true);
		$note->setAttributedTo('https://social.test/@alice');
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setContent('<p>hi</p>');
		$note->setPublishedTime($published);

		return $note;
	}

	private function written(): CommitResult {
		return new CommitResult(self::DID, Cid::forRaw('c'), '3kznmn7xqxl22', 1, []);
	}
}
