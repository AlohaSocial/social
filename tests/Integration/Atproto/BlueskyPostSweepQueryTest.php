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
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The queries the Bluesky deletion sweep pages through, against the real
 * database: only posts a Bluesky account wrote, after a stream number, and
 * published since a moment; with `author_host` filled they are narrowed by
 * its index.
 */
class BlueskyPostSweepQueryTest extends TestCase {
	private const BLUESKY = 'https://bsky.app/profile/did:plc:sweepquerytest';
	private const MASTODON = 'https://mastodon.test/users/sweep';

	/** @var list<string> */
	private array $prims = [];
	private string $filled = '0';

	protected function setUp(): void {
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

	private function post(string $author, string $host, int $published): int {
		$id = $author . '/post/' . bin2hex(random_bytes(6));
		$prim = md5($id);
		$this->prims[] = $prim;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
			'id' => $id, 'id_prim' => $prim, 'type' => Note::TYPE, 'local' => false,
			'attributed_to' => $author, 'attributed_to_prim' => md5($author), 'author_host' => $host,
			'published_time' => new \DateTime('@' . $published),
		]);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('nid')->from(CoreRequestBuilder::TABLE_STREAM)->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
		$result = $qb->executeQuery();
		$nid = (int)$result->fetchOne();
		$result->closeCursor();

		return $nid;
	}

	public function testOnlyBlueskyPostsAfterTheCursorAndSinceTheMomentAreListed(): void {
		$now = time();
		$old = $this->post(self::BLUESKY, 'bsky.app', $now - 30 * 86400);
		$this->post(self::MASTODON, 'mastodon.test', $now);
		$recent = $this->post(self::BLUESKY, 'bsky.app', $now - 3600);
		$streams = Server::get(StreamRequest::class);

		$all = $streams->getBlueskyPostIds(0, $old - 1, 10);
		$this->assertSame([$old, $recent], array_keys(array_filter($all, static fn (string $id): bool => str_starts_with($id, self::BLUESKY))));
		$this->assertSame([], array_filter($all, static fn (string $id): bool => str_starts_with($id, self::MASTODON)));
		$this->assertSame([$recent], array_keys(array_filter(
			$streams->getBlueskyPostIds($now - 7 * 86400, $old - 1, 10),
			static fn (string $id): bool => str_starts_with($id, self::BLUESKY),
		)), 'the recent window');
		$this->assertGreaterThanOrEqual($recent, $streams->getBlueskyPostMaxNid());
	}
}
