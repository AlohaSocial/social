<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The queries the Bluesky deletion sweep pages through, against the real
 * database: only posts a Bluesky account wrote, after a stream number, and
 * published since a moment; with `author_host` filled they are narrowed by
 * its index. The rows here carry their own author and stream numbers, so
 * what other tests leave in the table does not matter: `nid` is no
 * autoincrement (it is derived from the publication time), and a seeded
 * random one would put the rows in any order.
 */
class BlueskyPostSweepQueryTest extends TestCase {
	private string $bluesky = '';
	private string $mastodon = '';
	private int $nextNid = 0;
	/** @var list<string> */
	private array $prims = [];
	private string $filled = '0';

	protected function setUp(): void {
		$run = bin2hex(random_bytes(6));
		$this->bluesky = 'https://bsky.app/profile/did:plc:sweep' . $run;
		$this->mastodon = 'https://mastodon.test/users/sweep' . $run;
		$this->nextNid = random_int(1 << 61, 1 << 62);
		$config = Server::get(IAppConfig::class);
		$this->filled = $config->getValueString('social', ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED, '0');
		$config->setValueString('social', ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED, '1');
	}

	protected function tearDown(): void {
		foreach ($this->prims as $prim) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $prim]);
		}
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED, $this->filled);
		parent::tearDown();
	}

	/** A row with the next stream number of this test. */
	private function post(string $author, string $host, int $published): int {
		$id = $author . '/post/' . bin2hex(random_bytes(6));
		$prim = md5($id);
		$nid = $this->nextNid++;
		$this->prims[] = $prim;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
			'nid' => $nid, 'id' => $id, 'id_prim' => $prim, 'type' => Note::TYPE, 'local' => false,
			'attributed_to' => $author, 'attributed_to_prim' => md5($author), 'author_host' => $host,
			'published_time' => new \DateTime('@' . $published),
		]);

		return $nid;
	}

	public function testOnlyBlueskyPostsAfterTheCursorAndSinceTheMomentAreListed(): void {
		$now = time();
		$old = $this->post($this->bluesky, 'bsky.app', $now - 30 * 86400);
		$this->post($this->mastodon, 'mastodon.test', $now);
		$recent = $this->post($this->bluesky, 'bsky.app', $now - 3600);
		$streams = Server::get(StreamRequest::class);

		// bounded to this test's own stream numbers, whatever else the table holds
		$all = $streams->getBlueskyPostIds(0, $old - 1, 10, $recent);
		$this->assertSame([$old, $recent], array_keys(array_filter($all, fn (string $id): bool => str_starts_with($id, $this->bluesky))));
		$this->assertSame([], array_filter($all, fn (string $id): bool => str_starts_with($id, $this->mastodon)));
		$this->assertSame([$recent], array_keys(array_filter(
			$streams->getBlueskyPostIds($now - 7 * 86400, $old - 1, 10, $recent),
			fn (string $id): bool => str_starts_with($id, $this->bluesky),
		)), 'the recent window');
		$this->assertGreaterThanOrEqual($recent, $streams->getBlueskyPostMaxNid());
	}
}
