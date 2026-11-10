<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ReactionSummaryService;
use PHPUnit\Framework\TestCase;

/**
 * What a reaction bar comes out looking like.
 *
 * Apart from ReactionServiceTest because the two halves are apart: reading is
 * a query, writing asks ModerationService whether the account may act, and
 * putting both in one class closed a circle through StreamService that the
 * container refused to build.
 */
class ReactionSummaryServiceTest extends TestCase {
	private const POST = 'https://cloud.example/post/1';
	private const ALICE = 'https://cloud.example/users/alice';

	/**
	 * @param array<string, int> $counts
	 * @param array<string, true> $mine
	 * @return list<array{name: string, count: int, me: bool}>
	 */
	private function summarise(array $counts, array $mine = []): array {
		$method = new \ReflectionMethod(ReactionSummaryService::class, 'summarise');

		return $method->invoke(null, $counts, $mine);
	}

	public function testTheBarIsOrderedByHowMany(): void {
		$this->assertSame([
			['name' => '👍', 'count' => 2, 'me' => false],
			['name' => '🎉', 'count' => 1, 'me' => false],
		], $this->summarise(['🎉' => 1, '👍' => 2]));
	}

	/**
	 * Ties are broken by the emoji itself, so the bar does not come out in a
	 * different order for two readers or on two page loads. Which of two
	 * equally-used emoji comes first is not the point and is not asserted —
	 * that the answer does not depend on the order they arrived in is.
	 */
	public function testEqualCountsAreOrderedStably(): void {
		$oneWay = $this->summarise(['🎉' => 1, '👍' => 1]);
		$theOther = $this->summarise(['👍' => 1, '🎉' => 1]);

		$this->assertSame(array_column($oneWay, 'name'), array_column($theOther, 'name'));
	}

	public function testTheBarSaysWhichOnesAreTheReadersOwn(): void {
		$summary = $this->summarise(['👍' => 1, '🎉' => 1], ['👍' => true]);

		$mine = array_column(array_filter($summary, static fn (array $row): bool => $row['me']), 'name');

		$this->assertSame(['👍'], $mine);
	}

	public function testAReactionWithNoEmojiIsNotCounted(): void {
		$this->assertSame([['name' => '👍', 'count' => 1, 'me' => false]], $this->summarise(['' => 3, '👍' => 1]));
	}

	public function testNoReactionsIsAnEmptyBarRatherThanAnError(): void {
		$this->assertSame([], $this->summarise([]));
	}

	/** Counted by the database, so a busy post is counted in full rather than to a row limit. */
	public function testAPostIsCountedByTheDatabaseInOneQueryWithTheReadersOwnInAnother(): void {
		$reactions = $this->createMock(ReactionsRequest::class);
		$reactions->expects($this->once())->method('countByObjectIds')
			->with([self::POST], self::ALICE, [], [])
			->willReturn([md5(self::POST) => ['👍' => 4321, '🎉' => 2]]);
		$reactions->expects($this->once())->method('emojisOf')
			->with(self::ALICE, [self::POST])
			->willReturn([md5(self::POST) => ['🎉' => true]]);

		$summary = (new ReactionSummaryService($reactions, $this->createStub(EmojiService::class)))->summaryOf(self::POST, self::ALICE);

		$this->assertSame([
			['name' => '👍', 'count' => 4321, 'me' => false],
			['name' => '🎉', 'count' => 2, 'me' => true],
		], $summary);
	}

	public function testAnAnonymousReaderOwnsNothingAndIsNotAskedAbout(): void {
		$reactions = $this->createMock(ReactionsRequest::class);
		$reactions->method('countByObjectIds')->willReturn([md5(self::POST) => ['👍' => 1]]);
		$reactions->expects($this->never())->method('emojisOf');

		$summary = (new ReactionSummaryService($reactions, $this->createStub(EmojiService::class)))->summaryOf(self::POST);

		$this->assertFalse($summary[0]['me']);
	}

	public function testAPageWithoutReactionsAsksNoSecondQuery(): void {
		$reactions = $this->createMock(ReactionsRequest::class);
		$reactions->method('countByObjectIds')->willReturn([]);
		$reactions->expects($this->never())->method('emojisOf');

		$this->assertSame([], (new ReactionSummaryService($reactions, $this->createStub(EmojiService::class)))->summaryOfMany([self::POST, ''], self::ALICE));
	}

	/**
	 * Suspended and silenced accounts and blocked and silenced instances are
	 * not counted; the reader's own reactions always are.
	 */
	public function testModeratedAccountsAndInstancesAreLeftOutOfTheCount(): void {
		$moderation = $this->createStub(ModerationRequest::class);
		$moderation->method('getActorIdsAt')->willReturnMap([
			[Moderation::SUSPEND, ['https://bad.example/users/troll']],
			[Moderation::SILENCE, ['https://loud.example/users/shouty', self::ALICE]],
		]);
		$fediverse = $this->createStub(FediverseService::class);
		$fediverse->method('blockedHosts')->willReturn(['evil.example' => true]);
		$fediverse->method('silencedHosts')->willReturn(['noisy.example' => true]);
		$reactions = $this->createMock(ReactionsRequest::class);
		$reactions->expects($this->once())->method('countByObjectIds')
			->with(
				[self::POST], self::ALICE,
				['https://bad.example/users/troll', 'https://loud.example/users/shouty'],
				['evil.example', 'noisy.example']
			)
			->willReturn([]);

		(new ReactionSummaryService($reactions, $this->createStub(EmojiService::class), $moderation, $fediverse))->summaryOf(self::POST, self::ALICE);
	}

	public function testOnlyAnInstanceOwnedCustomEmojiGetsAnImageUrl(): void {
		$emoji = $this->createMock(EmojiService::class);
		$emoji->expects($this->once())->method('byShortcode')->with('blobcat')->willReturn(
			(new CustomEmoji('blobcat', 'blobcat.png', 'image/png'))->setUrl('https://social.example/emoji/blobcat')
		);
		$reactions = $this->createStub(ReactionsRequest::class);
		$reactions->method('countByObjectIds')->willReturn([md5(self::POST) => [':blobcat:' => 1, ':cat@remote.example:' => 1]]);
		$summary = (new ReactionSummaryService($reactions, $emoji))->summaryOf(self::POST);

		$this->assertSame([
			['name' => ':blobcat:', 'count' => 1, 'me' => false, 'url' => 'https://social.example/emoji/blobcat'],
			['name' => ':cat@remote.example:', 'count' => 1, 'me' => false],
		], $summary);
	}
}
