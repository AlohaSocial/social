<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Controller\ConversationController;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Conversation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TimelineRevisionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * What turns a direct timeline into conversations.
 *
 * The contract under test is the one a client depends on across two requests:
 * that every message of an exchange lands in one conversation, that the
 * conversation's id is the same the next time it is asked for — otherwise
 * `POST /api/v1/conversations/{id}/read` marks something else read — and that
 * a conversation nobody may see is the same answer as one that is not there.
 */
class ConversationServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';
	private const BOB = 'https://remote.example/users/bob';
	private const CAROL = 'https://remote.example/users/carol';

	private StreamService|Stub $streamService;
	private CacheActorService|Stub $cacheActorService;
	private ConversationsRequest|Stub $conversationsRequest;
	private StreamRequest|Stub $streamRequest;
	private TimelineRevisionService|Stub $timelineRevisionService;
	/** @var array<string, int> the revisions, by user and key */
	private array $settings = [];

	/** @var Stream[] what the direct timeline answers, newest first */
	private array $timeline = [];
	/** @var array<string, array{nid: int, inReplyTo: string}> posts the walks can find */
	private array $stored = [];
	/** @var array<string, array{rootId: string, readNid: int, hiddenNid: int}> */
	private array $markers = [];
	/** @var array<string, array<string, int>> thread root => the viewer's messages in it */
	private array $threads = [];
	/** @var array<int, array> [method, actorId, rootId, nid] of every write */
	private array $writes = [];
	private ?ProbeOptions $asked = null;
	/** @var list<string> what was read to answer: `posts`, `links`, `accounts`, `walk`, `thread` */
	private array $reads = [];
	/** @var list<string[]> the actor ids of every cache lookup */
	private array $lookedUp = [];
	/** @var array<string, string> post id => author, for the posts the walks can find */
	private array $authors = [];
	/** @var array<string, string> the user values of the notification policy */
	private array $policyValues = [];
	/** @var string[] the accounts the viewer follows */
	private array $following = [];
	/** @var array{accepted: array<string, bool>, dismissed: array<string, bool>} */
	private array $decisions = ['accepted' => [], 'dismissed' => []];

	protected function setUp(): void {
		$this->streamService = $this->createStub(StreamService::class);
		$this->streamService->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options): array {
				$this->asked = $options;
				$this->reads[] = 'posts';

				return $this->timeline;
			});
		$this->streamService->method('getDirectTimelineLinks')
			->willReturnCallback(function (ProbeOptions $options): array {
				$this->asked = $options;
				$this->reads[] = 'links';

				return array_map(static fn (Stream $message): array => [
					'id' => $message->getId(),
					'nid' => (string)$message->getNid(),
					'inReplyTo' => $message->getInReplyTo(),
					'attributedTo' => $message->getAttributedTo(),
				], $this->timeline);
			});
		$this->streamService->method('getStreamByNid')
			->willReturnCallback(function (int|string $nid): Stream {
				foreach ($this->timeline as $message) {
					if ((string)$message->getNid() === (string)$nid) {
						return $message;
					}
				}

				foreach ($this->stored as $id => $link) {
					if ((string)$link['nid'] === (string)$nid) {
						return $this->note($id, $link['nid'], $link['inReplyTo'], self::BOB);
					}
				}

				throw new StreamNotFoundException('stream not found');
			});

		$this->streamService->method('getStreamById')
			->willReturnCallback(function (string $id): Stream {
				if (!array_key_exists($id, $this->stored)) {
					throw new StreamNotFoundException('stream not found');
				}

				return $this->note($id, $this->stored[$id]['nid'], $this->stored[$id]['inReplyTo'], self::BOB);
			});

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getCachedFromIds')
			->willReturnCallback(function (array $ids): array {
				$this->reads[] = 'accounts';
				$this->lookedUp[] = $ids;
				$actors = [];
				foreach ($ids as $id) {
					// an id that is not a cached actor — a collection, a
					// stranger this instance never saw — is simply not answered
					if (in_array($id, [self::VIEWER, self::BOB, self::CAROL], true)) {
						$actors[$id] = $this->person($id);
					}
				}

				return $actors;
			});

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->timelineRevisionService = $this->createStub(TimelineRevisionService::class);
		$this->timelineRevisionService->method('of')
			->willReturnCallback(fn (string $userId, string $key = TimelineRevisionService::KEY): int => $this->settings[$userId . '/' . $key] ?? 0);
		$this->timelineRevisionService->method('bump')
			->willReturnCallback(function (string $userId, string $key = TimelineRevisionService::KEY): void {
				if ($userId !== '') {
					$this->settings[$userId . '/' . $key] = ($this->settings[$userId . '/' . $key] ?? 0) + 1;
				}
			});

		$this->conversationsRequest = $this->createStub(ConversationsRequest::class);
		$this->conversationsRequest->method('getThreadLinks')
			->willReturnCallback(function (array $ids): array {
				$this->reads[] = 'thread';
				$links = [];
				foreach ($ids as $id) {
					if (array_key_exists($id, $this->stored)) {
						$links[$id] = [
							'id' => $id,
							'idPrim' => md5($id),
							'nid' => $this->stored[$id]['nid'],
							'inReplyTo' => $this->stored[$id]['inReplyTo'],
						];
					}
				}

				return $links;
			});
		// the database's walk up: each id asked for and its ancestors, as far
		// as `$levels` and what is stored reach
		$this->conversationsRequest->method('getAncestorLinks')
			->willReturnCallback(function (array $ids, int $levels): array {
				$this->reads[] = 'walk';
				$links = [];
				foreach ($ids as $id) {
					for ($level = 0; $level < $levels && array_key_exists($id, $this->stored); $level++) {
						$links[$id] = [
							'id' => $id,
							'idPrim' => md5($id),
							'nid' => $this->stored[$id]['nid'],
							'inReplyTo' => $this->stored[$id]['inReplyTo'],
						];
						$id = $this->stored[$id]['inReplyTo'];
					}
				}

				return $links;
			});
		$this->conversationsRequest->method('getThreadFor')
			->willReturnCallback(function (string $actorId, string $rootId): array {
				$thread = [];
				foreach ($this->threads[$rootId] ?? [] as $id => $nid) {
					$thread[$id] = ['id' => $id, 'idPrim' => md5($id), 'nid' => $nid, 'inReplyTo' => ''];
				}

				return $thread;
			});
		$this->conversationsRequest->method('getAuthorsOf')
			->willReturnCallback(function (array $ids): array {
				$this->reads[] = 'authors';

				return array_intersect_key($this->authors, array_flip($ids));
			});
		$this->conversationsRequest->method('getMarkers')
			->willReturnCallback(function (string $actorId, array $rootIds): array {
				return array_intersect_key($this->markers, array_flip($rootIds));
			});
		$this->conversationsRequest->method('markRead')
			->willReturnCallback(function (string $actorId, string $rootId, int $nid): void {
				$this->writes[] = ['markRead', $actorId, $rootId, $nid];
			});
		$this->conversationsRequest->method('markHidden')
			->willReturnCallback(function (string $actorId, string $rootId, int $nid): void {
				$this->writes[] = ['markHidden', $actorId, $rootId, $nid];
			});
	}

	private function service(?ChatState $chat = null, ?NotificationPolicyService $policy = null): ConversationService {
		$container = null;
		if ($chat !== null || $policy !== null) {
			$container = $this->createStub(ContainerInterface::class);
			$container->method('get')->willReturnCallback(static fn (string $id): ?object => match ($id) {
				ChatState::class => $chat,
				NotificationPolicyService::class => $policy,
				default => null,
			});
		}

		return new ConversationService(
			$this->streamService, $this->cacheActorService, $this->conversationsRequest,
			$this->streamRequest, $this->timelineRevisionService, $container
		);
	}

	/**
	 * Where the conversations' state goes to Bluesky, as what it was told.
	 *
	 * @param list<array> $told
	 */
	private function chat(array &$told): ChatState {
		$chat = $this->createStub(ChatState::class);
		$chat->method('read')->willReturnCallback(function (Person $viewer, array $ids, bool $all = false) use (&$told): void {
			$told[] = ['read', $ids, $all];
		});
		$chat->method('dismissed')->willReturnCallback(function (Person $viewer, array $ids) use (&$told): void {
			$told[] = ['dismissed', $ids];
		});

		return $chat;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	/**
	 * A direct message, as the timeline hands one over, and stored so that the
	 * thread walks can find it too.
	 *
	 * @param string[] $to
	 */
	private function note(
		string $id,
		int $nid,
		string $inReplyTo = '',
		string $author = self::BOB,
		array $to = [self::VIEWER],
	): Note {
		$note = new Note();
		$note->setId($id)
			->setNid($nid);
		$note->setInReplyTo($inReplyTo)
			->setAttributedTo($author);
		$note->setToArray($to);

		$this->stored[$id] = ['nid' => $nid, 'inReplyTo' => $inReplyTo];
		$this->authors[$id] = $author;

		return $note;
	}

	/**
	 * The real notification policy, over what the viewer chose, follows and
	 * decided about: the rule a conversation is held by is the bell's.
	 */
	private function policy(): NotificationPolicyService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getUserValue')
			->willReturnCallback(fn (string $key, string $userId = ''): string => $this->policyValues[$key] ?? '');
		$config->method('setValueForUser')
			->willReturnCallback(function (string $userId, string $key, string $value): void {
				$this->policyValues[$key] = $value;
			});
		$follows = $this->createStub(FollowsRequest::class);
		$follows->method('getBetweenMany')
			->willReturnCallback(fn (): array => ['following' => array_fill_keys($this->following, new Follow()), 'followedBy' => []]);
		$moderation = $this->createStub(ModerationRequest::class);
		$moderation->method('getActorIdsAt')->willReturn([]);
		$relations = $this->createStub(AccountRelationService::class);
		$relations->method('notificationDecisions')->willReturnCallback(fn (): array => $this->decisions);

		return new NotificationPolicyService($config, $follows, $moderation, $relations, $this->timelineRevisionService);
	}

	/** The viewer as a request has them: with the Nextcloud user the policy is stored for. */
	private function reader(): Person {
		return $this->viewer()->setUserId('alice');
	}

	/** @return list<int|string> the ids of the conversations listed */
	private function listedIds(NotificationPolicyService $policy): array {
		return array_map(
			static fn (Conversation $c): int|string => $c->getId(),
			$this->service(null, $policy)->getPage($this->reader(), 20)['conversations']
		);
	}

	/** The direct timeline, in the order it answers: newest message first. */
	private function given(Note ...$messages): void {
		$this->timeline = array_values($messages);
		usort(
			$this->timeline,
			static fn (Stream $a, Stream $b): int => $b->getNid() <=> $a->getNid()
		);
	}

	private function viewer(): Person {
		return $this->person(self::VIEWER);
	}

	/** @return Conversation[] */
	private function page(int $limit = 20): array {
		return $this->service()->getPage($this->viewer(), $limit)['conversations'];
	}

	public function testEveryMessageOfOneExchangeIsOneConversation(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1', self::VIEWER),
			$this->note('https://a/3', 12, 'https://a/2'),
		);

		$conversations = $this->page();

		$this->assertCount(1, $conversations);
		$this->assertSame(12, $conversations[0]->getLastStatus()->getNid(), 'the newest message');
	}

	public function testTheConversationIdIsTheThreadRoot(): void {
		// the id has to mean the same thing on the next request, and the root
		// is the one post of a thread every message in it agrees on
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1'),
		);

		$this->assertSame(10, $this->page()[0]->getId());
	}

	public function testTheRootIsFoundEvenWhenItIsOutsideThePage(): void {
		// the walk upwards reads the parents the window does not carry
		$root = $this->note('https://a/1', 10);
		$this->given($this->note('https://a/9', 19, 'https://a/1'));
		$this->stored[$root->getId()] = ['nid' => 10, 'inReplyTo' => ''];

		$conversations = $this->page();

		$this->assertCount(1, $conversations);
		$this->assertSame(10, $conversations[0]->getId());
	}

	/** What the walk up read names the root's nid: nothing is asked for it again. */
	public function testARootOutsideThePageIsNotLookedUpTwice(): void {
		$this->note('https://a/1', 10);
		$this->given($this->note('https://a/9', 19, 'https://a/1'));

		$this->page();

		$this->assertNotContains('thread', $this->reads);
	}

	/** The walk up reads several levels a statement, not one. */
	public function testALongThreadIsWalkedUpSeveralLevelsAtATime(): void {
		$parent = '';
		for ($nid = 1; $nid <= 12; $nid++) {
			$this->note('https://a/' . $nid, $nid, $parent);
			$parent = 'https://a/' . $nid;
		}
		$this->given($this->note('https://a/13', 13, $parent));

		$this->assertSame(1, $this->page()[0]->getId(), 'the root, twelve levels up');
		$this->assertCount(3, array_keys($this->reads, 'walk', true), 'twelve levels in three statements, the last finding the end');
	}

	/** One cache lookup for the participants of the whole page, and only of the page. */
	public function testTheParticipantsOfThePageAreLookedUpOnceAndOnlyForThePage(): void {
		$this->given(
			$this->note('https://a/1', 10, '', self::BOB),
			$this->note('https://b/1', 11, '', self::CAROL),
			$this->note('https://c/1', 12, '', self::BOB),
		);

		$conversations = $this->page(2);

		$this->assertCount(2, $conversations);
		$this->assertCount(1, array_keys($this->reads, 'accounts', true));
		$this->assertSame([self::BOB, self::CAROL], $this->lookedUp[0], 'the two conversations listed, not the third');
		$this->assertSame(self::CAROL, $conversations[1]->getAccounts()[0]->getId());
	}

	public function testReadingEverythingLooksUpNobody(): void {
		$this->given($this->note('https://a/1', 10), $this->note('https://b/1', 11, '', self::CAROL));

		$this->service()->markAllRead($this->viewer());

		$this->assertNotContains('accounts', $this->reads);
	}

	/** The badge's tag moves whenever a conversation is read or dismissed, and only then. */
	public function testTheTagMovesWithEveryReadAndDismissal(): void {
		$viewer = $this->viewer()->setUserId('alice');
		$this->given($this->note('https://a/1', 10), $this->note('https://b/1', 11, '', self::CAROL));
		$this->threads['https://a/1'] = ['https://a/1' => 10];
		$service = $this->service();

		$tags = [$service->unreadTag($viewer)];
		$service->markRead($viewer, 10);
		$tags[] = $service->unreadTag($viewer);
		$service->remove($viewer, 10);
		$tags[] = $service->unreadTag($viewer);
		$service->markAllRead($viewer);
		$tags[] = $service->unreadTag($viewer);
		$service->countUnread($viewer);
		$service->getPage($viewer, 20);
		$tags[] = $service->unreadTag($viewer);

		$this->assertCount(4, array_unique($tags));
		$this->assertSame($tags[3], $tags[4], 'reading changes nothing');
	}

	/** "People you follow": a stranger's conversation waits among the requests, a friend's is listed. */
	public function testAConversationAHeldStrangerStartedIsNotListed(): void {
		$this->following = [self::CAROL];
		$policy = $this->policy();
		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_FOLLOWING);
		$this->given(
			$this->note('https://a/1', 10, '', self::BOB),
			$this->note('https://b/1', 11, '', self::CAROL),
			$this->note('https://a/2', 12, 'https://a/1', self::BOB),
		);

		$this->assertSame([11], $this->listedIds($policy));
		$this->assertSame(1, $this->service(null, $policy)->countUnread($this->reader()), 'the badge agrees');
	}

	/** "Nobody": every conversation waits, until its starter is allowed from the requests. */
	public function testNobodyHoldsTheConversationsOfPeopleFollowedUntilAllowed(): void {
		$this->following = [self::CAROL];
		$policy = $this->policy();
		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_NONE);
		$this->given(
			$this->note('https://a/1', 10, '', self::BOB),
			$this->note('https://b/1', 11, '', self::CAROL),
		);

		$this->assertSame([], $this->listedIds($policy));
		$this->assertSame(0, $this->service(null, $policy)->countUnread($this->reader()));

		$this->decisions['accepted'][self::CAROL] = true;

		$this->assertSame([11], $this->listedIds($policy));
	}

	/** A conversation the reader started, or has written in, is theirs whoever else is in it. */
	public function testAConversationTheReaderStartedOrWroteInIsListed(): void {
		$policy = $this->policy();
		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_NONE);
		$this->given(
			$this->note('https://a/1', 10, '', self::VIEWER, [self::BOB]),
			$this->note('https://a/2', 11, 'https://a/1', self::BOB),
			$this->note('https://b/1', 12, '', self::CAROL),
			$this->note('https://b/2', 13, 'https://b/1', self::VIEWER, [self::CAROL]),
		);

		$this->assertSame([12, 10], $this->listedIds($policy));
	}

	/** The starter of a thread whose root is outside the window is read from storage. */
	public function testTheStarterOfARootOutsideTheWindowIsLookedUp(): void {
		$policy = $this->policy();
		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_FOLLOWING);
		$this->note('https://a/1', 10, '', self::BOB);
		$this->given($this->note('https://a/9', 19, 'https://a/1', self::CAROL));

		$this->assertSame([], $this->listedIds($policy));
		$this->assertContains('authors', $this->reads);
	}

	/** A policy that holds nobody costs the list nothing: no author is read, no actor looked up twice. */
	public function testAPolicyThatHoldsNobodyReadsNothingMore(): void {
		$this->given(
			$this->note('https://a/1', 10, '', self::BOB),
			$this->note('https://b/9', 19, 'https://b/1', self::CAROL),
		);
		$this->note('https://b/1', 11, '', self::CAROL);

		$this->assertSame([11, 10], $this->listedIds($this->policy()));
		$this->assertNotContains('authors', $this->reads);
		$this->assertCount(1, array_keys($this->reads, 'accounts', true), 'the participants alone');
	}

	/** The streaming update of an edited message does not bring a held conversation in. */
	public function testAnEditInAHeldConversationIsNotStreamed(): void {
		$policy = $this->policy();
		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_FOLLOWING);
		$this->given($this->note('https://a/1', 10, '', self::BOB));
		$this->threads['https://a/1'] = ['https://a/1' => 10];

		$this->assertNull($this->service(null, $policy)->updatedByStatus($this->reader(), 10));

		$this->decisions['accepted'][self::BOB] = true;

		$this->assertNotNull($this->service(null, $policy)->updatedByStatus($this->reader(), 10));
	}

	/** Changing who may write moves the badge's tag, so a client that cached the count asks again. */
	public function testTheTagMovesWhenWhoMayWriteChanges(): void {
		$policy = $this->policy();
		$service = $this->service(null, $policy);
		$before = $service->unreadTag($this->reader());

		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_NONE);

		$this->assertNotSame($before, $service->unreadTag($this->reader()));
	}

	/** `/api/v1/conversations`, as Mastodon apps and the web page read it, leaves a held conversation out. */
	public function testTheMastodonListingLeavesAHeldConversationOut(): void {
		$this->following = [self::CAROL];
		$policy = $this->policy();
		$policy->saveDirectMessagesFrom('alice', NotificationPolicyService::DIRECT_FOLLOWING);
		$this->given(
			$this->note('https://a/1', 10, '', self::BOB),
			$this->note('https://b/1', 11, '', self::CAROL),
		);

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v1/conversations');
		$request->method('getParam')->willReturn('');
		$request->method('getParams')->willReturn([]);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn($this->reader());

		$response = (new ConversationController(
			$request, $session, new NullLogger(), $accounts,
			$this->createStub(ClientService::class), $this->service(null, $policy)
		))->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['11'], array_map(
			static fn (Conversation $c): string => (string)$c->jsonSerialize()['id'],
			$response->getData()
		));
	}

	public function testAThreadWhoseParentIsNotStoredHereRootsAtTheTopmostMessageThatIs(): void {
		$this->given($this->note('https://a/9', 19, 'https://elsewhere.example/1'));

		$this->assertSame(19, $this->page()[0]->getId());
	}

	public function testACycleInTheReplyChainDoesNotHangTheWalk(): void {
		// only a remote server can create one. A cycle has no root, so each
		// message ends up its own conversation; what is being asserted is that
		// the walk ends at all rather than what it decides
		$this->given(
			$this->note('https://a/1', 10, 'https://a/2'),
			$this->note('https://a/2', 11, 'https://a/1'),
		);

		$this->assertCount(2, $this->page());
	}

	public function testTwoExchangesAreTwoConversationsNewestFirst(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://b/1', 20, '', self::CAROL),
			$this->note('https://a/2', 30, 'https://a/1'),
		);

		$conversations = $this->page();

		$this->assertSame([10, 20], array_map(
			static fn (Conversation $c): int => $c->getId(), $conversations
		));
	}

	public function testTheAccountsAreTheOtherParticipantsAndNeverTheViewer(): void {
		$this->given(
			$this->note('https://a/1', 10, '', self::BOB, [self::VIEWER, self::CAROL]),
			$this->note('https://a/2', 11, 'https://a/1', self::VIEWER, [self::BOB, self::CAROL]),
		);

		$accounts = array_map(
			static fn (Person $p): string => $p->getId(), $this->page()[0]->getAccounts()
		);

		sort($accounts);
		$this->assertSame([self::BOB, self::CAROL], $accounts);
	}

	public function testAnAddresseeThatIsNotACachedActorIsLeftOut(): void {
		// the public collection rides in `to` on nothing that reaches here, but
		// a followers collection and a stranger both do
		$this->given($this->note('https://a/1', 10, '', self::BOB, [
			self::VIEWER, ACore::CONTEXT_PUBLIC, 'https://remote.example/users/bob/followers',
		]));

		$this->assertSame(
			[self::BOB],
			array_map(static fn (Person $p): string => $p->getId(), $this->page()[0]->getAccounts())
		);
	}

	public function testAConversationIsUnreadUntilItsNewestMessageHasBeenRead(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1'),
		);

		$this->assertTrue($this->page()[0]->isUnread(), 'never read');

		$this->markers['https://a/1'] = ['rootId' => 'https://a/1', 'readNid' => 10, 'hiddenNid' => 0];
		$this->assertTrue($this->page()[0]->isUnread(), 'read up to the older message only');

		$this->markers['https://a/1'] = ['rootId' => 'https://a/1', 'readNid' => 11, 'hiddenNid' => 0];
		$this->assertFalse($this->page()[0]->isUnread());
	}

	public function testAMessageTheViewerSentIsNotUnread(): void {
		// writing a message is having read the conversation, as on Mastodon
		$this->given($this->note('https://a/1', 10, '', self::VIEWER, [self::BOB]));

		$this->assertFalse($this->page()[0]->isUnread());
	}

	public function testADismissedConversationIsGoneUntilANewMessageArrives(): void {
		$this->given($this->note('https://a/1', 10));
		$this->markers['https://a/1'] = ['rootId' => 'https://a/1', 'readNid' => 0, 'hiddenNid' => 10];

		$this->assertSame([], $this->page(), 'dismissed up to the newest message there was');

		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1'),
		);

		$this->assertCount(1, $this->page(), 'a message the dismissal did not cover brings it back');
	}

	public function testAnEditedMessageReturnsItsVisibleConversation(): void {
		$this->given($this->note('https://a/1', 10));
		$this->threads['https://a/1'] = ['https://a/1' => 10];

		$conversation = $this->service()->updatedByStatus($this->viewer(), 10);

		$this->assertInstanceOf(Conversation::class, $conversation);
		$this->assertSame('https://a/1', $conversation->getRootId());
		$this->assertSame(10, $conversation->getLastStatusNid());
		$this->assertTrue($conversation->isUnread());
	}

	public function testAnEditDoesNotResurfaceADismissedConversation(): void {
		$this->given($this->note('https://a/1', 10));
		$this->threads['https://a/1'] = ['https://a/1' => 10];
		$this->markers['https://a/1'] = ['rootId' => 'https://a/1', 'readNid' => 10, 'hiddenNid' => 10];

		$this->assertNull($this->service()->updatedByStatus($this->viewer(), 10));
	}

	public function testThePageIsBuiltFromTheWidestWindowTheTimelineAllows(): void {
		$this->given($this->note('https://a/1', 10));
		$this->service()->getPage($this->viewer(), 20, 5, 0, 0);

		$this->assertSame(ProbeOptions::DIRECT, $this->asked->getProbe());
		$this->assertSame(ConversationService::WINDOW, $this->asked->getLimit());
		$this->assertSame(5, $this->asked->getMaxId(), 'the cursor the client sent');
	}

	public function testThePageCursorIsAMessageAndNotAConversation(): void {
		// conversations are ordered by their newest message, and a conversation
		// id does not move when a message arrives, so it cannot page
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://b/1', 20, '', self::CAROL),
		);

		$page = $this->service()->getPage($this->viewer(), 1);

		$this->assertCount(1, $page['conversations']);
		$this->assertSame(20, $page['conversations'][0]->getId());
		$this->assertSame(
			20, $page['next'],
			'the newest message of the last conversation kept: every one left out is older'
		);
		$this->assertSame(20, $page['prev']);
	}

	public function testAWindowThatCameBackShortIsTheEndOfTheList(): void {
		$this->given($this->note('https://a/1', 10));

		$this->assertSame(0, $this->service()->getPage($this->viewer(), 20)['next']);
	}

	public function testAWindowFullOfOneThreadStillPagesOn(): void {
		// 50 messages of one exchange are one conversation, and the older
		// conversations behind them must still be reachable
		$messages = [];
		for ($i = 0; $i < ConversationService::WINDOW; $i++) {
			$messages[] = $this->note(
				'https://a/' . $i, 100 + $i, ($i === 0) ? '' : 'https://a/' . ($i - 1)
			);
		}
		$this->given(...$messages);

		$page = $this->service()->getPage($this->viewer(), 20);

		$this->assertCount(1, $page['conversations']);
		$this->assertSame(100, $page['next'], 'the oldest message the window reached');
	}

	public function testAnEmptyTimelineHasNoCursorAtAll(): void {
		$page = $this->service()->getPage($this->viewer(), 20);

		$this->assertSame(['conversations' => [], 'next' => 0, 'prev' => 0], $page);
	}

	public function testMarkingReadStoresTheNewestMessageOfTheWholeThread(): void {
		// the thread, not the page: a message the window did not reach is still
		// read, and a message that arrives after this is not
		$this->note('https://a/1', 10);
		$this->threads['https://a/1'] = ['https://a/1' => 10, 'https://a/2' => 11];

		$conversation = $this->service()->markRead($this->viewer(), 10);

		$this->assertSame([['markRead', self::VIEWER, 'https://a/1', 11]], $this->writes);
		$this->assertSame(10, $conversation->getId(), 'the answer names the conversation, not the message');
		$this->assertFalse($conversation->isUnread());
	}

	public function testMarkingReadTakesTheNidOfAReplyToMeanItsThread(): void {
		$this->note('https://a/1', 10);
		$this->note('https://a/2', 11, 'https://a/1');
		$this->threads['https://a/1'] = ['https://a/1' => 10, 'https://a/2' => 11];

		$conversation = $this->service()->markRead($this->viewer(), 11);

		$this->assertSame(10, $conversation->getId());
		$this->assertSame([['markRead', self::VIEWER, 'https://a/1', 11]], $this->writes);
	}

	public function testAConversationTheViewerIsNoPartOfIsNotFound(): void {
		// somebody else's exchange, and an id that names nothing, are one answer
		$this->note('https://a/1', 10);

		foreach ([10, 99, 0] as $id) {
			try {
				$this->service()->markRead($this->viewer(), $id);
				$this->fail('conversation ' . $id . ' was answered');
			} catch (ItemNotFoundException $e) {
				$this->assertSame('Record not found', $e->getMessage());
			}
		}

		$this->assertSame([], $this->writes, 'and nothing is written');
	}

	/**
	 * The routes hand the id over as the string it was in the url, so one
	 * that is not a nid is as unknown as one that names nothing, rather than
	 * an exception out of `Nid`.
	 */
	public function testAnIdThatIsNotANidIsNotFound(): void {
		$this->note('https://a/1', 10);
		$this->threads['https://a/1'] = ['https://a/1' => 10];

		foreach (['abc', '10abc', '', '-10'] as $id) {
			foreach (['markRead', 'remove'] as $method) {
				try {
					$this->service()->$method($this->viewer(), $id);
					$this->fail($method . ' answered ' . var_export($id, true));
				} catch (ItemNotFoundException $e) {
					$this->assertSame('Record not found', $e->getMessage());
				}
			}
		}

		$this->assertSame([], $this->writes);
	}

	public function testRemovingDismissesTheConversationRatherThanDeletingAnything(): void {
		$this->note('https://a/1', 10);
		$this->threads['https://a/1'] = ['https://a/1' => 10, 'https://a/2' => 12];

		$this->service()->remove($this->viewer(), 10);

		$this->assertSame([['markHidden', self::VIEWER, 'https://a/1', 12]], $this->writes);
	}

	public function testRemovingSomebodyElsesConversationIsNotFoundAndWritesNothing(): void {
		$this->note('https://a/1', 10);

		$this->expectException(ItemNotFoundException::class);

		try {
			$this->service()->remove($this->viewer(), 10);
		} finally {
			$this->assertSame([], $this->writes);
		}
	}

	// the badge

	/**
	 * The badge counts conversations, not messages: five messages in one
	 * exchange are one thing to go and read, and a badge saying five would
	 * send somebody looking for four conversations that are not there.
	 */
	public function testTheCountIsOfConversationsAndNotOfMessages(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1'),
			$this->note('https://a/3', 12, 'https://a/1'),
			$this->note('https://b/1', 13),
		);

		$this->assertSame(2, $this->service()->countUnread($this->viewer()));
	}

	/** Writing a message is having read the conversation, as it is in Mastodon. */
	public function testAConversationTheAccountItselfSpokeLastInIsNotUnread(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1', self::VIEWER, [self::BOB]),
		);

		$this->assertSame(0, $this->service()->countUnread($this->viewer()));
	}

	public function testAConversationReadUpToItsNewestMessageIsNotUnread(): void {
		$this->given($this->note('https://a/1', 10));
		$this->markers['https://a/1'] = ['readNid' => 10, 'hiddenNid' => 0];

		$this->assertSame(0, $this->service()->countUnread($this->viewer()));
	}

	public function testTheCountStopsAtItsCap(): void {
		$notes = [];
		for ($i = 1; $i <= 6; $i++) {
			$notes[] = $this->note('https://c' . $i . '/1', 100 + $i);
		}
		$this->given(...$notes);

		$this->assertSame(3, $this->service()->countUnread($this->viewer(), 3));
	}

	public function testNoMessagesAtAllIsNoBadge(): void {
		$this->given();

		$this->assertSame(0, $this->service()->countUnread($this->viewer()));
	}

	/**
	 * The badge and the list have to agree, so both decide it in the same
	 * place: whatever `getPage()` marks unread is what is counted.
	 */
	public function testTheCountAgreesWithWhatTheListShows(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://b/1', 11),
			$this->note('https://c/1', 12, '', self::VIEWER, [self::BOB]),
		);
		$this->markers['https://b/1'] = ['readNid' => 11, 'hiddenNid' => 0];

		$shown = 0;
		foreach ($this->page() as $conversation) {
			if ($conversation->isUnread()) {
				$shown++;
			}
		}

		$this->assertSame($shown, $this->service()->countUnread($this->viewer()));
	}

	/**
	 * The badge is asked for on every poll and every push, so it reads the
	 * window's places in their threads and nothing the list draws: not the
	 * posts with their cards and reactions, not the participants.
	 */
	public function testTheCountReadsTheThreadsAndNotThePosts(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://a/2', 11, 'https://a/1', self::CAROL),
		);

		$this->assertSame(1, $this->service()->countUnread($this->viewer()));
		$this->assertSame(['links'], $this->reads);
		$this->assertSame(ProbeOptions::DIRECT, $this->asked?->getProbe());
		$this->assertSame(ConversationService::WINDOW, $this->asked?->getLimit(), 'the window the list is built from');
	}

	/**
	 * A thread whose root is outside the window, or not stored here at all,
	 * is counted as the list shows it: under its topmost stored message, and
	 * not at all when that has no nid a client could name.
	 */
	public function testTheCountGroupsUnderARootOutsideTheWindowAsTheListDoes(): void {
		$this->note('https://a/1', 5);
		$this->given(
			$this->note('https://a/2', 11, 'https://a/1'),
			$this->note('https://a/3', 12, 'https://a/2'),
			$this->note('https://b/2', 13, 'https://b/1'),
			$this->note('https://z/1', 0),
		);
		$this->markers['https://b/2'] = ['readNid' => 0, 'hiddenNid' => 13];

		$shown = 0;
		foreach ($this->page() as $conversation) {
			$shown += $conversation->isUnread() ? 1 : 0;
		}

		$this->assertSame(1, $shown);
		$this->assertSame($shown, $this->service()->countUnread($this->viewer()));
	}

	// clearing it

	public function testReadingThePageMarksTheUnreadConversationsRead(): void {
		$this->given(
			$this->note('https://a/1', 10),
			$this->note('https://b/1', 11),
		);

		$this->assertSame(2, $this->service()->markAllRead($this->viewer()));

		// which conversation is written first is the order the timeline
		// answered in, and not part of what this promises
		$written = $this->writes;
		sort($written);
		$this->assertSame(
			[
				['markRead', self::VIEWER, 'https://a/1', 10],
				['markRead', self::VIEWER, 'https://b/1', 11],
			],
			$written
		);
	}

	/**
	 * This runs every time somebody looks at the page, and moving a marker
	 * that is already where it belongs is a write for nothing.
	 */
	public function testAConversationAlreadyReadIsNotWrittenAgain(): void {
		$this->given($this->note('https://a/1', 10));
		$this->markers['https://a/1'] = ['readNid' => 10, 'hiddenNid' => 0];

		$this->assertSame(0, $this->service()->markAllRead($this->viewer()));
		$this->assertSame([], $this->writes);
	}

	public function testMarkingAnEmptyInboxReadWritesNothing(): void {
		$this->given();

		$this->assertSame(0, $this->service()->markAllRead($this->viewer()));
		$this->assertSame([], $this->writes);
	}
	// the same conversation on Bluesky

	public function testReadingAConversationIsToldByItsFirstAndNewestMessage(): void {
		$told = [];
		$this->note('https://a/1', 10);
		$this->threads['https://a/1'] = ['https://a/1' => 10, 'https://a/2' => 12, 'https://a/3' => 11];

		$this->service($this->chat($told))->markRead($this->viewer(), 10);

		$this->assertSame([['read', ['https://a/1', 'https://a/2'], false]], $told);
	}

	public function testReadingEveryConversationIsToldAsAll(): void {
		$told = [];
		$this->given($this->note('https://a/1', 10), $this->note('https://b/1', 11));

		$this->service($this->chat($told))->markAllRead($this->viewer());

		$this->assertSame('read', $told[0][0]);
		$this->assertTrue($told[0][2]);
		$this->assertEqualsCanonicalizing(['https://a/1', 'https://b/1'], array_values(array_unique($told[0][1])));
	}

	public function testRemovingAConversationIsToldAsTurningItDown(): void {
		$told = [];
		$this->note('https://a/1', 10);
		$this->threads['https://a/1'] = ['https://a/1' => 10, 'https://a/2' => 12];

		$this->service($this->chat($told))->remove($this->viewer(), 10);

		$this->assertSame([['dismissed', ['https://a/1', 'https://a/2']]], $told);
	}

	/** Read somewhere else up to a message: read here up to that one, and not past it. */
	public function testAConversationReadElsewhereIsReadUpToThatMessage(): void {
		$told = [];
		$this->note('https://a/1', 10);
		$this->note('https://a/2', 11, 'https://a/1');
		$this->threads['https://a/1'] = ['https://a/1' => 10, 'https://a/2' => 11, 'https://a/3' => 12];

		$this->assertTrue($this->service($this->chat($told))->markReadUpTo($this->viewer(), 'https://a/2'));

		$this->assertSame([['markRead', self::VIEWER, 'https://a/1', 11]], $this->writes);
		$this->assertSame([], $told, 'nothing goes back to where it came from');
	}

	public function testAMessageInNoConversationOfTheViewersIsNotMarked(): void {
		$this->note('https://a/1', 10);

		$this->assertFalse($this->service()->markReadUpTo($this->viewer(), 'https://a/1'));
		$this->assertFalse($this->service()->markReadUpTo($this->viewer(), 'https://nowhere/1'));
		$this->assertSame([], $this->writes);
	}
}
