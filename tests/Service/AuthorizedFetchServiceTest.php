<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AuthorizedFetchService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\SignatureService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who is asking, on a GET.
 *
 * Signature verification ran on inbox POSTs only, so this instance could not
 * tell one remote reader from another and served every ActivityPub GET what an
 * anonymous reader gets. That failed closed — nothing leaked — and it is also
 * why a follower on another server saw a profile with nothing on it.
 *
 * What is asserted here is what the answer is in each of the ways a fetch can
 * go wrong, because every one of them decides whether a private post is served
 * or a legitimate peer is turned away.
 */
class AuthorizedFetchServiceTest extends TestCase {
	private const REMOTE = 'https://remote.example/users/bob';

	private SignatureService|Stub $signatureService;
	private CacheActorService|Stub $cacheActorService;
	private FediverseService|Stub $fediverseService;
	private ConfigService|Stub $configService;
	private IRequest|Stub $request;
	private AuthorizedFetchService $service;

	/** What the signature check answers: an origin, or an exception to throw. */
	private string $origin = '';
	private string $signer = '';
	private ?\Exception $signatureFailure = null;
	/** Whether the access list lets that instance in. */
	private bool $federates = true;
	/** What the actor lookup answers. */
	private ?Person $actor = null;
	private ?\Exception $lookupFailure = null;
	private string $secureMode = '0';

	protected function setUp(): void {
		$this->signatureService = $this->createStub(SignatureService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->fediverseService = $this->createStub(FediverseService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->request = $this->createStub(IRequest::class);

		$this->signatureService->method('checkGetRequest')->willReturnCallback(
			function (IRequest $request, string &$signer): string {
				if ($this->signatureFailure !== null) {
					throw $this->signatureFailure;
				}

				$signer = $this->signer;

				return $this->origin;
			}
		);

		$this->fediverseService->method('authorized')->willReturnCallback(
			function (string $origin): bool {
				if (!$this->federates) {
					throw new UnauthorizedFediverseException('Unauthorized Fediverse');
				}

				return true;
			}
		);

		$this->cacheActorService->method('getFromId')->willReturnCallback(
			function (string $id): Person {
				if ($this->lookupFailure !== null) {
					throw $this->lookupFailure;
				}

				return $this->actor ?? $this->remotePerson();
			}
		);

		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string
				=> $key === ConfigService::SOCIAL_SECURE_MODE ? $this->secureMode : ''
		);

		$this->service = new AuthorizedFetchService(
			$this->signatureService, $this->cacheActorService, $this->fediverseService,
			$this->configService, new NullLogger()
		);
	}

	private function remotePerson(bool $local = false): Person {
		$person = new Person();
		$person->setId(self::REMOTE)->setPreferredUsername('bob')->setLocal($local);

		return $person;
	}

	/** A verified signature from an instance this one talks to. */
	private function signedBy(string $actorId): void {
		$this->origin = (string)parse_url($actorId, PHP_URL_HOST);
		$this->signer = $actorId;
	}

	public function testASignedFetchNamesTheAccountBehindIt(): void {
		$this->signedBy(self::REMOTE);

		$reader = $this->service->reader($this->request);

		$this->assertNotNull($reader);
		$this->assertSame(self::REMOTE, $reader->getId());
	}

	/**
	 * The ordinary case: every crawler, every link preview, every server that
	 * does not sign its fetches.
	 */
	public function testAnUnsignedFetchIsNobody(): void {
		$this->assertNull($this->service->reader($this->request));
	}

	/**
	 * A peer whose clock has drifted, or whose key cannot be fetched right
	 * now, should see what an anonymous reader sees rather than nothing at
	 * all — the route's job is still to serve a public object.
	 */
	public function testASignatureThatDoesNotVerifyIsNobody(): void {
		$this->signedBy(self::REMOTE);
		$this->signatureFailure = new SignatureException('signature cannot be checked');

		$this->assertNull($this->service->reader($this->request));
	}

	/** An instance this one does not federate with is not a reader either. */
	public function testAnInstanceThisServerBlocksIsNobody(): void {
		$this->signedBy(self::REMOTE);
		$this->federates = false;

		$this->assertNull($this->service->reader($this->request));
	}

	public function testAnAccountThatCannotBeResolvedIsNobody(): void {
		$this->signedBy(self::REMOTE);
		$this->lookupFailure = new \Exception('not found and not fetchable');

		$this->assertNull($this->service->reader($this->request));
	}

	/**
	 * A local actor's key signing an inbound fetch is this instance talking to
	 * itself, or somebody replaying one of our own requests.
	 */
	public function testALocalActorSigningAnInboundFetchIsNobody(): void {
		$this->signedBy(self::REMOTE);
		$this->actor = $this->remotePerson(true);

		$this->assertNull($this->service->reader($this->request));
	}

	public function testASignatureWithNoSignerIsNobody(): void {
		$this->origin = 'remote.example';
		$this->signer = '';

		$this->assertNull($this->service->reader($this->request));
	}

	// secure mode

	/**
	 * Off by default, and deliberately: turning it on makes this instance
	 * invisible to every peer that does not sign its fetches.
	 */
	public function testSecureModeIsOffUnlessItIsSwitchedOn(): void {
		$this->assertFalse($this->service->isSecureMode());

		$this->secureMode = '1';

		$this->assertTrue($this->service->isSecureMode());
	}

	public function testWithoutSecureModeAnUnsignedRequestIsAllowed(): void {
		$this->service->assertReadable($this->request, null);
		$this->addToAssertionCount(1);
	}

	public function testInSecureModeAnUnsignedRequestIsRefused(): void {
		$this->secureMode = '1';

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('signed requests only');

		$this->service->assertReadable($this->request, null);
	}

	public function testInSecureModeASignedRequestIsAllowed(): void {
		$this->secureMode = '1';

		$this->service->assertReadable($this->request, $this->remotePerson());
		$this->addToAssertionCount(1);
	}
}
