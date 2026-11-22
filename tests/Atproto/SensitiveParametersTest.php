<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\DpopVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

/**
 * A stack trace written to the log carries every argument of every frame,
 * except those of a parameter marked #[\SensitiveParameter]: the passwords
 * and tokens that pass through these methods are marked.
 */
class SensitiveParametersTest extends TestCase {
	/**
	 * @return array<string, array{0: class-string, 1: string, 2: list<string>}>
	 */
	public static function carriers(): array {
		return [
			'an app password signing in' => [SessionService::class, 'create', ['password']],
			'a session refreshed' => [SessionService::class, 'refresh', ['authorization']],
			'a session token read' => [SessionService::class, 'authenticate', ['authorization']],
			'a session token\'s claims' => [SessionService::class, 'claims', ['authorization']],
			'an XRPC query' => [ClientXrpc::class, 'query', ['headers']],
			'an XRPC procedure' => [ClientXrpc::class, 'procedure', ['rawBody', 'headers']],
			'an OAuth token' => [AuthorizationServer::class, 'authenticate', ['authorization', 'dpopProof']],
			'an OAuth code or refresh token' => [AuthorizationServer::class, 'token', ['params', 'dpopProof']],
			'a DPoP proof' => [DpopVerifier::class, 'verify', ['proof', 'accessToken']],
			'a Bluesky password moving in' => [MoveInService::class, 'start', ['password', 'authFactorToken']],
			'a password at the new server' => [MoveAwayService::class, 'start', ['password']],
			'a call to another PDS' => [PdsClient::class, 'call', ['json', 'token']],
			'a call to another PDS that must succeed' => [PdsClient::class, 'expect', ['json', 'token']],
			'an account moving here' => [InboundMoveService::class, 'createAccount', ['authorization', 'body']],
		];
	}

	/**
	 * @param class-string $class
	 * @param list<string> $names
	 */
	#[DataProvider('carriers')]
	public function testASecretIsNotWrittenToTheLog(string $class, string $method, array $names): void {
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
			if (in_array($parameter->getName(), $names, true)) {
				$this->assertNotSame([], $parameter->getAttributes(SensitiveParameter::class), $class . '::' . $method . ' $' . $parameter->getName());
				$names = array_diff($names, [$parameter->getName()]);
			}
		}
		$this->assertSame([], array_values($names), 'every parameter named is there');
	}
}
