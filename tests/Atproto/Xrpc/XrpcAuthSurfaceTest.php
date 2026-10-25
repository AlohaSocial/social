<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Xrpc;

use OCA\Social\Atproto\Client\AppViewProxy;
use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Client\NotificationSettings;
use OCA\Social\Atproto\Client\PlcSignatureService;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Client\ServiceAuthGrant;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Publisher\BlueskyBookmarks;
use OCA\Social\Atproto\Publisher\BlueskyMutes;
use OCA\Social\Atproto\Publisher\BlueskyThreadMutes;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Controller\AtprotoXrpcController;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The route walk of the XRPC surface: every method Bluesky defines
 * (tests/Atproto/Xrpc/upstream-methods.txt, from the lexicons the app is
 * pinned to) and a few it does not, sent through the controller with no
 * credentials at all. Each one either asks to be signed in, says it is not
 * served here, or is on the public list below with the reason it may
 * answer anybody. A method that starts answering anonymously without
 * being put on that list fails here.
 *
 * The credentials themselves are checked where they are made: app
 * password sessions in SessionService, OAuth tokens and DPoP proofs in
 * AuthorizationServer and DpopVerifier, service-auth tokens in
 * ServiceAuth, ServiceAuthGrant and InboundMoveService. Here those
 * checkers refuse everything, and what is pinned is that nothing outside
 * the list reaches an answer without passing through one of them.
 */
#[AllowMockObjectsWithoutExpectations]
class XrpcAuthSurfaceTest extends TestCase {
	/**
	 * What answers without credentials, and why it may.
	 */
	private const PUBLIC = [
		'_health' => 'the liveness probe, naming the version only',
		'com.atproto.server.describeServer' => 'the server description every client reads before signing in',
		'com.atproto.identity.resolveHandle' => 'handle resolution is public by design',
		'com.atproto.repo.describeRepo' => 'repository reads are public: the repository holds public posts only (§16.5)',
		'com.atproto.repo.getRecord' => 'as describeRepo',
		'com.atproto.repo.listRecords' => 'as describeRepo',
		'com.atproto.sync.getRepo' => 'the sync surface a relay crawls without an account',
		'com.atproto.sync.getLatestCommit' => 'as getRepo',
		'com.atproto.sync.getRepoStatus' => 'as getRepo',
		'com.atproto.sync.getRecord' => 'as getRepo',
		'com.atproto.sync.getBlocks' => 'as getRepo',
		'com.atproto.sync.getBlob' => 'as getRepo: blobs of public records',
		'com.atproto.sync.listBlobs' => 'as getRepo',
		'com.atproto.sync.listRepos' => 'as getRepo',
		'com.atproto.sync.subscribeRepos' => 'the firehose; over plain HTTP it only asks for the WebSocket upgrade',
		'com.atproto.server.createSession' => 'the sign-in: the app password in the body is the credential (SessionService::create)',
		'com.atproto.server.refreshSession' => 'the refresh token in Authorization is the credential (SessionService::refresh)',
		'com.atproto.server.deleteSession' => 'as refreshSession (SessionService::delete)',
		'com.atproto.server.createAccount' => 'a move here: the invite code and a service-auth token the DID signed are the credentials (InboundMoveService::createAccount)',
	];

	/** what a signed-in Bluesky app is served here, so must be refused for want of a sign-in rather than as unknown */
	private const SIGNED_IN = [
		'com.atproto.server.getSession', 'com.atproto.server.getServiceAuth',
		'com.atproto.server.createAppPassword', 'com.atproto.server.listAppPasswords', 'com.atproto.server.revokeAppPassword',
		'com.atproto.repo.createRecord', 'com.atproto.repo.putRecord', 'com.atproto.repo.deleteRecord', 'com.atproto.repo.applyWrites',
		'com.atproto.repo.uploadBlob', 'com.atproto.repo.importRepo', 'com.atproto.moderation.createReport',
		'app.bsky.actor.getPreferences', 'app.bsky.actor.putPreferences', 'app.bsky.feed.getTimeline', 'app.bsky.graph.muteActor',
		'app.bsky.graph.muteThread', 'app.bsky.graph.unmuteThread',
		'chat.bsky.convo.listConvos', 'chat.bsky.convo.sendMessage',
		'com.atproto.identity.requestPlcOperationSignature', 'com.atproto.identity.signPlcOperation',
	];

	private bool $enabled = true;
	/** @var array<string, string> */
	private array $headers = [];
	private AtprotoXrpcController $controller;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$config->method('serviceDid')->willReturn('did:web:social.example.com');
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$config->method('pdsEndpoint')->willReturn('https://social.example.com');

		$sessions = $this->createMock(SessionService::class);
		$sessions->method('authenticate')->willReturn(null);
		$oauth = $this->createMock(AuthorizationServer::class);
		$oauth->method('authenticate')->willThrowException(new OAuthException('invalid_token', 'Not a token', 401));
		$oauth->method('issuer')->willReturn('https://social.example.com');
		$grants = $this->createMock(ServiceAuthGrant::class);
		$grants->method('uploader')->willReturn(null);
		$inbound = $this->createMock(InboundMoveService::class);
		$inbound->method('owns')->willReturn(false);
		$client = new ClientXrpc(
			$config, $sessions, $this->createMock(AppViewProxy::class), $this->createMock(Preferences::class),
			$this->createMock(WriteService::class), $grants, $oauth, $this->createMock(ModerationService::class),
			$inbound, $this->createMock(BlueskyMutes::class), $this->createMock(BlueskyThreadMutes::class), $this->createMock(BlueskyBookmarks::class),
			$this->createMock(NotificationSettings::class), $this->createMock(PlcSignatureService::class),
		);

		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willThrowException(new AtprotoIdentityNotFoundException());
		$identities->method('getByHandle')->willThrowException(new AtprotoIdentityNotFoundException());
		$xrpc = new XrpcService(
			$config, $identities, $this->createMock(RepositoryService::class), $this->createMock(AtprotoRepoRequest::class),
			$this->createMock(AtprotoBlobRequest::class), $this->createMock(PictureService::class), $this->createMock(VideoBlobService::class),
			$this->createMock(ConfigService::class), $this->createMock(ModerationService::class), $this->createMock(AtprotoIdentityRequest::class),
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getRemoteAddress')->willReturn('192.0.2.1');
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[strtolower($name)] ?? '');
		$nonce = $this->createMock(DpopNonce::class);
		$nonce->method('current')->willReturn('nonce');

		$this->controller = new AtprotoXrpcController($request, $xrpc, $client, $nonce, $this->createMock(LoggerInterface::class));
		$_SERVER['QUERY_STRING'] = '';
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		unset($_SERVER['QUERY_STRING']);
		\OC::$server->reset();
	}

	public function testNothingButThePublicListAnswersWithoutCredentials(): void {
		$answered = [];
		$unexpected = [];
		foreach (self::methods() as $method => $kind) {
			$response = $this->call($method, $kind);
			$status = $response->getStatus();
			if ($status === 401) {
				$this->assertSame('AuthenticationRequired', $response->getData()['error'] ?? null, $method);
				continue;
			}
			if ($status === 501) {
				$this->assertSame('MethodNotImplemented', $response->getData()['error'] ?? null, $method);
				continue;
			}
			$answered[] = $method;
			if (!isset(self::PUBLIC[$method])) {
				$unexpected[] = $method . ' (' . $status . ')';
			}
		}

		$this->assertSame([], $unexpected, 'answered without credentials, and not on the public list');
		sort($answered);
		$public = array_keys(self::PUBLIC);
		sort($public);
		$this->assertSame($public, $answered, 'every public method is served');
	}

	public function testWhatASignedInAppIsServedAsksForTheSignIn(): void {
		foreach (self::SIGNED_IN as $method) {
			$response = $this->call($method, self::methods()[$method]);

			$this->assertSame(401, $response->getStatus(), $method);
			$this->assertSame('AuthenticationRequired', $response->getData()['error'], $method);
		}
	}

	/**
	 * A credential that is not one is no better than none, whichever
	 * scheme it claims; a DPoP-bound token refused carries a fresh nonce.
	 */
	public function testABogusCredentialIsRefusedLikeNone(): void {
		foreach (['Bearer not-a-token', 'Bearer a.b.c', 'Basic YWxpY2U6c2VjcmV0', 'DPoP forged'] as $authorization) {
			$this->headers = ['authorization' => $authorization, 'dpop' => str_starts_with($authorization, 'DPoP') ? 'x.y.z' : ''];
			foreach (['com.atproto.server.getSession' => 'query', 'com.atproto.repo.createRecord' => 'procedure', 'app.bsky.feed.getTimeline' => 'query'] as $method => $kind) {
				$response = $this->call($method, $kind);

				$this->assertSame(401, $response->getStatus(), $authorization . ' ' . $method);
				if (str_starts_with($authorization, 'DPoP')) {
					$this->assertSame('nonce', $response->getHeaders()['DPoP-Nonce'] ?? null);
				}
			}
		}
	}

	/**
	 * With Bluesky off, the instance is no PDS: every method says it is
	 * not served, signed in or not, and only the health probe answers.
	 */
	public function testWithBlueskyOffNothingIsServed(): void {
		$this->enabled = false;
		$answered = [];
		foreach (self::methods() as $method => $kind) {
			$status = $this->call($method, $kind)->getStatus();
			if ($status !== 501) {
				$answered[] = $method . ' (' . $status . ')';
			}
		}

		$this->assertSame(['_health (200)'], $answered);
	}

	public function testEveryAnswerCarriesTheCorsHeadersABrowserAppNeeds(): void {
		foreach (['com.atproto.server.describeServer' => 'query', 'com.atproto.repo.createRecord' => 'procedure', 'com.atproto.admin.getAccountInfo' => 'query'] as $method => $kind) {
			$headers = $this->call($method, $kind)->getHeaders();

			$this->assertSame('*', $headers['Access-Control-Allow-Origin'] ?? null, $method);
			$this->assertStringContainsString('DPoP', $headers['Access-Control-Allow-Headers'] ?? '');
			$this->assertStringContainsString('X-Atproto-Session-Id', $headers['Access-Control-Allow-Headers'] ?? '');
			$this->assertStringContainsString('X-Atproto-Device-Id', $headers['Access-Control-Allow-Headers'] ?? '');
			$this->assertStringContainsString('X-Atproto-Bsky-Topics', $headers['Access-Control-Allow-Headers'] ?? '');
			$this->assertStringContainsString('X-Bsky-Topics', $headers['Access-Control-Allow-Headers'] ?? '');
			$this->assertStringContainsString('DPoP-Nonce', $headers['Access-Control-Expose-Headers'] ?? '');
		}
		$preflight = $this->controller->preflight('com.atproto.repo.createRecord');
		$this->assertSame(204, $preflight->getStatus());
		$this->assertStringContainsString('X-Atproto-Session-Id', $preflight->getHeaders()['Access-Control-Allow-Headers'] ?? '');
		$this->assertStringContainsString('X-Atproto-Device-Id', $preflight->getHeaders()['Access-Control-Allow-Headers'] ?? '');
		$this->assertStringContainsString('X-Atproto-Bsky-Topics', $preflight->getHeaders()['Access-Control-Allow-Headers'] ?? '');
		$this->assertStringContainsString('X-Bsky-Topics', $preflight->getHeaders()['Access-Control-Allow-Headers'] ?? '');
	}

	private function call(string $method, string $kind): Response {
		return $kind === 'procedure' ? $this->controller->procedure($method) : $this->controller->query($method);
	}

	/**
	 * @return array<string, string> method => query, procedure or subscription
	 */
	private static function methods(): array {
		$methods = [];
		foreach (file(__DIR__ . '/upstream-methods.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			if (str_starts_with($line, '#')) {
				continue;
			}
			[$kind, $method] = explode(' ', $line, 2);
			$methods[$method] = $kind;
		}

		return $methods + [
			'_health' => 'query',
			'com.example.unknown.method' => 'query',
			'com.example.unknown.procedure' => 'procedure',
			'app.bsky.unstable.notInAnyLexicon' => 'query',
		];
	}
}
