<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\AdminApiController;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ModerationNotAllowedException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\AccessBlockService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MetricsService;
use OCA\Social\Service\TrendReviewService;
use OCA\Social\Service\TrendService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * The admin API, and above all who may reach it.
 *
 * Every route here can silence an account, delete everything it has posted, or
 * cut this instance off from another one. An admin API an ordinary account can
 * call is worse than no admin API at all, so the first three tests below are
 * the point of this file: *every* route is called as a non-administrator and
 * must answer 403 having done nothing, *every* route is called with no
 * credentials at all and must answer 401, and the list of routes those two run
 * over is checked against the controller by reflection — so a route added
 * later cannot quietly escape either.
 *
 * The service itself is doubled here. What is checked is the controller's own
 * work: the refusals, the scopes, the parameters it turns into a query, and
 * the statuses it maps a failure onto.
 */
#[AllowMockObjectsWithoutExpectations]
class AdminApiControllerTest extends TestCase {
	private const ADMIN = 'root';
	private const ACTOR = 'https://cloud.example/users/alice';

	/** @var IRequest&Stub */
	private $request;
	private AdminApiService|MockObject $adminApiService;
	private AccessBlockService|Stub $accessBlockService;
	private MetricsService|Stub $metricsService;
	private HashtagService|Stub $hashtagService;
	private TrendService|Stub $trendService;
	private TrendReviewService|Stub $trendReviewService;
	private ClientService|Stub $clientService;
	private IUserSession|Stub $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var string[] the scopes the bearer token was granted */
	private array $scopes = ['admin:read', 'admin:write'];
	private bool $csrf = true;
	private bool $signedIn = true;
	private bool $isAdmin = true;
	private string $uri = '/index.php/apps/social/api/v1/admin/accounts';

	/**
	 * Every route of the controller, with arguments that reach its body.
	 *
	 * @return array<string, array{string, array}>
	 */
	public static function routes(): array {
		return [
			'accounts' => ['accounts', []],
			'account' => ['account', ['7']],
			'accountAction' => ['accountAction', ['7', 'suspend', 'spamming', 0]],
			'accountEnable' => ['accountEnable', ['7']],
			'accountUnsilence' => ['accountUnsilence', ['7']],
			'accountUnsuspend' => ['accountUnsuspend', ['7']],
			'accountUnsensitive' => ['accountUnsensitive', ['7']],
			'accountDelete' => ['accountDelete', ['7']],
			'reports' => ['reports', []],
			'report' => ['report', [4]],
			'reportResolve' => ['reportResolve', [4]],
			'reportReopen' => ['reportReopen', [4]],
			'reportAssignToSelf' => ['reportAssignToSelf', [4]],
			'reportUnassign' => ['reportUnassign', [4]],
			'reportUpdate' => ['reportUpdate', [4, 'spam']],
			'domainBlocks' => ['domainBlocks', []],
			'domainBlock' => ['domainBlock', ['evil.example']],
			'domainBlockCreate' => ['domainBlockCreate', ['evil.example', 'suspend']],
			'domainBlockUpdate' => ['domainBlockUpdate', ['evil.example', 'suspend']],
			'domainBlockRemove' => ['domainBlockRemove', ['evil.example']],
			'domainAllows' => ['domainAllows', []],
			'domainAllow' => ['domainAllow', ['friend.example']],
			'domainAllowCreate' => ['domainAllowCreate', ['friend.example']],
			'domainAllowRemove' => ['domainAllowRemove', ['friend.example']],
			'ipBlocks' => ['ipBlocks', []],
			'ipBlock' => ['ipBlock', [4]],
			'ipBlockCreate' => ['ipBlockCreate', ['1.2.3.0/24', 'no_access', '', 0]],
			'ipBlockUpdate' => ['ipBlockUpdate', [4, 'no_access', '', 0]],
			'ipBlockRemove' => ['ipBlockRemove', [4]],
			'emailDomainBlocks' => ['emailDomainBlocks', []],
			'emailDomainBlock' => ['emailDomainBlock', [4]],
			'emailDomainBlockCreate' => ['emailDomainBlockCreate', ['throwaway.example']],
			'emailDomainBlockRemove' => ['emailDomainBlockRemove', [4]],
			'measures' => ['measures', [['new_users'], '2026-09-01', '2026-09-10', '', '']],
			'dimensions' => ['dimensions', [['servers'], '2026-09-01', '2026-09-10', 10, '']],
			'retention' => ['retention', ['2026-09-01', '2026-09-10']],
			'trendTags' => ['trendTags', [10]],
			'trendTagApprove' => ['trendTagApprove', ['cats']],
			'trendTagReject' => ['trendTagReject', ['cats']],
			'trendLinkApprove' => ['trendLinkApprove', ['https://news.example/a']],
			'trendLinkReject' => ['trendLinkReject', ['https://news.example/a']],
			'trendStatusApprove' => ['trendStatusApprove', ['https://cloud.example/@a/1']],
			'trendStatusReject' => ['trendStatusReject', ['https://cloud.example/@a/1']],
			'adminTags' => ['adminTags', []],
			'adminTag' => ['adminTag', ['cats']],
			'adminTagUpdate' => ['adminTagUpdate', ['cats', false]],
			'trendStatuses' => ['trendStatuses', [10, 0]],
			'trendLinks' => ['trendLinks', [10, 0]],
		];
	}

	/** Everything the service can be asked to read or do. */
	private const SERVICE_WORK = [
		'accountPage', 'account', 'act', 'unsilence', 'unsuspend',
		'reports', 'report', 'resolveReport', 'reopenReport', 'assignReport',
		'domainBlocks', 'domainBlock', 'blockDomain', 'unblockDomain', 'updateDomainBlock', 'assertSeverity',
	];

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturnCallback(fn (): string => $this->uri);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn(self::ADMIN);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->userSession->method('getUser')
			->willReturnCallback(fn (): ?IUser => $this->signedIn ? $user : null);

		$this->clientService = $this->createStub(ClientService::class);
		$this->clientService->method('getFromToken')
			->willReturnCallback(function (string $token): SocialClient {
				if ($token !== 'token') {
					throw new \OCA\Social\Exceptions\ClientNotFoundException('unknown token');
				}

				$client = new SocialClient();
				$client->setAuthUserId(self::ADMIN);
				$client->setAuthScopes($this->scopes);

				return $client;
			});

		// Response::getHeaders() asks the server container for the request id
		\OC::$server->register(IRequest::class, $this->request);

		$this->adminApiService = $this->createMock(AdminApiService::class);
		$this->accessBlockService = $this->createStub(AccessBlockService::class);
		$this->metricsService = $this->createStub(MetricsService::class);
		$this->hashtagService = $this->createStub(HashtagService::class);
		$this->trendService = $this->createStub(TrendService::class);
		$this->trendReviewService = $this->createStub(TrendReviewService::class);
		$this->adminApiService->method('isAdministrator')
			->willReturnCallback(fn (string $userId): bool => $this->isAdmin && $userId === self::ADMIN);
	}

	private function controller(): AdminApiController {
		return new AdminApiController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->adminApiService,
			$this->accessBlockService,
			$this->metricsService,
			$this->hashtagService,
			$this->trendService,
			$this->trendReviewService,
			$this->clientService
		);
	}

	private function person(string $id, int $nid): Person {
		$person = new Person();
		$person->setPreferredUsername('alice');
		$person->setId($id);
		$person->setNid($nid);
		$person->setLocal(true);

		return $person;
	}

	private function call(string $method, array $arguments): DataResponse {
		return $this->controller()->$method(...$arguments);
	}

	/** Refuses the service any work at all, whatever the route asks it for. */
	private function expectNoWork(): void {
		foreach (self::SERVICE_WORK as $method) {
			$this->adminApiService->expects($this->never())->method($method);
		}
	}

	#[DataProvider('routes')]
	public function testEveryRouteRefusesANonAdministrator(string $method, array $arguments): void {
		// signed in, CSRF token in hand, and simply not an administrator of
		// this instance
		$this->isAdmin = false;
		$this->expectNoWork();

		$response = $this->call($method, $arguments);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $method . ' must refuse a non-admin');
		$this->assertArrayHasKey('error', $response->getData());
		// PHP turns a response carrying WWW-Authenticate into a 401, which a
		// client reads as a dead token
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
	}

	#[DataProvider('routes')]
	public function testEveryRouteRefusesANonAdminHoldingAnAdminScopedToken(
		string $method, array $arguments,
	): void {
		// the scope is what a client asked for during an authorisation, and
		// this app stores whatever string that was: it says nothing about
		// whether the user behind it may moderate anything
		$this->isAdmin = false;
		$this->headers['Authorization'] = 'Bearer token';
		$this->scopes = ['admin:read', 'admin:write', 'admin'];
		$this->expectNoWork();

		$response = $this->call($method, $arguments);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $method . ' must refuse a non-admin');
	}

	#[DataProvider('routes')]
	public function testEveryRouteRefusesACallerWithNoCredentials(string $method, array $arguments): void {
		$this->signedIn = false;
		$this->expectNoWork();

		$response = $this->call($method, $arguments);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $method . ' must refuse a stranger');
	}

	#[DataProvider('routes')]
	public function testEveryRouteRefusesASessionWithoutItsCsrfToken(string $method, array $arguments): void {
		// a session cookie alone is what a cross-site request carries
		$this->csrf = false;
		$this->expectNoWork();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->call($method, $arguments)->getStatus());
	}

	#[DataProvider('routes')]
	public function testEveryRouteRefusesARevokedToken(string $method, array $arguments): void {
		$this->headers['Authorization'] = 'Bearer stale';
		$this->expectNoWork();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->call($method, $arguments)->getStatus());
	}

	public function testEveryRouteOfTheControllerIsCoveredByThoseRefusals(): void {
		$declared = [];
		foreach ((new ReflectionClass(AdminApiController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === AdminApiController::class
				&& $method->getName() !== '__construct') {
				$declared[] = $method->getName();
			}
		}

		sort($declared);
		$covered = array_map(static fn (array $route): string => $route[0], $this->routes());
		sort($covered);

		$this->assertSame(
			$declared,
			$covered,
			'a route of the admin API is not in the refusal tests above:'
			. ' add it to routes(), so that it is proven to refuse a non-administrator'
		);
	}

	public function testAnOrdinaryClientTokenCannotReachTheAdminApi(): void {
		// `read` and `write` are what every timeline client holds; Mastodon
		// keeps the admin scopes outside that tree for exactly this reason
		$this->headers['Authorization'] = 'Bearer token';
		$this->scopes = ['read', 'write', 'follow'];
		$this->expectNoWork();

		$response = $this->controller()->accounts();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
	}

	public function testAReadTokenCannotWrite(): void {
		$this->headers['Authorization'] = 'Bearer token';
		$this->scopes = ['admin:read'];

		$this->adminApiService->expects($this->never())->method('act');
		$this->adminApiService->method('accountPage')->willReturn(['accounts' => [], 'cursors' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->accounts()->getStatus());
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller()->accountAction('7', 'suspend')->getStatus()
		);
	}

	public function testTheBroadAdminScopeCoversBoth(): void {
		$this->headers['Authorization'] = 'Bearer token';
		$this->scopes = ['admin'];

		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->expects($this->once())->method('act');

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountAction('7', 'suspend')->getStatus());
	}

	public function testAnAdministratorsOwnSessionNeedsNoScope(): void {
		// the admin panel calls these from the browser, where there is no
		// token to carry a scope at all
		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->expects($this->once())->method('act');

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountAction('7', 'suspend')->getStatus());
	}

	public function testThePageIsAskedForWithTheFiltersTheClientSent(): void {
		$this->adminApiService->expects($this->once())
			->method('accountPage')
			->with(false, 'bob', 'Bobby', 'remote.example', 'suspended', 20, 12, 3)
			->willReturn(['accounts' => [$this->account()], 'cursors' => []]);

		$response = $this->controller()->accounts(
			'remote', 'suspended', 'bob', 'Bobby', 'remote.example', '', '', 20, 12, 3
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testAnOriginThisApiDoesNotKnowIsRefusedRatherThanIgnored(): void {
		$this->adminApiService->expects($this->never())->method('accountPage');

		$response = $this->controller()->accounts('elsewhere');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testAFilterOnSomethingThisInstanceDoesNotHoldMatchesNothing(): void {
		// answering an email search with every account on the instance would
		// be the filter silently falling off
		$this->adminApiService->expects($this->never())->method('accountPage');

		$this->assertSame([], $this->controller()->accounts('', '', '', '', '', 'a@b.example')->getData());
		$this->assertSame([], $this->controller()->accounts('', '', '', '', '', '', '10.0.0.1')->getData());
	}

	public function testThePageCarriesTheCursorAClientPagesOn(): void {
		$this->adminApiService->method('accountPage')
			->willReturn(['accounts' => [$this->account()], 'cursors' => [9, 7]]);

		$response = $this->controller()->accounts('', '', '', '', '', '', '', 2);

		$this->assertSame(
			'</index.php/apps/social/api/v1/admin/accounts?max_id=7>; rel="next",'
			. ' </index.php/apps/social/api/v1/admin/accounts?min_id=9>; rel="prev"',
			$response->getHeaders()['Link']
		);
	}

	public function testAPageLargerThanTheInstanceWillBuildStillOffersItsCursor(): void {
		// asked for a thousand and given the two hundred this instance builds:
		// judged against what was asked for, a full page would look like the
		// end of the list and the client would stop there
		$this->adminApiService->method('accountPage')
			->willReturn(['accounts' => [$this->account()], 'cursors' => range(200, 1)]);

		$link = $this->controller()->accounts('', '', '', '', '', '', '', 1000)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('rel="next"', $link);
	}

	public function testAPageWithNoCursorSendsNoLinkHeader(): void {
		// the silenced and suspended lists are read from the decisions, which
		// nothing pages; a Link header there would send the client back to the
		// page it already has
		$this->adminApiService->method('accountPage')
			->willReturn(['accounts' => [$this->account()], 'cursors' => []]);

		$this->assertArrayNotHasKey('Link', $this->controller()->accounts()->getHeaders());
	}

	public function testAnUnknownAccountIsARecordNotFound(): void {
		$this->adminApiService->method('account')
			->willThrowException(new ItemNotFoundException('Record not found'));

		$response = $this->controller()->account('404');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	public function testAnActionThisAppHasNoStateForIsAnUnprocessableEntity(): void {
		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->method('act')
			->willThrowException(new \InvalidArgumentException('this instance has no "disable" state'));

		$response = $this->controller()->accountAction('7', 'disable');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('this instance has no "disable" state', $response->getData()['error']);
	}

	public function testAnActionAnsweredFromAReportResolvesIt(): void {
		// the decision and the report it came from must not be able to
		// disagree, so the client does not have to send a second call
		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->expects($this->once())->method('act');
		$this->adminApiService->expects($this->once())->method('resolveReport')->with(4, self::ADMIN);

		$response = $this->controller()->accountAction('7', 'silence', 'enough', 4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(new \stdClass(), $response->getData());
	}

	public function testAnActionOnItsOwnResolvesNothing(): void {
		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->expects($this->never())->method('resolveReport');

		$this->controller()->accountAction('7', 'silence');
	}

	/**
	 * The rule is AdminApiService's; what is checked here is that every write
	 * about an account asks it before anything is changed.
	 */
	public function testAnAccountTheCallerMayNotActOnIsRefusedOnEveryWrite(): void {
		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->method('assertMayActOn')
			->willReturnCallback(function (string $actorId, string $callerId): void {
				$this->assertSame(self::ACTOR, $actorId);
				$this->assertSame(self::ADMIN, $callerId);

				throw new ModerationNotAllowedException('a moderator cannot act on their own account');
			});
		foreach (['act', 'unsilence', 'unsuspend', 'unsensitive', 'purge', 'resolveReport'] as $write) {
			$this->adminApiService->expects($this->never())->method($write);
		}

		$controller = $this->controller();
		foreach ([
			$controller->accountAction('7', 'suspend', '', 4),
			$controller->accountUnsilence('7'),
			$controller->accountUnsuspend('7'),
			$controller->accountUnsensitive('7'),
			$controller->accountDelete('7'),
		] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('a moderator cannot act on their own account', $response->getData()['error']);
		}
	}

	public function testEnablingAnAccountChangesNothingAndSaysSo(): void {
		$this->adminApiService->method('account')->willReturn($this->account());
		$this->adminApiService->expects($this->never())->method('act');

		$response = $this->controller()->accountEnable('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(AdminAccount::class, $response->getData());
	}

	public function testTheReportQueueIsWhatTheListDefaultsTo(): void {
		$this->adminApiService->expects($this->once())
			->method('reports')
			->with(false, '', '', AdminApiService::LIMIT, 0, 0)
			->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->reports()->getStatus());
	}

	public function testTheResolvedFilterIsReadFromWhatTheClientWrote(): void {
		$this->adminApiService->expects($this->once())
			->method('reports')
			->with(true, '9', '7', 10, 0, 0)
			->willReturn([]);

		$this->controller()->reports('true', '9', '7', 10);
	}

	public function testTakingAReportNamesTheAdministratorTakingIt(): void {
		$this->adminApiService->expects($this->once())->method('assignReport')->with(4, self::ADMIN);

		$this->controller()->reportAssignToSelf(4);
	}

	public function testGivingAReportBackNamesNobody(): void {
		$this->adminApiService->expects($this->once())->method('assignReport')->with(4, null);

		$this->controller()->reportUnassign(4);
	}

	public function testAnAllowListInstanceRefusesTheDomainBlockRoutes(): void {
		$this->adminApiService->method('domainBlocks')
			->willThrowException(new \InvalidArgumentException(
				'this instance federates by an allow list, so its access list is not a list of blocked domains'
			));

		$response = $this->controller()->domainBlocks();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('allow list', $response->getData()['error']);
	}

	public function testASeverityThisInstanceDoesNotHaveIsRefused(): void {
		$this->adminApiService->method('updateDomainBlock')
			->willThrowException(new \InvalidArgumentException('severity "noop" cannot be applied'));

		$response = $this->controller()->domainBlockUpdate('evil.example', 'noop');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testAnUpdateMovesTheBlockThroughTheService(): void {
		$this->adminApiService->expects($this->once())
			->method('updateDomainBlock')
			->with('evil.example', 'silence')
			->willReturn(new AdminDomainBlock('evil.example', AdminDomainBlock::SEVERITY_SILENCE));

		$response = $this->controller()->domainBlockUpdate('evil.example', 'silence');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('silence', $response->getData()->getSeverity());
	}

	public function testABlockIsLiftedByTheEntryItNames(): void {
		$this->adminApiService->expects($this->once())
			->method('unblockDomain')
			->with('evil.example')
			->willReturn(new AdminDomainBlock('evil.example'));

		$response = $this->controller()->domainBlockRemove('evil.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('evil.example', $response->getData()->getDomain());
	}

	public function testAFailureOfThisAppsOwnIsNotEchoedToTheCaller(): void {
		// these are #[PublicPage] routes; echoing getMessage() publishes
		// whatever the failure happened to name
		$this->adminApiService->method('domainBlocks')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02]: social_report is missing'));

		$response = $this->controller()->domainBlocks();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal server error'], $response->getData());
	}

	private function account(): AdminAccount {
		return AdminAccount::fromPerson($this->person(self::ACTOR, 7), Moderation::SILENCE);
	}
}
