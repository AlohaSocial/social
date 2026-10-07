<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CountsService;
use PHPUnit\Framework\TestCase;

class CountsServiceTest extends TestCase {
	/** @var array<string, string> user id => stored `show_counts` */
	private array $stored = [];
	private CountsService $service;

	protected function setUp(): void {
		$config = $this->createStub(ConfigService::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $key, string $userId = ''): string => $this->stored[$userId] ?? '');
		$config->method('setValueForUser')->willReturnCallback(function (string $userId, string $key, string $value): void {
			$this->stored[$userId] = $value;
		});
		$this->service = new CountsService($config);
	}

	/** Every account hides the numbers on the day this ships, without a migration. */
	public function testTheNumbersAreHiddenUnlessTheReaderTurnedThemOn(): void {
		$this->assertTrue($this->service->hides('alice'));

		$this->service->setHides('alice', false);
		$this->assertSame('1', $this->stored['alice']);
		$this->assertFalse($this->service->hides('alice'));

		$this->service->setHides('alice', true);
		$this->assertTrue($this->service->hides('alice'));
	}

	public function testNobodyInParticularHasNothingHidden(): void {
		$this->assertFalse($this->service->hides(''));
	}

	private function decoded(array $value): mixed {
		return json_decode((string)json_encode($value));
	}

	public function testAStatusAndItsAuthorLoseTheirNumbersAndNothingElse(): void {
		$status = $this->decoded([
			'id' => '1',
			'favourites_count' => 12,
			'reblogs_count' => 3,
			'replies_count' => 4,
			'dislikes_count' => null,
			'account' => ['acct' => 'bob@remote.example', 'followers_count' => 900, 'following_count' => 80, 'statuses_count' => 50],
		]);

		$out = $this->service->strip($status);

		$this->assertSame(0, $out->favourites_count);
		$this->assertSame(0, $out->reblogs_count);
		$this->assertSame(0, $out->replies_count, 'a busy thread is attention too, and the reader asked not to be shown it');
		$this->assertNull($out->dislikes_count, 'null is what says "not a video"');
		$this->assertSame(0, $out->account->followers_count);
		$this->assertSame(80, $out->account->following_count);
		$this->assertSame(50, $out->account->statuses_count);
	}

	public function testNumbersAreFoundAtAnyDepthAndInLists(): void {
		$page = $this->decoded([
			['type' => 'favourite', 'account' => ['acct' => 'a', 'followers_count' => 5], 'status' => [
				'favourites_count' => 9, 'reblogs_count' => 1,
				'reblog' => ['favourites_count' => 7, 'reblogs_count' => 2, 'dislikes_count' => 4],
				'quote' => ['quoted_status' => ['favourites_count' => 6, 'reblogs_count' => 6]],
			]],
		]);

		$out = $this->service->strip($page);

		$this->assertSame(0, $out[0]->account->followers_count);
		$this->assertSame(0, $out[0]->status->favourites_count);
		$this->assertSame(0, $out[0]->status->reblog->favourites_count);
		$this->assertSame(0, $out[0]->status->reblog->dislikes_count, 'a video\'s dislikes are a score too');
		$this->assertSame(0, $out[0]->status->quote->quoted_status->reblogs_count);
	}

	/** Only an account has `acct` beside `followers_count`; a statistic named alike is left alone. */
	public function testANumberThatIsNotOnAnEntityIsLeftAlone(): void {
		$out = $this->service->strip($this->decoded(['followers_count' => 10, 'favourites_count' => 3, 'empty' => new \stdClass()]));

		$this->assertSame(10, $out->followers_count);
		$this->assertSame(3, $out->favourites_count);
		$this->assertSame('{"followers_count":10,"favourites_count":3,"empty":{}}', json_encode($out), 'an empty object stays an object');
	}
}
