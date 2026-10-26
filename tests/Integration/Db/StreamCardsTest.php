<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Model\StreamCard;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Link preview cards against the real table: they round-trip, a second save
 * for the same post replaces the first (the queue can hand the same post over
 * twice), a page of posts is read in one query, and pruning a post's card
 * leaves the others alone. Only this proves the primary-key-on-insert
 * behaviour and the IN read work on every supported database.
 */
class StreamCardsTest extends TestCase {
	private const POST = 'https://cloud.example.org/cardtest/@alice/1';
	private const OTHER = 'https://cloud.example.org/cardtest/@alice/2';

	private StreamCardsRequest $streamCardsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamCardsRequest = Server::get(StreamCardsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::POST, self::OTHER] as $id) {
			$this->streamCardsRequest->deleteByStreamId($id);
		}
	}

	private function card(string $streamId, string $title = 'The headline'): StreamCard {
		$card = new StreamCard($streamId, 'https://example.org/news/today');
		$card->setTitle($title)
			->setDescription('What it is about')
			->setImage('https://example.org/img/hero.png')
			->setProviderName('Example News');

		return $card;
	}

	public function testACardRoundTrips(): void {
		$this->streamCardsRequest->save($this->card(self::POST));

		$stored = $this->streamCardsRequest->getByStreamId(self::POST);
		$this->assertSame('https://example.org/news/today', $stored->getUrl());
		$this->assertSame('The headline', $stored->getTitle());
		$this->assertSame('What it is about', $stored->getDescription());
		$this->assertSame('https://example.org/img/hero.png', $stored->getImage());
		$this->assertSame('Example News', $stored->getProviderName());
		$this->assertGreaterThan(0, $stored->getCreation());
	}

	public function testAPostWithoutACardIsNotFound(): void {
		$this->expectException(CardNotFoundException::class);
		$this->streamCardsRequest->getByStreamId(self::POST);
	}

	public function testSavingTwiceForOnePostReplacesTheCard(): void {
		// the same post can reach the queue more than once
		$this->streamCardsRequest->save($this->card(self::POST, 'The first headline'));
		$this->streamCardsRequest->save($this->card(self::POST, 'The second headline'));

		$this->assertSame(
			'The second headline',
			$this->streamCardsRequest->getByStreamId(self::POST)->getTitle()
		);
	}

	public function testAPageOfPostsIsReadInOneQuery(): void {
		$this->streamCardsRequest->save($this->card(self::POST, 'One'));
		$this->streamCardsRequest->save($this->card(self::OTHER, 'Two'));

		$cards = $this->streamCardsRequest->getByStreamIds([self::POST, self::OTHER, 'https://cloud.example.org/cardtest/@alice/3']);

		$this->assertCount(2, $cards);
		$this->assertSame('One', $cards[md5(self::POST)]->getTitle());
		$this->assertSame('Two', $cards[md5(self::OTHER)]->getTitle());
	}

	public function testCardsCanBeReadByPreviouslyHashedStreamIds(): void {
		$this->streamCardsRequest->save($this->card(self::POST, 'One'));
		$this->streamCardsRequest->save($this->card(self::OTHER, 'Two'));

		$cards = $this->streamCardsRequest->getByStreamPrims([md5(self::POST), md5(self::OTHER)]);

		$this->assertSame('One', $cards[md5(self::POST)]->getTitle());
		$this->assertSame('Two', $cards[md5(self::OTHER)]->getTitle());
	}

	public function testReadingNoPostsAsksNothing(): void {
		$this->assertSame([], $this->streamCardsRequest->getByStreamIds([]));
	}

	public function testDeletingOneCardLeavesTheOthers(): void {
		$this->streamCardsRequest->save($this->card(self::POST));
		$this->streamCardsRequest->save($this->card(self::OTHER));

		$this->streamCardsRequest->deleteByStreamId(self::POST);

		$this->assertCount(1, $this->streamCardsRequest->getByStreamIds([self::POST, self::OTHER]));
		$this->assertSame(
			'The headline',
			$this->streamCardsRequest->getByStreamId(self::OTHER)->getTitle()
		);
	}

	public function testPruningPostsTakesTheirCardsAlong(): void {
		$this->streamCardsRequest->save($this->card(self::POST));
		$this->streamCardsRequest->save($this->card(self::OTHER));

		// this is what the retention job hands over
		$this->streamCardsRequest->deleteByStreamPrims([md5(self::POST)]);

		$this->assertSame(
			[md5(self::OTHER)],
			array_keys($this->streamCardsRequest->getByStreamIds([self::POST, self::OTHER]))
		);
	}

	public function testUnicodeAndQuotesSurviveTheColumns(): void {
		$card = new StreamCard(self::POST, 'https://example.org/artikel?a=1&b=2');
		$card->setTitle('Straßenbahn "fährt" — 🚋')
			->setDescription("Ein Satz mit 'Zitat' & Zeichen");
		$this->streamCardsRequest->save($card);

		$stored = $this->streamCardsRequest->getByStreamId(self::POST);
		$this->assertSame('Straßenbahn "fährt" — 🚋', $stored->getTitle());
		$this->assertSame("Ein Satz mit 'Zitat' & Zeichen", $stored->getDescription());
		$this->assertSame('https://example.org/artikel?a=1&b=2', $stored->getUrl());
	}

	public function testALongDescriptionFitsTheColumn(): void {
		$card = new StreamCard(self::POST, 'https://example.org/a');
		$card->setTitle(str_repeat('t', StreamCard::MAX_TITLE))
			->setDescription(str_repeat('d', StreamCard::MAX_DESCRIPTION));
		$this->streamCardsRequest->save($card);

		$stored = $this->streamCardsRequest->getByStreamId(self::POST);
		$this->assertSame(StreamCard::MAX_TITLE, mb_strlen($stored->getTitle()));
		$this->assertSame(StreamCard::MAX_DESCRIPTION, mb_strlen($stored->getDescription()));
	}
}
