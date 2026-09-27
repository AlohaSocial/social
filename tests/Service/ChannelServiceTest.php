<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Channel;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Channels: the thing PeerTube has no video without.
 *
 * What is held still here is the contract that makes a video ingestable
 * anywhere else — a `Group` actor, owned by a `Person`, named in that order —
 * and the rule that nobody should have to learn the word in order to post.
 */
#[AllowMockObjectsWithoutExpectations]
class ChannelServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const CHANNEL = 'https://cloud.example/apps/social/@alice_channel';

	private ChannelsRequest|MockObject $channelsRequest;
	private ActorsRequest|Stub $actorsRequest;
	private AccountService|MockObject $accountService;
	private ChannelService $service;

	protected function setUp(): void {
		$this->channelsRequest = $this->createMock(ChannelsRequest::class);
		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->accountService = $this->createMock(AccountService::class);

		$this->service = new ChannelService(
			$this->channelsRequest,
			$this->actorsRequest,
			$this->accountService,
			new NullLogger(),
		);
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE)->setPreferredUsername('alice')->setName('Alice')->setLocal(true);

		return $alice;
	}

	private function channelActor(string $handle = 'alice_channel'): Person {
		$actor = new Person();
		$actor->setId(self::CHANNEL)->setPreferredUsername($handle)->setLocal(true);
		$actor->setType(Group::TYPE);

		return $actor;
	}

	private function channel(bool $default = true, int $id = 1): Channel {
		$channel = new Channel();
		$channel->setId($id)->setActorId(self::CHANNEL)->setOwnerId(self::ALICE)
			->setName('Alice')->setDefault($default);

		return $channel;
	}

	// --- making one -------------------------------------------------------

	/**
	 * The whole point of the actor: PeerTube's builder looks for a `Group` in a
	 * video's `attributedTo` and refuses the video when there is none.
	 */
	public function testAChannelIsCreatedAsAGroupActor(): void {
		$this->accountService->expects($this->once())->method('createActor')
			->with('channel/news', 'news', Group::TYPE);
		$this->accountService->method('getActor')->willReturn($this->channelActor('news'));
		$this->channelsRequest->method('getByOwner')->willReturn([]);

		$channel = $this->service->create($this->alice(), 'news', 'The news');

		$this->assertSame(self::CHANNEL, $channel->getActorId());
		$this->assertSame(self::ALICE, $channel->getOwnerId());
		$this->assertSame('The news', $channel->getName());
	}

	/** The first one is the one a video goes to when nobody chose. */
	public function testTheFirstChannelIsTheDefault(): void {
		$this->accountService->method('getActor')->willReturn($this->channelActor());
		$this->channelsRequest->method('getByOwner')->willReturn([]);

		$this->assertTrue($this->service->create($this->alice(), 'alice_channel')->isDefault());
	}

	/**
	 * The actor is cached when it is made and again once its row exists: the
	 * first caching happened before there was an owner to name, and a channel
	 * whose document does not say whose it is is one PeerTube refuses.
	 */
	public function testTheActorIsCachedAgainOnceItsOwnerIsKnown(): void {
		$this->accountService->method('getActor')->willReturn($this->channelActor());
		$this->channelsRequest->method('getByOwner')->willReturn([]);
		$this->accountService->expects($this->once())
			->method('cacheLocalActorByUsername')->with('alice_channel');

		$this->service->create($this->alice(), 'alice_channel');
	}

	public function testAHandleThatIsNotOneIsRefused(): void {
		$this->accountService->expects($this->never())->method('createActor');

		$this->expectException(InvalidResourceException::class);

		$this->service->create($this->alice(), '   ');
	}

	public function testAnAccountCannotCollectChannelsWithoutEnd(): void {
		$this->channelsRequest->method('getByOwner')->willReturn(array_fill(0, 20, $this->channel()));
		$this->accountService->expects($this->never())->method('createActor');

		$this->expectException(InvalidResourceException::class);

		$this->service->create($this->alice(), 'one_more');
	}

	/** A handle already taken is the actor layer's refusal, passed through. */
	public function testATakenHandleIsRefusedWithItsOwnReason(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([]);
		$this->accountService->method('createActor')
			->willThrowException(new \RuntimeException('actor with that name already exist'));

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('already exist');

		$this->service->create($this->alice(), 'taken');
	}

	// --- the default ------------------------------------------------------

	/**
	 * Nobody should have to learn what a channel is in order to post a video,
	 * so one is made on the first video and named after the account.
	 */
	public function testAnAccountWithNoChannelGetsOneNamedAfterIt(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([]);
		// the handle is free until it is taken, which is what the derivation
		// probes for and what `create()` then reads back
		$made = false;
		$this->accountService->method('getActor')
			->willReturnCallback(function (string $handle) use (&$made): Person {
				if (!$made) {
					throw new ActorDoesNotExistException();
				}

				return $this->channelActor($handle);
			});
		$this->accountService->expects($this->once())->method('createActor')
			->with('channel/alice_channel', 'alice_channel', Group::TYPE)
			->willReturnCallback(static function () use (&$made): void {
				$made = true;
			});

		$this->service->defaultFor($this->alice());
	}

	public function testAnAccountThatAlreadyHasOneIsGivenItBack(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([$this->channel()]);
		$this->accountService->method('getFromId')->willReturn($this->channelActor());
		$this->accountService->expects($this->never())->method('createActor');

		$this->assertSame(self::CHANNEL, $this->service->defaultFor($this->alice())->getActorId());
	}

	/** A row written by hand must not leave an account without a default. */
	public function testTheOldestStandsInWhenNoneIsMarked(): void {
		$this->channelsRequest->method('getByOwner')
			->willReturn([$this->channel(default: false, id: 3), $this->channel(default: false, id: 4)]);
		$this->accountService->method('getFromId')->willReturn($this->channelActor());
		$this->accountService->expects($this->never())->method('createActor');

		$this->assertSame(3, $this->service->defaultFor($this->alice())->getId());
	}

	// --- what a video carries ---------------------------------------------

	/**
	 * The channel first and the account second, which is the order PeerTube
	 * writes and the shape its `findOwner` reads: it filters `attributedTo` by
	 * `type`, so bare id strings would make it fetch each one to learn what it
	 * is.
	 */
	public function testAVideoIsAttributedToTheChannelThenTheAccount(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([$this->channel()]);

		$this->assertSame([
			['type' => 'Group', 'id' => self::CHANNEL],
			['type' => 'Person', 'id' => self::ALICE],
		], $this->service->attributionOf(self::ALICE));
	}

	/** An account with no channel has no attribution, and publishes a Note. */
	public function testAnAccountWithNoChannelHasNoAttribution(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([]);

		$this->assertSame([], $this->service->attributionOf(self::ALICE));
	}

	/**
	 * A fan-out to forty servers serialises the post forty times, and must not
	 * be forty queries to ask the same question.
	 */
	public function testTheAttributionIsAskedForOnce(): void {
		$this->channelsRequest->expects($this->once())->method('getByOwner')
			->willReturn([$this->channel()]);

		$this->service->attributionOf(self::ALICE);
		$this->service->attributionOf(self::ALICE);
		$this->service->attributionOf(self::ALICE);
	}

	/** Nothing is created on a read path: a serialisation must not write. */
	public function testAskingForTheAttributionNeverMakesAChannel(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([]);
		$this->accountService->expects($this->never())->method('createActor');

		$this->service->attributionOf(self::ALICE);
	}

	// --- whose is whose ---------------------------------------------------

	public function testSomebodyElsesChannelIsNotFound(): void {
		$this->channelsRequest->method('getByOwner')->willReturn([$this->channel()]);
		$this->accountService->method('getFromId')->willReturn($this->channelActor());

		$this->expectException(InvalidResourceException::class);

		$this->service->ownChannel($this->alice(), 99);
	}

	public function testAChannelUserIdIsRecognisable(): void {
		$this->assertTrue(ChannelService::isChannelUserId('channel/news'));
		$this->assertFalse(ChannelService::isChannelUserId('alice'));
		// a slash cannot occur in a Nextcloud user id, which is what makes the
		// prefix reserved rather than merely unlikely
		$this->assertFalse(ChannelService::isChannelUserId('team/design'));
	}

	/**
	 * `parseActorsSelectSql()` used to set the type from the `bot` flag on
	 * every read, so the `Group` written into the column was overwritten before
	 * anything saw it — the channel was stored correctly and served as a
	 * `Person`, which is the one thing PeerTube will not accept.
	 */
	public function testAStoredGroupSurvivesBeingReadBack(): void {
		$actor = new Person();
		$actor->setType(Group::TYPE);

		$this->assertSame(Group::TYPE, $actor->storedActorType());

		// and an ordinary account still says "decide as before", which is what
		// keeps the bot flag in charge of every row written until now
		$plain = new Person();
		$this->assertSame('', $plain->storedActorType());
		$plain->setBot(true);
		$this->assertSame('', $plain->storedActorType());
	}
}
