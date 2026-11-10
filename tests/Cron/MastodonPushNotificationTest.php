<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use OCA\Social\Cron\MastodonPushNotification;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\MastodonPushContent;
use OCA\Social\Service\PushSubscriptionService;
use OCA\Social\Service\SafeWebPushClient;
use OCA\Social\Service\VapidService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

class MastodonPushNotificationTest extends TestCase {
	private const ACTOR = 'https://remote.example/users/bob';
	private const RECIPIENT = 'https://social.example/users/alice';

	public function testPushBodyUsesTheContentWarningBeforeThePostText(): void {
		$post = new Stream();
		$post->setContent('<p>Full post &amp; more text</p>');
		$post->setSpoilerText('<p>Content warning &amp; details</p>');
		$actor = new Person();
		$actor->setDescription('Profile description');

		self::assertSame('Content warning & details', MastodonPushContent::body($post, $actor));
	}

	public function testPushBodyUsesProfileDescriptionWhenThereIsNoStatus(): void {
		$actor = new Person();
		$actor->setDescription('<p>Followed you &amp; said hello</p>');

		self::assertSame('Followed you & said hello', MastodonPushContent::profileBody($actor));
	}

	public function testPushBodyFallsBackToProfileDescriptionWhenTheStatusHasNoText(): void {
		$post = new Stream();
		$actor = new Person();
		$actor->setDescription('<p>Account description</p>');

		self::assertSame('Account description', MastodonPushContent::body($post, $actor));
	}

	public function testPushBodyIsLimitedToMastodons140CharacterExcerpt(): void {
		$post = new Stream();
		$post->setContent('<p>' . str_repeat('x', 160) . '</p>');
		$actor = new Person();

		self::assertSame(str_repeat('x', 139) . '…', MastodonPushContent::body($post, $actor));
	}

	public function testSendsAnEncryptedStandardWebPushAndRemovesExpiredSubscriptions(): void {
		$endpoint = $this->subscription(true);
		$notification = $this->notification();
		$recipient = new Person();
		$recipient->setId(self::RECIPIENT)->setUserId('alice');

		$streams = $this->createStub(StreamRequest::class);
		$streams->method('getStreamByNid')->willReturn($notification);
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($recipient);
		$subscriptions = $this->createMock(PushSubscriptionService::class);
		$subscriptions->method('forUser')->with('alice')->willReturn([$endpoint]);
		$subscriptions->expects(self::once())->method('deleteById')->with('alice', $endpoint['id'])->willReturn(true);

		$request = null;
		$client = $this->createMock(SafeWebPushClient::class);
		$client->expects(self::once())->method('sendRequest')->willReturnCallback(
			function (RequestInterface $sent) use (&$request): Response {
				$request = $sent;

				return new Response(410);
			}
		);

		$this->runJob($streams, $actors, $subscriptions, $client);

		self::assertNotNull($request);
		self::assertSame('POST', $request->getMethod());
		self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
		self::assertStringNotContainsString('secret-bearer-token', (string)$request->getBody());
	}

	public function testUsesLegacyEncodingWhenTheSubscriptionDidNotNegotiateStandard(): void {
		$endpoint = $this->subscription(false);
		$notification = $this->notification();
		$recipient = new Person();
		$recipient->setId(self::RECIPIENT)->setUserId('alice');
		$streams = $this->createStub(StreamRequest::class);
		$streams->method('getStreamByNid')->willReturn($notification);
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($recipient);
		$subscriptions = $this->createStub(PushSubscriptionService::class);
		$subscriptions->method('forUser')->willReturn([$endpoint]);
		$request = null;
		$client = $this->createMock(SafeWebPushClient::class);
		$client->expects(self::once())->method('sendRequest')->willReturnCallback(
			function (RequestInterface $sent) use (&$request): Response {
				$request = $sent;

				return new Response(201);
			}
		);

		$this->runJob($streams, $actors, $subscriptions, $client);

		self::assertNotNull($request);
		self::assertSame('aesgcm', $request->getHeaderLine('Content-Encoding'));
	}

	public function testRemovesSubscriptionsRejectedByThePushProvider(): void {
		$endpoint = $this->subscription(true);
		$notification = $this->notification();
		$recipient = new Person();
		$recipient->setId(self::RECIPIENT)->setUserId('alice');
		$streams = $this->createStub(StreamRequest::class);
		$streams->method('getStreamByNid')->willReturn($notification);
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($recipient);
		$subscriptions = $this->createMock(PushSubscriptionService::class);
		$subscriptions->method('forUser')->willReturn([$endpoint]);
		$subscriptions->expects(self::once())->method('deleteById')->with('alice', $endpoint['id'])->willReturn(true);
		$client = $this->createMock(SafeWebPushClient::class);
		$client->expects(self::once())->method('sendRequest')->willReturn(new Response(403));

		$this->runJob($streams, $actors, $subscriptions, $client);
	}

	public function testADisabledAccountsDevicesAreSentNothing(): void {
		$recipient = new Person();
		$recipient->setId(self::RECIPIENT)->setUserId('alice');
		$streams = $this->createStub(StreamRequest::class);
		$streams->method('getStreamByNid')->willReturn($this->notification());
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($recipient);
		$subscriptions = $this->createMock(PushSubscriptionService::class);
		$subscriptions->method('forUser')->willReturn([$this->subscription(true)]);
		$subscriptions->expects(self::never())->method('deleteById');
		$client = $this->createMock(SafeWebPushClient::class);
		$client->expects(self::never())->method('sendRequest');

		$this->runJob($streams, $actors, $subscriptions, $client, false);
	}

	private function runJob(StreamRequest $streams, ActorsRequest $actors, PushSubscriptionService $subscriptions, SafeWebPushClient $client, bool $enabled = true): void {
		$keys = VAPID::createVapidKeys();
		$vapid = $this->createStub(VapidService::class);
		$vapid->method('auth')->willReturn([
			'subject' => 'https://social.example',
			'publicKey' => $keys['publicKey'],
			'privateKey' => $keys['privateKey'],
		]);
		$localizer = $this->createStub(IL10N::class);
		$localizer->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : sprintf($text, ...$parameters));
		$l10n = $this->createStub(IFactory::class);
		$l10n->method('get')->willReturn($localizer);
		$user = $this->createStub(IUser::class);
		$user->method('isEnabled')->willReturn($enabled);
		$manager = $this->createStub(IUserManager::class);
		$manager->method('get')->willReturn($user);
		$jobList = $this->createStub(IJobList::class);
		$time = $this->createStub(ITimeFactory::class);
		$job = new MastodonPushNotification(
			$time,
			$streams,
			$actors,
			$this->createStub(FollowsRequest::class),
			$subscriptions,
			$vapid,
			$client,
			$l10n,
			$manager,
			$jobList,
			new NullLogger(),
		);

		(new \ReflectionMethod($job, 'run'))->invoke($job, ['user' => 'alice', 'nid' => '123']);
	}

	/** @return array{id: string, token: string, accessToken: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int} */
	private function subscription(bool $standard): array {
		$keys = VAPID::createVapidKeys();
		$auth = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

		return [
			'id' => '17',
			'token' => hash('sha256', 'secret-bearer-token'),
			'accessToken' => 'secret-bearer-token',
			'endpoint' => 'https://push.example.test/subscription',
			'p256dh' => $keys['publicKey'],
			'auth' => $auth,
			'standard' => $standard,
			'alerts' => ['favourite' => true],
			'policy' => 'all',
			'created' => 1,
		];
	}

	private function notification(): SocialAppNotification {
		$notification = new SocialAppNotification();
		$notification->setNid(123);
		$notification->setTo(self::RECIPIENT);
		$notification->setAttributedTo(self::ACTOR);
		$notification->setSubType(Like::TYPE);
		$actor = new Person();
		$actor->setId(self::ACTOR)->setDisplayName('Bob');
		$notification->setActor($actor);

		return $notification;
	}
}
