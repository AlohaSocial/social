<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Activity\Publisher as ActivityPublisher;
use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\Story as ClientStory;
use OCA\Social\Model\Client\StoryInteraction as ClientStoryInteraction;
use OCA\Social\Model\NotificationDelivery;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\NotificationDeliveryService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * The fallback a Nextcloud user has instead of Web Push.
 *
 * What is asserted is what somebody is *told*: that each of the four things
 * that can happen to an account reaches the bell once, that nothing is raised
 * for an account acting on itself or for one the reader has blocked or muted,
 * and that dismissing a notification takes both halves of it away — the stored
 * row the client lists and the Nextcloud notification raised from it.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationServiceTest extends TestCase {
	use TActivityPubMocks;

	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://remote.example/users/bob';
	private const CAROL = 'https://cloud.example/users/carol';
	private const POST = 'https://cloud.example/@alice/post-1';

	private StreamRequest|Stub $streamRequest;
	private StreamService|Stub $streamService;
	private ActorsRequest|Stub $actorsRequest;
	private CacheActorsRequest|Stub $cacheActorsRequest;
	private ActorRelationRequest|Stub $actorRelationRequest;
	private ActionsRequest|Stub $actionsRequest;
	private AccountRelationService|Stub $accountRelationService;
	private FollowsRequest|Stub $followsRequest;
	private NotificationDeliveryService|Stub $deliveryService;
	private INotificationManager|Stub $notificationManager;
	private ActivityPublisher|Stub $activityPublisher;
	/** @var array<int, array> every Activity entry published: user, subject, actor label, link, excerpt */
	private array $activities = [];
	private NotificationService $service;

	/** @var array<string, string> local actor id => the Nextcloud user it belongs to */
	private array $local = [];
	/** @var array<string, ActorRelation[]> "reader|actor" => what the reader holds over them */
	private array $relations = [];
	/** @var string[] "reader|actor" pairs whose mute has run out */
	private array $expired = [];
	/** @var array<string, bool> "reader|actor" => whether the follow is accepted */
	private array $follows = [];
	/** The reader's delivery setting, read by the delivery stub */
	private NotificationDelivery $delivery;
	/** The moment the delivery stub reads the clock at, UTC */
	private \DateTimeImmutable $now;
	private string $postVisibility = Stream::TYPE_PUBLIC;
	/** @var Stream[] the viewer's notifications, newest first */
	private array $timeline = [];
	/** @var ACore[] what is stored against the edited post */
	private array $actions = [];
	/** @var array<int, array> every notification handed to the notification manager */
	private array $raised = [];
	/** @var array<int, array> every notification withdrawn from the bell */
	private array $withdrawn = [];
	/** @var array<int, array{string, string}> [id, type] of every deleted row */
	private array $deleted = [];
	/** @var SocialAppNotification[] the rows onStatusEdited() stored */
	private array $stored = [];
	/** @var ProbeOptions[] every timeline query the service made */
	private array $asked = [];
	private bool $bellFails = false;
	/** the cached icon of the remote account, when the test gives it one */
	private ?Image $bobIcon = null;

	protected function setUp(): void {
		$this->local = [self::ALICE => 'alice', self::CAROL => 'carol'];

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id): Stream {
				if ($id !== self::POST) {
					throw new StreamNotFoundException('stream not found');
				}

				return $this->post();
			});
		$this->streamRequest->method('deleteById')
			->willReturnCallback(function (string $id, string $type = ''): void {
				$this->deleted[] = [$id, $type];
				$this->timeline = array_values(array_filter(
					$this->timeline, static fn (Stream $row): bool => $row->getId() !== $id
				));
			});

		$this->streamService = $this->createStub(StreamService::class);
		$this->streamService->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options): array {
				$this->asked[] = $options;

				$rows = array_filter($this->timeline, static function (Stream $row) use ($options): bool {
					return ($options->getMaxId() === 0 || $row->getNid() < $options->getMaxId())
						&& ($options->getMinId() === 0 || $row->getNid() > $options->getMinId());
				});

				return array_slice(array_values($rows), 0, $options->getLimit());
			});

		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->actorsRequest->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				if (!array_key_exists($id, $this->local)) {
					throw new ActorDoesNotExistException('Actor not found');
				}

				return $this->person($id, $this->local[$id]);
			});

		$this->cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$this->cacheActorsRequest->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				if ($id !== self::BOB) {
					throw new CacheActorDoesNotExistException('not cached');
				}

				$bob = $this->person($id, '');
				$bob->setNid(42);
				$bob->setName('Bob');
				$bob->setAccount('bob@remote.example');
				$bob->setAvatar('https://remote.example/avatars/bob.png');
				if ($this->bobIcon !== null) {
					$bob->setIcon($this->bobIcon);
				}

				return $bob;
			});

		$this->actorRelationRequest = $this->createStub(ActorRelationRequest::class);
		$this->actorRelationRequest->method('getBetween')
			->willReturnCallback(
				fn (string $reader, string $actor): array => $this->relations[$reader . '|' . $actor] ?? []
			);

		$this->actionsRequest = $this->createStub(ActionsRequest::class);
		$this->actionsRequest->method('getByObjectId')->willReturnCallback(fn (): array => $this->actions);

		$this->accountRelationService = $this->createStub(AccountRelationService::class);
		$this->accountRelationService->method('isMuteExpired')->willReturnCallback(
			fn (string $reader, string $actor): bool
				=> in_array($reader . '|' . $actor, $this->expired, true)
		);

		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->followsRequest->method('getByPersons')->willReturnCallback(
			function (string $reader, string $actor): Follow {
				if (!array_key_exists($reader . '|' . $actor, $this->follows)) {
					throw new FollowNotFoundException('no follow');
				}
				$follow = new Follow();
				$follow->setAccepted($this->follows[$reader . '|' . $actor]);

				return $follow;
			}
		);

		// the real decision over a real setting, so what is asserted here is
		// what the reader's choice does to the bell rather than a stubbed yes
		$this->delivery = new NotificationDelivery();
		$this->now = new \DateTimeImmutable('2026-03-10 12:00:00', new \DateTimeZone('UTC'));
		$this->deliveryService = $this->createStub(NotificationDeliveryService::class);
		$this->deliveryService->method('holds')->willReturnCallback(
			fn (string $userId, string $subject, bool $followed): bool
				=> $this->delivery->holds($subject, $followed, $this->now, new \DateTimeZone('UTC'))
		);

		$this->notificationManager = $this->createStub(INotificationManager::class);
		$this->notificationManager->method('createNotification')
			->willReturnCallback(fn (): INotification => $this->notification());
		$this->notificationManager->method('notify')
			->willReturnCallback(function (INotification $notification): void {
				if ($this->bellFails) {
					throw new \RuntimeException('the notifications app is not there');
				}

				$this->raised[] = $this->fieldsOf($notification);
			});
		$this->notificationManager->method('markProcessed')
			->willReturnCallback(function (INotification $notification): void {
				$this->withdrawn[] = $this->fieldsOf($notification);
			});

		$this->activityPublisher = $this->createStub(ActivityPublisher::class);
		$this->activityPublisher->method('publish')->willReturnCallback(
			function (string $userId, string $subject, ?Person $actor, string $actorId, string $link, string $excerpt, int $objectId): void {
				$this->activities[] = [
					'user' => $userId, 'subject' => $subject, 'actor' => $actor?->getName() ?? $actorId,
					'actorUser' => $actor?->getUserId() ?? '',
					'link' => $link, 'excerpt' => $excerpt, 'object' => $objectId,
				];
			}
		);

		$this->service = new NotificationService(
			$this->streamRequest,
			$this->streamService,
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->actorRelationRequest,
			$this->actionsRequest,
			$this->accountRelationService,
			$this->followsRequest,
			$this->deliveryService,
			$this->notificationManager,
			$this->activityPublisher,
			new NullLogger(),
			$this->urlGenerator()
		);
	}

	/** The app is served at cloud.example/apps/social/, which every bell link points into. */
	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $parameters = []): string => match ($route) {
				'social.Navigation.navigate' => 'https://cloud.example/apps/social/',
				'core.avatar.getAvatar' => 'https://cloud.example/index.php/avatar/' . $parameters['userId'] . '/' . $parameters['size'],
				'social.MediaApi.mediaOpen' => 'https://cloud.example/apps/social/media/' . $parameters['uuid'],
				default => 'https://cloud.example/' . $route,
			}
		);

		return $urlGenerator;
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/** @var array<string, array> what each notification mock was told, keyed by its spl id */
	private array $fields = [];

	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$key = spl_object_hash($notification);
		$this->fields[$key] = [];

		foreach (['setApp' => 'app', 'setUser' => 'user'] as $method => $field) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use ($notification, $key, $field): INotification {
					$this->fields[$key][$field] = $value;

					return $notification;
				}
			);
		}

		$notification->method('setDateTime')->willReturn($notification);
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification, $key): INotification {
				$this->fields[$key]['object'] = [$type, $id];

				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use ($notification, $key): INotification {
				$this->fields[$key]['subject'] = $subject;
				$this->fields[$key]['parameters'] = $parameters;

				return $notification;
			}
		);

		return $notification;
	}

	private function fieldsOf(INotification $notification): array {
		return $this->fields[spl_object_hash($notification)] ?? [];
	}

	private function person(string $id, string $userId): Person {
		$person = new Person();
		$person->setId($id);
		$person->setUserId($userId);

		return $person;
	}

	private function post(): Stream {
		$post = new Note();
		$post->setId(self::POST);
		$post->setAttributedTo(self::BOB);
		$post->setNid(90);
		$post->setVisibility($this->postVisibility);

		return $post;
	}

	/** A stored notification row as one of the four generating paths writes it. */
	private function row(string $subType, string $to, string $attributedTo, int $nid = 5): SocialAppNotification {
		$notification = new SocialAppNotification();
		$notification->setSubType($subType);
		$notification->setObjectId(self::POST);
		$notification->setTo($to);
		$notification->setAttributedTo($attributedTo);
		$notification->setId(self::POST . '/notification+' . strtolower($subType));
		$notification->setNid($nid);

		return $notification;
	}

	/** A mention row, which is attributed to the account being told, not to the author. */
	private function mention(string $to, ?Stream $post = null): SocialAppNotification {
		$notification = $this->row(Mention::TYPE, $to, $to);
		if ($post !== null) {
			$notification->setDetailItem('post', $post);
		}

		return $notification;
	}

	private function relation(string $type, bool $notifications = true): ActorRelation {
		$relation = new ActorRelation();
		$relation->setType($type)
			->setNotifications($notifications);

		return $relation;
	}

	public function testAMentionTellsTheMentionedUser(): void {
		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertCount(1, $this->raised);
		$this->assertSame('social', $this->raised[0]['app']);
		$this->assertSame('alice', $this->raised[0]['user']);
		$this->assertSame('mention', $this->raised[0]['subject']);
		$this->assertSame('Bob', $this->raised[0]['parameters']['account']);
		// into this app's page for the post, not out to the remote object
		$this->assertSame('https://cloud.example/apps/social/@bob@remote.example/90', $this->raised[0]['parameters']['link']);
		// never the picture on bob's own server, which the web interface
		// would not load: his icon has no copy here yet
		$this->assertSame('', $this->raised[0]['parameters']['avatar']);
	}

	/** alohasocial/social#2483 */
	public function testARemoteActorsAvatarIsTheCopyThisServerServes(): void {
		$this->bobIcon = new Image();
		$this->bobIcon->setUrl('https://remote.example/avatars/bob.png');
		$this->bobIcon->setLocalCopy('full-copy');

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));
		$this->assertSame('https://cloud.example/apps/social/media/full-copy', $this->raised[0]['parameters']['avatar']);

		$this->bobIcon->setResizedCopy('thumb');
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));
		$this->assertSame('https://cloud.example/apps/social/media/thumb', $this->raised[1]['parameters']['avatar']);
	}

	public function testALocalActorsAvatarIsTheirNextcloudAvatar(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::CAROL));

		$this->assertSame('https://cloud.example/index.php/avatar/carol/64', $this->raised[0]['parameters']['avatar']);
	}

	public function testATeamAccountHasNoNextcloudAvatar(): void {
		$this->local[self::CAROL] = 'team/editors';
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::CAROL));

		$this->assertSame('', $this->raised[0]['parameters']['avatar']);
	}

	public function testWhatTheBellIsToldTheActivityAppIsToldToo(): void {
		$post = $this->post();
		$post->setContent('<p>Hello <b>@alice</b>, look at this</p>');
		$this->service->onNotification($this->mention(self::ALICE, $post));

		$this->assertCount(1, $this->activities);
		$this->assertSame('alice', $this->activities[0]['user']);
		$this->assertSame('mention', $this->activities[0]['subject']);
		$this->assertSame('Bob', $this->activities[0]['actor']);
		$this->assertSame($this->raised[0]['parameters']['link'], $this->activities[0]['link'], 'the same link as the bell');
		$this->assertSame('Hello @alice, look at this', $this->activities[0]['excerpt'], 'the post, as plain text');
		$this->assertSame(5, $this->activities[0]['object'], 'its own entry, keyed by the row it mirrors');
	}

	public function testALocalActorReachesTheActivityAppAsTheNextcloudUserTheyAre(): void {
		// carol has no cached copy at all in this fixture; the actors table knows her
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::CAROL));

		$this->assertCount(1, $this->activities);
		$this->assertSame('carol', $this->activities[0]['actorUser']);
	}

	public function testARemoteActorReachesTheActivityAppWithoutAUser(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame('', $this->activities[0]['actorUser']);
	}

	public function testTheActivityEntryReadsThePostOffTheStoreWhenTheRowOnlyNamesIt(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertCount(1, $this->activities);
		$this->assertSame('favourite', $this->activities[0]['subject']);
		$this->assertSame('', $this->activities[0]['excerpt'], 'the stored post has no content in this fixture');
	}

	public function testWhatTheBellKeepsQuietAboutTheActivityAppKeepsQuietAboutToo(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::BOB, self::BOB));
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::ALICE));

		$this->assertSame([], $this->raised);
		$this->assertSame([], $this->activities);
	}

	public function testAMentionWithoutTheAuthorAtHandReadsItOffThePost(): void {
		// the row as it comes back from the database: the post is an id, not an
		// object, and the author is not the account the row is attributed to
		$this->service->onNotification($this->mention(self::ALICE));

		$this->assertCount(1, $this->raised);
		$this->assertSame('Bob', $this->raised[0]['parameters']['account']);
	}

	public function testAFavouriteTellsThePostAuthor(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertCount(1, $this->raised);
		$this->assertSame('favourite', $this->raised[0]['subject']);
		$this->assertSame('alice', $this->raised[0]['user']);
	}

	public function testABoostTellsThePostAuthor(): void {
		$this->service->onNotification($this->row(Announce::TYPE, self::ALICE, self::BOB));

		$this->assertCount(1, $this->raised);
		$this->assertSame('reblog', $this->raised[0]['subject']);
	}

	public function testAFollowTellsTheFollowedUserAndLinksToTheProfile(): void {
		$this->service->onNotification($this->row(Follow::TYPE, self::ALICE, self::BOB));

		$this->assertCount(1, $this->raised);
		$this->assertSame('follow', $this->raised[0]['subject']);
		$this->assertSame('https://cloud.example/apps/social/@bob@remote.example', $this->raised[0]['parameters']['link']);
	}

	public function testAFollowRequestIsItsOwnSubjectAndNamesWhoToAnswer(): void {
		$this->service->onNotification($this->row(Follow::TYPE_REQUEST, self::ALICE, self::BOB));

		$this->assertCount(1, $this->raised);
		$this->assertSame('follow_request', $this->raised[0]['subject']);
		// the follower's id, which the Accept and Decline actions POST with
		$this->assertSame(42, $this->raised[0]['parameters']['nid']);
	}

	public function testAPostWhoseAuthorIsNotCachedLinksToTheObjectItself(): void {
		// a post this instance cannot name a page for: the remote address is
		// better than a broken one
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB)->setObjectId('https://elsewhere.example/p/1'));

		$this->assertSame('https://elsewhere.example/p/1', $this->raised[0]['parameters']['link']);
	}

	public function testAClosedPollTellsItsVotersAndItsAuthor(): void {
		// the author is the actor and the recipient at once: their own poll
		// closing is news to them, which is also who Mastodon tells
		$this->local[self::BOB] = 'bob';
		$this->service->onNotification($this->row(Stream::SUBTYPE_POLL, self::BOB, self::BOB));

		$this->assertCount(1, $this->raised);
		$this->assertSame('poll', $this->raised[0]['subject']);
		$this->assertSame('bob', $this->raised[0]['user']);
	}

	public function testAPostFromASubscribedAccountRingsTheBell(): void {
		$this->service->onNotification($this->row(Stream::SUBTYPE_STATUS, self::ALICE, self::BOB));

		$this->assertCount(1, $this->raised);
		$this->assertSame('status', $this->raised[0]['subject']);
		$this->assertSame('https://cloud.example/apps/social/@bob@remote.example/90', $this->raised[0]['parameters']['link']);
	}

	public function testAnsweringAFollowRequestTakesItsBellEntryDown(): void {
		$request = $this->row(Follow::TYPE_REQUEST, self::ALICE, self::BOB, 12);
		$other = $this->row(Like::TYPE, self::ALICE, self::BOB, 11);
		$this->timeline = [$request, $other];

		$this->service->onFollowRequestAnswered($this->person(self::ALICE, 'alice'), self::BOB);

		$this->assertSame([[$request->getId(), SocialAppNotification::TYPE]], $this->deleted);
		$this->assertCount(1, $this->withdrawn);
		$this->assertSame([$other], $this->timeline, 'the like stays');
	}

	// -- held for a digest or quiet hours -------------------------------------

	public function testADigestHoldsALikeButKeepsTheRowAndTheActivityEntry(): void {
		$this->delivery->apply(['mode' => 'digest']);

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
		$this->assertCount(1, $this->activities);
		$this->assertSame([], $this->deleted);
	}

	public function testADigestLetsADirectMessageThrough(): void {
		$this->delivery->apply(['mode' => 'digest']);
		$this->postVisibility = Stream::TYPE_DIRECT;

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertCount(1, $this->raised);
		$this->assertSame('mention', $this->raised[0]['subject']);
	}

	public function testADigestHoldsADirectMessageWhenThatSwitchIsOff(): void {
		$this->delivery->apply(['mode' => 'digest', 'passthrough' => ['direct' => false]]);
		$this->postVisibility = Stream::TYPE_DIRECT;

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertSame([], $this->raised);
	}

	public function testTheDirectMessageIsReadOffTheStoredPostWhenTheRowOnlyNamesIt(): void {
		$this->delivery->apply(['mode' => 'digest']);
		$this->postVisibility = Stream::TYPE_DIRECT;

		$this->service->onNotification($this->mention(self::ALICE));

		$this->assertCount(1, $this->raised);
	}

	public function testADigestLetsAMentionFromAFollowedAccountThrough(): void {
		$this->delivery->apply(['mode' => 'digest']);
		$this->follows[self::ALICE . '|' . self::BOB] = true;

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertCount(1, $this->raised);
	}

	public function testADigestHoldsAMentionFromAnAccountTheReaderDoesNotFollow(): void {
		$this->delivery->apply(['mode' => 'digest']);

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertSame([], $this->raised);
	}

	public function testAFollowStillPendingDoesNotLetAMentionThrough(): void {
		$this->delivery->apply(['mode' => 'digest']);
		$this->follows[self::ALICE . '|' . self::BOB] = false;

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertSame([], $this->raised);
	}

	public function testAFollowedAccountsLikeIsHeldAllTheSame(): void {
		$this->delivery->apply(['mode' => 'digest']);
		$this->follows[self::ALICE . '|' . self::BOB] = true;

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
	}

	public function testQuietHoursHoldTheBellInInstantMode(): void {
		$this->delivery->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']]);
		$this->now = new \DateTimeImmutable('2026-03-10 23:30:00', new \DateTimeZone('UTC'));

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
		$this->assertCount(1, $this->activities);
	}

	public function testOutsideQuietHoursInstantModeRaisesAsBefore(): void {
		$this->delivery->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']]);

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertCount(1, $this->raised);
	}

	public function testTheDeliverySettingIsTheRecipientsNotTheActors(): void {
		$this->delivery->apply(['mode' => 'digest']);
		$asked = [];
		$this->deliveryService = $this->createStub(NotificationDeliveryService::class);
		$this->deliveryService->method('holds')->willReturnCallback(
			function (string $userId, string $subject, bool $followed) use (&$asked): bool {
				$asked[] = [$userId, $subject, $followed];

				return false;
			}
		);
		$this->service = new NotificationService(
			$this->streamRequest, $this->streamService, $this->actorsRequest, $this->cacheActorsRequest,
			$this->actorRelationRequest, $this->actionsRequest, $this->accountRelationService,
			$this->followsRequest, $this->deliveryService, $this->notificationManager,
			$this->activityPublisher, new NullLogger(), $this->urlGenerator()
		);

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame([['alice', 'favourite', false]], $asked);
	}

	public function testNobodyIsToldAboutTheirOwnAction(): void {
		// boosting your own post: the row is written, the bell is not rung
		$this->service->onNotification($this->row(Announce::TYPE, self::ALICE, self::ALICE));

		$this->assertSame([], $this->raised);
	}

	public function testNobodyIsToldTheyMentionedThemselves(): void {
		$post = $this->post();
		$post->setAttributedTo(self::ALICE);

		$this->service->onNotification($this->mention(self::ALICE, $post));

		$this->assertSame([], $this->raised);
	}

	public function testNothingIsRaisedForABlockedActor(): void {
		$this->relations[self::ALICE . '|' . self::BOB] = [$this->relation(ActorRelation::TYPE_BLOCK)];

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
	}

	public function testNothingIsRaisedForAnActorWhoBlockedTheReader(): void {
		$this->relations[self::ALICE . '|' . self::BOB] = [$this->relation(ActorRelation::TYPE_BLOCKED_BY)];

		$this->service->onNotification($this->row(Announce::TYPE, self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
	}

	public function testNothingIsRaisedForAMutedActorWhoseNotificationsAreHidden(): void {
		$this->relations[self::ALICE . '|' . self::BOB] = [$this->relation(ActorRelation::TYPE_MUTE)];

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertSame([], $this->raised);
	}

	public function testAMuteThatKeepsNotificationsDoesNotStopOne(): void {
		$this->relations[self::ALICE . '|' . self::BOB] = [$this->relation(ActorRelation::TYPE_MUTE, false)];

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertCount(1, $this->raised);
	}

	public function testAMuteThatHasRunOutDoesNotStopOne(): void {
		$this->relations[self::ALICE . '|' . self::BOB] = [$this->relation(ActorRelation::TYPE_MUTE)];
		$this->expired[] = self::ALICE . '|' . self::BOB;

		$this->service->onNotification($this->mention(self::ALICE, $this->post()));

		$this->assertCount(1, $this->raised);
	}

	public function testNothingIsRaisedForAReaderOfAnotherServer(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::BOB, self::ALICE));

		$this->assertSame([], $this->raised);
	}

	public function testNothingIsRaisedForASubTypeThatHasNoSubject(): void {
		$this->service->onNotification($this->row('Arrive', self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
	}

	public function testTheObjectIdFitsTheColumnItIsWrittenTo(): void {
		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		[$type, $id] = $this->raised[0]['object'];
		$this->assertSame('notification', $type);
		$this->assertLessThanOrEqual(64, strlen($id));
		$this->assertLessThanOrEqual(64, strlen($type));
	}

	public function testAnIncomingActivityIsNotLostWhenTheBellFails(): void {
		$this->bellFails = true;

		$this->service->onNotification($this->row(Like::TYPE, self::ALICE, self::BOB));

		$this->assertSame([], $this->raised);
	}

	public function testEverySubTypeTheClientApiNamesHasASubject(): void {
		foreach (NotificationService::SUBJECTS as $subType => $subject) {
			$named = Stream::notificationTypeOfSubType($subType);
			if ($named === '') {
				// a type the client API does not name yet; nothing is raised
				// for it either, which testNothingIsRaised… covers
				continue;
			}

			$this->assertSame(
				$named,
				$subject,
				$subType . ' is called something else by the client API than by the bell'
			);
		}
	}

	public function testGetAnswersTheReadersNotification(): void {
		$this->timeline = [$this->row(Like::TYPE, self::ALICE, self::BOB, 7)];

		$found = $this->service->get($this->person(self::ALICE, 'alice'), 7);

		$this->assertSame(7, $found->getNid());
		// read through the notification timeline, not by id: that is what makes
		// somebody else's notification not found rather than refused
		$this->assertSame(ProbeOptions::NOTIFICATIONS, $this->asked[0]->getProbe());
	}

	public function testGetDoesNotFindANotificationOfSomebodyElses(): void {
		// the notification timeline is the reader's own; an id that is not in
		// it is not theirs, and is answered the same way as one that is gone
		$this->timeline = [];

		$this->expectException(ItemNotFoundException::class);

		$this->service->get($this->person(self::ALICE, 'alice'), 7);
	}

	public function testGetDoesNotFindOneTheTimelineLeavesOut(): void {
		$this->timeline = [$this->row('Arrive', self::ALICE, self::BOB, 7)];

		$this->expectException(ItemNotFoundException::class);

		$this->service->get($this->person(self::ALICE, 'alice'), 7);
	}

	public function testDismissRemovesTheRowAndTakesTheBellEntryWithIt(): void {
		$row = $this->row(Like::TYPE, self::ALICE, self::BOB, 7);
		$this->timeline = [$row];

		$this->service->dismiss($this->person(self::ALICE, 'alice'), 7);

		$this->assertSame([[$row->getId(), SocialAppNotification::TYPE]], $this->deleted);
		$this->assertCount(1, $this->withdrawn);
		$this->assertSame('alice', $this->withdrawn[0]['user']);
		$this->assertSame(['notification', sha1($row->getId())], $this->withdrawn[0]['object']);
	}

	public function testTheBellEntryWithdrawnIsTheOneThatWasRaised(): void {
		$row = $this->row(Like::TYPE, self::ALICE, self::BOB, 7);
		$this->timeline = [$row];

		$this->service->onNotification($row);
		$this->service->dismiss($this->person(self::ALICE, 'alice'), 7);

		$this->assertSame($this->raised[0]['object'], $this->withdrawn[0]['object']);
	}

	public function testDismissingANotificationOfSomebodyElsesRemovesNothing(): void {
		$this->timeline = [];

		try {
			$this->service->dismiss($this->person(self::ALICE, 'alice'), 7);
			$this->fail('a notification that is not the reader\'s was dismissed');
		} catch (ItemNotFoundException $e) {
		}

		$this->assertSame([], $this->deleted);
	}

	public function testClearRemovesEveryNotificationOfTheReader(): void {
		$this->timeline = [
			$this->row(Like::TYPE, self::ALICE, self::BOB, 9),
			$this->row(Announce::TYPE, self::ALICE, self::BOB, 8),
			$this->row(Follow::TYPE, self::ALICE, self::BOB, 7),
		];

		$cleared = $this->service->clear($this->person(self::ALICE, 'alice'));

		$this->assertSame(3, $cleared);
		$this->assertCount(3, $this->deleted);
		$this->assertCount(3, $this->withdrawn);
		$this->assertSame([], $this->timeline);
	}

	public function testClearPagesDownwardsRatherThanAskingForTheSamePageAgain(): void {
		$this->timeline = [
			$this->row(Like::TYPE, self::ALICE, self::BOB, 9),
			$this->row(Announce::TYPE, self::ALICE, self::BOB, 8),
		];

		$this->service->clear($this->person(self::ALICE, 'alice'));

		$cursors = array_map(static fn (ProbeOptions $o): int => $o->getMaxId(), $this->asked);
		$this->assertSame([0, 8], $cursors);
	}

	public function testAnEditTellsTheLocalAccountsThatBoostedThePost(): void {
		$this->installActivityPub();
		$this->captureStoredRows();

		$post = $this->post();
		$post->setUpdated('2026-09-11T10:00:00Z');
		$this->actions = [
			$this->action(Announce::TYPE, self::ALICE),
			$this->action(Announce::TYPE, self::CAROL),
			$this->action(Announce::TYPE, 'https://remote.example/users/dave'),
			$this->action(Like::TYPE, self::ALICE),
			$this->action(Announce::TYPE, self::BOB),
		];

		$this->service->onStatusEdited($post);

		$told = array_map(static fn (SocialAppNotification $n): string => $n->getTo(), $this->stored);
		$this->assertSame([self::ALICE, self::CAROL], $told);
		$this->assertSame(Update::TYPE, $this->stored[0]->getSubType());
		$this->assertSame(self::POST, $this->stored[0]->getObjectId());
		$this->assertSame(self::BOB, $this->stored[0]->getAttributedTo());
	}

	public function testTheAuthorIsNotToldAboutTheirOwnEdit(): void {
		$this->installActivityPub();
		$this->captureStoredRows();

		$post = $this->post();
		$post->setAttributedTo(self::ALICE);
		$this->actions = [$this->action(Announce::TYPE, self::ALICE)];

		$this->service->onStatusEdited($post);

		$this->assertSame([], $this->stored);
	}

	public function testASecondEditIsASecondNotification(): void {
		$this->installActivityPub();
		$this->captureStoredRows();

		$post = $this->post();
		$this->actions = [$this->action(Announce::TYPE, self::ALICE)];

		$post->setUpdated('2026-09-11T10:00:00Z');
		$this->service->onStatusEdited($post);
		$post->setUpdated('2026-09-11T11:00:00Z');
		$this->service->onStatusEdited($post);

		$this->assertCount(2, $this->stored);
		$this->assertNotSame($this->stored[0]->getId(), $this->stored[1]->getId());
	}

	private function captureStoredRows(): void {
		$this->apInterface(SocialAppNotificationInterface::class)
			->method('save')
			->willReturnCallback(function (ACore $item): void {
				$this->stored[] = $item;
			});
	}

	private function action(string $type, string $actorId): ACore {
		$action = ($type === Announce::TYPE) ? new Announce() : new Like();
		$action->setActorId($actorId);
		$action->setObjectId(self::POST);

		return $action;
	}

	/**
	 * Who a notification is *about* is who answered, not who is being told.
	 *
	 * Written after devel showed the bell drawing the story's own poster as
	 * the account beside "reacted to your story": `attributedTo` is what a
	 * client renders as the account, and it had been set to the recipient.
	 */
	public function testAStoryAnswerIsAttributedToWhoeverAnsweredIt(): void {
		$this->installActivityPub();
		$this->captureStoredRows();

		$story = (new ClientStory())->setId(7)->setOwnerId(self::ALICE);
		$answer = (new ClientStoryInteraction())
			->setStoryId(7)
			->setActorId(self::BOB)
			->setType(ClientStoryInteraction::TYPE_REPLY)
			->setContent('lovely light')
			->setSourceId('https://remote.example/p/bob/1');

		$this->service->onStoryInteraction($story, $answer);

		$this->assertCount(1, $this->stored);
		$this->assertSame(self::BOB, $this->stored[0]->getAttributedTo(), 'the one who answered');
		$this->assertSame(self::ALICE, $this->stored[0]->getTo(), 'the one being told');
		$this->assertSame(Stream::SUBTYPE_STORY_REPLY, $this->stored[0]->getSubType());
	}

	/** Answering your own story is not something to be told about. */
	public function testThePosterIsNotToldAboutTheirOwnAnswer(): void {
		$this->installActivityPub();
		$this->captureStoredRows();

		$story = (new ClientStory())->setId(7)->setOwnerId(self::ALICE);
		$answer = (new ClientStoryInteraction())
			->setStoryId(7)
			->setActorId(self::ALICE)
			->setContent('🔥')
			->setSourceId('https://cloud.example/@alice/story-reaction/1');

		$this->service->onStoryInteraction($story, $answer);

		$this->assertSame([], $this->stored);
	}
}
