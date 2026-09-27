<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\InstancesRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Instance;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What `/api/v1/instance` and the statistics page count, and the stored
 * description of this instance.
 */
class InstanceStatsRequestTest extends TestCase {
	private const BASE = 'https://cloud.example.org/instance-stats';
	private const AUTHOR = self::BASE . '/users/author';
	private const REMOTES = [
		'https://itest-stats-a.example/users/one',
		'https://itest-stats-a.example/users/two',
		'https://itest-stats-b.example/users/one',
	];
	private const INSTANCE_URI = 'itest-instance-stats.example';

	private InstanceStatsRequest $stats;
	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->stats = Server::get(InstanceStatsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (['old', 'new', 'remote'] as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach (self::REMOTES as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_INSTANCE)
			->where($qb->expr()->eq('uri', $qb->createNamedParameter(self::INSTANCE_URI)));
		$qb->executeStatement();
	}

	private function note(string $suffix, bool $local, int $publishedTime): void {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setLocal($local);
		$note->setPublishedTime($publishedTime);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $publishedTime));
		$this->streamRequest->save($note);
	}

	public function testOnlyStatusesWrittenHereAreCounted(): void {
		$before = $this->stats->countLocalStatuses();
		$now = time();

		$this->note('old', true, $now - 10 * 86400);
		$this->note('new', true, $now - 60);
		$this->note('remote', false, $now - 60);

		$this->assertSame($before + 2, $this->stats->countLocalStatuses());
		$lastWeek = $this->stats->countLocalStatusesBetween($now - 7 * 86400, $now + 1);
		$this->assertSame(1, $lastWeek - $this->stats->countLocalStatusesBetween($now - 7 * 86400, $now - 120));
	}

	public function testRemoteInstancesAreCountedByTheAccountsCachedFromThem(): void {
		foreach (self::REMOTES as $id) {
			$person = new Person();
			$person->setId($id)->setPreferredUsername(basename($id));
			$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST))
				->setInbox($id . '/inbox')
				->setOutbox($id . '/outbox')
				->setFollowers($id . '/followers')
				->setFollowing($id . '/following');
			$this->cacheActorsRequest->save($person);
		}

		$counts = $this->stats->remoteHostCounts();
		$this->assertSame(2, $counts['itest-stats-a.example'] ?? 0);
		$this->assertSame(1, $counts['itest-stats-b.example'] ?? 0);

		$domains = $this->stats->getRemoteDomains();
		$this->assertContains('itest-stats-a.example', $domains);
		$sorted = $domains;
		sort($sorted);
		$this->assertSame($sorted, $domains);
		$this->assertSame(count($domains), $this->stats->countRemoteDomains());
	}

	public function testTheLocalInstanceIsReadBackAsStored(): void {
		$instances = Server::get(InstancesRequest::class);
		try {
			$instances->getLocal();
			$this->markTestSkipped('this server already stores a local instance row');
		} catch (InstanceDoesNotExistException) {
		}

		$instances->save((new Instance())
			->setLocal(true)
			->setUri(self::INSTANCE_URI)
			->setTitle('Integration test')
			->setVersion('4.0.0')
			->setLanguages(['en', 'de']));

		$local = $instances->getLocal();
		$this->assertSame(self::INSTANCE_URI, $local->getUri());
		$this->assertSame('Integration test', $local->getTitle());
		$this->assertSame(['en', 'de'], $local->getLanguages());
	}
}
