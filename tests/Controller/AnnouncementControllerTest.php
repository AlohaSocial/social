<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\AnnouncementController;
use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Announcement;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AnnouncementService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\EmojiService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The five routes announcements are read, dismissed and administered through.
 *
 * The service and the entity are the real ones; only the table is a double, so
 * what these assert is what a client and an administration page are actually
 * handed. Two things are checked on every route that answers a person: that an
 * announcement outside its window is not among what they are served, and that
 * the read state they are shown is their own and nobody else's.
 */
#[AllowMockObjectsWithoutExpectations]
class AnnouncementControllerTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private ClientService|Stub $clientService;
	private IUserSession|Stub $userSession;
	private AnnouncementService $announcementService;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var array<int, Announcement> the stored announcements, by id */
	private array $announcements = [];
	/** @var array<string, int[]> actor id => the announcement ids it dismissed */
	private array $dismissals = [];
	/** @var array<int, array<string, array<string, bool>>> announcement => emoji => actors */
	private array $reactions = [];
	private int $nextId = 1;
	private bool $csrf = true;
	private string $uid = 'alice';

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(fn (string $userId): Person
				=> $this->person('https://cloud.example/users/' . $userId));

		$this->clientService = $this->createStub(ClientService::class);
		$this->announcementService = new AnnouncementService(
			$this->mockAnnouncementsRequest(), $this->createStub(EmojiService::class)
		);

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	/**
	 * A store that makes the two decisions the real one makes in SQL: the
	 * window is a predicate of the read, and a dismissal is looked up under
	 * the account that made it.
	 *
	 * @return AnnouncementsRequest&MockObject
	 */
	private function mockAnnouncementsRequest() {
		$request = $this->createMock(AnnouncementsRequest::class);

		$request->method('save')->willReturnCallback(function (Announcement $announcement): int {
			$announcement->setId($this->nextId++)->setPublishedAt(1_000);
			$this->announcements[$announcement->getId()] = $announcement;

			return $announcement->getId();
		});

		$request->method('getAll')->willReturnCallback(
			fn (): array => array_reverse(array_values($this->announcements))
		);

		$request->method('getActive')->willReturnCallback(fn (?int $now = null): array => array_values(
			array_filter(
				$this->announcements,
				static fn (Announcement $a): bool => $a->isActiveAt($now ?? 5_000)
			)
		));

		$request->method('getById')->willReturnCallback(function (int $id): Announcement {
			if (!isset($this->announcements[$id])) {
				throw new ItemNotFoundException('announcement not found');
			}

			return $this->announcements[$id];
		});

		$request->method('delete')->willReturnCallback(function (int $id): void {
			if (!isset($this->announcements[$id])) {
				throw new ItemNotFoundException('announcement not found');
			}

			unset($this->announcements[$id]);
		});

		$request->method('dismiss')->willReturnCallback(function (int $id, string $actorId): void {
			$this->dismissals[$actorId][] = $id;
		});

		$request->method('dismissedBy')->willReturnCallback(
			fn (string $actorId, array $ids): array
				=> array_values(array_intersect($this->dismissals[$actorId] ?? [], $ids))
		);

		$request->method('react')->willReturnCallback(
			function (int $id, string $actorId, string $name): void {
				$this->reactions[$id][$name][$actorId] = true;
			}
		);

		$request->method('unreact')->willReturnCallback(
			function (int $id, string $actorId, string $name): void {
				unset($this->reactions[$id][$name][$actorId]);
				if (($this->reactions[$id][$name] ?? []) === []) {
					unset($this->reactions[$id][$name]);
				}
			}
		);

		$request->method('reactionsOn')->willReturnCallback(
			function (string $actorId, array $ids): array {
				$on = [];
				foreach ($ids as $id) {
					foreach ($this->reactions[$id] ?? [] as $name => $actors) {
						$on[$id][$name] = ['count' => count($actors), 'me' => isset($actors[$actorId])];
					}
				}

				return $on;
			}
		);

		$request->method('countReactionsBy')->willReturnCallback(
			fn (int $id, string $actorId): int => count(array_filter(
				$this->reactions[$id] ?? [],
				static fn (array $actors): bool => isset($actors[$actorId])
			))
		);

		return $request;
	}

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = ''): AnnouncementController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];

		return new AnnouncementController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->announcementService,
		);
	}

	private function token(array $scopes, string $userId = 'alice'): SocialClient {
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId($userId);
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);

		return $client;
	}

	/** An announcement that is already there. */
	private function given(string $text, int $startsAt = 0, int $endsAt = 0): Announcement {
		$announcement = (new Announcement())
			->setId($this->nextId++)
			->setText($text)
			->setStartsAt($startsAt)
			->setEndsAt($endsAt)
			->setPublishedAt(1_000);
		$this->announcements[$announcement->getId()] = $announcement;

		return $announcement;
	}

	/** @return array<int, array<string, mixed>> the entities a route answered with */
	private function entities($response): array {
		return array_map(
			static fn (Announcement $announcement): array => $announcement->jsonSerialize(),
			$response->getData()
		);
	}

	public function testTheActiveAnnouncementsAreAnsweredAsMastodonEntities(): void {
		$this->given('Maintenance on Sunday');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'id', 'content', 'starts_at', 'ends_at', 'all_day', 'published_at',
				'updated_at', 'read', 'mentions', 'statuses', 'tags', 'emojis', 'reactions',
			],
			array_keys($this->entities($response)[0])
		);
		$this->assertSame('<p>Maintenance on Sunday</p>', $this->entities($response)[0]['content']);
	}

	public function testAnAnnouncementOutsideItsWindowIsNotServed(): void {
		$this->given('Over', 1_000, 2_000);
		$this->given('Not yet', 8_000, 9_000);
		$this->given('Now', 4_000, 6_000);

		$response = $this->controller()->index();

		$this->assertSame(['<p>Now</p>'], array_column($this->entities($response), 'content'));
	}

	public function testAnAnnouncementIsUnreadUntilTheViewerDismissesIt(): void {
		$announcement = $this->given('Maintenance on Sunday');

		$this->assertFalse($this->entities($this->controller()->index())[0]['read']);

		$response = $this->controller()->dismiss($announcement->getId());

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertTrue($this->entities($this->controller()->index())[0]['read']);
	}

	public function testOneAccountsDismissalIsNotAnother(): void {
		$announcement = $this->given('Maintenance on Sunday');

		$this->controller()->dismiss($announcement->getId());

		$this->uid = 'bob';
		$this->assertFalse($this->entities($this->controller()->index())[0]['read']);
		$this->assertSame([$announcement->getId()], $this->dismissals[self::ALICE]);
		$this->assertArrayNotHasKey(self::BOB, $this->dismissals);
	}

	public function testDismissingAnAnnouncementThatIsNotThereIsARecordNotFound(): void {
		$response = $this->controller()->dismiss(404);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Record not found', $response->getData()['error']);
	}

	public function testACallerWithNoCredentialsIsRefused(): void {
		$this->csrf = false;
		$this->given('Maintenance on Sunday');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(
			'Bearer error="invalid_token"', $response->getHeaders()['WWW-Authenticate']
		);
	}

	public function testARevokedTokenIsA401(): void {
		$this->csrf = false;
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException('the access_token was revoked'));

		$response = $this->controller('Bearer stale')->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAnyUserTokenMayReadWhatTheInstanceIsTellingEverybody(): void {
		// Mastodon asks for no scope on this route, so a token that was only
		// ever granted write is still a token
		$this->token(['write']);
		$this->given('Maintenance on Sunday');

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->index()->getStatus());
	}

	public function testDismissingNeedsTheScopeMastodonDocumentsForIt(): void {
		$this->token(['read']);
		$announcement = $this->given('Maintenance on Sunday');

		$response = $this->controller('Bearer t')->dismiss($announcement->getId());

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			'Bearer error="insufficient_scope"', $response->getHeaders()['WWW-Authenticate']
		);
		$this->assertSame([], $this->dismissals);
	}

	public function testAnotherGranularWriteScopeIsNotThisOne(): void {
		$this->token(['write:statuses']);
		$announcement = $this->given('Maintenance on Sunday');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('Bearer t')->dismiss($announcement->getId())->getStatus()
		);
	}

	public function testTheBroadScopeCarriesTheGranularOne(): void {
		$this->token(['write']);
		$announcement = $this->given('Maintenance on Sunday');

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller('Bearer t')->dismiss($announcement->getId())->getStatus()
		);
	}

	public function testATokenDismissesForTheAccountItWasIssuedTo(): void {
		$this->token(['write:accounts'], 'bob');
		$announcement = $this->given('Maintenance on Sunday');

		$this->controller('Bearer t')->dismiss($announcement->getId());

		$this->assertSame([self::BOB => [$announcement->getId()]], $this->dismissals);
	}

	public function testTheAdminIsShownEveryAnnouncementAndWhetherItIsBeingServed(): void {
		$this->given('Over', 1_000, 2_000);
		$this->given('Always');

		$rows = $this->controller()->adminIndex()->getData()['announcements'];

		$this->assertSame(['Always', 'Over'], array_column($rows, 'text'));
		$this->assertSame([true, false], array_column($rows, 'active'));
	}

	public function testPostingAnAnnouncementAnswersWithTheListItIsNowIn(): void {
		$response = $this->controller()->adminCreate('Maintenance on Sunday');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['Maintenance on Sunday'],
			array_column($response->getData()['announcements'], 'text')
		);
		$this->assertSame(
			['<p>Maintenance on Sunday</p>'],
			array_column($this->entities($this->controller()->index()), 'content')
		);
	}

	public function testPostingAnAnnouncementWithAWindowStoresTheWindow(): void {
		$response = $this->controller()->adminCreate(
			'Maintenance', '2026-09-12 10:00', '2026-09-12 12:00'
		);

		$rows = $response->getData()['announcements'];
		$this->assertSame(
			Announcement::datetime((int)strtotime('2026-09-12 10:00')), $rows[0]['starts_at']
		);
		$this->assertSame(
			Announcement::datetime((int)strtotime('2026-09-12 12:00')), $rows[0]['ends_at']
		);
	}

	public function testAnAnnouncementWithNothingInItIsRefused(): void {
		$response = $this->controller()->adminCreate('   ');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('Text cannot be blank', $response->getData()['error']);
		$this->assertSame([], $this->announcements);
	}

	public function testOneBoundWithoutTheOtherIsRefusedWithWhyItWas(): void {
		$response = $this->controller()->adminCreate('Maintenance', '2026-09-12 10:00');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			'starts_at and ends_at are given together or not at all', $response->getData()['error']
		);
	}

	public function testRemovingAnAnnouncementAnswersWithWhatIsLeft(): void {
		$announcement = $this->given('Maintenance on Sunday');
		$this->given('Something else');

		$response = $this->controller()->adminDelete($announcement->getId());

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['Something else'], array_column($response->getData()['announcements'], 'text'));
		$this->assertSame(
			['<p>Something else</p>'],
			array_column($this->entities($this->controller()->index()), 'content')
		);
	}

	public function testRemovingAnAnnouncementThatIsNotThereIsA404(): void {
		$response = $this->controller()->adminDelete(404);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('announcement not found', $response->getData()['error']);
	}

	// reactions

	/** @return array<int, array<string, mixed>> what the viewer is shown */
	private function reactionsOf(int $id, string $authorization = ''): array {
		foreach ($this->entities($this->controller($authorization)->index()) as $entity) {
			if ((int)$entity['id'] === $id) {
				return $entity['reactions'];
			}
		}

		return [];
	}

	/**
	 * `Announcement.reactions` was always `[]` and no route wrote one: the
	 * only thing an account could do with an instance-wide notice was put it
	 * away.
	 */
	public function testReactingPutsTheEmojiOnTheAnnouncementForTheViewer(): void {
		$this->token(['write:favourites']);
		$announcement = $this->given('Maintenance on Sunday');

		$response = $this->controller('Bearer t')->react($announcement->getId(), "\u{1F44D}");

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[['name' => "\u{1F44D}", 'count' => 1, 'me' => true]],
			$this->reactionsOf($announcement->getId(), 'Bearer t')
		);
	}

	public function testTakingAReactionBackRemovesIt(): void {
		$this->token(['write:favourites']);
		$announcement = $this->given('Maintenance on Sunday');
		$this->controller('Bearer t')->react($announcement->getId(), "\u{1F44D}");

		$response = $this->controller('Bearer t')->unreact($announcement->getId(), "\u{1F44D}");

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $this->reactionsOf($announcement->getId(), 'Bearer t'));
	}

	/**
	 * A label somebody wrote on an instance-wide notice, shown to everybody
	 * who reads it, is a second announcement rather than a reaction.
	 */
	public function testAReactionThatIsNotAnEmojiIsRefused(): void {
		$this->token(['write:favourites']);
		$announcement = $this->given('Maintenance on Sunday');

		$response = $this->controller('Bearer t')->react($announcement->getId(), 'read the rules');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->reactions);
	}

	public function testReactingToAnAnnouncementThatIsNotThereIsARecordNotFound(): void {
		$this->token(['write:favourites']);

		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller('Bearer t')->react(404, "\u{1F44D}")->getStatus()
		);
	}

	public function testReactingNeedsTheScopeMastodonDocumentsForIt(): void {
		$this->token(['read']);
		$announcement = $this->given('Maintenance on Sunday');

		$response = $this->controller('Bearer t')->react($announcement->getId(), "\u{1F44D}");

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->reactions);
	}
}
