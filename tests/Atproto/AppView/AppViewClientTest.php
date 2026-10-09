<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\AppView;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AppViewClientTest extends TestCase {
	public function testAProcedureIsAPostOfItsInputAsThePerson(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appViewAuth')->willReturn('https://api.bsky.app');
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$auth = $this->createMock(ServiceAuth::class);
		$auth->method('token')->with($this->anything(), 'did:plc:alice', 'did:web:api.bsky.app', 'app.bsky.notification.putActivitySubscription')->willReturn('signed');
		$sent = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $verb, string $url, array $options, &$contentType, &$status) use (&$sent): string {
			$sent = [$verb, $url, $options['headers']['Authorization'], $options['headers']['Content-Type'] ?? '', json_decode($options['body'], true)];
			$status = 200;

			return '{"subject":"did:plc:bob"}';
		});

		$answer = (new AppViewClient($config, $auth, $curl, new NullLogger()))->procedureAs('did:plc:alice', PrivateKey::generate(Curve::K256), 'app.bsky.notification.putActivitySubscription', ['subject' => 'did:plc:bob']);

		$this->assertSame(['subject' => 'did:plc:bob'], $answer);
		$this->assertSame(['post', 'https://api.bsky.app/xrpc/app.bsky.notification.putActivitySubscription', 'Bearer signed', 'application/json', ['subject' => 'did:plc:bob']], $sent);
	}

	public function testAProcedureWithoutOutputAnswersNothingAndAQueryMustAnswerJson(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appView')->willReturn('https://public.api.bsky.app');
		$config->method('appViewAuth')->willReturn('https://api.bsky.app');
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $verb, string $url, array $options, &$contentType, &$status): string {
			$status = 200;

			return '';
		});
		$client = new AppViewClient($config, $this->createMock(ServiceAuth::class), $curl, new NullLogger());

		$this->assertSame([], $client->procedureAs('did:plc:alice', PrivateKey::generate(Curve::K256), 'app.bsky.graph.muteActorList', ['list' => 'at://did:plc:bob/app.bsky.graph.list/3k']));
		$this->expectException(AtprotoException::class);
		$client->query('app.bsky.actor.getProfile', ['actor' => 'did:plc:bob']);
	}
}
