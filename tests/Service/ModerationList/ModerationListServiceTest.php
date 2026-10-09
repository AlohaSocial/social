<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\ModerationList;

use OCA\Social\Atproto\Reader\BlueskyModerationLists;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\ModerationList\ModerationListService;
use OCA\Social\Service\TimelineRevisionService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ModerationListServiceTest extends TestCase {
	private const LIST = 'at://did:plc:owner/app.bsky.graph.list/3kmods';

	/** @var array<string, string> user and app values, by key */
	private array $values = [];
	/** @var array<string, true> "actor|object|type" */
	private array $relations = [];
	/** @var list<string> */
	private array $members = ['https://bsky.app/profile/did:plc:a', 'https://bsky.app/profile/did:plc:b'];
	/** @var list<array{string, string, bool}> */
	private array $told = [];
	private bool $known = true;

	private function service(): ModerationListService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $user, string $app, string $key, mixed $default = ''): string => $this->values["$user/$key"] ?? (string)$default);
		$config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, mixed $value): void {
			$this->values["$user/$key"] = (string)$value;
		});
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->values["app/$key"] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, mixed $value): void {
			$this->values["app/$key"] = (string)$value;
		});

		$relations = $this->createMock(ActorRelationRequest::class);
		$relations->method('exists')->willReturnCallback(fn (string $a, string $o, string $t): bool => isset($this->relations["$a|$o|$t"]));
		$relations->method('save')->willReturnCallback(function (string $a, string $o, string $t): void {
			$this->relations["$a|$o|$t"] = true;
		});
		$relations->method('delete')->willReturnCallback(function (string $a, string $o, string $t): void {
			unset($this->relations["$a|$o|$t"]);
		});

		$source = $this->createMock(BlueskyModerationLists::class);
		$source->method('describe')->willReturnCallback(fn (string $reference): ?array => $this->known ? ['uri' => self::LIST, 'name' => 'Spammers'] : null);
		$source->method('members')->willReturnCallback(fn (string $uri): array => $uri === self::LIST ? $this->members : []);
		$source->method('subscribed')->willReturnCallback(function (Person $viewer, string $uri, string $kind, bool $on): void {
			$this->told[] = [$uri, $kind, $on];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($source);

		return new ModerationListService($config, $relations, $this->createMock(TimelineRevisionService::class), new NullLogger(), $container);
	}

	private function alice(): Person {
		$person = new Person();
		$person->setId('https://social.test/users/alice');
		$person->setUserId('alice');

		return $person;
	}

	private function relation(string $object, string $type): string {
		return 'https://social.test/users/alice|' . $object . '|' . $type;
	}

	public function testSubscribingMutesEverybodyOnTheListAndSaysSoWhereItLives(): void {
		$service = $this->service();

		$this->assertSame(['uri' => self::LIST, 'name' => 'Spammers', 'kind' => 'mute', 'accounts' => 2], $service->subscribe($this->alice(), 'https://bsky.app/profile/owner/lists/3kmods', 'mute'));
		$this->assertArrayHasKey($this->relation('https://bsky.app/profile/did:plc:a', ActorRelation::TYPE_MUTE), $this->relations);
		$this->assertArrayHasKey($this->relation('https://bsky.app/profile/did:plc:b', ActorRelation::TYPE_MUTE), $this->relations);
		$this->assertSame([[self::LIST, 'mute', true]], $this->told);
		$this->assertSame([['uri' => self::LIST, 'name' => 'Spammers', 'kind' => 'mute', 'accounts' => 2]], $service->list('alice'));
		$this->assertSame(['alice'], $service->subscribers());
	}

	public function testUnsubscribingTakesBackOnlyWhatTheListDid(): void {
		$service = $this->service();
		$already = $this->relation('https://bsky.app/profile/did:plc:a', ActorRelation::TYPE_BLOCK);
		$this->relations[$already] = true;
		$service->subscribe($this->alice(), self::LIST, 'block');
		$this->assertSame(1, $service->list('alice')[0]['accounts'], 'the account blocked already is not the list\'s');

		$service->unsubscribe($this->alice(), self::LIST);

		$this->assertSame([$already => true], $this->relations);
		$this->assertSame([], $service->list('alice'));
		$this->assertSame([], $service->subscribers());
		$this->assertSame([self::LIST, 'block', false], $this->told[1]);
	}

	public function testARefreshFollowsTheList(): void {
		$service = $this->service();
		$service->subscribe($this->alice(), self::LIST, 'mute');
		$this->members = ['https://bsky.app/profile/did:plc:b', 'https://bsky.app/profile/did:plc:c'];

		$service->refresh($this->alice());

		$this->assertSame([
			$this->relation('https://bsky.app/profile/did:plc:b', ActorRelation::TYPE_MUTE) => true,
			$this->relation('https://bsky.app/profile/did:plc:c', ActorRelation::TYPE_MUTE) => true,
		], $this->relations);
	}

	public function testAListThatCannotBeReadNowLeavesItsAccountsAsTheyWere(): void {
		$service = $this->service();
		$service->subscribe($this->alice(), self::LIST, 'mute');
		$this->members = [];

		$service->refresh($this->alice());

		$this->assertCount(2, $this->relations);
		$this->assertSame(2, $service->list('alice')[0]['accounts']);
	}

	public function testTheSubscriberIsNeverOnTheirOwnList(): void {
		$this->members[] = 'https://social.test/users/alice';

		$this->assertSame(2, $this->service()->subscribe($this->alice(), self::LIST, 'block')['accounts']);
	}

	public function testOnlyAKnownListOfAKnownKind(): void {
		try {
			$this->service()->subscribe($this->alice(), self::LIST, 'follow');
			$this->fail('a list follows nobody');
		} catch (InvalidActionException) {
		}
		$this->known = false;
		$this->expectException(InvalidActionException::class);
		$this->service()->subscribe($this->alice(), 'https://example.com/list', 'mute');
	}
}
