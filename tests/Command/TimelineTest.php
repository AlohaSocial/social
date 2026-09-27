<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use Exception;
use OCA\Social\Command\Timeline;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * `occ social:timeline` pages with nids. A nid wider than a PHP int reaches
 * the query as the string it was typed as; `intval()` clamped it to
 * PHP_INT_MAX, which is a different page.
 *
 * The timeline itself needs a database: the read is stopped once the options
 * it was asked with have been seen.
 */
class TimelineTest extends TestCase {
	private const WIDE = '92233720368547758070';

	private function timeline(array $options): ?ProbeOptions {
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturn($this->createStub(IUser::class));
		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturn(new Person());

		$asked = null;
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getTimeline')->willReturnCallback(
			static function (ProbeOptions $options) use (&$asked): array {
				$asked = $options;

				throw new RuntimeException('the options are all this test reads');
			}
		);

		$command = new Timeline(
			$userManager,
			$streamRequest,
			$accountService,
			$this->createStub(CacheActorService::class),
			$this->createStub(ConfigService::class),
		);

		try {
			$command->run(
				new ArrayInput(['userId' => 'alice', 'timeline' => 'home', '--output' => 'json'] + $options),
				new NullOutput()
			);
		} catch (RuntimeException $e) {
		}

		return $asked;
	}

	public function testAWideCursorReachesTheQueryExactly(): void {
		$options = $this->timeline(['--max_id' => self::WIDE, '--min_id' => self::WIDE, '--since' => self::WIDE]);

		$this->assertSame(self::WIDE, $options?->getMaxId());
		$this->assertSame(self::WIDE, $options?->getMinId());
		$this->assertSame(self::WIDE, $options?->getSince());
	}

	public function testNoCursorIsNoBound(): void {
		$options = $this->timeline([]);

		$this->assertSame(0, $options?->getMaxId());
		$this->assertSame(0, $options?->getMinId());
		$this->assertSame(0, $options?->getSince());
	}

	public function testACursorThatIsNotANidIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('--max_id must be a status id');

		$this->timeline(['--max_id' => 'yesterday']);
	}
}
