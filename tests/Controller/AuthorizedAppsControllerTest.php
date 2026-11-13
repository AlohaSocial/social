<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\AuthorizedAppsController;
use OCA\Social\Model\Client\SocialClient;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/authorized_apps`: the apps this account has signed in to, and
 * taking one back.
 *
 * The one place that manages other apps' tokens, so a bearer token is held to
 * more than its scope: listing needs all of `read`, and revoking with a token
 * reaches only that token's own authorization. The Nextcloud session — the
 * settings page, or an app password — may sign any of the account's apps out.
 */
#[AllowMockObjectsWithoutExpectations]
class AuthorizedAppsControllerTest extends TestCase {
	use ClientApiCallers;

	protected function setUp(): void {
		$this->setUpCallers();
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testTheAuthorizedAppsAreTheOnesThisAccountGranted(): void {
		$this->asSession('alice');
		$tusky = new SocialClient();
		$tusky->setAuthId(4)->setAppName('Tusky')->setAppWebsite('https://tusky.app')
			->setAuthScopes(['read', 'write'])->setAuthCreation(1757000000)
			->setLastUpdate(1757800000)->setToken('hashed');
		$this->clientService->method('getAuthorizationsOf')->with('alice')->willReturn([$tusky]);
		$this->clientService->method('signsOutAt')->willReturn(1757000000 + 365 * 86400);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([[
			'id' => 4,
			'name' => 'Tusky',
			'website' => 'https://tusky.app',
			'scopes' => ['read', 'write'],
			'created_at' => 1757000000,
			'last_used_at' => 1757800000,
			'expires_at' => 1757000000 + 365 * 86400,
			'signed_in' => true,
		]], $response->getData());
	}

	/**
	 * The token is stored hashed and nothing on this page wants it; a list of
	 * tokens is the one thing this route must never be.
	 */
	public function testTheAuthorizedAppsNeverCarryTheToken(): void {
		$this->asToken(['read']);
		$client = new SocialClient();
		$client->setAuthId(4)->setAppName('Tusky')->setToken('hashed');
		$this->clientService->method('getAuthorizationsOf')->willReturn([$client]);

		$row = $this->controller()->index()->getData()[0];

		$this->assertArrayNotHasKey('token', $row);
		$this->assertStringNotContainsString('hashed', json_encode($row));
	}

	/** A code that was never exchanged is not a sign-in, and is not shown as one. */
	public function testAnAuthorizationWhoseCodeWasNeverExchangedIsMarked(): void {
		$this->asSession('alice');
		$client = new SocialClient();
		$client->setAuthId(9)->setAppName('Something')->setToken('');
		$this->clientService->method('getAuthorizationsOf')->willReturn([$client]);

		$this->assertFalse($this->controller()->index()->getData()[0]['signed_in']);
	}

	public function testTheAuthorizedAppsNeedAnAccount(): void {
		$this->clientService->expects($this->never())->method('getAuthorizationsOf');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index()->getStatus());
	}

	/** One granular read is not consent to see every other app the person uses. */
	public function testListingWithATokenNeedsAllOfRead(): void {
		$this->asToken(['read:accounts', 'read:statuses', 'write']);
		$this->clientService->expects($this->never())->method('getAuthorizationsOf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index()->getStatus());
	}

	public function testListingWithATokenListsItsOwnAccountsApps(): void {
		$this->asToken(['read'], 'bob');
		$this->clientService->expects($this->once())->method('getAuthorizationsOf')->with('bob')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->index()->getStatus());
	}

	public function testSigningAnAppOutTakesTheAuthorizationBack(): void {
		$this->asSession('alice');
		$this->clientService->expects($this->once())->method('revokeAuthorizationOf')
			->with('alice', 4)->willReturn(true);

		$response = $this->controller()->revoke(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * The id is a number a caller can count upwards: an authorization that is
	 * not this account's own has to be unreachable, and the answer has to be
	 * the one an id that does not exist gets, or the pair of answers would say
	 * which ids exist.
	 */
	public function testSigningOutSomebodyElsesAppIsNotFound(): void {
		$this->asSession('alice');
		$this->clientService->method('revokeAuthorizationOf')->willReturn(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->revoke(4)->getStatus());
	}

	public function testSigningAnAppOutNeedsAnAccount(): void {
		$this->clientService->expects($this->never())->method('revokeAuthorizationOf');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->revoke(4)->getStatus());
	}

	/** A settings page on another site cannot sign the person's apps out through their session. */
	public function testASessionWithoutItsCsrfTokenSignsNothingOut(): void {
		$this->asSession('alice', false);
		$this->clientService->expects($this->never())->method('revokeAuthorizationOf');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->revoke(4)->getStatus());
	}

	/** An app signing itself out: its own authorization, which `write` covers. */
	public function testATokenMayRevokeItsOwnAuthorization(): void {
		$this->asToken(['write'], 'bob', 7);
		$this->clientService->expects($this->once())->method('revokeAuthorizationOf')
			->with('bob', 7)->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller()->revoke(7)->getStatus());
	}

	/**
	 * Another app's authorization is the session's to take back, never a
	 * token's — whatever the token was granted, and whether or not the id
	 * exists, so the answer says nothing about which ids do.
	 */
	public function testATokenMayNotRevokeAnotherAppsAuthorization(): void {
		$this->asToken(['read', 'write', 'follow', 'push'], 'bob', 7);
		$this->clientService->expects($this->never())->method('revokeAuthorizationOf');

		$response = $this->controller()->revoke(8);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('its own authorization', $response->getData()['error']);
	}

	public function testRevokingWithATokenNeedsWrite(): void {
		$this->asToken(['read'], 'bob', 7);
		$this->clientService->expects($this->never())->method('revokeAuthorizationOf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->revoke(7)->getStatus());
	}

	private function controller(): AuthorizedAppsController {
		return new AuthorizedAppsController(
			$this->request, $this->userSession, new NullLogger(), $this->accountService, $this->clientService,
		);
	}
}
