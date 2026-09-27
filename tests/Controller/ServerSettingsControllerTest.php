<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\Controller\ServerSettingsController;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\Relay;
use OCA\Social\Service\RelayService;
use OCA\Social\Service\ServerSettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint behind the Server card.
 *
 * Administrators only, and by omission rather than by a check: the controller
 * carries none of the attributes that relax the default, so the server
 * dispatches it for an administrator with a session and a CSRF token and for
 * nobody else — in particular not for a group the Social section was
 * delegated to, which passes `ModerationController` and not this.
 */
#[AllowMockObjectsWithoutExpectations]
class ServerSettingsControllerTest extends TestCase {
	private ServerSettingsService|MockObject $serverSettingsService;
	private RelayService|MockObject $relayService;
	private ServerSettingsController $controller;

	protected function setUp(): void {
		$this->serverSettingsService = $this->createMock(ServerSettingsService::class);
		$this->relayService = $this->createMock(RelayService::class);
		$this->controller = new ServerSettingsController(
			$this->createStub(IRequest::class), $this->serverSettingsService, $this->relayService
		);
	}

	public function testWhatTheCardSentReachesTheServiceUnchanged(): void {
		$this->serverSettingsService->expects($this->once())->method('save')
			->with('admin@instance.example', 'a friendly place', 20, 4096, 0, 85, true, 720, true, '360,720', 0, 'hide_all', 0, true, true, false, 'admin')
			->willReturn(['contact_email' => 'admin@instance.example']);

		$response = $this->controller->save(
			'admin@instance.example', 'a friendly place', 20, 4096, 0, 85, true, 720, true, '360,720', 0, 'hide_all', 0, true, true, false, 'admin'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['contact_email' => 'admin@instance.example'], $response->getData());
	}

	/**
	 * The field that was refused is the one thing that tells the
	 * administrator what to change, so it reaches the card rather than being
	 * flattened into "no".
	 */
	public function testARefusalNamesTheFieldAndIsAnUnprocessableEntity(): void {
		$this->serverSettingsService->method('save')
			->willThrowException(new InvalidArgumentException('max_size must be between 1 and 10240 MB'));

		$response = $this->controller->save(maxSize: 0);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'max_size must be between 1 and 10240 MB'], $response->getData());
	}

	/**
	 * A delegate moderates and does not administer. Were this route carrying
	 * `AuthorizedAdminSetting` like the rest of the panel, the group an
	 * administrator handed the Social section to could turn secure mode on
	 * for the whole instance.
	 */
	public function testTheRouteIsNotOneADelegateCanReach(): void {
		$method = new \ReflectionMethod(ServerSettingsController::class, 'save');

		foreach ([AuthorizedAdminSetting::class, NoAdminRequired::class, PublicPage::class] as $attribute) {
			$this->assertSame(
				[], $method->getAttributes($attribute),
				$attribute . ' would let somebody who is not an administrator write these'
			);
		}
	}

	/** The defaults are the app's own, so an older card cannot blank a field. */
	public function testTheDefaultsAreWhatTheAppShipsWith(): void {
		$this->serverSettingsService->expects($this->once())->method('save')
			->with('', '', 10, 2048, 0, 85, false, 1080, false, '360,720,1080', 0, 'default', 300, false, false, false)
			->willReturn([]);

		$this->controller->save();
	}

	// --- relays -----------------------------------------------------------

	public function testTheRelayListIsWhatTheServiceHolds(): void {
		$relay = new Relay();
		$relay->setId(3)->setActorId('https://relay.example/actor')->setStatus(Relay::STATUS_ACCEPTED);
		$this->relayService->method('all')->willReturn([$relay]);

		$response = $this->controller->relays();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$relay], $response->getData());
	}

	public function testSubscribingAnswersWithTheRowAsItNowStands(): void {
		$relay = new Relay();
		$relay->setId(3)->setActorId('https://relay.example/actor')->setStatus(Relay::STATUS_PENDING);
		$this->relayService->expects($this->once())->method('subscribe')
			->with('https://relay.example/actor')->willReturn($relay);

		$response = $this->controller->relaySubscribe('https://relay.example/actor');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(Relay::STATUS_PENDING, $response->getData()->getStatus());
	}

	/** The message says what is wrong with the address, which is the whole of the help. */
	public function testAnAddressTheServiceRefusesIsAnUnprocessableEntity(): void {
		$this->relayService->method('subscribe')->willThrowException(
			new InvalidResourceException('that actor publishes no inbox, so there is nowhere to subscribe')
		);

		$response = $this->controller->relaySubscribe('https://relay.example/actor');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			'that actor publishes no inbox, so there is nowhere to subscribe',
			$response->getData()['error']
		);
	}

	public function testUnsubscribingFromAnUnknownRelayIsNotFound(): void {
		$this->relayService->method('unsubscribe')->willReturn(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->relayUnsubscribe(9)->getStatus());
	}

	public function testUnsubscribingAnswersPlainlyWhenItWorked(): void {
		$this->relayService->expects($this->once())->method('unsubscribe')->with(3)->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller->relayUnsubscribe(3)->getStatus());
	}
}
