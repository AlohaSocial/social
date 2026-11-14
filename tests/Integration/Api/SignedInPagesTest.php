<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Http\Dispatcher;
use OC\AppFramework\Http\Request;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\AtprotoAccountController;
use OCA\Social\Controller\AuthorizedAppsController;
use OCA\Social\Controller\ConversationController;
use OCA\Social\Controller\DirectMessageSettingsController;
use OCA\Social\Controller\DiscoveryController;
use OCA\Social\Controller\FileCommentsController;
use OCA\Social\Controller\FilterController;
use OCA\Social\Controller\InterestsController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\MemoriesController;
use OCA\Social\Controller\MigrationController;
use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\NotificationController;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Controller\PixelfedController;
use OCA\Social\Controller\RelationController;
use OCA\Social\Controller\ReviewController;
use OCA\Social\Controller\ScheduledStatusApiController;
use OCA\Social\Controller\StatisticsController;
use OCA\Social\Controller\TimelineApiController;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The app's pages that are the reader's own — settings, statistics, moving
 * an account, follow requests, blocks, and the timelines under `/timeline/`
 * that hold their notifications, messages, favourites, bookmarks, lists and
 * For you — sent through Nextcloud's own dispatcher, middleware and all, as
 * a browser reaches them.
 *
 * A visitor is sent to Nextcloud's login with the address to come back to,
 * and a signed-in reader gets the page. The pages a visitor may read stay
 * public. And every read those pages make of the reader's own data refuses a
 * caller who is not signed in, so that the page shell was never what kept
 * the data in.
 */
class SignedInPagesTest extends ApiTestCase {
	/**
	 * One request through the dispatcher with its middleware, from a
	 * browser that has been here before (it holds the same-site cookies
	 * Nextcloud sets on a first visit).
	 *
	 * @param class-string $class
	 * @param array<string, string> $route
	 */
	private function dispatch(string $class, string $method, string $path, array $route = [], ?string $as = null, string $accept = 'text/html'): Response {
		$session = Server::get(IUserSession::class);
		// rendering the page reads the request from the server's container
		$serverRequest = Server::get(IRequest::class);
		if ($as !== null) {
			$this->account($as);
			$session->setUser(Server::get(IUserManager::class)->get(self::PREFIX . $as));
		}

		try {
			$request = new Request(
				[
					'get' => [],
					'server' => [
						'REQUEST_METHOD' => 'GET',
						'REQUEST_URI' => '/index.php/apps/social' . $path,
						'SCRIPT_NAME' => '/index.php',
						'HTTP_ACCEPT' => $accept,
					],
					'cookies' => ['nc_sameSiteCookielax' => 'true', 'nc_sameSiteCookiestrict' => 'true'],
					'method' => 'GET',
					'urlParams' => $route,
				],
				Server::get(IRequestId::class),
				Server::get(IConfig::class),
				Server::get(CsrfTokenManager::class),
			);

			\OC::$server->registerService(IRequest::class, static fn (): IRequest => $request);
			$container = new DIContainer('social');
			$container->registerService(IRequest::class, static fn (): IRequest => $request);
			$controller = $container->get($class);
			$this->assertIsObject($controller);
			$dispatcher = $container->get(Dispatcher::class);
			$this->assertInstanceOf(Dispatcher::class, $dispatcher);

			return $dispatcher->dispatch($controller, $method)[4];
		} finally {
			$session->setUser(null);
			\OC::$server->registerService(IRequest::class, static fn (): IRequest => $serverRequest);
		}
	}

	private function loginFor(string $path): string {
		return Server::get(IURLGenerator::class)->linkToRoute('core.login.showLoginForm', [
			'redirect_url' => '/index.php/apps/social' . $path,
		]);
	}

	/** @return iterable<string, array{string, string, array<string, string>}> */
	public static function personalPages(): iterable {
		foreach (['/settings', '/statistics', '/migration', '/switch', '/follow_requests', '/blocked'] as $path) {
			yield $path => ['personal', $path, []];
		}
		foreach (['notifications', 'direct', 'favourites', 'bookmarks', 'list/3', 'interests'] as $timeline) {
			yield '/timeline/' . $timeline => ['timeline', '/timeline/' . $timeline, ['path' => $timeline]];
		}
	}

	/** @param array<string, string> $route */
	#[DataProvider('personalPages')]
	public function testAVisitorIsSentToTheLoginAndBackToThePage(string $method, string $path, array $route): void {
		$response = $this->dispatch(NavigationController::class, $method, $path, $route);

		$this->assertInstanceOf(RedirectResponse::class, $response, $path);
		$this->assertSame($this->loginFor($path), $response->getRedirectURL(), $path);
	}

	/** @param array<string, string> $route */
	#[DataProvider('personalPages')]
	public function testASignedInReaderGetsThePage(string $method, string $path, array $route): void {
		$response = $this->dispatch(NavigationController::class, $method, $path, $route, 'alice');

		$this->assertInstanceOf(TemplateResponse::class, $response, $path);
		$this->assertNotInstanceOf(PublicTemplateResponse::class, $response, $path);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('main', $response->getTemplateName());
	}

	public function testBlueskyOAuthSendsAVisitorToLoginAndPreservesThePushedRequest(): void {
		$parameters = [
			'client_id' => 'https://app.example/client-metadata.json',
			'request_uri' => 'urn:ietf:params:oauth:request_uri:req-123',
		];
		$path = '/oauth/authorize?' . http_build_query($parameters);
		$response = $this->dispatch(OAuthController::class, 'authorize', $path, $parameters);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame($this->loginFor($path), $response->getRedirectURL());
	}

	/** @return iterable<string, array{string, string, array<string, string>}> */
	public static function publicPages(): iterable {
		yield '/' => ['navigate', '/', []];
		yield '/discover' => ['navigate', '/discover', []];
		yield '/shorts' => ['navigate', '/shorts', []];
		yield '/search' => ['navigate', '/search', []];
		yield '/collections/{id}' => ['navigate', '/collections/3', ['id' => '3']];
		yield '/places/{id}' => ['navigate', '/places/3', ['id' => '3']];
		yield '/timeline/timeline' => ['timeline', '/timeline/timeline', ['path' => 'timeline']];
		yield '/timeline/federated' => ['timeline', '/timeline/federated', ['path' => 'federated']];
		yield '/timeline/tags/{tag}' => ['timeline', '/timeline/tags/cats', ['path' => 'tags/cats']];
	}

	/** @param array<string, string> $route */
	#[DataProvider('publicPages')]
	public function testAVisitorStillReadsThePublicPages(string $method, string $path, array $route): void {
		$response = $this->dispatch(NavigationController::class, $method, $path, $route);

		$this->assertInstanceOf(PublicTemplateResponse::class, $response, $path);
		$this->assertSame(200, $response->getStatus());
	}

	/**
	 * What the pages above read of the reader's own, each asked by nobody.
	 *
	 * @return iterable<string, array{class-string, string, array<string, string>}>
	 */
	public static function personalReads(): iterable {
		$reads = [
			// settings
			[AccountApiController::class, 'preferences'],
			[AuthorizedAppsController::class, 'index'],
			[ScheduledStatusApiController::class, 'scheduledStatuses'],
			[ReviewController::class, 'held'],
			[DiscoveryController::class, 'featuredTags'],
			[DiscoveryController::class, 'featuredTagSuggestions'],
			[FilterController::class, 'counts'],
			[DirectMessageSettingsController::class, 'directMessages'],
			[FileCommentsController::class, 'filesComments'],
			[NotificationController::class, 'allowed'],
			[NotificationController::class, 'policy'],
			[ListController::class, 'index'],
			[MemoriesController::class, 'recap'],
			[PixelfedController::class, 'archiveList'],
			[PixelfedController::class, 'collectionsSelf'],
			[AtprotoAccountController::class, 'identity'],
			[AtprotoAccountController::class, 'appPasswords'],
			[AtprotoAccountController::class, 'oauthSessions'],
			[AtprotoAccountController::class, 'labelers'],
			// statistics
			[StatisticsController::class, 'statistics'],
			[StatisticsController::class, 'export'],
			// migration and moving in
			[MigrationController::class, 'export'],
			[MigrationController::class, 'imports'],
			[MigrationController::class, 'aliases'],
			[MigrationController::class, 'moveStatus'],
			[MigrationController::class, 'moveInFinishStatus'],
			[MigrationController::class, 'switchAnnouncement'],
			// follow requests and blocks
			[AccountApiController::class, 'followRequests'],
			[AccountApiController::class, 'blocks'],
			[AccountApiController::class, 'mutes'],
			[RelationController::class, 'domainBlocks'],
			[FilterController::class, 'index'],
			// the reader's own timelines
			[TimelineApiController::class, 'notifications'],
			[NotificationController::class, 'indexV2'],
			[ConversationController::class, 'index'],
			[TimelineApiController::class, 'favourites'],
			[TimelineApiController::class, 'bookmarks'],
			[InterestsController::class, 'timeline'],
		];
		foreach ($reads as [$class, $method]) {
			yield substr($class, strrpos($class, '\\') + 1) . '::' . $method => [$class, $method, []];
		}
		yield 'TimelineApiController::timelines home' => [TimelineApiController::class, 'timelines', ['timeline' => 'home']];
		yield 'ListController::timeline' => [ListController::class, 'timeline', ['id' => '3']];
	}

	/** @param array<string, string> $route */
	#[DataProvider('personalReads')]
	public function testWhatThosePagesReadRefusesSomebodyNotSignedIn(string $class, string $method, array $route): void {
		$response = $this->dispatch($class, $method, '/api/test', $route, null, 'application/json');

		$this->assertContains($response->getStatus(), [401, 403], $class . '::' . $method . ' answered somebody not signed in');
	}
}
