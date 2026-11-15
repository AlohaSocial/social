<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Move\MoveFailure;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PdsClientTest extends TestCase {
	private function client(bool $local): PdsClient {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('allow_local_remote_servers', false)->willReturn($local);

		return new PdsClient($this->createMock(CurlService::class), $config, $this->createMock(DurableCache::class), new NullLogger());
	}

	public function testAPdsIsItsHttpsOrigin(): void {
		$client = $this->client(false);

		$this->assertSame('https://pds.example.com', $client->origin('pds.example.com'));
		$this->assertSame('https://pds.example.com:8443', $client->origin('https://pds.example.com:8443/xrpc/'));
		foreach (['http://pds.example.com', 'https://user:pw@pds.example.com', 'ftp://pds.example.com', ''] as $typed) {
			try {
				$client->origin($typed);
				$this->fail('taken: ' . $typed);
			} catch (AtprotoException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testAnEmptyInputIsSentAsAnObject(): void {
		$sent = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$sent): string {
			$sent[] = [$method, $options['body']];
			$status = 200;

			return '{}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		$client->call('https://pds.example.com', 'com.atproto.server.deactivateAccount', 'POST', [], []);
		$client->call('https://pds.example.com', 'com.atproto.repo.createRecord', 'POST', [], ['repo' => 'did:plc:a']);

		$this->assertSame([['post', '{}'], ['post', '{"repo":"did:plc:a"}']], $sent);
	}

	/** Every call goes out through the app's own client, which guards and pins the address. */
	public function testACallIsMadeThroughCurlServiceAndAnswersStatusAndBody(): void {
		$seen = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$seen): string {
			$seen = ['method' => $method, 'url' => $url, 'options' => $options];
			$status = 400;

			return '{"error":"InvalidRequest","message":"no"}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		$answer = $client->call('https://pds.example.com', 'com.atproto.server.describeServer', 'GET', ['a' => 'b'], null, null, '', 'tok');

		$this->assertSame(400, $answer['status']);
		$this->assertSame('InvalidRequest', $answer['body']['error']);
		$this->assertSame('get', $seen['method']);
		$this->assertSame('https://pds.example.com/xrpc/com.atproto.server.describeServer?a=b', $seen['url']);
		$this->assertSame('Bearer tok', $seen['options']['headers']['Authorization']);
		$this->assertTrue($seen['options']['accept_errors'], 'the status is the caller\'s to judge');
		$this->assertFalse($seen['options']['json_headers'], 'no ActivityPub negotiation on an XRPC call');
	}

	/**
	 * A JSON call is waited for in a request, so it is given twenty seconds;
	 * only a repository or a blob, which move in a background job, get ten
	 * minutes.
	 */
	public function testAJsonCallWaitsTwentySecondsAndABodyUploadTenMinutes(): void {
		$timeouts = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$timeouts): string {
			$timeouts[] = $options['timeout'];
			$status = 200;

			return '{}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		$client->call('https://pds.example.com', 'com.atproto.server.describeServer');
		$client->call('https://pds.example.com', 'com.atproto.server.createAccount', 'POST', [], ['did' => 'did:plc:a']);
		$client->call('https://pds.example.com', 'com.atproto.repo.importRepo', 'POST', [], null, 'CAR', 'application/vnd.ipld.car');

		$this->assertSame([20, 20, 600], $timeouts);
		$this->assertSame(20, PdsClient::TIMEOUT);
	}

	public function testAServerThatCannotBeReachedIsAnAtprotoException(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willThrowException(new RequestNetworkException('refused'));
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		$this->expectException(AtprotoException::class);
		$client->call('https://pds.example.com', 'com.atproto.server.describeServer');
	}

	/**
	 * Why a PDS was not reached, or refused, is a code the page explains.
	 * An address this server does not send requests to is said no
	 * differently from one that did not answer, and what went wrong on the
	 * way is not said at all: the person would learn which hosts resolve
	 * to local addresses.
	 */
	public function testWhyAServerWasNotReachedIsACode(): void {
		$failures = [
			['pds-unreachable', new RequestNetworkException('cURL error 7: Failed to connect to 10.0.0.7 port 443')],
			['pds-unreachable', new RequestServerException('host does not resolve to a public address: pds.example.com')],
			['pds-blocked', new UnauthorizedFediverseException('blocked')],
		];
		$messages = [];
		foreach ($failures as [$slug, $failure]) {
			$curl = $this->createMock(CurlService::class);
			$curl->method('doRequest')->willThrowException($failure);
			try {
				(new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger()))->call('https://pds.example.com', 'com.atproto.server.describeServer');
				$this->fail('reached');
			} catch (MoveFailure $e) {
				$this->assertSame($slug, $e->errorCode);
				$this->assertStringNotContainsString($failure->getMessage(), $e->getMessage());
				$messages[] = $e->getMessage();
			}
		}
		$this->assertSame(['The PDS at https://pds.example.com could not be reached', 'The PDS at https://pds.example.com could not be reached'], array_slice($messages, 0, 2));
		try {
			$this->client(false)->origin('http://pds.example.com');
		} catch (MoveFailure $e) {
			$this->assertSame('pds-address-invalid', $e->errorCode);
		}

		$this->assertSame('rate-limited', PdsClient::refused('m', ['status' => 429, 'body' => []])->errorCode);
		$this->assertSame('pds-refused', PdsClient::refused('m', ['status' => 400, 'body' => ['error' => 'InvalidRequest']])->errorCode);
	}

	public function testBytesAreReadAsAStreamWithTheirType(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, 'CAR');
		rewind($stream);
		$curl = $this->createMock(CurlService::class);
		$curl->method('openStream')->willReturnCallback(function (string $url, array $headers, string $onBehalfOf, int $timeout) use ($stream): array {
			$this->assertSame('https://pds.example.com/xrpc/com.atproto.sync.getRepo?did=did%3Aplc%3Aa', $url);
			$this->assertSame('Bearer tok', $headers['Authorization']);
			$this->assertSame(600, $timeout);

			return ['stream' => $stream, 'status' => 200, 'headers' => ['Content-Type' => ['application/vnd.ipld.car']]];
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		$answer = $client->bytes('https://pds.example.com', 'com.atproto.sync.getRepo', ['did' => 'did:plc:a'], 10, 'tok');
		$this->assertSame([200, 3, 'application/vnd.ipld.car', 'CAR'], [$answer['status'], $answer['size'], $answer['type'], file_get_contents($answer['path'])]);
		unlink($answer['path']);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array}> the method, the code past the cap, the headers
	 */
	public static function overTheCap(): array {
		return [
			'a repository that says its length' => ['com.atproto.sync.getRepo', 'repo-too-large', ['Content-Length' => ['2000000']]],
			'a repository that does not' => ['com.atproto.sync.getRepo', 'repo-too-large', []],
			'a blob' => ['com.atproto.sync.getBlob', 'blob-too-large', []],
		];
	}

	/** An answer past the cap is refused as it arrives, and nothing of it is kept. */
	#[DataProvider('overTheCap')]
	public function testAnAnswerPastTheCapIsRefused(string $method, string $slug, array $headers): void {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, str_repeat('x', 200000));
		rewind($stream);
		$curl = $this->createMock(CurlService::class);
		$curl->method('openStream')->willReturn(['stream' => $stream, 'status' => 200, 'headers' => $headers]);
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());
		$before = glob(sys_get_temp_dir() . '/social-atproto-pds-*') ?: [];

		try {
			$client->bytes('https://pds.example.com', $method, ['did' => 'did:plc:a'], 100000);
			$this->fail('taken');
		} catch (MoveFailure $e) {
			$this->assertSame($slug, $e->errorCode);
		}
		$this->assertSame($before, glob(sys_get_temp_dir() . '/social-atproto-pds-*') ?: [], 'nothing is left behind');
	}

	/** The ceiling is handed to the transfer, which is ended there. */
	public function testAnAnswerEndedAtTheCeilingIsTooLarge(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('openStream')->willReturnCallback(function (string $url, array $headers, string $onBehalfOf, int $timeout, ?int $max = null): array {
			$this->assertSame(100, $max);

			throw new RequestResultSizeException();
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		try {
			$client->bytes('https://pds.example.com', 'com.atproto.sync.getRepo', ['did' => 'did:plc:a'], 100);
			$this->fail('taken');
		} catch (MoveFailure $e) {
			$this->assertSame('repo-too-large', $e->errorCode);
		}
	}

	public function testARefusedByteAnswerCarriesItsStatus(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('openStream')->willThrowException(new RequestContentException('url', 404));
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $this->createMock(DurableCache::class), new NullLogger());

		$this->assertSame(['status' => 404, 'path' => '', 'size' => 0, 'type' => ''], $client->bytes('https://pds.example.com', 'com.atproto.sync.getBlob', ['cid' => 'x'], 100));
	}

	/**
	 * Password sign-ins to one PDS are counted for the whole instance, as
	 * the PDS counts them per address: past the ceiling none is sent, and
	 * other calls and other servers are not held back.
	 */
	public function testSignInsToOneServerAreThrottledForTheWholeInstance(): void {
		$counts = [];
		$cache = $this->createMock(DurableCache::class);
		$cache->method('inc')->willReturnCallback(function (string $namespace, string $key, int $ttl) use (&$counts): int {
			$this->assertSame(PdsClient::SIGN_IN_WINDOW, $ttl);

			return $counts[$key] = ($counts[$key] ?? 0) + 1;
		});
		$sent = 0;
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$sent): string {
			$sent++;
			$status = 200;

			return '{}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class), $cache, new NullLogger());

		for ($i = 0; $i < PdsClient::SIGN_INS; $i++) {
			$client->call('https://bsky.social', 'com.atproto.server.createSession', 'POST', [], ['identifier' => 'a', 'password' => 'b']);
		}
		try {
			$client->call('https://bsky.social', 'com.atproto.server.createSession', 'POST', [], ['identifier' => 'a', 'password' => 'b']);
			$this->fail('sent');
		} catch (MoveFailure $e) {
			$this->assertSame('rate-limited', $e->errorCode);
		}
		$this->assertSame(PdsClient::SIGN_INS, $sent);
		$this->assertSame([20, 10], [PdsClient::SIGN_INS, PdsClient::SIGN_IN_WINDOW / 60]);

		$client->call('https://bsky.social', 'com.atproto.server.describeServer');
		$client->call('https://pds.example.com', 'com.atproto.server.createSession', 'POST', [], ['identifier' => 'a', 'password' => 'b']);
		$this->assertSame(PdsClient::SIGN_INS + 2, $sent);
	}

	public function testPlainHttpOnlyOnAServerThatTalksToLocalOnes(): void {
		$this->assertSame('http://localhost:2583', $this->client(true)->origin('http://localhost:2583'));
	}
}
