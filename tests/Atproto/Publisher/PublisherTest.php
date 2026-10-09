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
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\VideoUploadService;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
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

	/** @var VideoUploadService&MockObject */
	private VideoUploadService $videos;
	/** what the post's video is, as the upload service says */
	private array $video = ['state' => 'none'];
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
	/** the profile record as stored */
	private array $profile = ['$type' => RecordMapper::PROFILE];
	/** @var string[] the posts that were brought over rather than written here */
	private array $importedPosts = [];
	/** @var ImportedPostsRequest&MockObject */
	private ImportedPostsRequest $imported;

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
		$this->repositories->method('getRecord')->willReturnCallback(fn (): StoredRecord => new StoredRecord(self::DID, RecordMapper::PROFILE, 'self', Cid::forRaw('p'), DagCbor::encode($this->profile), '', 0));
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
		$this->videos = $this->createMock(VideoUploadService::class);
		$this->videos->method('forPost')->willReturnCallback(fn (): array => $this->video);
		$this->imported = $this->createMock(ImportedPostsRequest::class);
		$this->imported->method('isImported')->willReturnCallback(fn (string $actor, string $post): bool => in_array($post, $this->importedPosts, true));
		$this->publisher = new Publisher($config, $identities, $this->repositories, $this->mapper, $this->videos, $this->streamRequest, $actors, $this->time, new NullLogger(), $this->imported);
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

	public function testAPostWaitsForItsVideoAndGoesWithIt(): void {
		$this->video = ['state' => 'waiting'];
		$this->repositories->expects($this->once())->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes): CommitResult {
			$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $writes[0]->rkey, Cid::forRaw('r'), '', self::POST, 0);

			return $this->written();
		});
		$this->assertNull($this->publisher->publishPost($this->post()), 'the video service is making the video');

		$blob = new BlobRef(self::DID, Cid::forRaw('v'), 'https://social.test/documents/local/8', 'video/mp4', 10);
		$this->video = ['state' => 'ready', 'blob' => $blob, 'alt' => '', 'width' => 0, 'height' => 0];
		$this->mapper->expects($this->once())->method('post')->with($this->anything(), $this->anything(), $this->anything(), $this->video)
			->willReturn(['record' => ['$type' => RecordMapper::POST, 'text' => 'x', 'createdAt' => '2026-10-08T10:00:00.000Z'], 'truncated' => false]);
		$this->assertNotNull($this->publisher->publishPost($this->post()));
	}

	public function testDeletingAPostForgetsItsVideo(): void {
		$this->videos->expects($this->once())->method('forget')->with(self::POST);

		$this->assertFalse($this->publisher->deletePost(self::POST));
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

	public function testARestrictedQuotePolicyWritesAPostgateUnderThePostsRkey(): void {
		$this->mapper->method('postgate')->willReturnCallback(static fn (Stream $post, string $uri): array => ['$type' => RecordMapper::POSTGATE, 'post' => $uri, 'embeddingRules' => [['$type' => RecordMapper::POSTGATE . '#disableRule']], 'createdAt' => '2026-10-08T10:00:00.000Z']);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static function (array $writes): bool {
			[$post, $gate] = $writes;

			return count($writes) === 2
				&& $post->collection === RecordMapper::POST && $gate->collection === RecordMapper::POSTGATE
				&& $post->rkey === $gate->rkey
				&& $gate->record['post'] === 'at://' . self::DID . '/app.bsky.feed.post/' . $post->rkey;
		}))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertNotNull($this->publisher->publishPost($this->post()));
	}

	public function testADeleteTakesThePostgateWithIt(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POSTGATE, '3kznmn7xqxl22', Cid::forRaw('g'), '', self::POST, 0);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => array_map(static fn (RepoWrite $w): string => $w->action . ' ' . $w->collection, $writes) === [RepoWrite::DELETE . ' ' . RecordMapper::POST, RepoWrite::DELETE . ' ' . RecordMapper::POSTGATE]))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertTrue($this->publisher->deletePost(self::POST));
	}

	public function testANewReplyRuleRewritesTheGatesInOneCommit(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POSTGATE, '3kznmn7xqxl22', Cid::forRaw('g'), '', self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::THREADGATE, '3kznmn7xqxl22', Cid::forRaw('t'), '', self::POST, 0);
		$this->mapper->method('postgate')->willReturn(null);
		$this->mapper->method('threadgate')->willReturnCallback(static fn (Stream $post, string $uri): array => ['$type' => RecordMapper::THREADGATE, 'post' => $uri, 'allow' => [], 'createdAt' => '2026-10-09T10:00:00.000Z']);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => array_map(static fn (RepoWrite $w): string => $w->action . ' ' . $w->collection . ' ' . $w->rkey, $writes) === [
			RepoWrite::DELETE . ' ' . RecordMapper::POSTGATE . ' 3kznmn7xqxl22',
			RepoWrite::UPDATE . ' ' . RecordMapper::THREADGATE . ' 3kznmn7xqxl22',
		]))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->publisher->updateGates($this->post());
	}

	public function testAFirstReplyRuleMakesTheThreadgateAndAnUnpublishedPostNone(): void {
		$this->mapper->method('postgate')->willReturn(null);
		$this->mapper->method('threadgate')->willReturnCallback(static fn (Stream $post, string $uri): array => ['$type' => RecordMapper::THREADGATE, 'post' => $uri, 'allow' => [], 'createdAt' => '2026-10-09T10:00:00.000Z']);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::CREATE && $writes[0]->collection === RecordMapper::THREADGATE && $writes[0]->rkey === '3kznmn7xqxl22'
			&& $writes[0]->record['post'] === 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->publisher->updateGates($this->post());
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->publisher->updateGates($this->post());
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
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->streamRequest->method('getLocalPublicSince')->with(1760000000 - Publisher::RECONCILE_WINDOW, 100)->willReturn([$published, $missing]);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->localId === 'https://social.test/@alice/2'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame(1, $this->publisher->reconcile());
	}

	public function testReconcileReplacesAMissedEditAndRemovesADeletedPost(): void {
		$edited = $this->post(1760000000 - 60);
		$stale = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'before']), self::POST, 0);
		$orphan = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl33', Cid::forRaw('o'), $this->mappedBytes(), 'https://social.test/@alice/3', 0);
		$this->records = [$stale, $orphan];
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$edited]);
		$this->repositories->method('getRecordsSince')->with(RecordMapper::POST, 1760000000 - Publisher::RECONCILE_WINDOW, 100)->willReturn([$stale, $orphan]);
		$this->streamRequest->method('getStreamById')->willReturnCallback(static function (string $id) use ($edited): Note {
			if ($id === self::POST) {
				return $edited;
			}
			throw new StreamNotFoundException();
		});
		$writes = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $batch) use (&$writes): CommitResult {
			$writes[] = array_map(static fn (RepoWrite $w): string => $w->action . ' ' . $w->rkey, $batch);

			return $this->written();
		});

		$this->assertSame(2, $this->publisher->reconcile());
		$this->assertSame(RepoWrite::DELETE . ' 3kznmn7xqxl22', $writes[0][0], 'the stale record goes');
		$this->assertSame(RepoWrite::CREATE, explode(' ', $writes[0][1])[0], 'and the edit takes its place');
		$this->assertSame([RepoWrite::DELETE . ' 3kznmn7xqxl33'], $writes[1], 'the orphan record is removed');
	}

	public function testDeletingThePinnedPostWritesTheProfileAgain(): void {
		$record = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->records[] = $record;
		$this->profile['pinnedPost'] = ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
		$this->mapper->method('profile')->willReturn(['$type' => RecordMapper::PROFILE]);
		$writes = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $batch) use (&$writes): CommitResult {
			$writes[] = $batch[0]->collection . ' ' . $batch[0]->action;

			return $this->written();
		});

		$this->publisher->deletePost(self::POST);

		$this->assertSame([RecordMapper::POST . ' ' . RepoWrite::DELETE, RecordMapper::PROFILE . ' ' . RepoWrite::UPDATE], $writes, 'the profile no longer names it');
	}

	public function testReconcileLeavesAnImportedPostAsItCame(): void {
		$moved = $this->post(1760000000 - 60);
		$archived = $this->post(1760000000 - 60);
		$archived->setId('https://social.test/@alice/2');
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'as Bluesky had it']), self::POST, 0);
		$this->importedPosts = [self::POST, 'https://social.test/@alice/2'];
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$moved, $archived]);
		$this->repositories->expects($this->never())->method('write');

		$this->assertSame(0, $this->publisher->reconcile(), 'neither the moved record is rewritten nor the archived post published');
	}

	public function testReconcileLeavesAnUnchangedRecordAlone(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$this->post(1760000000 - 60)]);
		$this->repositories->expects($this->never())->method('write');

		$this->assertSame(0, $this->publisher->reconcile());
		$this->assertSame('kept', $this->publisher->editPost($this->post(1760000000 - 60)), 'an edit that maps to the same record writes nothing');
	}

	public function testAFollowRecordIsWrittenOncePerObjectAndRemovedByIt(): void {
		$actor = new Person();
		$actor->setId('https://social.test/@alice');
		$actor->setLocal(true);
		$record = ['$type' => RecordMapper::FOLLOW, 'subject' => 'did:plc:other', 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $writes): CommitResult {
			static $n = 0;
			$n++;
			if ($n === 1) {
				$this->assertSame(RepoWrite::CREATE, $writes[0]->action);
				$this->assertSame(RecordMapper::FOLLOW, $writes[0]->collection);
				$this->assertSame('https://social.test/follow/1', $writes[0]->localId);
				$this->records[] = new StoredRecord(self::DID, RecordMapper::FOLLOW, $writes[0]->rkey, Cid::forRaw('f'), '', 'https://social.test/follow/1', 0);
			} else {
				$this->assertSame(RepoWrite::DELETE, $writes[0]->action);
				$this->assertSame($this->records[0]->rkey, $writes[0]->rkey);
			}

			return $this->written();
		});

		$this->assertTrue($this->publisher->writeRecord($actor, RecordMapper::FOLLOW, $record, 'https://social.test/follow/1'));
		$this->assertFalse($this->publisher->writeRecord($actor, RecordMapper::FOLLOW, $record, 'https://social.test/follow/1'), 'written once');
		$this->assertFalse($this->publisher->removeRecord(RecordMapper::LIKE, 'https://social.test/follow/1'), 'another collection is not it');
		$this->assertTrue($this->publisher->removeRecord(RecordMapper::FOLLOW, 'https://social.test/follow/1'));
		$this->records = [];
		$this->assertFalse($this->publisher->removeRecord(RecordMapper::FOLLOW, 'https://social.test/follow/1'));
	}

	public function testNoRecordForARemoteActor(): void {
		$remote = new Person();
		$remote->setId('https://mastodon.test/users/bob');
		$this->repositories->expects($this->never())->method('write');
		$this->assertFalse($this->publisher->writeRecord($remote, RecordMapper::LIKE, ['$type' => RecordMapper::LIKE], 'https://social.test/like/1'));
	}

	/** A setting kept as the one record of its collection: written once, rewritten when it changed. */
	public function testASelfRecordIsWrittenUnderSelfAndOnlyWhenItChanged(): void {
		$collection = 'chat.bsky.actor.declaration';
		$stored = null;
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('getRecord')->willReturnCallback(function (string $did, string $c, string $rkey) use (&$stored, $collection): ?StoredRecord {
			return ($c === $collection && $rkey === 'self') ? $stored : null;
		});
		$writes = [];
		$repositories->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $w) use (&$writes, &$stored, $collection): CommitResult {
			$writes[] = $w[0];
			$stored = new StoredRecord(self::DID, $collection, 'self', Cid::forRaw('d'), DagCbor::encode($w[0]->record), '', 0);

			return $this->written();
		});
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn($this->identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$publisher = new Publisher($config, $identities, $repositories, $this->mapper, $this->videos, $this->streamRequest, $this->createMock(CacheActorService::class), $this->time, new NullLogger(), $this->imported);
		$alice = new Person();
		$alice->setId('https://social.test/@alice');
		$alice->setLocal(true);

		$this->assertFalse($publisher->hasSelfRecord($alice, $collection));
		$this->assertTrue($publisher->writeSelfRecord($alice, $collection, ['$type' => $collection, 'allowIncoming' => 'following']));
		$this->assertTrue($publisher->hasSelfRecord($alice, $collection));
		$this->assertFalse($publisher->writeSelfRecord($alice, $collection, ['$type' => $collection, 'allowIncoming' => 'following']), 'unchanged');
		$this->assertTrue($publisher->writeSelfRecord($alice, $collection, ['$type' => $collection, 'allowIncoming' => 'none']));

		$this->assertSame([RepoWrite::CREATE, RepoWrite::UPDATE], [$writes[0]->action, $writes[1]->action]);
		$this->assertSame(['self', 'self'], [$writes[0]->rkey, $writes[1]->rkey]);
		$this->assertSame('', $writes[0]->localId, 'a setting, which no object here stands for');

		$remote = new Person();
		$remote->setId('https://mastodon.test/users/bob');
		$this->assertFalse($publisher->writeSelfRecord($remote, $collection, ['$type' => $collection, 'allowIncoming' => 'all']));
	}

	private function mappedBytes(): string {
		return DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z']);
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
