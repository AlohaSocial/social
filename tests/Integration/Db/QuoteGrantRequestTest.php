<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Model\QuoteGrant;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The permissions an author has given to quote their posts: one per pair of
 * posts, rewritten rather than duplicated, and gone with either post.
 */
class QuoteGrantRequestTest extends TestCase {
	private const TARGET = 'https://itest.example/posts/quoted';
	private const QUOTE_1 = 'https://itest.example/posts/quote-1';
	private const QUOTE_2 = 'https://itest.example/posts/quote-2';
	private const QUOTE_3 = 'https://itest.example/posts/quote-3';

	private QuoteGrantRequest $grants;

	protected function setUp(): void {
		parent::setUp();
		$this->grants = Server::get(QuoteGrantRequest::class);
		$this->grants->deleteRelatedId(self::TARGET);
	}

	protected function tearDown(): void {
		$this->grants->deleteRelatedId(self::TARGET);
		parent::tearDown();
	}

	private function grant(string $quoting, string $authorization = 'https://itest.example/stamps/1'): QuoteGrant {
		return (new QuoteGrant())
			->setTargetId(self::TARGET)
			->setQuotingId($quoting)
			->setActorId('https://itest.example/users/quoter')
			->setRequestId('https://itest.example/requests/1')
			->setAuthorization($authorization);
	}

	public function testAGrantIsKeptAndGivingItAgainUpdatesIt(): void {
		$this->assertNull($this->grants->get(self::TARGET, self::QUOTE_1));

		$this->grants->save($this->grant(self::QUOTE_1, 'https://itest.example/stamps/first'));
		$this->grants->save($this->grant(self::QUOTE_1, 'https://itest.example/stamps/second'));

		$stored = $this->grants->get(self::TARGET, self::QUOTE_1);
		$this->assertNotNull($stored);
		$this->assertSame('https://itest.example/stamps/second', $stored->getAuthorization());
		$this->assertCount(1, $this->grants->getByTarget(self::TARGET));
	}

	public function testThePostsGrantsArePagedNewestFirst(): void {
		foreach ([self::QUOTE_1, self::QUOTE_2, self::QUOTE_3] as $quoting) {
			$this->grants->save($this->grant($quoting));
		}

		$page = $this->grants->getByTarget(self::TARGET, 2);
		$this->assertSame([self::QUOTE_3, self::QUOTE_2], array_map(static fn (QuoteGrant $g): string => $g->getQuotingId(), $page));

		$next = $this->grants->getByTarget(self::TARGET, 2, $page[1]->getId());
		$this->assertSame([self::QUOTE_1], array_map(static fn (QuoteGrant $g): string => $g->getQuotingId(), $next));
	}

	public function testTakingAGrantBackRemovesOnlyThatOne(): void {
		$this->grants->save($this->grant(self::QUOTE_1));
		$this->grants->save($this->grant(self::QUOTE_2));

		$this->assertTrue($this->grants->delete(self::TARGET, self::QUOTE_1));
		$this->assertFalse($this->grants->delete(self::TARGET, self::QUOTE_1));
		$this->assertNotNull($this->grants->get(self::TARGET, self::QUOTE_2));
	}

	/** a quote that is deleted takes its grant with it, from the quoting side too */
	public function testADeletedQuoteTakesItsGrant(): void {
		$this->grants->save($this->grant(self::QUOTE_1));

		$this->grants->deleteRelatedId(self::QUOTE_1);

		$this->assertNull($this->grants->get(self::TARGET, self::QUOTE_1));
	}
}
