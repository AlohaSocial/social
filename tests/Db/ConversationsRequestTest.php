<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ConversationsRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The thread walk, which is the part of a conversation that is not stored.
 *
 * What is asserted here is what a remote server cannot be trusted not to do:
 * a reply chain deeper than any human wrote, a thread wider than memory, and
 * `in_reply_to` pointing back at itself. Each of those is a loop holding a
 * database connection if the walk is written without a bound.
 *
 * The statements themselves need a real database and are exercised by the
 * integration suite; the levels they are called in are what this covers.
 */
#[AllowMockObjectsWithoutExpectations]
class ConversationsRequestTest extends TestCase {
	private const ACTOR = 'https://cloud.example/users/alice';

	/** @var array<int, array{0: string[], 1: int}> what each level was asked for */
	private array $levels = [];
	/** @var string[]|null the ids the dest filter was handed, null if never asked */
	private ?array $filtered = null;

	/**
	 * A request whose four statements are replaced: the constructor takes an
	 * IDBConnection, and mocking one needs DBAL, which the standalone suite
	 * cannot load.
	 *
	 * @param array<string, string> $replies child id => parent id
	 * @param string[]|null $direct the ids the account may see, null for all
	 *
	 * @return ConversationsRequest&MockObject
	 */
	private function request(array $replies, ?array $direct = null, bool $rootExists = true) {
		$request = $this->getMockBuilder(ConversationsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getThreadLinks', 'getRepliesTo', 'getDirectPostsById'])
			->getMock();

		$request->method('getThreadLinks')
			->willReturnCallback(function (array $ids) use ($replies, $rootExists): array {
				$links = [];
				foreach ($ids as $id) {
					if ($rootExists) {
						$links[$id] = $this->link($id, $replies[$id] ?? '');
					}
				}

				return $links;
			});

		$request->method('getRepliesTo')
			->willReturnCallback(function (array $ids, int $limit) use ($replies): array {
				$this->levels[] = [$ids, $limit];

				$level = [];
				foreach ($replies as $child => $parent) {
					if (count($level) >= $limit) {
						break;
					}

					if (in_array($parent, $ids, true)) {
						$level[$child] = $this->link($child, $parent);
					}
				}

				return $level;
			});

		$request->method('getDirectPostsById')
			->willReturnCallback(function (string $actorId, array $ids) use ($direct, $replies): array {
				$this->filtered = $ids;

				$posts = [];
				foreach ($ids as $id) {
					if ($direct === null || in_array($id, $direct, true)) {
						$posts[$id] = $this->link($id, $replies[$id] ?? '');
					}
				}

				return $posts;
			});

		return $request;
	}

	/** @return array{id: string, idPrim: string, nid: int, inReplyTo: string} */
	private function link(string $id, string $parent): array {
		return [
			'id' => $id,
			'idPrim' => md5($id),
			'nid' => (int)substr($id, strrpos($id, '/') + 1),
			'inReplyTo' => $parent,
		];
	}

	public function testAThreadIsTheRootAndEverythingUnderIt(): void {
		$thread = $this->request([
			'https://a/2' => 'https://a/1',
			'https://a/3' => 'https://a/2',
			'https://b/1' => 'https://b/0',
		])->getThread('https://a/1');

		$this->assertSame(
			['https://a/1', 'https://a/2', 'https://a/3'], array_keys($thread)
		);
	}

	public function testARootThisInstanceDoesNotStoreIsNoThreadAtAll(): void {
		$request = $this->request([], null, false);

		$this->assertSame([], $request->getThread('https://a/1'));
		$this->assertSame([], $request->getThreadFor(self::ACTOR, 'https://a/1'));
		$this->assertNull($this->filtered, 'and nothing is asked about who may see it');
	}

	public function testTheWalkStopsAtTheDepthBound(): void {
		// a reply chain longer than the bound is one a remote server made
		$replies = [];
		for ($i = 1; $i < 60; $i++) {
			$replies['https://a/' . ($i + 1)] = 'https://a/' . $i;
		}

		$thread = $this->request($replies)->getThread('https://a/1');

		$this->assertCount(ConversationsRequest::MAX_THREAD_DEPTH, $this->levels);
		$this->assertCount(ConversationsRequest::MAX_THREAD_DEPTH + 1, $thread, 'the root and one post a level');
	}

	public function testTheWalkStopsAtTheWidthBound(): void {
		$replies = [];
		for ($i = 2; $i < 500; $i++) {
			// every post answers the root, so one level is as wide as the thread
			$replies['https://a/' . $i] = 'https://a/1';
		}

		$thread = $this->request($replies)->getThread('https://a/1');

		$this->assertCount(ConversationsRequest::MAX_THREAD_WIDTH, $thread);
		foreach ($this->levels as [$ids, $limit]) {
			$this->assertGreaterThan(0, $limit, 'a level is never asked for a negative page');
		}
	}

	public function testAReplyPointingBackUpTheThreadDoesNotLoop(): void {
		$thread = $this->request([
			'https://a/2' => 'https://a/1',
			'https://a/1' => 'https://a/2',
		])->getThread('https://a/1');

		$this->assertSame(['https://a/1', 'https://a/2'], array_keys($thread));
	}

	public function testAThreadIsNarrowedToWhatTheAccountMaySee(): void {
		// an account brought into an exchange halfway down is a recipient of
		// nothing above where it joined, and the walk must still reach it
		$request = $this->request(
			[
				'https://a/2' => 'https://a/1',
				'https://a/3' => 'https://a/2',
			],
			['https://a/3']
		);

		$thread = $request->getThreadFor(self::ACTOR, 'https://a/1');

		$this->assertSame(['https://a/3'], array_keys($thread));
		$this->assertSame(
			['https://a/1', 'https://a/2', 'https://a/3'], $this->filtered,
			'the whole thread is walked, and only then narrowed'
		);
	}

	public function testAThreadTheAccountIsNoPartOfIsEmpty(): void {
		$request = $this->request(['https://a/2' => 'https://a/1'], []);

		$this->assertSame([], $request->getThreadFor(self::ACTOR, 'https://a/1'));
	}

	public function testAnIdThatCannotBeAPostIsNotAskedAbout(): void {
		// the id columns are md5s of URLs: anything else matches no row, and
		// the real statement is never built for it
		$request = $this->getMockBuilder(ConversationsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getRepliesTo'])
			->getMock();

		$this->assertSame([], $request->getThreadLinks([]));
		$this->assertSame([], $request->getThreadLinks(['', 'not-a-url', '4']));
		$this->assertSame([], $request->getMarkers(self::ACTOR, ['nonsense']));
	}
}
