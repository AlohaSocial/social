<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\BlockedBy\BlockedByService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyBlockedByTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';
	private const ALICE_DID = 'did:plc:alice';
	private const BOB = 'https://bsky.app/profile/did:plc:bob';
	private const CAROL = 'https://bsky.app/profile/did:plc:carol';

	/** @var AtprotoConfig&MockObject */
	private AtprotoConfig $config;
	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var BlockedByService&MockObject */
	private BlockedByService $blockedBy;
	private BlueskyBlockedBy $source;
	private string $state = Identity::STATE_ACTIVE;

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('getByActorId')->willReturnCallback(fn (string $id): Identity => $id === self::ALICE
			? $this->identity()
			: throw new AtprotoIdentityNotFoundException());
		$this->identities->method('forActor')->willReturnCallback(fn (): Identity => $this->identity());
		$this->identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$this->blockedBy = $this->createMock(BlockedByService::class);
		$this->source = new BlueskyBlockedBy($this->config, $this->appView, $this->identities, $this->blockedBy, new NullLogger());
	}

	private function identity(): Identity {
		return new Identity(1, self::ALICE, self::ALICE_DID, 'alice.social.test', 'sealed', '', '', $this->state, '', 0);
	}

	private static function profile(string $did, bool $blockedBy): array {
		return ['did' => $did, 'handle' => substr($did, 8) . '.test', 'viewer' => ['muted' => false, 'blockedBy' => $blockedBy]];
	}

	public function testOnlyBlueskyAccountsAreItsToAskAbout(): void {
		$this->assertTrue($this->source->supports(self::BOB));
		$this->assertFalse($this->source->supports('https://remote.example/users/carol'));
		$this->assertFalse($this->source->supports(self::ALICE));
	}

	public function testTheAppViewIsAskedAsTheLocalAccountWhoseProfilesBlockIt(): void {
		$this->appView->expects($this->once())->method('queryAs')
			->with(self::ALICE_DID, $this->isInstanceOf(PrivateKey::class), 'app.bsky.actor.getProfiles', ['actors' => ['did:plc:bob', 'did:plc:carol']])
			->willReturn(['profiles' => [self::profile('did:plc:bob', true), self::profile('did:plc:carol', false)]]);

		$this->assertSame(
			[self::BOB => true, self::CAROL => false],
			$this->source->ask(self::ALICE, [self::BOB, self::CAROL, 'https://remote.example/users/dave']),
		);
	}

	public function testAnAccountTheAppViewDoesNotShowIsLeftOut(): void {
		$this->appView->method('queryAs')->willReturn(['profiles' => [self::profile('did:plc:bob', false), self::profile('did:plc:zed', true)]]);

		$this->assertSame([self::BOB => false], $this->source->ask(self::ALICE, [self::BOB, self::CAROL]));
	}

	public function testManyAccountsAreAskedAboutTwentyFiveAtATime(): void {
		$ids = array_map(static fn (int $i): string => 'https://bsky.app/profile/did:plc:a' . $i, range(1, 30));
		$this->appView->expects($this->exactly(2))->method('queryAs')->willReturnCallback(static fn (string $did, PrivateKey $key, string $method, array $params): array => [
			'profiles' => array_map(static fn (string $actor): array => self::profile($actor, false), $params['actors']),
		]);

		$this->assertCount(30, $this->source->ask(self::ALICE, $ids));
	}

	public function testNobodyIsAskedForAnAccountNotOnBluesky(): void {
		$this->appView->expects($this->never())->method('queryAs');

		$this->assertSame([], $this->source->ask('https://social.test/@nobody', [self::BOB]));
		$this->state = Identity::STATE_MOVED_AWAY;
		$this->assertSame([], $this->source->ask(self::ALICE, [self::BOB]), 'an account that moved away speaks for nobody here');
	}

	public function testNobodyIsAskedWhileBlueskyIsOff(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(false);
		$this->appView->expects($this->never())->method('queryAs');
		$source = new BlueskyBlockedBy($config, $this->appView, $this->identities, $this->blockedBy, new NullLogger());

		$this->assertSame([], $source->ask(self::ALICE, [self::BOB]));
	}

	public function testTheThreadsFirstAuthorKeepsARepliesOutToo(): void {
		$reply = new Note();
		$reply->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://did:plc:bob/app.bsky.feed.post/3kb', 'reply_root' => ['uri' => 'at://did:plc:carol/app.bsky.feed.post/3ka', 'cid' => 'bafy']]);
		$this->assertSame([self::CAROL], $this->source->threadAuthors($reply));

		$root = new Note();
		$root->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://did:plc:bob/app.bsky.feed.post/3kb', 'reply_root' => null]);
		$this->assertSame([], $this->source->threadAuthors($root));
	}

	public function testEveryAccountAnAnswerShowsSaysWhetherItBlocksTheViewer(): void {
		$answer = ['feed' => [
			['post' => ['uri' => 'at://did:plc:bob/app.bsky.feed.post/1', 'author' => self::profile('did:plc:bob', false), 'viewer' => ['like' => '']]],
			['post' => ['uri' => 'at://did:plc:dan/app.bsky.feed.post/2', 'author' => self::profile('did:plc:dan', false),
				'embed' => ['record' => ['$type' => 'app.bsky.embed.record#viewBlocked', 'uri' => 'at://did:plc:carol/app.bsky.feed.post/3', 'blocked' => true,
					'author' => ['did' => 'did:plc:carol', 'viewer' => ['blockedBy' => true]]]]]],
			['post' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/4', 'author' => self::profile(self::ALICE_DID, false)]],
			['post' => ['uri' => 'at://did:plc:eve/app.bsky.feed.post/5', 'author' => ['did' => 'did:plc:eve', 'handle' => 'eve.test']]],
		]];

		$this->assertSame([
			self::BOB => false,
			'https://bsky.app/profile/did:plc:dan' => false,
			self::CAROL => true,
		], BlueskyBlockedBy::blockedByIn($answer, self::ALICE_DID), 'not the viewer, nor an account read without a viewer');
	}

	public function testWhatAReadAsThePersonSaysIsRecorded(): void {
		$alice = (new Person())->setId(self::ALICE);
		$this->blockedBy->expects($this->once())->method('record')->with(self::ALICE, [self::BOB => true]);

		$this->source->learn($alice, ['actors' => [self::profile('did:plc:bob', true), self::profile(self::ALICE_DID, false)]]);
	}
}
