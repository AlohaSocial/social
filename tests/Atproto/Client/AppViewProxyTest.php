<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\AppViewProxy;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AppViewProxyTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var CurlService&MockObject */
	private CurlService $curl;
	private AppViewProxy $proxy;
	private ClientSession $session;
	private PrivateKey $userKey;
	/** the chat service as configured, '' for none */
	private string $chat = 'https://api.bsky.chat';

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$config->method('appViewAuth')->willReturn('https://api.bsky.app');
		$config->method('chat')->willReturnCallback(fn (): string => $this->chat);
		$config->method('chatDid')->willReturnCallback(fn (): string => $this->chat === '' ? '' : 'did:web:api.bsky.chat');
		$this->userKey = PrivateKey::generate(Curve::K256);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('signingKey')->willReturn($this->userKey);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->curl = $this->createMock(CurlService::class);
		$this->proxy = new AppViewProxy($config, $identities, new ServiceAuth($time, $this->createStub(AtprotoOAuthRequest::class)), $this->curl, new NullLogger());
		$this->session = new ClientSession('alice', new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0), 'jti');
	}

	public function testACallGoesToTheAppViewSignedByTheAccountForThatOneMethod(): void {
		$this->curl->expects($this->once())->method('doRequest')->with('get', 'https://api.bsky.app/xrpc/app.bsky.feed.getTimeline?limit=30&cursor=x%3Ay', $this->callback(function (array $options): bool {
			[$header, $payload, $signature] = explode('.', substr($options['headers']['Authorization'], 7));
			$claims = json_decode(Encoding::base64UrlDecode($payload), true);
			$this->assertSame(self::DID, $claims['iss']);
			$this->assertSame('did:web:api.bsky.app#bsky_appview', $claims['aud']);
			$this->assertSame('app.bsky.feed.getTimeline', $claims['lxm']);
			$this->assertTrue($this->userKey->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));
			$this->assertSame('did:plc:labeler', $options['headers']['atproto-accept-labelers']);
			$this->assertSame('device-123', $options['headers']['X-Atproto-Device-Id']);
			$this->assertSame('session-456', $options['headers']['X-Atproto-Session-Id']);
			$this->assertSame('for-you,following', $options['headers']['X-Atproto-Bsky-Topics']);
			$this->assertArrayNotHasKey('authorization', $options['headers'], 'the app\'s own token stays here');

			return $options['accept_errors'] === true;
		}))->willReturnCallback(static function (string $m, string $u, array $o, &$contentType, &$status): string {
			$contentType = 'application/json; charset=utf-8';
			$status = 200;

			return '{"feed":[]}';
		});

		$answer = $this->proxy->forward($this->session, 'app.bsky.feed.getTimeline', 'get', 'limit=30&cursor=x%3Ay', '', [
			'authorization' => 'Bearer app-token',
			'atproto-accept-labelers' => 'did:plc:labeler',
			'x-atproto-device-id' => 'device-123',
			'x-atproto-session-id' => 'session-456',
			'x-atproto-bsky-topics' => 'for-you,following',
		]);
		$this->assertSame('{"feed":[]}', $answer->bytes);
		$this->assertSame(200, $answer->status);
		$this->assertSame('application/json; charset=utf-8', $answer->contentType);
	}

	public function testTheAppViewsRefusalIsPassedOnAsItCame(): void {
		$this->curl->method('doRequest')->willReturnCallback(static function (string $m, string $u, array $o, &$contentType, &$status): string {
			$contentType = 'application/json';
			$status = 400;

			return '{"error":"InvalidRequest","message":"Profile not found"}';
		});
		$answer = $this->proxy->forward($this->session, 'app.bsky.actor.getProfile', 'get', 'actor=nobody', '', []);
		$this->assertSame(400, $answer->status);
		$this->assertStringContainsString('Profile not found', $answer->bytes);
	}

	public function testTheOfficialAppViewServiceReferenceIsPreservedInTheSignedAudience(): void {
		$this->curl->expects($this->once())->method('doRequest')->with('get', 'https://api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=alice.test', $this->callback(function (array $options): bool {
			$claims = json_decode(Encoding::base64UrlDecode(explode('.', substr($options['headers']['Authorization'], 7))[1]), true);
			$this->assertSame('did:web:api.bsky.app#bsky_appview', $claims['aud']);

			return true;
		}))->willReturnCallback(static function (string $method, string $url, array $options, &$contentType, &$status): string {
			$status = 200;
			$contentType = 'application/json';

			return '{"did":"did:plc:alice","posts":[]}';
		});

		$answer = $this->proxy->forward($this->session, 'app.bsky.actor.getProfile', 'get', 'actor=alice.test', '', ['atproto-proxy' => 'did:web:api.bsky.app#bsky_appview']);
		$this->assertSame(200, $answer->status);
		$this->assertStringContainsString('did:plc:alice', $answer->bytes);
	}

	public function testAppViewRejectingTheServiceFragmentIsRetriedWithItsBareDid(): void {
		$audiences = [];
		$this->curl->expects($this->exactly(2))->method('doRequest')->willReturnCallback(static function (string $method, string $url, array $options, &$contentType, &$status) use (&$audiences): string {
			$payload = explode('.', substr($options['headers']['Authorization'], 7))[1];
			$claims = json_decode(Encoding::base64UrlDecode($payload), true);
			$audiences[] = $claims['aud'];
			$contentType = 'application/json';

			if (count($audiences) === 1) {
				$status = 401;

				return '{"error":"BadJwtAudience","message":"jwt audience does not match service did"}';
			}

			$status = 200;

			return '{"feed":[]}';
		});

		$answer = $this->proxy->forward($this->session, 'app.bsky.feed.getTimeline', 'get', 'limit=5', '', []);

		$this->assertSame(['did:web:api.bsky.app#bsky_appview', 'did:web:api.bsky.app'], $audiences);
		$this->assertSame(200, $answer->status);
		$this->assertSame('{"feed":[]}', $answer->bytes);
	}

	public function testDirectMessagesGoToTheChatServiceSignedForIt(): void {
		$this->curl->expects($this->once())->method('doRequest')->with('post', 'https://api.bsky.chat/xrpc/chat.bsky.convo.sendMessage', $this->callback(function (array $options): bool {
			$claims = json_decode(Encoding::base64UrlDecode(explode('.', substr($options['headers']['Authorization'], 7))[1]), true);
			$this->assertSame(['did:web:api.bsky.chat#bsky_chat', 'chat.bsky.convo.sendMessage'], [$claims['aud'], $claims['lxm']]);
			$this->assertSame('{"convoId":"c1"}', $options['body']);
			$this->assertSame('device-123', $options['headers']['X-Atproto-Device-Id']);
			$this->assertSame('session-456', $options['headers']['X-Atproto-Session-Id']);

			return true;
		}))->willReturnCallback(static function (string $m, string $u, array $o, &$contentType, &$status): string {
			$status = 200;

			return '{}';
		});

		$this->proxy->forward($this->session, 'chat.bsky.convo.sendMessage', 'post', '', '{"convoId":"c1"}', [
			'atproto-proxy' => 'did:web:api.bsky.chat#bsky_chat',
			'content-type' => 'application/json',
			'x-atproto-device-id' => 'device-123',
			'x-atproto-session-id' => 'session-456',
		]);
	}

	public function testDeviceAndSessionHeaderValuesCannotInjectAnotherUpstreamHeader(): void {
		$this->curl->expects($this->once())->method('doRequest')->with('get', 'https://api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=alice.test', $this->callback(static function (array $options): bool {
			self::assertArrayNotHasKey('X-Atproto-Device-Id', $options['headers']);
			self::assertArrayNotHasKey('X-Atproto-Session-Id', $options['headers']);

			return true;
		}))->willReturnCallback(static function (string $method, string $url, array $options, &$contentType, &$status): string {
			$status = 200;
			$contentType = 'application/json';

			return '{}';
		});

		$this->proxy->forward($this->session, 'app.bsky.actor.getProfile', 'get', 'actor=alice.test', '', [
			'x-atproto-device-id' => "device-123\r\nX-Injected: yes",
			'x-atproto-session-id' => "session-456\nX-Injected: yes",
		]);
	}

	public function testOnlyTheConfiguredServicesAreATarget(): void {
		$this->curl->expects($this->never())->method('doRequest');
		foreach ([
			['app.bsky.feed.getTimeline', ['atproto-proxy' => 'did:web:evil.example#bsky_appview'], 'InvalidRequest'],
			['chat.bsky.convo.listConvos', ['atproto-proxy' => 'did:web:evil.example#bsky_chat'], 'InvalidRequest'],
		] as [$method, $headers, $error]) {
			try {
				$this->proxy->forward($this->session, $method, 'get', '', '', $headers);
				$this->fail($method);
			} catch (XrpcException $e) {
				$this->assertSame($error, $e->error);
			}
		}
		$this->chat = '';
		try {
			$this->proxy->forward($this->session, 'chat.bsky.convo.listConvos', 'get', '', '', []);
			$this->fail('direct messages turned off');
		} catch (XrpcException $e) {
			$this->assertSame('MethodNotImplemented', $e->error);
		}
		$this->assertTrue(AppViewProxy::isProxied('app.bsky.feed.getTimeline'));
		$this->assertFalse(AppViewProxy::isProxied('app.bsky.actor.getPreferences'), 'kept here');
		$this->assertFalse(AppViewProxy::isProxied('com.atproto.repo.createRecord'));
	}

	public function testPreferencesAreKeptHereAsTheAppWroteThem(): void {
		$stored = '[]';
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static function () use (&$stored): string {
			return $stored;
		});
		$config->method('setUserValue')->willReturnCallback(static function (string $user, string $app, string $key, string $value) use (&$stored): void {
			$stored = $value;
		});
		$preferences = new Preferences($config, $this->createMock(\OCA\Social\Atproto\Client\MutedWords::class), $this->createMock(\OCA\Social\Service\AccountService::class));
		$wanted = [['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => []], ['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false]];

		$this->assertSame([], $preferences->put($this->session, ['preferences' => $wanted]));
		$this->assertSame(['preferences' => $wanted], $preferences->get($this->session));
		foreach ([['preferences' => 'x'], ['preferences' => [['$type' => 'com.example.x']]]] as $bad) {
			try {
				$preferences->put($this->session, $bad);
				$this->fail('stored ' . json_encode($bad));
			} catch (XrpcException $e) {
				$this->assertSame('InvalidRequest', $e->error);
			}
		}
	}

	public function testTheMutedWordsAreTheFiltersNotWhatTheAppWrote(): void {
		$stored = '[]';
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static function () use (&$stored): string {
			return $stored;
		});
		$config->method('setUserValue')->willReturnCallback(static function (string $user, string $app, string $key, string $value) use (&$stored): void {
			$stored = $value;
		});
		$words = $this->createMock(\OCA\Social\Atproto\Client\MutedWords::class);
		$fromFilters = ['$type' => 'app.bsky.actor.defs#mutedWordsPref', 'items' => [['id' => 'f1k2', 'value' => 'spoilers', 'targets' => ['content', 'tag'], 'actorTarget' => 'all']]];
		$words->method('pref')->willReturn($fromFilters);
		$sent = ['$type' => 'app.bsky.actor.defs#mutedWordsPref', 'items' => [['value' => 'crypto', 'targets' => ['content']]]];
		$words->expects($this->once())->method('apply')->with($this->anything(), $sent);
		$preferences = new Preferences($config, $words, $this->createMock(\OCA\Social\Service\AccountService::class));
		$feeds = ['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => []];

		$preferences->put($this->session, ['preferences' => [$feeds, $sent]]);

		$this->assertSame([$feeds], json_decode($stored, true), 'the words are not kept beside the filters');
		$this->assertSame(['preferences' => [$feeds, $fromFilters]], $preferences->get($this->session));
	}

	public function testReplacingPreferencesWithoutMutedWordsClearsTheLocalProjection(): void {
		$config = $this->createMock(IConfig::class);
		$words = $this->createMock(\OCA\Social\Atproto\Client\MutedWords::class);
		$words->expects($this->once())->method('apply')->with(
			$this->anything(),
			['$type' => \OCA\Social\Atproto\Client\MutedWords::PREF, 'items' => []]
		);
		$preferences = new Preferences($config, $words, $this->createMock(\OCA\Social\Service\AccountService::class));

		$preferences->put($this->session, ['preferences' => [
			['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => []],
		]]);
	}

	public function testMalformedMutedWordsAreRejectedBeforePreferencesOrFiltersChange(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setUserValue');
		$words = $this->createMock(\OCA\Social\Atproto\Client\MutedWords::class);
		$words->expects($this->never())->method('apply');
		$preferences = new Preferences($config, $words, $this->createMock(\OCA\Social\Service\AccountService::class));
		$malformed = [
			['value' => ['unexpected'], 'targets' => ['content']],
			['value' => 'spoilers', 'targets' => [['unexpected']]],
			['value' => 'spoilers', 'targets' => ['unknown']],
			['value' => 'spoilers', 'targets' => ['content'], 'expiresAt' => ['unexpected']],
			['value' => 'spoilers', 'targets' => ['content'], 'actorTarget' => 'friends'],
		];

		foreach ($malformed as $item) {
			try {
				$preferences->put($this->session, ['preferences' => [
					['$type' => \OCA\Social\Atproto\Client\MutedWords::PREF, 'items' => [$item]],
				]]);
				$this->fail('accepted malformed muted word ' . json_encode($item));
			} catch (XrpcException $e) {
				$this->assertSame('InvalidRequest', $e->error);
			}
		}
	}
}
