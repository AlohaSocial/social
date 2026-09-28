<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Middleware\ExternalScopeException;
use OCA\Social\Middleware\ExternalScopeMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Settings\IIconSection;
use OCP\Settings\IManager as ISettingsManager;
use OCP\Settings\ISettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ExternalScopeMiddlewareTest extends TestCase {
	private array $params = [];
	private string $method = 'GET';
	private string $accept = 'text/html';
	private array $provided = [];

	private function user(bool $external): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getBackend')->willReturn($external ? $this->createStub(ExternalUserBackend::class) : null);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}

	private function middleware(?IUser $viewer, array $users = [], ?ISettingsManager $settings = null, ?IRootFolder $rootFolder = null): ExternalScopeMiddleware {
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($viewer);
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$request->method('getMethod')->willReturnCallback(fn (): string => $this->method);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === 'Accept') ? $this->accept : '');
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []): string => $route . ($args === [] ? '' : '?' . http_build_query($args))
		);
		$initialState = $this->createStub(IInitialStateService::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $app, string $key, $data): void {
				$this->provided[$app][$key] = $data;
			}
		);
		if ($rootFolder === null) {
			$folder = $this->createStub(IUserFolder::class);
			$folder->method('getId')->willReturn(1);
			$rootFolder = $this->createStub(IRootFolder::class);
			$rootFolder->method('getUserFolder')->willReturn($folder);
		}

		return new ExternalScopeMiddleware(
			$session,
			$userManager,
			$request,
			$url,
			$settings ?? $this->createStub(ISettingsManager::class),
			$initialState,
			$rootFolder,
			new NullLogger(),
		);
	}

	/** A controller whose class name the middleware reads, as the dispatcher hands it over. */
	private function controller(string $class): Controller {
		if (!class_exists($class, false)) {
			$namespace = substr($class, 0, (int)strrpos($class, '\\'));
			$short = substr($class, (int)strrpos($class, '\\') + 1);
			eval('namespace ' . $namespace . '; class ' . $short . ' extends \\OCP\\AppFramework\\Controller { public function __construct() {} }');
		}

		// a real one, where the suite has it, without what its constructor wants
		return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
	}

	private function refusal(ExternalScopeMiddleware $middleware, Controller $controller, string $method = 'index'): ExternalScopeException {
		try {
			$middleware->beforeController($controller, $method);
		} catch (ExternalScopeException $e) {
			return $e;
		}

		$this->fail('the request was let through');
	}

	public function testInternalUsersAreNeverStopped(): void {
		$this->middleware($this->user(false))->beforeController($this->controller('OCA\\Files\\Controller\\ViewController'), 'index');
		$this->middleware(null)->beforeController($this->controller('OCA\\Files\\Controller\\ViewController'), 'index');

		$this->addToAssertionCount(1);
	}

	public function testAnExternalUserMayUseSocial(): void {
		$this->middleware($this->user(true))->beforeController($this->controller('OCA\\Social\\Controller\\NavigationController'), 'navigate');

		$this->addToAssertionCount(1);
	}

	public function testAnExternalUserAskingForAnotherAppIsSentToSocial(): void {
		$middleware = $this->middleware($this->user(true));
		$controller = $this->controller('OCA\\Files\\Controller\\ViewController');
		$refusal = $this->refusal($middleware, $controller);

		$response = $middleware->afterException($controller, 'index', $refusal);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('social.Navigation.navigate', $response->getRedirectURL());
	}

	public function testAnApiCallIsRefusedRatherThanRedirected(): void {
		$this->accept = 'application/json';
		$middleware = $this->middleware($this->user(true));
		$controller = $this->controller('OC\\Core\\Controller\\UnifiedSearchController');

		$response = $middleware->afterException($controller, 'search', $this->refusal($middleware, $controller, 'search'));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAPostIsRefusedEvenFromABrowser(): void {
		$this->method = 'POST';
		$middleware = $this->middleware($this->user(true));
		$controller = $this->controller('OCA\\Files\\Controller\\ApiController');

		$response = $middleware->afterException($controller, 'index', $this->refusal($middleware, $controller));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAnOcsCallIsRefusedInTheShapeOcsAnswers(): void {
		$middleware = $this->middleware($this->user(true));
		$controller = $this->createStub(OCSController::class);

		$response = $middleware->afterException($controller, 'x', new ExternalScopeException('social.Navigation.navigate'));

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testOtherExceptionsAreNotItsBusiness(): void {
		$this->expectException(\RuntimeException::class);

		$this->middleware($this->user(true))->afterException(
			$this->controller('OCA\\Files\\Controller\\ViewController'), 'index', new \RuntimeException('boom')
		);
	}

	public function testPersonalSettingsOpenOnlyTheAllowedSections(): void {
		$controller = $this->controller('OCA\\Settings\\Controller\\PersonalSettingsController');

		$this->params = ['section' => 'security'];
		$this->middleware($this->user(true))->beforeController($controller, 'index');

		$this->params = ['section' => 'sharing'];
		$refusal = $this->refusal($this->middleware($this->user(true)), $controller);
		$this->assertSame('settings.PersonalSettings.index?section=security', $refusal->getRedirect());
	}

	public function testPersonalSettingsCreateTheExternalUsersEmptyHomeBeforeCoreReadsItsQuota(): void {
		$this->params = ['section' => 'personal-info'];
		$folder = $this->createMock(IUserFolder::class);
		$folder->expects($this->once())->method('getId')->willReturn(1);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);

		$this->middleware($this->user(true), [], null, $rootFolder)->beforeController(
			$this->controller('OCA\\Settings\\Controller\\PersonalSettingsController'), 'index'
		);
	}

	public function testTheSettingsNavigationListsOnlyTheAllowedSections(): void {
		$sections = [];
		foreach (['personal-info' => 'Personal info', 'sharing' => 'Sharing', 'security' => 'Security', 'theming' => 'Appearance'] as $id => $name) {
			$section = $this->createStub(IIconSection::class);
			$section->method('getID')->willReturn($id);
			$section->method('getName')->willReturn($name);
			$section->method('getIcon')->willReturn('/icon/' . $id);
			$sections[] = $section;
		}
		$settings = $this->createStub(ISettingsManager::class);
		$settings->method('getPersonalSections')->willReturn([10 => $sections]);
		$settings->method('getPersonalSettings')->willReturnCallback(
			fn (string $id): array => ($id === 'theming') ? [] : [10 => [$this->createStub(ISettings::class)]]
		);
		$this->params = ['section' => 'security'];

		$this->middleware($this->user(true), [], $settings)->afterController(
			$this->controller('OCA\\Settings\\Controller\\PersonalSettingsController'), 'index', new TemplateResponse('settings', 'settings/frame')
		);

		$this->assertSame([
			'personal' => [
				['id' => 'personal-info', 'name' => 'Personal info', 'active' => false, 'icon' => '/icon/personal-info'],
				['id' => 'security', 'name' => 'Security', 'active' => true, 'icon' => '/icon/security'],
			],
			'admin' => [],
		], $this->provided['settings']['sections']);
	}

	public function testAnInternalUsersSettingsNavigationIsUntouched(): void {
		$this->middleware($this->user(false))->afterController(
			$this->controller('OCA\\Settings\\Controller\\PersonalSettingsController'), 'index', new TemplateResponse('settings', 'settings/frame')
		);

		$this->assertSame([], $this->provided);
	}

	public function testAThrowingOptionalSettingsProviderDoesNotBreakExternalSettings(): void {
		$section = $this->createStub(IIconSection::class);
		$section->method('getID')->willReturn('security');
		$section->method('getName')->willThrowException(new \RuntimeException('optional provider failed'));
		$settings = $this->createStub(ISettingsManager::class);
		$settings->method('getPersonalSections')->willReturn([[ $section ]]);
		$settings->method('getPersonalSettings')->willReturn([1 => [$this->createStub(ISettings::class)]]);

		$this->middleware($this->user(true), [], $settings)->afterController(
			$this->controller('OCA\\Settings\\Controller\\PersonalSettingsController'), 'index', new TemplateResponse('settings', 'settings/frame')
		);

		$this->assertSame(['personal' => [], 'admin' => []], $this->provided['settings']['sections']);
	}

	public function testTheCoreProfileOfAnExternalUserIsTheirSocialProfileForEverybody(): void {
		$this->params = ['targetUserId' => 'alice'];
		$controller = $this->controller('OCA\\Profile\\Controller\\ProfilePageController');
		$users = ['alice' => $this->user(true), 'bob' => $this->user(false)];

		$refusal = $this->refusal($this->middleware($this->user(false), $users), $controller);
		$this->assertSame('social.ActivityPub.actorAlias?username=alice', $refusal->getRedirect());
		$refusal = $this->refusal($this->middleware(null, $users), $controller);
		$this->assertSame('social.ActivityPub.actorAlias?username=alice', $refusal->getRedirect());

		// an internal user's profile, seen by an internal user, is left alone
		$this->params = ['targetUserId' => 'bob'];
		$this->middleware($this->user(false), $users)->beforeController($controller, 'index');

		// and seen by an external user, it is the Social profile too
		$refusal = $this->refusal($this->middleware($this->user(true), $users), $controller);
		$this->assertSame('social.ActivityPub.actorAlias?username=bob', $refusal->getRedirect());
	}
}
