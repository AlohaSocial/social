<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use OCA\Social\Controller\AdminApiControllerBase;
use OCA\Social\Controller\ClientApiController;
use OCA\Social\Controller\MastodonApiController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Who may reach each route, read from the attributes the server reads and
 * from the scope each route asks a token for.
 *
 * Nextcloud lets a `#[PublicPage]` route answer anybody and a
 * `#[NoCSRFRequired]` one be driven from another site, so each of them has
 * to authenticate the caller in code. The three API base classes do that the
 * same way for every route they carry: a bearer token, or a session that
 * passes its CSRF check. Everything else that waives either check is named
 * below with the reason it may, so a new public or CSRF-exempt route outside
 * those bases fails here until somebody has said why it is safe. So is every
 * API route that refuses bearer tokens and takes the web app's session only.
 */
class RouteAuthMatrixTest extends TestCase {
	/** @var list<class-string> the bases that authenticate every route in code */
	private const API_BASES = [
		MastodonApiController::class,
		ClientApiController::class,
		AdminApiControllerBase::class,
	];

	/** @var array<string, string> public routes outside the API bases, and why */
	private const PUBLIC_OUTSIDE_API = [
		'ActivityPubController::actor' => 'ActivityPub actor document, public by protocol',
		'ActivityPubController::feed' => 'RSS of public posts',
		'ActivityPubController::actorAlias' => 'ActivityPub actor document under its @-alias',
		'ActivityPubController::sharedInbox' => 'federation delivery, authenticated by HTTP signature',
		'ActivityPubController::inbox' => 'federation delivery, authenticated by HTTP signature',
		'ActivityPubController::getInbox' => 'an empty collection; inboxes are not readable',
		'ActivityPubController::outbox' => 'public posts only, signed fetch enforced by assertReadable()',
		'ActivityPubController::featured' => 'pinned public posts, by protocol',
		'ActivityPubController::followers' => 'follower collection, honours the hide-network setting',
		'ActivityPubController::following' => 'following collection, honours the hide-network setting',
		'ActivityPubController::displayQuoteAuthorization' => 'FEP-044f stamp a peer verifies',
		'ActivityPubController::displayReplyAuthorization' => 'GoToSocial reply grant is an HMAC-bound public protocol document',
		'ActivityPubController::instanceActor' => 'the instance actor, needed to verify its signed fetches',
		'ActivityPubController::replies' => 'replies collection of a post, filtered to public',
		'ActivityPubController::likes' => 'likes collection of a post, by protocol',
		'ActivityPubController::dislikes' => 'dislikes collection of a post, by protocol',
		'ActivityPubController::story' => 'a story object, checked against the signer',
		'AtprotoOAuthController::protectedResource' => 'OAuth discovery document',
		'AtprotoOAuthController::par' => 'OAuth pushed authorization request, client authenticates in the body',
		'AtprotoOAuthController::parPreflight' => 'CORS preflight, answers headers only',
		'AtprotoOAuthController::tokenPreflight' => 'CORS preflight, answers headers only',
		'AtprotoOAuthController::revokePreflight' => 'CORS preflight, answers headers only',
		'OAuthController::authorize' => 'Mastodon and AT Protocol authorization, returns guests to Nextcloud login before showing consent',
		'AtprotoRecoveryController::recoveryTool' => 'the offline recovery tool, a static page holding nothing of anybody',
		'AtprotoRecoveryController::downloadRecoveryTool' => 'the offline recovery tool as a file, a static page holding nothing of anybody',
		'AtprotoWellKnownController::atprotoDid' => 'atproto handle resolution, public by protocol',
		'AtprotoWellKnownController::didJson' => 'did:web document, public by protocol',
		'AtprotoXrpcController::query' => 'XRPC, authenticated by its own access token',
		'AtprotoXrpcController::procedure' => 'XRPC, authenticated by its own access token',
		'AtprotoXrpcController::preflight' => 'CORS preflight, answers headers only',
		'AvatarController::avatar' => 'the avatar of a local actor, by protocol; a visitor gets initials where it is not published',
		'ConfigController::local' => 'version and setup flag',
		'ConfigController::remote' => 'WebFinger self-test, inert unless the social.tests system value is set',
		'LocalController::accountInfo' => 'public profile of a local account',
		'LocalController::globalAccountInfo' => 'public profile of any account, rate limited',
		'LocalController::globalActorAvatar' => 'cached avatar bytes',
		'NavigationController::accountDeleted' => 'static page after account deletion',
		'NavigationController::navigate' => 'the single-page app shell; data comes from guarded routes',
		'NavigationController::timeline' => 'the single-page app shell; data comes from guarded routes',
		'NavigationController::documentGetPublic' => 'cached media of public posts',
		'NavigationController::resizedGetPublic' => 'cached media of public posts, resized',
		'OAuthController::nodeinfo2' => 'NodeInfo, public by protocol',
		'OAuthController::nodeinfo21' => 'NodeInfo, public by protocol',
		'OAuthController::apps' => 'Mastodon app registration, open by protocol',
		'OAuthController::deviceAuthorization' => 'OAuth device authorization, client ID checked and endpoint rate limited',
		'OAuthController::token' => 'OAuth token endpoint, client authenticates in the body',
		'OAuthController::revoke' => 'OAuth revocation, client authenticates in the body',
		'OAuthController::oauthMetadata' => 'OAuth discovery document',
		'OAuthController::userinfo' => 'OpenID userinfo, authenticated by the bearer token itself',
		'OStatusController::followRemote' => 'remote-follow landing page',
		'OStatusController::getLink' => 'remote-follow address lookup, rate limited',
		'QueueController::asyncForRequest' => 'the server calling itself, authenticated by AsyncRequestSigner',
		'SignupController::page' => 'signup form',
		'SignupController::submit' => 'signup form submission, CSRF checked',
		'SignupController::verify' => 'email verification link',
		'SocialPubController::collections' => 'public collections page',
		'SocialPubController::portfolio' => 'public portfolio page',
		'SocialPubController::embedPost' => 'public post embed, limited to local public posts and an isolated CSP',
	];

	/** @var array<string, string> CSRF-exempt state-changing routes outside the API bases, and why */
	private const CSRF_EXEMPT_WRITES = [
		'ActivityPubController::sharedInbox' => 'server-to-server, HTTP signature instead of a cookie',
		'ActivityPubController::inbox' => 'server-to-server, HTTP signature instead of a cookie',
		'ActivityPubController::outbox' => 'a POST answers the same read-only collection as the GET',
		'AtprotoOAuthController::par' => 'OAuth, no cookie involved',
		'AtprotoOAuthController::parPreflight' => 'CORS preflight, changes nothing',
		'AtprotoOAuthController::tokenPreflight' => 'CORS preflight, changes nothing',
		'AtprotoOAuthController::revokePreflight' => 'CORS preflight, changes nothing',
		'AtprotoXrpcController::procedure' => 'bearer access token, no cookie involved',
		'AtprotoXrpcController::preflight' => 'CORS preflight, changes nothing',
		'OAuthController::apps' => 'OAuth client registration, no cookie involved',
		'OAuthController::deviceAuthorization' => 'OAuth device authorization, client ID checked and endpoint rate limited',
		'OAuthController::token' => 'OAuth, no cookie involved',
		'OAuthController::revoke' => 'OAuth, no cookie involved',
		'QueueController::asyncForRequest' => 'signed by AsyncRequestSigner, no cookie involved',
	];

	/**
	 * @var array<string, string> client-API routes that ask no token for any
	 *                            scope, and why they may
	 */
	private const CLIENT_ROUTES_WITHOUT_SCOPE = [
		'AnnouncementController::adminIndex' => 'admin page route, the server checks the admin setting',
		'AnnouncementController::adminCreate' => 'admin page route, the server checks the admin setting',
		'AnnouncementController::adminDelete' => 'admin page route, the server checks the admin setting',
		'MigrationController::moveInFinishCallback' => 'session route the browser lands on after the old server\'s consent page',
		'MigrationController::moveStatus' => 'session route, the server requires a Nextcloud login',
		'MigrationController::moveOut' => 'session route behind Nextcloud\'s password confirmation, which a token cannot give',
		'MigrationController::undoMove' => 'session route behind Nextcloud\'s password confirmation, which a token cannot give',
		'MigrationController::switchPeople' => 'switch wizard session route, the server requires a Nextcloud login',
		'MigrationController::switchFind' => 'switch wizard session route, the server requires a Nextcloud login',
		'MigrationController::switchAnnouncement' => 'switch wizard session route, the server requires a Nextcloud login',
		'MigrationController::aliases' => 'session route, the server requires a Nextcloud login',
		'MigrationController::aliasAdd' => 'session route, the server requires a Nextcloud login',
		'MigrationController::aliasRemove' => 'session route, the server requires a Nextcloud login',
		'DiscoveryController::starterPacks' => 'public starter packs, no viewer involved',
		'DiscoveryController::starterPack' => 'one public starter pack, no viewer involved',
		'PeerTubeApiController::config' => 'PeerTube server configuration, public by protocol',
		'PeerTubeApiController::oauthClient' => 'PeerTube client bootstrap, public by protocol',
		'PeerTubeApiController::channels' => 'public channel directory',
		'PeerTubeApiController::channelByHandle' => 'one public channel',
		'PixelfedController::config' => 'Pixelfed instance configuration, public by protocol',
		'PixelfedController::discoverPostsTrending' => 'public trending posts',
		'PixelfedController::discoverNetworkTrending' => 'public trending posts across the network',
		'PixelfedController::portfolioOf' => 'public portfolio',
		'PixelfedController::discoverCategories' => 'public category list',
	];

	/** @var array<string, string> client-API routes any valid token may call, and why */
	private const CLIENT_ROUTES_FOR_ANY_TOKEN = [
		'AnnouncementController::index' => 'Mastodon asks no scope: any user token may read instance announcements',
	];

	/** @var array<string, string> client-API POST routes that only read, and why */
	private const CLIENT_READS_BY_POST = [
		'PixelfedController::pushCompare' => 'Pixelfed\'s push comparison answers a fixed state and stores nothing',
	];

	/**
	 * @var array<string, string> admin API routes Mastodon defines as POST
	 *                            that only read, behind `admin:read`
	 */
	private const ADMIN_READS_BY_POST = [
		'AdminApiController::measures' => 'Mastodon POST /api/v1/admin/measures is a query',
		'AdminApiController::dimensions' => 'Mastodon POST /api/v1/admin/dimensions is a query',
		'AdminApiController::retention' => 'Mastodon POST /api/v1/admin/retention is a query',
	];

	/**
	 * @var array<string, string> API routes for the web app's signed-in
	 *                            session only, refusing every bearer token
	 *                            through `requireWebSession()`, and why
	 */
	private const WEB_SESSION_ONLY = [
		'MediaApiController::mediaFromFile' => 'copies any of the user\'s own files, more than a client allowed to upload media was granted',
		'CalendarEventController::importStatusCalendar' => 'writes a remote event to the user\'s Nextcloud calendar',
	];

	/** @var array<string, string> the broad scopes that permit a state-changing client-API route */
	private const WRITE_SCOPES = ['write' => 'write', 'follow' => 'follow', 'push' => 'push'];

	/**
	 * A public route answers whoever asks, so it has to establish the caller
	 * in code. The API bases do; any other class has to be named here.
	 */
	public function testEveryPublicRouteIsAnApiRouteOrNamedWithAReason(): void {
		$unexplained = [];
		$public = [];
		$checked = 0;

		foreach (self::routes() as [$method, $verb]) {
			if ($method->getAttributes(PublicPage::class) === []) {
				continue;
			}

			$checked++;
			if (self::onApiBase($method)) {
				continue;
			}

			$key = self::key($method);
			$public[$key] = true;
			if (!isset(self::PUBLIC_OUTSIDE_API[$key])) {
				$unexplained[] = $key . ' (' . $verb . ')';
			}
		}

		$this->assertGreaterThan(400, $checked, 'the route walk found the public routes');
		$this->assertSame([], array_values(array_unique($unexplained)), 'public routes outside the API bases with no stated reason');
		$this->assertSame(
			[], array_keys(array_diff_key(self::PUBLIC_OUTSIDE_API, $public)),
			'allowlisted routes that are no longer public: drop them from the list'
		);
	}

	/**
	 * A state-changing route without the CSRF check can be fired by any page
	 * the user visits while signed in. The API bases accept a session only
	 * when it passes the CSRF check itself, so on them the waiver only lets
	 * bearer tokens through; anywhere else it has to be named.
	 */
	public function testEveryCsrfExemptWriteIsBearerOnlyOrNamedWithAReason(): void {
		$unexplained = [];
		$exempt = [];
		$checked = 0;

		foreach (self::routes() as [$method, $verb]) {
			if ($verb === 'GET' || $method->getAttributes(NoCSRFRequired::class) === []) {
				continue;
			}

			$checked++;
			if (self::onApiBase($method)) {
				continue;
			}

			$key = self::key($method);
			$exempt[$key] = true;
			if (!isset(self::CSRF_EXEMPT_WRITES[$key])) {
				$unexplained[] = $key . ' (' . $verb . ')';
			}
		}

		$this->assertGreaterThan(150, $checked, 'the route walk found the CSRF-exempt writes');
		$this->assertSame([], $unexplained, 'CSRF-exempt state-changing routes with no stated reason');
		$this->assertSame(
			[], array_keys(array_diff_key(self::CSRF_EXEMPT_WRITES, $exempt)),
			'allowlisted routes that are no longer CSRF-exempt writes: drop them from the list'
		);
	}

	/**
	 * Administration pages are guarded by the server: a route with neither
	 * `#[NoAdminRequired]` nor `#[PublicPage]` is refused to anybody who is
	 * not an administrator before the controller runs. Either attribute on an
	 * admin route hands that check to the controller, and only the admin API
	 * base does it there.
	 */
	public function testAdminPagesLeaveTheAdminCheckToTheServer(): void {
		$opened = [];
		$checked = 0;

		foreach (self::routes() as [$method, $verb, $url]) {
			if (self::onApiBase($method) || !self::isAdminRoute($method, $url)) {
				continue;
			}

			$checked++;
			if ($method->getAttributes(NoAdminRequired::class) !== []
				|| $method->getAttributes(PublicPage::class) !== []) {
				$opened[] = self::key($method) . ' ' . $verb . ' ' . $url;
			}
		}

		$this->assertGreaterThan(40, $checked, 'the route walk found the admin pages');
		$this->assertSame([], $opened, 'admin routes that skip the server\'s admin check');
	}

	/**
	 * The admin API is public so a bearer token can reach it, which makes
	 * `initAdmin()` the only thing between a token and the instance. Every
	 * route has to call it, with a write scope where it changes something.
	 */
	public function testEveryAdminApiRouteChecksForAnAdministratorWithTheRightScope(): void {
		$problems = [];
		$checked = 0;

		foreach (self::routes() as [$method, $verb]) {
			if (!$method->getDeclaringClass()->isSubclassOf(AdminApiControllerBase::class)) {
				continue;
			}

			$checked++;
			$key = self::key($method);
			$sets = self::scopeSets($method, ['initAdmin' => 0], ['admin:read']);
			if ($sets === []) {
				$problems[] = $key . ' never calls initAdmin()';
				continue;
			}

			foreach ($sets as $scopes) {
				foreach ($scopes as $scope) {
					$wanted = ($verb === 'GET' || isset(self::ADMIN_READS_BY_POST[$key])) ? 'admin:read' : 'admin:write';
					if (!str_starts_with($scope, $wanted)) {
						$problems[] = $key . ' is a ' . $verb . ' route asking for ' . $scope;
					}
				}
			}
		}

		$this->assertGreaterThan(50, $checked, 'the route walk found the admin API');
		$this->assertSame([], array_values(array_unique($problems)));
	}

	/**
	 * The scope a client-API route asks for is the literal it hands
	 * `initViewer()` or `optionalViewer()`, so it is read from the source:
	 * a state-changing route has to need a write, follow or push scope, and a
	 * route that names no scope at all has to be listed with a reason.
	 * `initViewer([])` would let any valid token through and is refused
	 * outright.
	 */
	public function testEveryClientApiRouteAsksForAScopeThatMatchesWhatItDoes(): void {
		$problems = [];
		$scopeless = [];
		$anyToken = [];
		$checked = 0;

		foreach (self::clientRoutes() as $key => [$method, $verbs]) {
			$checked++;
			$sets = self::scopeSets($method, ['initViewer' => 0, 'optionalViewer' => 0]);
			if ($sets === []) {
				$scopeless[$key] = true;
				if (!isset(self::CLIENT_ROUTES_WITHOUT_SCOPE[$key])) {
					$problems[] = $key . ' asks a token for no scope at all';
				}
				continue;
			}

			$writes = array_filter($sets, static fn (array $scopes): bool => self::allWrite($scopes));
			foreach ($sets as $scopes) {
				if ($scopes === []) {
					$anyToken[$key] = true;
					if (!isset(self::CLIENT_ROUTES_FOR_ANY_TOKEN[$key])) {
						$problems[] = $key . ' accepts a token with any scope';
					}
				}
			}

			$changes = array_diff($verbs, ['GET']);
			if ($changes === [] || isset(self::CLIENT_READS_BY_POST[$key])) {
				continue;
			}

			if (count($changes) === count($verbs)) {
				if (count($writes) !== count($sets)) {
					$problems[] = $key . ' (' . implode(',', $verbs) . ') is satisfied by a read scope';
				}
			} elseif ($writes === []) {
				// one method serving GET and a write: the write branch needs its own scope
				$problems[] = $key . ' (' . implode(',', $verbs) . ') has no write branch';
			}
		}

		$this->assertGreaterThan(150, $checked, 'the route walk found the client API');
		$this->assertSame([], $problems);
		$this->assertSame(
			[], array_keys(array_diff_key(self::CLIENT_ROUTES_WITHOUT_SCOPE, $scopeless)),
			'allowlisted routes that now ask for a scope: drop them from the list'
		);
		$this->assertSame(
			[], array_keys(array_diff_key(self::CLIENT_ROUTES_FOR_ANY_TOKEN, $anyToken)),
			'allowlisted routes that now ask for a scope: drop them from the list'
		);
	}

	/**
	 * A route a bearer token may not use at all says so with
	 * `requireWebSession()`, and is named here with the reason: a new one
	 * fails until somebody has said why, and a listed one that stops calling
	 * it fails too.
	 */
	public function testEveryWebSessionOnlyRouteIsNamedAndRefusesBearerTokens(): void {
		$found = [];
		foreach (self::routes() as [$method]) {
			if (!self::onApiBase($method)) {
				continue;
			}

			foreach (self::thisCalls(self::classMethods($method->getDeclaringClass())[$method->getName()]) as $call) {
				if ($call->name->toString() === 'requireWebSession') {
					$found[self::key($method)] = true;
				}
			}
		}

		ksort($found);
		$listed = self::WEB_SESSION_ONLY;
		ksort($listed);
		$this->assertSame(array_keys($listed), array_keys($found), 'routes refusing bearer tokens, against the list of them');
	}

	/**
	 * The walk itself: a route that calls a private helper which in turn
	 * forwards its argument to `initViewer()` is read at the call site.
	 */
	public function testTheScopeWalkFollowsHelpersThatForwardTheScope(): void {
		$resetInterests = new ReflectionMethod('OCA\\Social\\Controller\\InterestsController', 'reset');
		$accept = new ReflectionMethod('OCA\\Social\\Controller\\NotificationController', 'requestAccept');
		$settings = new ReflectionMethod('OCA\\Social\\Controller\\PixelfedController', 'appSettings');

		$takers = ['initViewer' => 0, 'optionalViewer' => 0];
		$this->assertSame([['write:accounts']], self::scopeSets($resetInterests, $takers));
		$this->assertSame([['write:notifications']], self::scopeSets($accept, $takers));
		$this->assertSame([['write:accounts'], ['read']], self::scopeSets($settings, $takers));
	}

	/**
	 * Through two helpers, and through every arm of a `match`: an import
	 * reaches `initViewer()` by way of `queueUpload()` and `caller()`, and a
	 * CSV export asks for the scope of the list it names.
	 */
	public function testTheScopeWalkReadsTheMigrationHelpersAndEveryArmOfAMatch(): void {
		$takers = ['initViewer' => 0, 'optionalViewer' => 0];
		$migration = 'OCA\\Social\\Controller\\MigrationController';

		$this->assertSame([['write']], self::scopeSets(new ReflectionMethod($migration, 'importFollows'), $takers));
		$this->assertSame([['read']], self::scopeSets(new ReflectionMethod($migration, 'export'), $takers));
		$this->assertSame(
			[['read:follows'], ['read:blocks'], ['read:mutes'], ['read:lists'], ['read:bookmarks'], ['read']],
			self::scopeSets(new ReflectionMethod($migration, 'exportCsv'), $takers)
		);
	}

	/** @param list<string> $scopes */
	private static function allWrite(array $scopes): bool {
		if ($scopes === []) {
			return false;
		}

		foreach ($scopes as $scope) {
			$broad = strstr($scope, ':', true);
			if (!isset(self::WRITE_SCOPES[($broad === false) ? $scope : $broad])) {
				return false;
			}
		}

		return true;
	}

	private static function onApiBase(ReflectionMethod $method): bool {
		foreach (self::API_BASES as $base) {
			if ($method->getDeclaringClass()->isSubclassOf($base)) {
				return true;
			}
		}

		return false;
	}

	private static function isAdminRoute(ReflectionMethod $method, string $url): bool {
		return str_starts_with($url, '/admin/')
			|| str_starts_with($url, '/moderation/')
			|| str_contains($method->getDeclaringClass()->getShortName(), 'Admin');
	}

	private static function key(ReflectionMethod $method): string {
		return $method->getDeclaringClass()->getShortName() . '::' . $method->getName();
	}

	/**
	 * Every route of every controller in `lib/Controller`, one entry per
	 * route attribute, found the way `OC\Route\Router` finds them.
	 *
	 * @return list<array{ReflectionMethod, string, string}> method, verb, url
	 */
	private static function routes(): array {
		$routes = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = new ReflectionClass('OCA\\Social\\Controller\\' . basename($file, '.php'));
			foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class->getName()) {
					continue;
				}

				foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
					$route = $attribute->newInstance();
					$routes[] = [$method, strtoupper($route->getVerb()), $route->getUrl()];
				}
			}
		}

		return $routes;
	}

	/**
	 * The client-API routes, each method once with all the verbs it serves.
	 *
	 * @return array<string, array{ReflectionMethod, list<string>}>
	 */
	private static function clientRoutes(): array {
		$routes = [];
		foreach (self::routes() as [$method, $verb]) {
			if (!$method->getDeclaringClass()->isSubclassOf(ClientApiController::class)) {
				continue;
			}

			$key = self::key($method);
			$routes[$key] ??= [$method, []];
			$routes[$key][1][] = $verb;
		}

		return $routes;
	}

	/**
	 * The scope lists a route can hand to one of the scope-taking methods,
	 * read from its source and from the private helpers it calls. A helper
	 * that passes one of its own parameters straight to a scope-taking
	 * method becomes one itself, read at its callers. A ternary yields both
	 * of its branches.
	 *
	 * @param array<string, int> $takers method name => position of the scope argument
	 * @param list<string>|null $default what a taker called without the argument asks for
	 *
	 * @return list<list<string>>
	 */
	private static function scopeSets(ReflectionMethod $route, array $takers, ?array $default = null): array {
		$methods = self::classMethods($route->getDeclaringClass());
		$takers += self::forwardingHelpers($methods, $takers);

		$sets = [];
		$visited = [];
		$walk = static function (string $name) use (&$walk, &$sets, &$visited, $methods, $takers, $default): void {
			if (isset($visited[$name]) || !isset($methods[$name])) {
				return;
			}
			$visited[$name] = true;

			foreach (self::thisCalls($methods[$name]) as $call) {
				$called = $call->name->toString();
				if (isset($takers[$called])) {
					$argument = $call->args[$takers[$called]] ?? null;
					if ($argument instanceof Node\Arg) {
						array_push($sets, ...self::literalSets($argument->value, $name));
					} elseif ($default !== null) {
						$sets[] = $default;
					}
					continue;
				}

				if (isset($methods[$called]) && !$methods[$called]->isPublic()) {
					$walk($called);
				}
			}
		};
		$walk($route->getName());

		return $sets;
	}

	/**
	 * @param array<string, ClassMethod> $methods
	 * @param array<string, int> $takers
	 *
	 * @return array<string, int>
	 */
	private static function forwardingHelpers(array $methods, array $takers): array {
		$helpers = [];
		foreach ($methods as $name => $method) {
			if ($method->isPublic()) {
				continue;
			}

			$parameters = array_map(
				static fn (Node\Param $param): string => $param->var instanceof Node\Expr\Variable ? (string)$param->var->name : '',
				$method->params
			);
			foreach (self::thisCalls($method) as $call) {
				$argument = $call->args[$takers[$call->name->toString()] ?? -1] ?? null;
				if ($argument instanceof Node\Arg && $argument->value instanceof Node\Expr\Variable) {
					$position = array_search($argument->value->name, $parameters, true);
					if ($position !== false) {
						$helpers[$name] = $position;
					}
				}
			}
		}

		return $helpers;
	}

	/** @return list<list<string>> */
	private static function literalSets(Node\Expr $expression, string $where): array {
		if ($expression instanceof Node\Expr\Ternary && $expression->if !== null) {
			return array_merge(self::literalSets($expression->if, $where), self::literalSets($expression->else, $where));
		}

		if (!$expression instanceof Node\Expr\Array_) {
			self::fail($where . '() passes a scope argument that is not a literal list');
		}

		$scopes = [];
		foreach ($expression->items as $item) {
			$value = $item?->value;
			if ($value instanceof Node\Scalar\String_) {
				$scopes[] = $value->value;
			} elseif ($value instanceof Node\Expr\ClassConstFetch
				&& $value->class instanceof Node\Name
				&& $value->name instanceof Node\Identifier) {
				$scopes[] = (string)constant($value->class->toString() . '::' . $value->name->toString());
			} else {
				self::fail($where . '() passes a scope that is neither a string nor a class constant');
			}
		}

		return [$scopes];
	}

	/** @return list<MethodCall> calls on `$this` by a plain name */
	private static function thisCalls(ClassMethod $method): array {
		/** @var list<MethodCall> */
		return (new NodeFinder())->find(
			$method->stmts ?? [],
			static fn (Node $node): bool => $node instanceof MethodCall
				&& $node->var instanceof Node\Expr\Variable
				&& $node->var->name === 'this'
				&& $node->name instanceof Node\Identifier
		);
	}

	/** @return array<string, ClassMethod> */
	private static function classMethods(ReflectionClass $class): array {
		static $parsed = [];

		$file = (string)$class->getFileName();
		if (!isset($parsed[$file])) {
			$traverser = new NodeTraverser(new NameResolver());
			$ast = $traverser->traverse(
				(new ParserFactory())->createForHostVersion()->parse((string)file_get_contents($file)) ?? []
			);
			$parsed[$file] = [];
			foreach ((new NodeFinder())->findInstanceOf($ast, ClassMethod::class) as $method) {
				$parsed[$file][$method->name->toString()] = $method;
			}
		}

		return $parsed[$file];
	}
}
