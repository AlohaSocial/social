<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\AppViewProxy;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Client\ServiceAuthGrant;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Publisher\BlueskyMutes;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Service\ModerationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ClientXrpcTest extends TestCase {
	/** @var SessionService&MockObject */
	private SessionService $sessions;
	/** @var AppViewProxy&MockObject */
	private AppViewProxy $proxy;
	/** @var WriteService&MockObject */
	private WriteService $writes;
	/** @var ModerationService&MockObject */
	private ModerationService $moderation;
	/** @var ServiceAuthGrant&MockObject */
	private ServiceAuthGrant $grants;
	/** @var AuthorizationServer&MockObject */
	private AuthorizationServer $oauth;
	/** @var string[] */
	private array $files = [];
	/** @var InboundMoveService&MockObject */
	private InboundMoveService $inbound;
	/** @var BlueskyMutes&MockObject */
	private BlueskyMutes $mutes;
	private ClientXrpc $client;
	private ClientSession $session;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$this->sessions = $this->createMock(SessionService::class);
		$this->session = new ClientSession('alice', new Identity(1, 'https://social.test/@alice', 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti');
		$this->sessions->method('authenticate')->willReturnCallback(fn (string $h): ?ClientSession => $h === '' ? null : $this->session);
		$this->proxy = $this->createMock(AppViewProxy::class);
		$this->writes = $this->createMock(WriteService::class);
		$this->moderation = $this->createMock(ModerationService::class);
		$this->grants = $this->createMock(ServiceAuthGrant::class);
		$this->oauth = $this->createMock(AuthorizationServer::class);
		$this->oauth->method('issuer')->willReturn('https://social.test');
		$this->inbound = $this->createMock(InboundMoveService::class);
		$this->inbound->method('owns')->willReturnCallback(static fn (string $authorization): bool => $authorization === 'Bearer move');
		$this->client = new ClientXrpc($config, $this->sessions, $this->proxy, $this->createMock(Preferences::class), $this->writes, $this->grants, $this->oauth, $this->moderation, $this->inbound, $this->mutes = $this->createMock(BlueskyMutes::class));
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	private function file(string $bytes): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($path, $bytes);
		$this->files[] = $path;

		return $path;
	}

	public function testThePublicSurfaceIsNotOurs(): void {
		$this->assertNull($this->client->query('com.atproto.sync.getRepo', 'did=x', []));
		$this->assertNull($this->client->query('com.atproto.identity.resolveHandle', '', ['authorization' => 'Bearer x']));
		$this->assertNull($this->client->procedure('com.atproto.sync.requestCrawl', '{}', [], '1.2.3.4'));
	}

	public function testSigningInNeedsNoTokenButEverythingElseDoes(): void {
		$this->sessions->expects($this->once())->method('create')->with('alice.social.test', 'abcd-efgh-ijkl-mnop', '203.0.113.5')->willReturn(['did' => 'x']);
		$this->assertSame(['did' => 'x'], $this->client->procedure('com.atproto.server.createSession', '{"identifier":"alice.social.test","password":"abcd-efgh-ijkl-mnop"}', [], '203.0.113.5'));
		foreach (['app.bsky.feed.getTimeline', 'com.atproto.server.getSession'] as $method) {
			try {
				$this->client->query($method, '', []);
				$this->fail($method);
			} catch (XrpcException $e) {
				$this->assertSame(401, $e->status);
			}
		}
	}

	public function testAppCallsAreProxiedAndWritesGoToSocial(): void {
		$headers = ['authorization' => 'Bearer t'];
		$this->proxy->expects($this->once())->method('forward')->with($this->session, 'app.bsky.feed.getTimeline', 'get', 'limit=5', '', $headers)->willReturn(new XrpcBytes('{}', 'application/json'));
		$this->writes->expects($this->once())->method('create')->with($this->session, ['repo' => 'x'])->willReturn(['uri' => 'at://x']);
		$path = $this->file('bytes');
		$this->writes->expects($this->once())->method('upload')->with($this->session, $path, 'image/png')->willReturn(['blob' => []]);

		$this->assertInstanceOf(XrpcBytes::class, $this->client->query('app.bsky.feed.getTimeline', 'limit=5', $headers));
		$this->assertSame(['uri' => 'at://x'], $this->client->procedure('com.atproto.repo.createRecord', '{"repo":"x"}', $headers, '1.2.3.4'));
		$this->assertSame(['blob' => []], $this->client->upload($path, $headers + ['content-type' => 'image/png']));
	}

	public function testAMuteAnAppMakesIsMadeHereOnceTheAppViewTookIt(): void {
		$headers = ['authorization' => 'Bearer t'];
		$this->proxy->method('forward')->willReturnOnConsecutiveCalls(new XrpcBytes('{}', 'application/json'), new XrpcBytes('{"error":"x"}', 'application/json', 400));
		$this->mutes->expects($this->once())->method('fromApp')->with($this->session, 'app.bsky.graph.muteActor', ['actor' => 'did:plc:bob']);

		$this->assertSame(200, $this->client->procedure('app.bsky.graph.muteActor', '{"actor":"did:plc:bob"}', $headers, '1.2.3.4')->status);
		$this->assertSame(400, $this->client->procedure('app.bsky.graph.muteActor', '{"actor":"did:plc:carol"}', $headers, '1.2.3.4')->status, 'refused there, not made here');
	}

	public function testAnAccountMovingHereIsAnsweredByTheMoveAlone(): void {
		$move = ['authorization' => 'Bearer move'];
		$this->inbound->expects($this->once())->method('createAccount')->with('Bearer service-token', ['did' => 'did:plc:alice'], '203.0.113.5')->willReturn(['did' => 'did:plc:alice']);
		$this->inbound->expects($this->once())->method('query')->with('com.atproto.server.checkAccountStatus', ['x' => '1'], 'Bearer move')->willReturn(['activated' => false]);
		$this->inbound->expects($this->exactly(2))->method('procedure')->willReturn([]);
		$path = $this->file('car');
		$this->inbound->expects($this->once())->method('importRepo')->with($path, 'Bearer move')->willReturn([]);
		$this->inbound->expects($this->once())->method('upload')->with($path, 'Bearer move', 'image/png')->willReturn(['blob' => []]);
		$this->sessions->expects($this->never())->method('authenticate');
		$this->writes->expects($this->never())->method('upload');

		$this->assertSame(['did' => 'did:plc:alice'], $this->client->procedure('com.atproto.server.createAccount', '{"did":"did:plc:alice"}', ['authorization' => 'Bearer service-token'], '203.0.113.5'));
		$this->assertSame(['activated' => false], $this->client->query('com.atproto.server.checkAccountStatus', 'x=1', $move));
		$this->assertNull($this->client->query('com.atproto.sync.getRepo', 'did=x', $move), 'the public surface stays public');
		$this->client->procedure('com.atproto.server.refreshSession', '', $move, '1.2.3.4');
		$this->client->procedure('com.atproto.server.activateAccount', '', $move, '1.2.3.4');
		$this->client->importRepo($path, $move);
		$this->client->upload($path, $move + ['content-type' => 'image/png']);
	}

	public function testOnlyAnAccountMovingHereImportsARepository(): void {
		try {
			$this->client->importRepo($this->file('car'), ['authorization' => 'Bearer t']);
			$this->fail('imported');
		} catch (XrpcException $e) {
			$this->assertSame('InvalidRequest', $e->error);
		}
	}

	public function testAServiceAuthTokenIsHandedOutForTheVideoService(): void {
		$this->grants->expects($this->once())->method('grant')
			->with($this->session, 'did:web:video.bsky.app', 'app.bsky.video.getUploadLimits', 1760000000)
			->willReturn(['token' => 'signed']);

		$this->assertSame(['token' => 'signed'], $this->client->query(
			'com.atproto.server.getServiceAuth', 'aud=did%3Aweb%3Avideo.bsky.app&lxm=app.bsky.video.getUploadLimits&exp=1760000000', ['authorization' => 'Bearer t']
		));
	}

	public function testTheVideoServiceStoresAnUploadWithTheAccountsToken(): void {
		$path = $this->file('video');
		$this->grants->method('uploader')->with('Bearer service-token')->willReturn($this->session);
		$this->sessions->expects($this->never())->method('authenticate');
		$this->writes->expects($this->once())->method('upload')->with($this->session, $path, 'video/mp4')->willReturn(['blob' => ['size' => 5]]);

		$this->assertSame(['blob' => ['size' => 5]], $this->client->upload($path, ['authorization' => 'Bearer service-token', 'content-type' => 'video/mp4']));
	}

	public function testASuspendedAccountsVideoIsNotStored(): void {
		$this->grants->method('uploader')->willReturn($this->session);
		$this->moderation->method('isSuspended')->willReturn(true);
		$this->writes->expects($this->never())->method('upload');

		$this->expectException(XrpcException::class);
		$this->client->upload($this->file('video'), ['authorization' => 'Bearer service-token']);
	}

	public function testAnOAuthAppIsSignedInByItsDpopBoundToken(): void {
		$oauthSession = new ClientSession('alice', $this->session->identity, 'sid', ['atproto', ClientSession::GENERIC]);
		$this->oauth->expects($this->once())->method('authenticate')
			->with('DPoP token', 'proof', 'GET', 'https://social.test/xrpc/app.bsky.feed.getTimeline')
			->willReturn($oauthSession);
		$this->sessions->expects($this->never())->method('authenticate');
		$this->proxy->expects($this->once())->method('forward')->with($oauthSession)->willReturn(new XrpcBytes('{}', 'application/json'));

		$this->client->query('app.bsky.feed.getTimeline', '', ['authorization' => 'DPoP token', 'dpop' => 'proof']);
	}

	public function testANonceChallengeReachesTheApp(): void {
		$this->oauth->method('authenticate')->willThrowException(new OAuthException('use_dpop_nonce', 'Use the nonce', 401, ['DPoP-Nonce' => 'n2', 'WWW-Authenticate' => 'DPoP error="use_dpop_nonce"']));

		try {
			$this->client->query('app.bsky.feed.getTimeline', '', ['authorization' => 'DPoP token', 'dpop' => 'proof']);
			$this->fail('answered');
		} catch (XrpcException $e) {
			$this->assertSame(401, $e->status);
			$this->assertSame('UseDpopNonce', $e->error);
			$this->assertSame('n2', $e->headers['DPoP-Nonce']);
		}
	}

	public function testAnAppGivenOnlyTheAtprotoScopeMayOnlyAskWhoItIs(): void {
		$this->oauth->method('authenticate')->willReturn(new ClientSession('alice', $this->session->identity, 'sid', ['atproto']));
		$this->sessions->method('describe')->willReturn(['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz']);
		$headers = ['authorization' => 'DPoP token', 'dpop' => 'proof'];

		$this->assertSame(['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz'], $this->client->query('com.atproto.server.getSession', '', $headers));
		try {
			$this->client->procedure('com.atproto.repo.createRecord', '{}', $headers, '1.2.3.4');
			$this->fail('written');
		} catch (XrpcException $e) {
			$this->assertSame(403, $e->status);
			$this->assertSame('InsufficientScope', $e->error);
		}
	}

	public function testAnAppIsHeldToTheGranularPermissionsItWasGiven(): void {
		$this->oauth->method('authenticate')->willReturn(new ClientSession('alice', $this->session->identity, 'sid', [
			'atproto', 'repo:app.bsky.feed.post?action=create', 'rpc:app.bsky.feed.getTimeline?aud=did:web:api.bsky.app%23bsky_appview', 'blob:image/*',
		]));
		$headers = ['authorization' => 'DPoP token', 'dpop' => 'proof'];
		$this->writes->method('create')->willReturn(['uri' => 'at://x']);
		$this->proxy->method('forward')->willReturn(new XrpcBytes('{}', 'application/json'));
		$this->writes->method('upload')->willReturn(['blob' => []]);
		$refused = function (callable $call): string {
			try {
				$call();
			} catch (XrpcException $e) {
				return $e->status . ' ' . $e->error;
			}

			return 'allowed';
		};

		$this->assertSame('allowed', $refused(fn () => $this->client->procedure('com.atproto.repo.createRecord', '{"collection":"app.bsky.feed.post"}', $headers, 'ip')));
		$this->assertSame('403 InsufficientScope', $refused(fn () => $this->client->procedure('com.atproto.repo.createRecord', '{"collection":"app.bsky.feed.like"}', $headers, 'ip')));
		$this->assertSame('403 InsufficientScope', $refused(fn () => $this->client->procedure('com.atproto.repo.applyWrites', '{"writes":[{"$type":"com.atproto.repo.applyWrites#create","collection":"app.bsky.feed.post"},{"$type":"com.atproto.repo.applyWrites#delete","collection":"app.bsky.feed.post"}]}', $headers, 'ip')), 'every write of a batch is its own');
		$this->assertSame('allowed', $refused(fn () => $this->client->query('app.bsky.feed.getTimeline', '', $headers)));
		$this->assertSame('403 InsufficientScope', $refused(fn () => $this->client->query('app.bsky.feed.getAuthorFeed', '', $headers)));
		$this->assertSame('403 InsufficientScope', $refused(fn () => $this->client->query('app.bsky.feed.getTimeline', '', $headers + ['atproto-proxy' => 'did:web:other.example#bsky_appview'])), 'another service');
		$this->assertSame('allowed', $refused(fn () => $this->client->upload($this->file('pixels'), $headers + ['content-type' => 'image/png'])));
		$this->assertSame('403 InsufficientScope', $refused(fn () => $this->client->upload($this->file('frames'), $headers + ['content-type' => 'video/mp4'])));
	}

	public function testASuspendedAccountsAppIsTurnedAway(): void {
		$this->moderation->method('isSuspended')->willReturn(true);
		$this->proxy->expects($this->never())->method('forward');
		$this->expectException(XrpcException::class);
		$this->client->query('app.bsky.feed.getTimeline', '', ['authorization' => 'Bearer t']);
	}

	public function testATooLargeUploadIsRefusedBeforeItIsStored(): void {
		$this->writes->expects($this->never())->method('upload');
		try {
			$this->client->upload($this->file(str_repeat('x', ClientXrpc::MAX_BLOB + 1)), ['authorization' => 'Bearer t']);
			$this->fail('stored');
		} catch (XrpcException $e) {
			$this->assertSame(413, $e->status);
		}
	}
}
