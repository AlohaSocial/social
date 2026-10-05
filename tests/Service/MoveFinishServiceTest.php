<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveFinishService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class MoveFinishServiceTest extends TestCase {
	private const OLD = 'https://old.example/index.php/apps/social/@alice';
	private const CALLBACK = 'https://cloud.example/index.php/apps/social/migration/move-in/callback';

	private MigrationService|MockObject $migrationService;
	private FediverseDirectoryService|MockObject $directoryService;
	private CurlService|MockObject $curlService;
	private MoveFinishService $service;
	/** @var array<string, string> the user values written, by key */
	private array $userValues = [];
	/** @var array<int, array{url: string, body: array<string, mixed>, headers: array<string, string>}> every POST, in order */
	private array $posts = [];
	/** @var array<string, array<string, mixed>|\Throwable> what each URL answers */
	private array $answers = [];

	protected function setUp(): void {
		$old = new Person();
		$old->setId(self::OLD)->setPreferredUsername('alice')->setAccount('alice@old.example');
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->migrationService->method('resolveActor')->willReturn($old);

		$this->directoryService = $this->createMock(FediverseDirectoryService::class);
		$this->directoryService->method('softwareName')->willReturn('nextcloud-social');

		$this->curlService = $this->createMock(CurlService::class);
		$this->curlService->method('retrieveJson')->willReturnCallback(function (string $method, string $url, array $options): array {
			$this->posts[] = [
				'url' => $url,
				'body' => json_decode((string)($options['body'] ?? ''), true) ?? [],
				'headers' => $options['headers'] ?? [],
			];
			$answer = $this->answers[$url] ?? [];
			if ($answer instanceof \Throwable) {
				throw $answer;
			}

			return $answer;
		});

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialAddress')->willReturn('cloud.example');
		$configService->method('getSocialUrl')->willReturn('https://cloud.example/index.php/apps/social/');

		$me = new Person();
		$me->setId('https://cloud.example/index.php/apps/social/@bob')->setPreferredUsername('bob')->setUserId('bob');
		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturn($me);

		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $app, string $key, string $default = ''): string => $this->userValues[$key] ?? $default
		);
		$config->method('setUserValue')->willReturnCallback(function (string $userId, string $app, string $key, string $value): void {
			$this->userValues[$key] = $value;
		});

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn(self::CALLBACK);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn('state-of-the-art');

		$this->service = new MoveFinishService(
			$this->migrationService, $this->directoryService, $this->curlService, $configService,
			$accountService, $config, $urlGenerator, $random, new NullLogger()
		);
	}

	private function registered(): void {
		$this->answers['https://old.example/index.php/apps/social/api/v1/apps'] = ['client_id' => 'cid', 'client_secret' => 'sec'];
	}

	public function testOnlyAnAlohaSocialServerCanBeFinishedFrom(): void {
		$this->directoryService = $this->createMock(FediverseDirectoryService::class);
		$this->directoryService->method('softwareName')->willReturnCallback(static fn (string $host, string $scheme = 'https'): string => $host === 'old.example' && $scheme === 'https' ? 'nextcloud-social' : 'mastodon');

		$mastodon = new Person();
		$mastodon->setId('https://mastodon.example/users/alice');
		$old = new Person();
		$old->setId(self::OLD);

		$service = new MoveFinishService(
			$this->migrationService, $this->directoryService, $this->curlService, $this->createStub(ConfigService::class),
			$this->createStub(AccountService::class), $this->createStub(IConfig::class), $this->createStub(IURLGenerator::class),
			$this->createStub(ISecureRandom::class), new NullLogger()
		);
		$this->assertTrue($service->canFinish($old));
		$this->assertFalse($service->canFinish($mastodon), 'Mastodon has no API for a move; the person finishes there');
	}

	public function testStartRegistersThisInstanceThereAndSaysWhereToSendThePerson(): void {
		$this->registered();

		$url = $this->service->start('bob', '@alice@old.example');

		$this->assertCount(1, $this->posts);
		$this->assertSame('https://old.example/index.php/apps/social/api/v1/apps', $this->posts[0]['url']);
		$this->assertSame('write:migration', $this->posts[0]['body']['scopes'], 'the one scope, and nothing a phone app holds');
		$this->assertSame(self::CALLBACK, $this->posts[0]['body']['redirect_uris']);

		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		$this->assertStringStartsWith('https://old.example/index.php/apps/social/oauth/authorize?', $url);
		$this->assertSame('code', $query['response_type']);
		$this->assertSame('cid', $query['client_id']);
		$this->assertSame(self::CALLBACK, $query['redirect_uri']);
		$this->assertSame('write:migration', $query['scope']);
		$this->assertSame('state-of-the-art', $query['state']);
		$this->assertSame('pending', $this->service->status('bob')['status']);
		$this->assertSame('alice@old.example', $this->service->status('bob')['acct']);
	}

	public function testStartRefusesAServerThatIsNotThisApp(): void {
		$this->directoryService->method('softwareName')->willReturn('mastodon');
		$this->directoryService = $this->createMock(FediverseDirectoryService::class);
		$this->directoryService->method('softwareName')->willReturn('mastodon');
		$service = new MoveFinishService(
			$this->migrationService, $this->directoryService, $this->curlService, $this->createStub(ConfigService::class),
			$this->createStub(AccountService::class), $this->createStub(IConfig::class), $this->createStub(IURLGenerator::class),
			$this->createStub(ISecureRandom::class), new NullLogger()
		);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('finish the move there');
		$service->start('bob', '@alice@old.example');
	}

	public function testFinishTurnsTheCodeIntoATokenAndHasTheOldServerMoveTheFollowersHere(): void {
		$this->registered();
		$this->answers['https://old.example/index.php/apps/social/oauth/token'] = ['access_token' => 'tok', 'scope' => 'write:migration'];
		$this->answers['https://old.example/index.php/apps/social/api/v1/migration/move/authorized'] = ['moved_to' => 'https://cloud.example/index.php/apps/social/@bob'];
		$this->service->start('bob', '@alice@old.example');

		$status = $this->service->finish('bob', 'the-code', 'state-of-the-art');

		[, $token, $move] = $this->posts;
		$this->assertSame('authorization_code', $token['body']['grant_type']);
		$this->assertSame('the-code', $token['body']['code']);
		$this->assertSame('cid', $token['body']['client_id']);
		$this->assertSame('sec', $token['body']['client_secret']);
		$this->assertSame(self::CALLBACK, $token['body']['redirect_uri'], 'the code is only good for the URI it was sent to');
		$this->assertSame('Bearer tok', $move['headers']['Authorization']);
		$this->assertSame('https://cloud.example/index.php/apps/social/@bob', $move['body']['target'], 'the new account by its id, which every server resolves as it is');
		$this->assertSame('done', $status['status']);
		$this->assertSame('alice@old.example', $status['acct']);
		$this->assertSame('done', $this->service->status('bob')['status'], 'kept for the page the person comes back to');
	}

	public function testFinishRefusesACodeThatIsNotForTheMoveThatWasStarted(): void {
		$this->registered();
		$this->service->start('bob', '@alice@old.example');

		try {
			$this->service->finish('bob', 'the-code', 'somebody-elses-state');
			$this->fail('expected the state to be checked');
		} catch (InvalidResourceException $e) {
			$this->assertStringContainsString('not the move that was started', $e->getMessage());
		}
		$this->assertCount(1, $this->posts, 'nothing was sent to the old server');
		$this->assertSame('pending', $this->service->status('bob')['status']);
	}

	public function testFinishWithNothingStartedIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('no move was started');
		$this->service->finish('bob', 'the-code', 'state-of-the-art');
	}

	public function testTheOldServersRefusalIsKeptWithItsReason(): void {
		$this->registered();
		$this->answers['https://old.example/index.php/apps/social/oauth/token'] = ['access_token' => 'tok'];
		$this->answers['https://old.example/index.php/apps/social/api/v1/migration/move/authorized']
			= new RequestContentException('this account moved on 2026-10-01 and can move again on 2026-10-31', 422);
		$this->service->start('bob', '@alice@old.example');

		try {
			$this->service->finish('bob', 'the-code', 'state-of-the-art');
			$this->fail('expected the refusal to surface');
		} catch (InvalidResourceException $e) {
			$this->assertStringContainsString('can move again on 2026-10-31', $e->getMessage());
		}
		$status = $this->service->status('bob');
		$this->assertSame('failed', $status['status']);
		$this->assertStringContainsString('can move again on 2026-10-31', $status['error']);
	}

	public function testAStartNobodyCameBackFromExpires(): void {
		$this->registered();
		$this->service->start('bob', '@alice@old.example');
		$pending = json_decode($this->userValues['move_finish'], true);
		$pending['created'] = time() - MoveFinishService::TTL - 1;
		$this->userValues['move_finish'] = (string)json_encode($pending);

		$this->assertSame('failed', $this->service->status('bob')['status']);
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('took too long');
		$this->service->finish('bob', 'the-code', 'state-of-the-art');
	}
}
