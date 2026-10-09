<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskySearch;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Service\SearchService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskySearchTest extends TestCase {
	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	private BlueskySearch $search;

	protected function setUp(): void {
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->willReturnCallback(static function (string $type) {
			$item = $type === Image::TYPE ? new Image() : new Person();
			$item->setUrlCloud('https://social.test');

			return $item;
		});
		$ap->method('getItemFromData')->willReturnCallback(static function (array $data): Person {
			$person = new Person();
			$person->setUrlCloud('https://social.test');
			$person->import($data);

			return $person;
		});
		AP::set($ap);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->search = new BlueskySearch($config, $this->appView, new ActorMapper(), $this->createMock(Blocklist::class), new NullLogger());
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testOnlyTheStartOfAHandleIsACandidate(): void {
		$this->assertTrue($this->search->isCandidate('alice.bs'));
		$this->assertTrue($this->search->isCandidate('@alice.bsky.social'));
		$this->assertFalse($this->search->isCandidate('alice'), 'a plain username stays on the instance');
		$this->assertFalse($this->search->isCandidate('alice@mastodon.social'), 'a Fediverse address');
		$this->assertFalse($this->search->isCandidate('alice bsky'), 'words');
		$this->assertFalse($this->search->isCandidate('https://bsky.app/profile/alice'));
		$this->assertFalse($this->search->isCandidate(''));
		$off = $this->createMock(AtprotoConfig::class);
		$off->method('isEnabled')->willReturn(false);
		$this->assertFalse((new BlueskySearch($off, $this->appView, new ActorMapper(), $this->createMock(Blocklist::class), new NullLogger()))->isCandidate('alice.bsky.social'));
	}

	public function testTheTypeaheadAnswersUnstoredAccounts(): void {
		$this->appView->expects($this->once())->method('query')->with('app.bsky.actor.searchActorsTypeahead', ['q' => 'alice.b', 'limit' => 8])->willReturn(['actors' => [
			['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'handle' => 'alice.bsky.social', 'displayName' => 'Alice', 'avatar' => 'https://cdn.bsky.app/a@jpeg'],
			['did' => 'did:plc:z72i7hdynmk6r22z27h6tvur', 'handle' => 'alice.blue'],
			['did' => '', 'handle' => 'nobody'],
			'junk',
		]]);
		$people = $this->search->typeahead('@alice.b');
		$this->assertCount(2, $people);
		$this->assertSame('alice.bsky.social', $people[0]->getAccount());
		$this->assertSame('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz', $people[0]->getId());
		$this->assertTrue($people[0]->getDetails('bluesky')['native']);
		$this->assertSame([], $this->search->typeahead('alice'), 'not a candidate, not asked');
	}

	public function testAnUnreachableAppViewAnswersNothing(): void {
		$this->appView->method('query')->willThrowException(new AtprotoException('down'));
		$this->assertSame([], $this->search->typeahead('alice.bsky.social'));
	}

	public function testBlueskyResultsFollowTheCachedOnesWithoutRepeats(): void {
		$cached = new Person();
		$cached->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz');
		$same = new Person();
		$same->setId($cached->getId());
		$other = new Person();
		$other->setId('https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur');
		$this->assertSame([$cached, $other], SearchService::withBluesky([$cached], [$same, $other]));
	}

	public function testABlockedAccountIsNotOffered(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$blocklist = $this->createMock(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturnCallback(static fn (string $did): bool => $did === 'did:plc:z72i7hdynmk6r22z27h6tvur');
		$this->appView->method('query')->willReturn(['actors' => [
			['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'handle' => 'alice.bsky.social'],
			['did' => 'did:plc:z72i7hdynmk6r22z27h6tvur', 'handle' => 'alice.evil.example'],
		]]);
		$people = (new BlueskySearch($config, $this->appView, new ActorMapper(), $blocklist, new NullLogger()))->typeahead('alice.');
		$this->assertSame(['alice.bsky.social'], array_map(static fn ($p): string => $p->getAccount(), $people));
	}

	/**
	 * An account with no stored row has no id: a client could neither open
	 * it nor follow it, and `/api/v1/accounts/0/follow` is all it would have.
	 */
	public function testAnAccountFoundIsTheCachedOneOrIsStoredFirst(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->appView->method('query')->willReturn(['actors' => [
			['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'handle' => 'alice.bsky.social'],
			['did' => 'did:plc:z72i7hdynmk6r22z27h6tvur', 'handle' => 'bsky.app'],
		]]);
		$known = new Person();
		$known->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz')->setNid(41);
		$stored = [];
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->willReturnCallback(static function (string $did) use ($known, &$stored): ?Person {
			if ($did === 'did:plc:ewvi7nxzyoun6zhxrhs64oiz') {
				return $known;
			}

			return isset($stored[$did]) ? (clone $stored[$did])->setNid(42) : null;
		});
		$actors->expects($this->once())->method('store')->willReturnCallback(static function (Person $person) use (&$stored): void {
			$stored['did:plc:z72i7hdynmk6r22z27h6tvur'] = $person;
		});

		$people = (new BlueskySearch($config, $this->appView, new ActorMapper(), $this->createMock(Blocklist::class), new NullLogger(), $actors))->typeahead('alice.');

		$this->assertSame([41, 42], array_map(static fn (Person $p): int => (int)$p->getNid(), $people));
	}
}
