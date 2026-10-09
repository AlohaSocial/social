<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Reader\ActivitySubscriptions;
use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Relationship;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\DomainBlockService;
use OCA\Social\Service\RelationshipService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The note one account keeps about another, the accounts it features, and when
 * a mute stops applying.
 *
 * None of the three is visible to the account it is about and none of them is
 * ever another account's business, so what is checked here is as much whose
 * rows a read can reach as what each of them does — and, for the mute, that the
 * expiry is answered by the read rather than by something that has to run.
 */
#[AllowMockObjectsWithoutExpectations]
class AccountRelationServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';
	private const CAROL = 'https://remote.example/users/carol';

	private const NOW = 1757600000;

	private AccountNotesRequest|Stub $accountNotesRequest;
	private ActorRelationRequest|Stub $actorRelationRequest;
	private FollowsRequest|Stub $followsRequest;
	private MuteExpiryRequest|Stub $muteExpiryRequest;
	private DomainBlockService|Stub $domainBlockService;
	private RelationshipService|MockObject $relationshipService;

	/** @var array<string, string> "author|subject" => note */
	private array $notes = [];
	/** @var array<string, bool> "actor|object|type" => true */
	private array $relations = [];
	/** @var array<string, bool> "follower|followed" => accepted */
	private array $follows = [];
	/** @var array<string, int> "muter|muted" => unix time */
	private array $expiries = [];
	/** @var array<int, array> every write, in order */
	private array $writes = [];
	private int $expiryReads = 0;

	protected function setUp(): void {
		$this->accountNotesRequest = $this->createStub(AccountNotesRequest::class);
		$this->accountNotesRequest->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $note): void {
				$this->writes[] = ['note', $actorId, $objectId, $note];
				$this->notes[$actorId . '|' . $objectId] = $note;
			});
		$this->accountNotesRequest->method('delete')
			->willReturnCallback(function (string $actorId, string $objectId): void {
				$this->writes[] = ['note-delete', $actorId, $objectId];
				unset($this->notes[$actorId . '|' . $objectId]);
			});
		$this->accountNotesRequest->method('getNote')
			->willReturnCallback(fn (string $actorId, string $objectId): string
				=> $this->notes[$actorId . '|' . $objectId] ?? '');

		$this->actorRelationRequest = $this->createStub(ActorRelationRequest::class);
		$this->actorRelationRequest->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $type): void {
				$this->writes[] = ['relation', $actorId, $objectId, $type];
				$this->relations[$actorId . '|' . $objectId . '|' . $type] = true;
			});
		$this->actorRelationRequest->method('delete')
			->willReturnCallback(function (string $actorId, string $objectId, string $type): void {
				$this->writes[] = ['relation-delete', $actorId, $objectId, $type];
				unset($this->relations[$actorId . '|' . $objectId . '|' . $type]);
			});
		$this->actorRelationRequest->method('getByActor')
			->willReturnCallback(function (string $actorId, string $type, int $limit = 40): array {
				$found = [];
				foreach (array_keys($this->relations) as $key) {
					[$actor, $object, $storedType] = explode('|', $key);
					if ($actor === $actorId && $storedType === $type) {
						$found[] = (new ActorRelation())
							->setActorIdPrim(md5($actor))
							->setObjectId($object)
							->setType($storedType);
					}
				}

				return array_slice($found, 0, $limit);
			});
		$this->actorRelationRequest->method('exists')
			->willReturnCallback(fn (string $actorId, string $objectId, string $type): bool
				=> isset($this->relations[$actorId . '|' . $objectId . '|' . $type]));

		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $objectId): Follow {
				$key = $actorId . '|' . $objectId;
				if (!array_key_exists($key, $this->follows)) {
					throw new FollowNotFoundException('not following');
				}

				return (new Follow())->setAccepted($this->follows[$key]);
			});

		$this->muteExpiryRequest = $this->createStub(MuteExpiryRequest::class);
		$this->muteExpiryRequest->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, int $expiresAt): void {
				$this->writes[] = ['expiry', $actorId, $objectId, $expiresAt];
				$this->expiries[$actorId . '|' . $objectId] = $expiresAt;
			});
		$this->muteExpiryRequest->method('delete')
			->willReturnCallback(function (string $actorId, string $objectId): void {
				$this->writes[] = ['expiry-delete', $actorId, $objectId];
				unset($this->expiries[$actorId . '|' . $objectId]);
			});
		$this->muteExpiryRequest->method('getExpiry')
			->willReturnCallback(fn (string $actorId, string $objectId): int
				=> $this->expiries[$actorId . '|' . $objectId] ?? 0);
		$this->muteExpiryRequest->method('getExpiries')
			->willReturnCallback(function (string $actorId, array $objectIds): array {
				$this->expiryReads++;
				$found = [];
				foreach ($objectIds as $objectId) {
					if (isset($this->expiries[$actorId . '|' . $objectId])) {
						$found[$objectId] = $this->expiries[$actorId . '|' . $objectId];
					}
				}

				return $found;
			});

		$this->domainBlockService = $this->createStub(DomainBlockService::class);
		$this->relationshipService = $this->createMock(RelationshipService::class);
	}

	/** @var list<array{string, bool}> */
	private array $rung = [];

	private function service(): AccountRelationService {
		return new AccountRelationService(
			$this->accountNotesRequest,
			$this->actorRelationRequest,
			$this->followsRequest,
			$this->muteExpiryRequest,
			$this->domainBlockService,
			$this->relationshipService,
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	public function testANoteIsStoredAsItWasTypedWithoutItsSurroundingSpace(): void {
		$stored = $this->service()->setNote($this->person(self::ALICE), $this->person(self::CAROL), "  met at a conference \n");

		$this->assertSame('met at a conference', $stored);
		$this->assertSame('met at a conference', $this->notes[self::ALICE . '|' . self::CAROL]);
	}

	public function testANoteIsCutToWhatMastodonKeepsAndTheColumnHolds(): void {
		$stored = AccountRelationService::normaliseNote(str_repeat('a', 3000));

		$this->assertSame(AccountRelationService::MAX_NOTE, mb_strlen($stored));
	}

	public function testTheCutCountsCharactersRatherThanBytes(): void {
		// substr() would cut a multi-byte note mid-character and store a broken one
		$stored = AccountRelationService::normaliseNote(str_repeat('é', 3000));

		$this->assertSame(AccountRelationService::MAX_NOTE, mb_strlen($stored));
		$this->assertSame(str_repeat('é', AccountRelationService::MAX_NOTE), $stored);
	}

	public function testAnEmptyNoteClearsTheRowRatherThanStoringABlankOne(): void {
		// Mastodon clears a note by sending an empty comment, and a row holding
		// '' is a note the account did not write
		$service = $this->service();
		$service->setNote($this->person(self::ALICE), $this->person(self::CAROL), 'remember this');

		$this->assertSame('', $service->setNote($this->person(self::ALICE), $this->person(self::CAROL), '   '));
		$this->assertSame([], $this->notes);
		$this->assertSame('note-delete', $this->writes[1][0]);
	}

	public function testANoteIsOnlyEverReadBackForTheAccountThatWroteIt(): void {
		$service = $this->service();
		$service->setNote($this->person(self::ALICE), $this->person(self::CAROL), 'alice knows carol');

		$this->assertSame('alice knows carol', $service->getNote(self::ALICE, self::CAROL));
		$this->assertSame('', $service->getNote(self::BOB, self::CAROL), "bob cannot read alice's note");
		$this->assertSame('', $service->getNote(self::CAROL, self::ALICE), 'nor can the account it is about');
	}

	public function testTheNoteReachesTheRelationshipTheClientReads(): void {
		// the whole point of storing one: without this `note` is the empty
		// string on every account
		$service = $this->service();
		$service->setNote($this->person(self::ALICE), $this->person(self::CAROL), 'met at a conference');

		$relationship = $service->decorate(new Relationship(4), self::ALICE, self::CAROL);

		$this->assertSame('met at a conference', $relationship->getNote());
		$this->assertSame('met at a conference', $relationship->jsonSerialize()['note']);
	}

	public function testAnotherAccountsRelationshipCarriesNoneOfThatNote(): void {
		$service = $this->service();
		$service->setNote($this->person(self::ALICE), $this->person(self::CAROL), 'met at a conference');

		$this->assertSame('', $service->decorate(new Relationship(4), self::BOB, self::CAROL)->getNote());
	}

	public function testABlockedInstanceShowsInTheRelationship(): void {
		$this->domainBlockService->method('isBlocking')
			->willReturnCallback(static fn (string $viewerId, string $actorId): bool => $viewerId === self::ALICE);

		$service = $this->service();

		$this->assertTrue($service->decorate(new Relationship(4), self::ALICE, self::CAROL)->isDomainBlocking());
		$this->assertFalse($service->decorate(new Relationship(4), self::BOB, self::CAROL)->isDomainBlocking());
	}

	public function testFeaturingAnAccountIsARowOfItsOwnKind(): void {
		// an endorsement is one actor, one other actor and one word, which is
		// what social_actor_relation holds — and the timelines hide the types
		// they name, which does not include this one
		$this->follows[self::ALICE . '|' . self::CAROL] = true;
		$this->service()->endorse($this->person(self::ALICE), $this->person(self::CAROL));

		$this->assertSame([['relation', self::ALICE, self::CAROL, 'endorse']], $this->writes);
		$this->assertSame('endorse', AccountRelationService::TYPE_ENDORSE);
	}

	public function testFeaturingAnAccountYouDoNotFollowIsRefused(): void {
		// Mastodon's rule: an endorsement is published as part of saying who
		// you follow
		$service = $this->service();

		try {
			$service->endorse($this->person(self::ALICE), $this->person(self::CAROL));
			$this->fail('endorsed a stranger');
		} catch (InvalidResourceException $e) {
			$this->assertSame('Account must be followed', $e->getMessage());
		}

		$this->assertSame([], $this->writes);
	}

	public function testAFollowTheOtherSideHasNotAnsweredIsNotAFollowYet(): void {
		$this->follows[self::ALICE . '|' . self::CAROL] = false;

		$this->expectException(InvalidResourceException::class);
		$this->service()->endorse($this->person(self::ALICE), $this->person(self::CAROL));
	}

	public function testFeaturingYourOwnAccountIsRefused(): void {
		$this->follows[self::ALICE . '|' . self::ALICE] = true;

		$this->expectException(InvalidResourceException::class);
		$this->service()->endorse($this->person(self::ALICE), $this->person(self::ALICE));
	}

	public function testFeaturingTwiceFeaturesOnce(): void {
		$this->follows[self::ALICE . '|' . self::CAROL] = true;
		$service = $this->service();

		$service->endorse($this->person(self::ALICE), $this->person(self::CAROL));
		$service->endorse($this->person(self::ALICE), $this->person(self::CAROL));

		$this->assertCount(1, $this->relations);
	}

	public function testUnfeaturingAnAccountThatWasNotFeaturedIsNotAnError(): void {
		$service = $this->service();
		$service->unendorse($this->person(self::ALICE), $this->person(self::CAROL));

		$this->assertSame([['relation-delete', self::ALICE, self::CAROL, 'endorse']], $this->writes);
		$this->assertFalse($service->isEndorsing(self::ALICE, self::CAROL));
	}

	public function testOneAccountsEndorsementIsNotAnothers(): void {
		$this->follows[self::ALICE . '|' . self::CAROL] = true;
		$service = $this->service();
		$service->endorse($this->person(self::ALICE), $this->person(self::CAROL));

		$this->assertTrue($service->isEndorsing(self::ALICE, self::CAROL));
		$this->assertFalse($service->isEndorsing(self::BOB, self::CAROL));
	}

	public function testTheFeaturedAccountsAreTheOnesHeldUnderThatType(): void {
		$this->relationshipService->expects($this->once())
			->method('getRelated')
			->with($this->anything(), 'endorse', 12)
			->willReturn([$this->person(self::CAROL)]);

		$accounts = $this->service()->getEndorsed($this->person(self::ALICE), 12);

		$this->assertSame([self::CAROL], array_map(static fn (Person $p): string => $p->getId(), $accounts));
	}

	public function testAMuteWithNoDurationNeverExpires(): void {
		$this->assertSame(0, AccountRelationService::expiryOf(0, self::NOW));
	}

	public function testAMuteThatWasAskedToExpireInThePastIsPermanent(): void {
		// a client that sent a negative duration meant to mute, and a mute that
		// has already run out is not a mute at all
		$this->assertSame(0, AccountRelationService::expiryOf(-60, self::NOW));
	}

	public function testADurationIsSecondsFromNow(): void {
		$this->assertSame(self::NOW + 3600, AccountRelationService::expiryOf(3600, self::NOW));
	}

	public function testADurationLongerThanTheColumnHoldsIsStoredAsTheLongestOneThatFits(): void {
		$this->assertSame(
			self::NOW + AccountRelationService::MAX_DURATION,
			AccountRelationService::expiryOf(PHP_INT_MAX, self::NOW)
		);
	}

	public function testAMutePermanentAgainDropsTheExpiryItUsedToHave(): void {
		// re-muting without a duration is Mastodon's "until I say otherwise",
		// and a leftover expiry would end that mute by itself
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 0, self::NOW);

		$this->assertSame([], $this->expiries);
		$this->assertFalse($service->isMuteExpired(self::ALICE, self::CAROL, self::NOW + 7200));
	}

	/**
	 * An archive carries the moment a mute runs out, not how long it had left
	 * when it was written: turning it back into a duration would move every
	 * expiry forward by however long the archive sat in a download folder.
	 */
	public function testAnExpiryDecidedElsewhereIsStoredAsTheMomentItIs(): void {
		$service = $this->service();

		$service->setMuteExpiresAt($this->person(self::ALICE), $this->person(self::CAROL), self::NOW + 60);

		$this->assertSame(self::NOW + 60, $this->expiries[self::ALICE . '|' . self::CAROL]);
		$this->assertTrue($service->isMuteExpired(self::ALICE, self::CAROL, self::NOW + 120));
	}

	public function testAnExpiryOfNoneClearsTheOneThatWasThere(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$service->setMuteExpiresAt($this->person(self::ALICE), $this->person(self::CAROL), 0);

		$this->assertSame([], $this->expiries);
	}

	public function testTheExpiriesOfAPageOfMutesAreReadInOneQuery(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);
		$before = $this->expiryReads;

		$expiries = $service->muteExpiries(self::ALICE, [self::CAROL, self::BOB]);

		$this->assertSame([self::CAROL => self::NOW + 3600], $expiries);
		$this->assertSame($before + 1, $this->expiryReads);
	}

	public function testUnmutingTakesTheExpiryWithIt(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);
		$service->clearMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL));

		$this->assertSame([], $this->expiries);
	}

	public function testAMuteStopsApplyingWhenItsExpiryPassesAndNothingRunsToMakeThatHappen(): void {
		// the point of the feature: the row is still there, and the read is
		// what says it no longer counts
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$this->assertFalse($service->isMuteExpired(self::ALICE, self::CAROL, self::NOW + 3599));
		$this->assertTrue($service->isMuteExpired(self::ALICE, self::CAROL, self::NOW + 3601));
		$this->assertArrayHasKey(self::ALICE . '|' . self::CAROL, $this->expiries, 'nothing deleted the row');
	}

	public function testAnExpiredMuteIsNotReportedAsAMute(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$muting = (new Relationship(4))->setMuting(true)->setMutingNotifications(true);
		$relationship = $service->decorate($muting, self::ALICE, self::CAROL, self::NOW + 7200);

		$this->assertFalse($relationship->isMuting());
		$this->assertFalse($relationship->isMutingNotifications());
	}

	public function testAMuteThatHasNotExpiredIsStillAMute(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$muting = (new Relationship(4))->setMuting(true)->setMutingNotifications(true);
		$relationship = $service->decorate($muting, self::ALICE, self::CAROL, self::NOW + 60);

		$this->assertTrue($relationship->isMuting());
		$this->assertTrue($relationship->isMutingNotifications());
	}

	/**
	 * A client that has just muted somebody for an hour is told for how long,
	 * which is the only place it could learn it from.
	 */
	public function testATimedMuteReportsWhenItLifts(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$muting = (new Relationship(4))->setMuting(true);

		$this->assertSame(
			self::NOW + 3600,
			$service->decorate($muting, self::ALICE, self::CAROL, self::NOW + 60)->getMuteExpiresAt()
		);
	}

	public function testAPermanentMuteHasNoExpiryToReport(): void {
		$muting = (new Relationship(4))->setMuting(true);

		$this->assertSame(
			0,
			$this->service()->decorate($muting, self::ALICE, self::CAROL, self::NOW)->getMuteExpiresAt()
		);
	}

	/** An expiry that has passed is not a mute, so it is not a date either. */
	public function testAnExpiredMuteReportsNoExpiry(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$muting = (new Relationship(4))->setMuting(true);

		$this->assertSame(
			0,
			$service->decorate($muting, self::ALICE, self::CAROL, self::NOW + 7200)->getMuteExpiresAt()
		);
	}

	public function testOneAccountsExpiryDoesNotEndAnothersMute(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);

		$muting = (new Relationship(4))->setMuting(true);
		$relationship = $service->decorate($muting, self::BOB, self::CAROL, self::NOW + 7200);

		$this->assertTrue($relationship->isMuting(), "bob's mute of carol is his own");
	}

	public function testAMutesListingLosesTheMutesThatHaveRunOut(): void {
		$service = $this->service();
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::CAROL), 3600, self::NOW);
		$service->setMuteExpiry($this->person(self::ALICE), $this->person(self::BOB), 86400, self::NOW);

		$accounts = $service->withoutExpiredMutes(
			$this->person(self::ALICE),
			[$this->person(self::CAROL), $this->person(self::BOB), $this->person('https://remote.example/users/dave')],
			self::NOW + 7200
		);

		$this->assertSame(
			[self::BOB, 'https://remote.example/users/dave'],
			array_map(static fn (Person $p): string => $p->getId(), $accounts),
			'the timed mute that is still running and the permanent one stay'
		);
	}

	public function testTheMutesListingAsksAboutItsWholePageAtOnce(): void {
		$accounts = [];
		for ($i = 0; $i < 20; $i++) {
			$accounts[] = $this->person('https://remote.example/users/' . $i);
		}

		$this->service()->withoutExpiredMutes($this->person(self::ALICE), $accounts, self::NOW);

		$this->assertSame(1, $this->expiryReads);
	}

	public function testAnEmptyListingIsNotAskedAboutAtAll(): void {
		$this->assertSame([], $this->service()->withoutExpiredMutes($this->person(self::ALICE), []));
		$this->assertSame(0, $this->expiryReads);
	}

	public function testDecoratingARelationshipLeavesTheFieldsItDoesNotOwnAlone(): void {
		// following, blocked_by and the rest are somebody else's lookup, and
		// `endorsed` is read where the relation rows already are
		$relationship = (new Relationship(4))->setFollowing(true)->setBlockedBy(true)->setEndorsed(true);

		$this->service()->decorate($relationship, self::ALICE, self::CAROL, self::NOW);

		$this->assertTrue($relationship->isFollowing());
		$this->assertTrue($relationship->isBlockedBy());
		$this->assertTrue($relationship->isEndorsed());
	}

	// the bell on a profile

	/**
	 * Mastodon sends `notify` *with* the follow, so demanding an accepted
	 * follow first would refuse the one call that ever sets it.
	 */
	public function testTheBellCanBeTurnedOnWithoutAFollow(): void {
		$alice = $this->person(self::ALICE);
		$bob = $this->person(self::BOB);

		$this->service()->setNotify($alice, $bob, true);

		$this->assertTrue($this->service()->isNotified(self::ALICE, self::BOB));
	}

	public function testTheBellCanBeTurnedOffAgain(): void {
		$alice = $this->person(self::ALICE);
		$bob = $this->person(self::BOB);
		$service = $this->service();
		$service->setNotify($alice, $bob, true);

		$service->setNotify($alice, $bob, false);

		$this->assertFalse($service->isNotified(self::ALICE, self::BOB));
	}

	public function testTheBellOnABlueskyAccountIsKeptOnBlueskyToo(): void {
		$subscriptions = $this->createMock(ActivitySubscriptions::class);
		$subscriptions->expects($this->exactly(2))->method('set')->willReturnCallback(function (Person $subscriber, Person $target, bool $on): void {
			$this->rung[] = [$target->getId(), $on];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ActivitySubscriptions::class)->willReturn($subscriptions);
		$service = new AccountRelationService($this->accountNotesRequest, $this->actorRelationRequest, $this->followsRequest, $this->muteExpiryRequest, $this->domainBlockService, $this->relationshipService, $container);
		$bob = $this->person('https://bsky.app/profile/did:plc:bob');

		$service->setNotify($this->person(self::ALICE), $bob, true);
		$service->setNotify($this->person(self::ALICE), $bob, false);

		$this->assertSame([['https://bsky.app/profile/did:plc:bob', true], ['https://bsky.app/profile/did:plc:bob', false]], $this->rung);
	}

	/** Being told about your own posts is a notification nobody wants. */
	public function testAnAccountCannotSubscribeToItself(): void {
		$alice = $this->person(self::ALICE);

		$this->service()->setNotify($alice, $alice, true);

		$this->assertFalse($this->service()->isNotified(self::ALICE, self::ALICE));
		$this->assertSame([], array_filter(
			$this->writes,
			static fn (array $write): bool => $write[0] === 'relation'
		));
	}

	// "show me this account, not what they pass on"

	public function testBoostsAreShownUntilSomebodySaysOtherwise(): void {
		$this->assertTrue($this->service()->isShowingReblogs(self::ALICE, self::BOB));
	}

	public function testTurningBoostsOffStoresARow(): void {
		$service = $this->service();
		$service->setShowReblogs($this->person(self::ALICE), $this->person(self::BOB), false);

		$this->assertFalse($service->isShowingReblogs(self::ALICE, self::BOB));
	}

	/** Only a "no" is stored: the default costs no row on every follow. */
	public function testTurningBoostsOnAgainRemovesTheRow(): void {
		$service = $this->service();
		$alice = $this->person(self::ALICE);
		$bob = $this->person(self::BOB);

		$service->setShowReblogs($alice, $bob, false);
		$service->setShowReblogs($alice, $bob, true);

		$this->assertTrue($service->isShowingReblogs(self::ALICE, self::BOB));
	}

	public function testAnAccountCannotHideItsOwnBoosts(): void {
		$service = $this->service();
		$alice = $this->person(self::ALICE);

		$service->setShowReblogs($alice, $alice, false);

		$this->assertTrue($service->isShowingReblogs(self::ALICE, self::ALICE));
	}

	// deciding about a sender the notification policy is holding

	public function testAcceptingASenderRemovesAnEarlierDismissal(): void {
		$service = $this->service();
		$alice = $this->person(self::ALICE);
		$bob = $this->person(self::BOB);

		$service->dismissNotifications($alice, $bob);
		$service->acceptNotifications($alice, $bob);

		$decisions = $service->notificationDecisions(self::ALICE, [self::BOB]);

		$this->assertSame([self::BOB => true], $decisions['accepted']);
		$this->assertSame([], $decisions['dismissed']);
	}

	public function testDismissingASenderRemovesAnEarlierAcceptance(): void {
		$service = $this->service();
		$alice = $this->person(self::ALICE);
		$bob = $this->person(self::BOB);

		$service->acceptNotifications($alice, $bob);
		$service->dismissNotifications($alice, $bob);

		$decisions = $service->notificationDecisions(self::ALICE, [self::BOB]);

		$this->assertSame([], $decisions['accepted']);
		$this->assertSame([self::BOB => true], $decisions['dismissed']);
	}

	/** Only the senders that were asked about come back. */
	public function testDecisionsAreScopedToTheSendersAskedAbout(): void {
		$service = $this->service();
		$service->acceptNotifications($this->person(self::ALICE), $this->person(self::BOB));

		$this->assertSame(
			['accepted' => [], 'dismissed' => []],
			$service->notificationDecisions(self::ALICE, [self::CAROL])
		);
	}

}
