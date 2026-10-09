<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\Controller\VerificationController;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\VerificationService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Verifying and taking back are the moderators'; choosing the verifying
 * account is the administrator's alone.
 */
#[AllowMockObjectsWithoutExpectations]
class VerificationControllerTest extends TestCase {
	private const BOB = 'https://mastodon.example/users/bob';

	/** @var VerificationService&MockObject */
	private VerificationService $verifications;
	/** @var CacheActorService&MockObject */
	private CacheActorService $cache;

	protected function setUp(): void {
		$this->verifications = $this->createMock(VerificationService::class);
		$this->cache = $this->createMock(CacheActorService::class);
	}

	private function controller(): VerificationController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mod');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new VerificationController($this->createMock(IRequest::class), $this->verifications, $this->cache, $session, new NullLogger());
	}

	public function testTheModeratorsMayVerifyAndOnlyTheAdministratorChoosesTheVerifier(): void {
		foreach (['index', 'verify', 'unverify'] as $method) {
			$attributes = (new ReflectionMethod(VerificationController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame(AdminSettings::class, $attributes[0]->getArguments()['settings']);
		}
		$setVerifier = new ReflectionMethod(VerificationController::class, 'setVerifier');
		$this->assertSame([], $setVerifier->getAttributes(AuthorizedAdminSetting::class));
		$this->assertSame([], $setVerifier->getAttributes(NoAdminRequired::class));
	}

	public function testTheIndexIsTheStateAndTheList(): void {
		$this->verifications->method('state')->willReturn(['verifier' => null, 'publishing' => false]);
		$this->verifications->method('list')->willReturn([['actor_id' => self::BOB]]);

		$this->assertSame(['verifier' => null, 'publishing' => false, 'verifications' => [['actor_id' => self::BOB]]], $this->controller()->index()->getData());
	}

	public function testVerifyingNamesTheModeratorAndAnswersTheEntitysVerification(): void {
		$bob = (new Person())->setId(self::BOB);
		$this->cache->method('resolve')->with('bob@mastodon.example', true)->willReturn($bob);
		$this->verifications->expects($this->once())->method('verify')->with($bob, 'mod');
		$this->verifications->method('exportOf')->with(self::BOB)->willReturn(['by' => 'Example Inc', 'issuer' => '', 'created_at' => '2026-10-09T10:00:00.000Z']);

		$response = $this->controller()->verify('bob@mastodon.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['actor_id' => self::BOB, 'verification' => ['by' => 'Example Inc', 'issuer' => '', 'created_at' => '2026-10-09T10:00:00.000Z']], $response->getData());
	}

	public function testAnUnknownAccountIsA404(): void {
		$this->cache->method('resolve')->willThrowException(new CacheActorDoesNotExistException());
		$this->verifications->expects($this->never())->method('verify');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->verify('nobody')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->unverify('nobody')->getStatus());
	}

	public function testAFailedVerificationIsA500(): void {
		$this->cache->method('resolve')->willReturn((new Person())->setId(self::BOB));
		$this->verifications->method('verify')->willThrowException(new \RuntimeException('database'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller()->verify(self::BOB)->getStatus());
	}

	public function testTakingBackByActorIdOrHandle(): void {
		$this->cache->expects($this->once())->method('resolve')->with('bob@mastodon.example')->willReturn((new Person())->setId(self::BOB));
		$this->verifications->method('unverify')->willReturnCallback(static fn (string $id): bool => $id === self::BOB);

		$this->assertSame(['actor_id' => self::BOB, 'verification' => null], $this->controller()->unverify(self::BOB)->getData(), 'an actor id is not looked up');
		$this->assertSame(Http::STATUS_OK, $this->controller()->unverify('bob@mastodon.example')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->unverify('https://mastodon.example/users/carol')->getStatus(), 'not verified');
	}

	public function testChoosingTheVerifierAnswersTheStateOrWhatWasRefused(): void {
		$this->verifications->method('setVerifier')->willReturnCallback(static function (string $account): ?Person {
			if ($account === 'bob@mastodon.example') {
				throw new InvalidArgumentException('Only an account on this server can verify others');
			}

			return null;
		});
		$this->verifications->method('state')->willReturn(['verifier' => null, 'publishing' => false]);

		$this->assertSame(['verifier' => null, 'publishing' => false], $this->controller()->setVerifier('')->getData());
		$refused = $this->controller()->setVerifier('bob@mastodon.example');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'Only an account on this server can verify others'], $refused->getData());
	}
}
