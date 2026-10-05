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
}
