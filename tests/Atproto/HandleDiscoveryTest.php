<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Controller\AtprotoWellKnownController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class HandleDiscoveryTest extends TestCase {
	public function testHandleDiscoveryUsesIncomingHostWithoutThePort(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('ALICE.pds.example:8443');
		$request->method('getServerHost')->willReturn('nextcloud.example');
		$identities = $this->createMock(IdentityService::class);
		$identities->method('isEnabled')->willReturn(true);
		$identities->expects(self::once())->method('getIdentityByHandle')->with('alice.pds.example')
			->willReturn(['did' => 'did:plc:abcdefghijklmnopqrstuvwx', 'state' => IdentityService::STATE_ACTIVE]);
		$response = (new AtprotoWellKnownController('social', $request, $identities))->atprotoDid();
		self::assertSame(200, $response->getStatus());
		self::assertSame('did:plc:abcdefghijklmnopqrstuvwx', $response->getData());
	}
}
