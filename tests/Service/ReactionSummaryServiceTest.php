<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Service\EmojiService;
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
	private function reaction(string $actor, string $emoji): EmojiReact {
		$reaction = new EmojiReact();
		$reaction->setActorId($actor);
		$reaction->setObjectId('https://cloud.example/post/1');
		$reaction->setContent($emoji);

		return $reaction;
	}

	/**
	 * @param EmojiReact[] $reactions
	 * @return list<array{name: string, count: int, me: bool}>
	 */
	private function summarise(array $reactions, string $viewerId = ''): array {
		$method = new \ReflectionMethod(ReactionSummaryService::class, 'summarise');

		return $method->invoke(null, $reactions, $viewerId);
	}

	public function testTheBarCountsEachEmojiOnce(): void {
		$summary = $this->summarise([
			$this->reaction('https://cloud.example/users/alice', '👍'),
			$this->reaction('https://cloud.example/users/bob', '👍'),
			$this->reaction('https://cloud.example/users/carol', '🎉'),
		]);

		$this->assertSame([
			['name' => '👍', 'count' => 2, 'me' => false],
			['name' => '🎉', 'count' => 1, 'me' => false],
		], $summary);
	}

	/**
	 * Ties are broken by the emoji itself, so the bar does not come out in a
	 * different order for two readers or on two page loads. Which of two
	 * equally-used emoji comes first is not the point and is not asserted —
	 * that the answer does not depend on the order they arrived in is.
	 */
	public function testEqualCountsAreOrderedStably(): void {
		$oneWay = $this->summarise([
			$this->reaction('https://cloud.example/users/alice', '🎉'),
			$this->reaction('https://cloud.example/users/bob', '👍'),
		]);
		$theOther = $this->summarise([
			$this->reaction('https://cloud.example/users/bob', '👍'),
			$this->reaction('https://cloud.example/users/alice', '🎉'),
		]);

		$this->assertSame(array_column($oneWay, 'name'), array_column($theOther, 'name'));
	}

	public function testTheBarSaysWhichOnesAreTheReadersOwn(): void {
		$summary = $this->summarise([
			$this->reaction('https://cloud.example/users/alice', '👍'),
			$this->reaction('https://cloud.example/users/bob', '🎉'),
		], 'https://cloud.example/users/alice');

		$mine = array_column(array_filter($summary, static fn (array $row): bool => $row['me']), 'name');

		$this->assertSame(['👍'], $mine);
	}

	public function testAnAnonymousReaderOwnsNothing(): void {
		$summary = $this->summarise([
			$this->reaction('https://cloud.example/users/alice', '👍'),
		]);

		$this->assertFalse($summary[0]['me']);
	}

	public function testAReactionWithNoEmojiIsNotCounted(): void {
		$summary = $this->summarise([
			$this->reaction('https://cloud.example/users/alice', ''),
			$this->reaction('https://cloud.example/users/bob', '👍'),
		]);

		$this->assertSame([['name' => '👍', 'count' => 1, 'me' => false]], $summary);
	}

	public function testNoReactionsIsAnEmptyBarRatherThanAnError(): void {
		$this->assertSame([], $this->summarise([]));
	}

	public function testOnlyAnInstanceOwnedCustomEmojiGetsAnImageUrl(): void {
		$emoji = $this->createMock(EmojiService::class);
		$emoji->expects($this->once())->method('byShortcode')->with('blobcat')->willReturn(
			(new CustomEmoji('blobcat', 'blobcat.png', 'image/png'))->setUrl('https://social.example/emoji/blobcat')
		);
		$reactions = $this->createStub(ReactionsRequest::class);
		$reactions->method('getByObjectId')->willReturn([
			$this->reaction('https://remote.example/users/bob', ':blobcat:'),
			$this->reaction('https://remote.example/users/carol', ':cat@remote.example:'),
		]);
		$summary = (new ReactionSummaryService($reactions, $emoji))->summaryOf('https://cloud.example/post/1');

		$this->assertSame([
			['name' => ':blobcat:', 'count' => 1, 'me' => false, 'url' => 'https://social.example/emoji/blobcat'],
			['name' => ':cat@remote.example:', 'count' => 1, 'me' => false],
		], $summary);
	}
}
