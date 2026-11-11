<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\AsyncRequestSigner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signature that lets `/async/request/{token}` tell this server's own
 * call from anybody else's. The route is public and the tokens are written
 * to the log, so the signature is all that keeps a reader of the log from
 * making a worker deliver on demand.
 */
class AsyncRequestSignerTest extends TestCase {
	private const NOW = 1760000000;

	private int $now = self::NOW;

	private function signer(string $secret = 'instance-secret'): AsyncRequestSigner {
		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(
			static fn (string $message, string $password = ''): string => hash_hmac('sha512', $message, $secret, true)
		);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new AsyncRequestSigner($crypto, $time);
	}

	public function testASignatureVerifiesForTheTokenItWasMadeFor(): void {
		$signer = $this->signer();

		$this->assertTrue($signer->verify('tok-1', $signer->sign('tok-1')));
	}

	public function testASignatureIsOfNoUseForAnotherToken(): void {
		$signer = $this->signer();

		$this->assertFalse($signer->verify('tok-2', $signer->sign('tok-1')));
	}

	/** Only the holder of the instance secret can produce one. */
	public function testASignatureMadeUnderAnotherSecretIsRefused(): void {
		$this->assertFalse($this->signer()->verify('tok-1', $this->signer('other-secret')->sign('tok-1')));
	}

	/**
	 * The token and the time are signed under a prefix of their own, so an
	 * HMAC the instance secret makes for another purpose over the same
	 * string is not a signature here.
	 */
	public function testTheTokenAndTheTimeAreSignedUnderTheirOwnPrefix(): void {
		$signer = $this->signer();

		$this->assertSame(self::NOW . '.' . bin2hex(hash_hmac('sha512', 'social-async-request:' . self::NOW . ':tok-1', 'instance-secret', true)), $signer->sign('tok-1'));
		$this->assertFalse($signer->verify('tok-1', self::NOW . '.' . bin2hex(hash_hmac('sha512', 'tok-1', 'instance-secret', true))));
		$this->assertFalse($signer->verify('tok-1', self::NOW . '.' . bin2hex(hash_hmac('sha512', 'social-async-request:tok-1', 'instance-secret', true))), 'the time is part of what is signed');
	}

	/**
	 * A follow-up round of a drain is signed with its number, so a signature
	 * for one round starts no other; the first round is signed as it always
	 * was.
	 */
	public function testARoundIsPartOfWhatIsSigned(): void {
		$signer = $this->signer();

		$this->assertTrue($signer->verify('tok-1', $signer->sign('tok-1', 2), 2));
		$this->assertFalse($signer->verify('tok-1', $signer->sign('tok-1', 2), 3));
		$this->assertFalse($signer->verify('tok-1', $signer->sign('tok-1', 2)));
		$this->assertFalse($signer->verify('tok-1', $signer->sign('tok-1'), 2));
		$this->assertSame($signer->sign('tok-1'), $signer->sign('tok-1', 0));
	}

	/** The time, a dot, lower-case hex: it travels in a header unchanged. */
	public function testTheSignatureIsTheTimeAndHex(): void {
		$this->assertMatchesRegularExpression('/^[0-9]+\.[0-9a-f]{128}$/', $this->signer()->sign('tok-1'));
	}

	// --- the signature expires

	public function testASignatureIsGoodForFiveMinutesEitherWay(): void {
		$signer = $this->signer();
		$signature = $signer->sign('tok-1');

		$this->now = self::NOW + AsyncRequestSigner::MAX_AGE;
		$this->assertTrue($signer->verify('tok-1', $signature), 'at the edge');
		$this->now = self::NOW - AsyncRequestSigner::MAX_AGE;
		$this->assertTrue($signer->verify('tok-1', $signature), 'a clock behind this one');
	}

	public function testASignatureOlderThanFiveMinutesIsRefused(): void {
		$signer = $this->signer();
		$signature = $signer->sign('tok-1');

		$this->now = self::NOW + AsyncRequestSigner::MAX_AGE + 1;

		$this->assertFalse($signer->verify('tok-1', $signature));
	}

	public function testASignatureFromTooFarAheadIsRefused(): void {
		$signer = $this->signer();
		$signature = $signer->sign('tok-1');

		$this->now = self::NOW - AsyncRequestSigner::MAX_AGE - 1;

		$this->assertFalse($signer->verify('tok-1', $signature));
	}

	/** Moving the time forward does not keep a signature alive: the time is what was signed. */
	public function testTheTimeInTheHeaderCannotBeChanged(): void {
		$signer = $this->signer();
		[, $mac] = explode('.', $signer->sign('tok-1'), 2);

		$this->now = self::NOW + 2 * AsyncRequestSigner::MAX_AGE;

		$this->assertFalse($signer->verify('tok-1', $this->now . '.' . $mac));
	}

	/** @return array<string, array{string}> */
	public static function malformedSignatures(): array {
		return [
			'no time' => [bin2hex(str_repeat("\0", 64))],
			'a time that is not one' => ['soon.' . bin2hex(str_repeat("\0", 64))],
			'a negative time' => ['-1.' . bin2hex(str_repeat("\0", 64))],
			'nothing after the dot' => [self::NOW . '.'],
		];
	}

	#[DataProvider('malformedSignatures')]
	public function testAMalformedSignatureIsRefused(string $signature): void {
		$this->assertFalse($this->signer()->verify('tok-1', $signature));
	}

	/** @return array<string, array{string, string}> */
	public static function emptyInputs(): array {
		return [
			'no signature' => ['tok-1', ''],
			'no token' => ['', 'whatever'],
		];
	}

	#[DataProvider('emptyInputs')]
	public function testNothingVerifiesWithoutATokenAndASignature(string $token, string $signature): void {
		$this->assertFalse($this->signer()->verify($token, $signature));
	}

	/** An empty token signed is still refused: there is no queue entry it could name. */
	public function testTheSignatureOfAnEmptyTokenIsRefused(): void {
		$signer = $this->signer();

		$this->assertFalse($signer->verify('', $signer->sign('')));
	}

	public function testAnUpperCasedSignatureIsNotTheSameSignature(): void {
		$signer = $this->signer();

		$this->assertFalse($signer->verify('tok-1', strtoupper($signer->sign('tok-1'))));
	}
}
