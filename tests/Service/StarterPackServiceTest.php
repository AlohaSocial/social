<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\StarterPack;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StarterPackService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class StarterPackServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	private ConfigService|Stub $configService;
	private CacheActorService|MockObject $cacheActorService;
	private FollowService|Stub $followService;
	private StarterPackService $service;

	/** @var string[] handles that resolve; anything else raises */
	private array $resolvable = [];
	/** @var string[] what followAccount was asked to follow */
	private array $followed = [];
	private string $configured = '';

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string
				=> ($key === StarterPackService::CONFIG_KEY) ? $this->configured : '');

		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $handle): Person {
				if (!in_array($handle, $this->resolvable, true)) {
					throw new RuntimeException('unreachable');
				}

				$person = new Person();
				$person->setId('https://' . explode('@', $handle)[1] . '/users/' . explode('@', $handle)[0]);
				$person->setAccount($handle);

				return $person;
			});

		$this->followService = $this->createStub(FollowService::class);
		$this->followService->method('followAccount')
			->willReturnCallback(function (Person $actor, string $account): bool {
				if (!in_array($account, $this->resolvable, true)) {
					throw new RuntimeException('cannot follow');
				}
				$this->followed[] = $account;

				return true;
			});

		$this->service = new StarterPackService(
			$this->configService, $this->cacheActorService, $this->followService, new NullLogger()
		);
	}

	private function viewer(): Person {
		$person = new Person();
		$person->setId(self::VIEWER);

		return $person;
	}

	public function testTheShippedPacksAreThere(): void {
		$packs = $this->service->packs();

		$this->assertNotEmpty($packs);
		foreach ($packs as $pack) {
			$this->assertSame(StarterPack::SOURCE_BUILTIN, $pack->getSource());
			$this->assertNotSame('', $pack->getName());
			$this->assertNotEmpty($pack->getHandles());
		}
	}

	/**
	 * The index is names and counts. Resolving a handle costs a WebFinger
	 * lookup and an actor fetch against another server, and doing that to draw
	 * a list of names would make the page wait on the internet for nothing.
	 */
	public function testTheIndexResolvesNobody(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');

		$packs = $this->service->packs();

		foreach ($packs as $pack) {
			$this->assertSame([], $pack->getAccounts());
			$this->assertGreaterThan(0, $pack->jsonSerialize()['size']);
		}
	}

	public function testOpeningAPackResolvesItsHandles(): void {
		$slug = $this->service->packs()[0]->getSlug();
		$this->resolvable = $this->service->packs()[0]->getHandles();

		$pack = $this->service->pack($slug);

		$this->assertCount(count($this->resolvable), $pack->getAccounts());
		$this->assertSame([], $pack->getUnresolved());
	}

	/**
	 * A pack that quietly shrinks looks like one somebody wrote badly, when what
	 * happened is that a server was down or an account moved.
	 */
	public function testAHandleThatWillNotResolveIsReportedNotDropped(): void {
		$first = $this->service->packs()[0];
		$this->resolvable = [$first->getHandles()[0]];

		$pack = $this->service->pack($first->getSlug());

		$this->assertCount(1, $pack->getAccounts());
		$this->assertSame(
			array_slice($first->getHandles(), 1),
			$pack->getUnresolved(),
			'the unreachable handles were dropped instead of reported'
		);
	}

	public function testAnUnknownSlugIsANotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service->pack('no-such-pack');
	}

	public function testAnAdministratorCanAddAPack(): void {
		$this->configured = json_encode([[
			'slug' => 'local-folk',
			'name' => 'People here',
			'description' => 'the ones nearby',
			'handles' => ['bob@example.org'],
		]]);

		$packs = $this->service->packs();
		$mine = array_values(array_filter($packs, fn (StarterPack $p): bool => $p->getSlug() === 'local-folk'));

		$this->assertCount(1, $mine);
		$this->assertSame(StarterPack::SOURCE_LOCAL, $mine[0]->getSource());
		$this->assertSame(['bob@example.org'], $mine[0]->getHandles());
	}

	/** A configured pack with a shipped slug edits it rather than sitting beside it. */
	public function testAConfiguredPackReplacesAShippedOneOfTheSameSlug(): void {
		$shipped = $this->service->packs()[0]->getSlug();
		$this->configured = json_encode([[
			'slug' => $shipped,
			'name' => 'Ours instead',
			'handles' => ['bob@example.org'],
		]]);

		$packs = $this->service->packs();
		$matching = array_values(array_filter($packs, fn (StarterPack $p): bool => $p->getSlug() === $shipped));

		$this->assertCount(1, $matching, 'the shipped pack was duplicated rather than replaced');
		$this->assertSame('Ours instead', $matching[0]->getName());
		$this->assertSame(StarterPack::SOURCE_LOCAL, $matching[0]->getSource());
	}

	/**
	 * Three states, deliberately distinguishable: unset is "whatever ships", an
	 * empty list is "suggest nobody", a list is "these as well".
	 */
	public function testAnEmptyConfigMeansSuggestNobody(): void {
		$this->configured = json_encode([]);

		$this->assertSame([], $this->service->packs());
	}

	public function testAnUnsetConfigMeansWhateverShips(): void {
		$this->configured = '';

		$this->assertNotEmpty($this->service->packs());
	}

	/**
	 * A typo in a config value must not be why a page is blank with no
	 * explanation.
	 */
	public function testMalformedConfigIsIgnoredRatherThanRaised(): void {
		foreach (['not json at all', '{"not":"a list"}', '[', 'null'] as $rubbish) {
			$this->configured = $rubbish;

			$this->assertNotEmpty($this->service->packs(), "rubbish config emptied the packs: $rubbish");
		}
	}

	public static function badHandleProvider(): array {
		return [
			'no host' => ['alice'],
			'no tld' => ['alice@localhost'],
			'a url' => ['https://example.org/users/alice'],
			'spaces' => ['alice @ example.org'],
			'empty' => [''],
			'injection attempt' => ['alice@example.org/../../etc/passwd'],
		];
	}

	/**
	 * These strings reach a WebFinger lookup. One that is not a handle is
	 * refused here rather than turned into a request to whatever it resembles.
	 */
	#[DataProvider('badHandleProvider')]
	public function testAHandleThatIsNotOneIsRefused(string $handle): void {
		$this->configured = json_encode([[
			'slug' => 'bad', 'name' => 'bad', 'handles' => [$handle],
		]]);

		$slugs = array_map(fn (StarterPack $p): string => $p->getSlug(), $this->service->packs());

		$this->assertNotContains('bad', $slugs, "a pack was built from the handle '$handle'");
	}

	public function testALeadingAtIsAccepted(): void {
		$this->configured = json_encode([[
			'slug' => 'ats', 'name' => 'ats', 'handles' => ['@bob@example.org'],
		]]);

		$pack = $this->service->pack('ats');

		$this->assertSame(['bob@example.org'], $pack->getHandles());
	}

	public function testADuplicateHandleIsListedOnce(): void {
		$this->configured = json_encode([[
			'slug' => 'dupes', 'name' => 'dupes',
			'handles' => ['bob@example.org', '@bob@example.org', 'bob@example.org'],
		]]);

		$this->assertSame(['bob@example.org'], $this->service->pack('dupes')->getHandles());
	}

	public function testAPackWithNoUsableHandlesIsNotAPack(): void {
		$this->configured = json_encode([['slug' => 'empty', 'name' => 'empty', 'handles' => []]]);

		$slugs = array_map(fn (StarterPack $p): string => $p->getSlug(), $this->service->packs());

		$this->assertNotContains('empty', $slugs);
	}

	// following

	public function testFollowingAPackFollowsEveryoneInIt(): void {
		$this->configured = json_encode([[
			'slug' => 'three', 'name' => 'three',
			'handles' => ['a@example.org', 'b@example.org', 'c@example.org'],
		]]);
		$this->resolvable = ['a@example.org', 'b@example.org', 'c@example.org'];

		$followed = $this->service->followAll($this->viewer(), 'three');

		$this->assertSame($this->resolvable, $followed);
		$this->assertSame($this->resolvable, $this->followed);
	}

	/**
	 * The point of the button is that nobody follows six accounts by hand.
	 * Refusing all six because one host is down would defeat it.
	 */
	public function testOneUnreachableAccountDoesNotStopTheRest(): void {
		$this->configured = json_encode([[
			'slug' => 'three', 'name' => 'three',
			'handles' => ['a@example.org', 'gone@example.org', 'c@example.org'],
		]]);
		$this->resolvable = ['a@example.org', 'c@example.org'];

		$followed = $this->service->followAll($this->viewer(), 'three');

		$this->assertSame(['a@example.org', 'c@example.org'], $followed);
	}

	public function testTheViewerIsNotMadeToFollowThemselves(): void {
		$this->configured = json_encode([[
			'slug' => 'me', 'name' => 'me', 'handles' => ['alice@cloud.example', 'b@example.org'],
		]]);
		$this->resolvable = ['alice@cloud.example', 'b@example.org'];

		// the viewer's own id is what the resolver builds for that handle
		$viewer = new Person();
		$viewer->setId('https://cloud.example/users/alice');

		$followed = $this->service->followAll($viewer, 'me');

		$this->assertSame(['b@example.org'], $followed);
	}
}
