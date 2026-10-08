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
	/** @var string[] */
	private array $files = [];
	private ClientXrpc $client;
	private ClientSession $session;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->sessions = $this->createMock(SessionService::class);
		$this->session = new ClientSession('alice', new Identity(1, 'https://social.test/@alice', 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti');
		$this->sessions->method('authenticate')->willReturnCallback(fn (string $h): ?ClientSession => $h === '' ? null : $this->session);
		$this->proxy = $this->createMock(AppViewProxy::class);
		$this->writes = $this->createMock(WriteService::class);
		$this->moderation = $this->createMock(ModerationService::class);
		$this->grants = $this->createMock(ServiceAuthGrant::class);
		$this->client = new ClientXrpc($config, $this->sessions, $this->proxy, $this->createMock(Preferences::class), $this->writes, $this->grants, $this->moderation);
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
