<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Cron;

use OCA\Social\Cron\Queue;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * What one pass of the delivery cron costs the database, against a real queue.
 *
 * The job is built from the container's services; only the edge of the
 * network is replaced — every server answers at once, and nothing is
 * signed — and the clock is frozen, so the budget of the run never runs out
 * and what ends it is the queue running dry. The cost has to be a constant per
 * delivery on top of a constant per pass: a statement that grows with the
 * backlog — a count of the whole queue, a read of every row — is what turns a
 * cron pass on an instance with a week of backlog into one that never ends.
 */
class QueueWorkBudgetTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.org/queuebudget/users/author';

	protected function setUp(): void {
		parent::setUp();
		Server::get(RequestQueueRequest::class)->deleteByAuthor(self::AUTHOR);
	}

	protected function tearDown(): void {
		Server::get(RequestQueueRequest::class)->deleteByAuthor(self::AUTHOR);
		parent::tearDown();
	}

	/** `$count` deliveries waiting, spread over fifty servers. */
	private function backlog(int $count): void {
		$queue = Server::get(RequestQueueRequest::class);
		$connection = Server::get(IDBConnection::class);
		$connection->beginTransaction();
		for ($i = 0; $i < $count; $i++) {
			$request = new RequestQueue(
				'{"type":"Create","id":"' . self::AUTHOR . '/activity/' . $i . '"}',
				new InstancePath('https://host' . ($i % 50) . '.queuebudget.example/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM),
				self::AUTHOR
			);
			$request->setToken('queuebudget' . $i);
			$queue->create($request);
		}
		$connection->commit();
	}

	/** The deliveries of the backlog not yet delivered; a delivered one is kept as such for a while. */
	private function waiting(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('social_req_queue')
			->where($qb->expr()->eq('author', $qb->createNamedParameter(self::AUTHOR)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(RequestQueue::STATUS_SUCCESS)));
		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}

	/**
	 * The job, with every server answering 2xx at once and a clock that does
	 * not move.
	 */
	private function job(): Queue {
		$curl = $this->createStub(CurlService::class);
		$curl->method('sendMany')->willReturnCallback(
			static fn (array $requests): array => array_fill_keys(array_keys($requests), null)
		);
		$signatures = $this->createStub(SignatureService::class);
		$signatures->method('signRequest')->willReturn([]);

		$arguments = [];
		foreach ((new ReflectionClass(ActivityService::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[] = match ($type->getName()) {
				CurlService::class => $curl,
				SignatureService::class => $signatures,
				default => Server::get($type->getName()),
			};
		}

		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(time());

		return new Queue(
			$clock,
			Server::get(RequestQueueService::class),
			Server::get(StreamQueueService::class),
			new ActivityService(...$arguments),
			Server::get(LoggerInterface::class),
		);
	}

	/** The statements one pass runs over a backlog of `$count` deliveries. */
	private function passOver(int $count): QueryCounter {
		$this->backlog($count);
		$job = $this->job();
		$run = QueryCounter::record(static fn () => (new ReflectionMethod(Queue::class, 'run'))->invoke($job, null));
		$this->assertSame(0, $this->waiting(), 'the pass delivered the whole backlog of ' . $count);

		return $run;
	}

	public function testAPassCostsTheSameForEachDeliveryWhateverTheBacklog(): void {
		// one pass first, not counted: what the server reads once a process
		// (configuration, the author) is then read before every measured pass
		// alike, whichever tests ran before this one
		$this->passOver(RequestQueueRequest::STANDBY_BATCH);
		$empty = $this->passOver(0)->count();
		$small = $this->passOver(RequestQueueRequest::STANDBY_BATCH)->count();
		$large = $this->passOver(2 * RequestQueueRequest::STANDBY_BATCH)->count();

		$this->assertSame(
			$small - $empty, $large - $small,
			'the second batch of ' . RequestQueueRequest::STANDBY_BATCH . ' cost more than the first: '
			. 'something in the pass grows with the backlog'
		);
		// two a delivery — claiming the row and recording it delivered — and
		// four a batch: giving up on the rows that spent their tries and on
		// those queued longer than RequestQueueService::MAX_AGE (one statement
		// each, so each reads its own index), reading the due ones, reading
		// the breaker
		$this->assertLessThanOrEqual(2 * RequestQueueRequest::STANDBY_BATCH + 4, $small - $empty);
		// the housekeeping of a pass, with nothing to deliver: 9 today
		$this->assertLessThanOrEqual(11, $empty);
	}
}
