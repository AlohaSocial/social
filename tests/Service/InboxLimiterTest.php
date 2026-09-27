<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\PayloadTooLargeException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\InboxLimiter;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Run on an instance with no memcache, which is where the throttle used to be
 * off: the counters were kept in `createDistributed()`, a cache there that
 * forgets every write, so every delivery read a count of zero.
 */
#[AllowMockObjectsWithoutExpectations]
class InboxLimiterTest extends TestCase {
	private ConfigService|MockObject $configService;
	private InboxLimiter $limiter;
	/** the counters' table */
	private InMemoryDurableCacheRequest $table;

	protected function setUp(): void {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static fn (): int => time());
		$this->table = new InMemoryDurableCacheRequest();

		$this->configService = $this->createMock(ConfigService::class);
		$this->limiter = new InboxLimiter(
			new DurableCache($cacheFactory, $this->table, $time), $this->configService
		);
	}

	private function limit(int $limit): void {
		$this->configService->method('getAppValue')
			->with(ConfigService::SOCIAL_INBOX_THROTTLE)->willReturn((string)$limit);
	}

	/** @return IRequest&MockObject */
	private function request(string $keyId, string $ip = '198.51.100.7'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Signature')->willReturn(
			$keyId === '' ? '' : 'keyId="' . $keyId . '",algorithm="rsa-sha256",signature="…"'
		);
		$request->method('getRemoteAddress')->willReturn($ip);

		return $request;
	}

	public function testRequestsUnderTheLimitPass(): void {
		$this->limit(3);
		$request = $this->request('https://remote.example/users/bob#main-key');

		$this->limiter->assertAllowed($request);
		$this->limiter->assertAllowed($request);
		$this->limiter->assertAllowed($request);
		$this->addToAssertionCount(1);
	}

	public function testTheRequestOverTheLimitIsRefused(): void {
		$this->limit(2);
		$request = $this->request('https://remote.example/users/bob#main-key');
		$this->limiter->assertAllowed($request);
		$this->limiter->assertAllowed($request);

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed($request);
	}

	public function testRotatingTheClaimedHostDoesNotMintAFreshBucket(): void {
		// the keyId is unverified at this point, so a flood that writes a new
		// hostname on every request must still land in the same bucket
		$this->limit(2);
		$this->limiter->assertAllowed($this->request('https://one.example/actor#main-key'));
		$this->limiter->assertAllowed($this->request('https://two.example/actor#main-key'));

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed($this->request('https://three.example/actor#main-key'));
	}

	/**
	 * The pre-signature check must not touch a bucket named by the sender: a
	 * keyId is free to write, so counting it there let anyone spend a chosen
	 * instance's budget from a handful of cheap addresses and have its genuine
	 * deliveries answered 429 for the rest of the minute.
	 */
	public function testAnUnverifiedClaimedHostCannotSpendThatHostsBudget(): void {
		$this->limit(1);
		$allowed = InboxLimiter::HOST_LIMIT_FACTOR + 1;
		for ($i = 0; $i < $allowed; $i++) {
			$this->limiter->assertAllowed(
				$this->request('https://mastodon.example/actor#main-key', '198.51.100.' . $i)
			);
		}

		// the host it named is untouched, so a delivery it really signed still passes
		$this->limiter->assertOriginAllowed('mastodon.example');
		$this->addToAssertionCount(1);
	}

	public function testAVerifiedOriginIsCappedAcrossAddresses(): void {
		// one origin delivering from a fleet of addresses is bounded too, at a
		// looser ceiling than a single address gets — but only once the
		// signature has proven the origin
		$this->limit(1);
		for ($i = 0; $i < InboxLimiter::HOST_LIMIT_FACTOR; $i++) {
			$this->limiter->assertOriginAllowed('one.example');
		}

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertOriginAllowed('one.example');
	}

	public function testTheOriginBucketIsPerOriginAndCaseInsensitive(): void {
		$this->limit(1);
		for ($i = 0; $i < InboxLimiter::HOST_LIMIT_FACTOR; $i++) {
			$this->limiter->assertOriginAllowed('ONE.example');
		}
		// a different origin has its own budget
		$this->limiter->assertOriginAllowed('two.example');

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertOriginAllowed('one.example');
	}

	public function testADisabledLimiterHasNoOriginCeilingEither(): void {
		$this->limit(0);
		for ($i = 0; $i < 50; $i++) {
			$this->limiter->assertOriginAllowed('one.example');
		}

		$this->assertSame([], $this->table->rows);
	}

	public function testDifferentSourceAddressesUseDifferentBuckets(): void {
		// the per-address ceiling is per address: one noisy peer does not spend
		// another's budget
		$this->limit(1);
		$this->limiter->assertAllowed($this->request('https://one.example/a#k', '198.51.100.7'));
		$this->limiter->assertAllowed($this->request('https://two.example/a#k', '203.0.113.9'));
		$this->addToAssertionCount(1);
	}

	public function testAMissingSignatureHeaderFallsBackToTheAddressBucket(): void {
		$this->limit(1);
		$this->limiter->assertAllowed($this->request(''));

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed($this->request(''));
	}

	/** @return IRequest&MockObject */
	private function delivery(string $contentLength): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $header): string => $header === 'Content-Length' ? $contentLength : ''
		);

		return $request;
	}

	/**
	 * Both inboxes are public and unauthenticated, and the `Digest` is hashed
	 * over the body before the signature is verified — because a signature over
	 * a body nobody has hashed proves nothing about it. The only ceiling on
	 * that was PHP's `post_max_size`, which a Nextcloud sets to hundreds of
	 * megabytes so that file uploads work: anyone could hand every worker in
	 * the pool half a gigabyte to allocate and SHA-256. The rate limit bounds
	 * requests, not bytes.
	 */
	public function testABodyTooBigToBeAnActivityIsRefusedBeforeItIsRead(): void {
		$this->expectException(PayloadTooLargeException::class);

		$this->limiter->readBody($this->delivery((string)(InboxLimiter::MAX_BODY + 1)));
	}

	/** An activity is a few kilobytes; a megabyte is a wide margin over that. */
	public function testAnOrdinaryDeliveryIsRead(): void {
		// php://input is empty under the test runner: what is asserted here is
		// that the declared length is accepted and the read is reached
		$this->assertSame('', $this->limiter->readBody($this->delivery('4096')));
	}

	/** A chunked request declares no length at all, and is still bounded. */
	public function testADeliveryWithNoDeclaredLengthIsStillRead(): void {
		$this->assertSame('', $this->limiter->readBody($this->delivery('')));
	}

	public function testZeroDisablesTheLimiter(): void {
		$this->limit(0);
		$request = $this->request('https://remote.example/users/bob#main-key');

		for ($i = 0; $i < 50; $i++) {
			$this->limiter->assertAllowed($request);
		}
		$this->assertSame([], $this->table->rows, 'disabled limiter never touches the cache');
	}
}
