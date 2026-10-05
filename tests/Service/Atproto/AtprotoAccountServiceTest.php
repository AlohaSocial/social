<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Atproto;

use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\Atproto\AtprotoAccountService;
use OCA\Social\Service\Atproto\AtprotoClient;
use OCA\Social\Service\Atproto\AtprotoIdentity;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AtprotoAccountServiceTest extends TestCase {
	public function testUnauthorizedProfileReadMarksTheLinkBroken(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$request = $this->createMock(AtprotoRequest::class);
		$cipher = $this->createMock(PrivateKeyCipher::class);
		$config = $this->createMock(ConfigService::class);
		$account = (new AtprotoAccount())
			->setUserId('alice')
			->setHandle('alice.example')
			->setDid('did:plc:alice')
			->setPds('https://pds.example');
		$request->expects($this->once())->method('getAccount')->with('alice')->willReturn($account);
		$identity->expects($this->once())->method('profile')->with('did:plc:alice', 'https://pds.example')
			->willThrowException(new AtprotoException('session expired', 401));
		$request->expects($this->once())->method('setAccountState')->with('alice', AtprotoAccount::STATE_BROKEN, 'session expired');

		$service = new AtprotoAccountService($client, $identity, $request, $cipher, $config, new NullLogger());
		$status = $service->status('alice');

		$this->assertSame(AtprotoAccount::STATE_BROKEN, $status['account']['state']);
		$this->assertSame('session expired', $status['account']['lastError']);
	}

	public function testLinkRejectsASessionForAnotherDid(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$request = $this->createMock(AtprotoRequest::class);
		$cipher = $this->createMock(PrivateKeyCipher::class);
		$config = $this->createMock(ConfigService::class);
		$identity->expects($this->once())->method('normalize')->with('alice.example')->willReturn('alice.example');
		$identity->expects($this->once())->method('resolve')->with('alice.example')->willReturn([
			'did' => 'did:plc:resolved', 'handle' => 'alice.example', 'pds' => 'https://pds.example',
		]);
		$client->expects($this->once())->method('post')->with(
			'com.atproto.server.createSession',
			['identifier' => 'alice.example', 'password' => 'xxxx'],
			'https://pds.example',
		)->willReturn(['did' => 'did:plc:other']);
		$request->expects($this->never())->method('saveAccount');

		$service = new AtprotoAccountService($client, $identity, $request, $cipher, $config, new NullLogger());

		$this->expectException(AtprotoException::class);
		$this->expectExceptionCode(409);
		$service->link('alice', 'alice.example', 'xxxx');
	}

	public function testBrokenLinkCannotWriteProfileMetadata(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$request = $this->createMock(AtprotoRequest::class);
		$cipher = $this->createMock(PrivateKeyCipher::class);
		$config = $this->createMock(ConfigService::class);
		$account = (new AtprotoAccount())
			->setUserId('alice')
			->setHandle('alice.example')
			->setDid('did:plc:alice')
			->setPds('https://pds.example')
			->setState(AtprotoAccount::STATE_BROKEN);
		$request->expects($this->once())->method('getAccount')->with('alice')->willReturn($account);
		$identity->expects($this->never())->method('profile');
		$client->expects($this->never())->method('authedPost');

		$service = new AtprotoAccountService($client, $identity, $request, $cipher, $config, new NullLogger());

		$this->expectException(AtprotoException::class);
		$this->expectExceptionCode(401);
		$service->updateProfile('alice', 'Alice', 'Description');
	}

	public function testProfileImageUploadsAsNativeBlob(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$request = $this->createMock(AtprotoRequest::class);
		$cipher = $this->createMock(PrivateKeyCipher::class);
		$config = $this->createMock(ConfigService::class);
		$account = (new AtprotoAccount())
			->setUserId('alice')->setHandle('alice.example')->setDid('did:plc:alice')->setPds('https://pds.example');
		$path = tempnam(sys_get_temp_dir(), 'social-atproto-avatar-');
		file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
		try {
			$request->expects($this->once())->method('getAccount')->with('alice')->willReturn($account);
			$identity->expects($this->once())->method('profile')->with('did:plc:alice', 'https://pds.example')->willReturn([
				'$type' => 'app.bsky.actor.profile', 'displayName' => 'Old', 'banner' => ['$type' => 'blob'],
			]);
			$client->expects($this->once())->method('authedBlobPost')
				->with($this->anything(), 'image/png', $account, 'https://pds.example')
				->willReturn(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafy-avatar']]]);
			$client->expects($this->once())->method('authedPost')
				->with('com.atproto.repo.putRecord', $this->callback(static fn (array $body): bool => ($body['record']['avatar']['ref']['$link'] ?? '') === 'bafy-avatar' && !isset($body['record']['banner'])), $account, 'https://pds.example')
				->willReturn([]);

			$service = new AtprotoAccountService($client, $identity, $request, $cipher, $config, new NullLogger());
			$this->assertSame(['displayName' => 'Alice', 'description' => 'Description'], $service->updateProfile('alice', 'Alice', 'Description', [
				'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'type' => 'image/png', 'size' => filesize($path),
			], null, false, true));
		} finally {
			if (is_string($path) && is_file($path)) {
				unlink($path);
			}
		}
	}

	public function testProfileImageRejectsOversizedReportedUpload(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$request = $this->createMock(AtprotoRequest::class);
		$cipher = $this->createMock(PrivateKeyCipher::class);
		$config = $this->createMock(ConfigService::class);
		$account = (new AtprotoAccount())->setUserId('alice')->setDid('did:plc:alice')->setPds('https://pds.example');
		$path = tempnam(sys_get_temp_dir(), 'social-atproto-avatar-');
		file_put_contents($path, 'small');
		try {
			$request->method('getAccount')->willReturn($account);
			$identity->method('profile')->willReturn(['$type' => 'app.bsky.actor.profile']);
			$client->expects($this->never())->method('authedBlobPost');
			$service = new AtprotoAccountService($client, $identity, $request, $cipher, $config, new NullLogger());

			$this->expectException(AtprotoException::class);
			$this->expectExceptionCode(422);
			$service->updateProfile('alice', 'Alice', '', [
				'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'type' => 'image/png', 'size' => 2 * 1024 * 1024,
			]);
		} finally {
			if (is_string($path) && is_file($path)) {
				unlink($path);
			}
		}
	}

	public function testProfileImageRejectsMismatchedContentType(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$request = $this->createMock(AtprotoRequest::class);
		$cipher = $this->createMock(PrivateKeyCipher::class);
		$config = $this->createMock(ConfigService::class);
		$account = (new AtprotoAccount())->setUserId('alice')->setDid('did:plc:alice')->setPds('https://pds.example');
		$path = tempnam(sys_get_temp_dir(), 'social-atproto-avatar-');
		file_put_contents($path, 'not-an-image');
		try {
			$request->method('getAccount')->willReturn($account);
			$identity->method('profile')->willReturn(['$type' => 'app.bsky.actor.profile']);
			$client->expects($this->never())->method('authedBlobPost');
			$service = new AtprotoAccountService($client, $identity, $request, $cipher, $config, new NullLogger());

			$this->expectException(AtprotoException::class);
			$this->expectExceptionCode(422);
			$service->updateProfile('alice', 'Alice', '', [
				'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'type' => 'image/png', 'size' => filesize($path),
			]);
		} finally {
			if (is_string($path) && is_file($path)) {
				unlink($path);
			}
		}
	}
}
