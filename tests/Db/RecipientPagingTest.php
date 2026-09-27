<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Which nid the public, notification and direct timelines page on.
 *
 * Each of them joins the recipient row with its collection and type fixed, and
 * that row carries the post's nid, so `social_sd_atn` (actor_id, type, nid) can
 * answer the filter and the order as one descending range. Paged on `s.nid`
 * instead, MariaDB drove from the same recipient rows anyway, joined every one
 * of them to its post and sorted them in a temporary table: `Using temporary;
 * Using filesort` over every public post ever written, or over every
 * notification an account ever received.
 */
#[AllowMockObjectsWithoutExpectations]
class RecipientPagingTest extends TestCase {
	/** @var string[] */
	private array $where = [];
	/** @var string[] */
	private array $order = [];

	/** The real paginate(), recording what it asks for. */
	private function builder(): SocialQueryBuilder&MockObject {
		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['expr', 'createNamedParameter', 'andWhere', 'orderBy', 'setMaxResults', 'getDefaultSelectAlias'])
			->getMock();
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('getDefaultSelectAlias')->willReturn('s');
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => (string)$value);
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});
		$qb->method('orderBy')->willReturnCallback(function (string $sort, ?string $direction = null) use ($qb) {
			$this->order[] = $sort . ' ' . $direction;

			return $qb;
		});

		return $qb;
	}

	private function options(): ProbeOptions {
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::PUBLIC)->setLimit(20);
		$options->setMaxId('1790000000123456789');

		return $options;
	}

	private function request(bool $filled): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn($filled);
		(new ReflectionProperty(StreamRequest::class, 'configService'))->setValue($request, $config);
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))->setValue($request, null);

		return $request;
	}

	private function paginateOnRecipient(bool $filled): void {
		(new ReflectionMethod(StreamRequest::class, 'paginateOnRecipient'))
			->invoke($this->request($filled), $this->builder(), $this->options());
	}

	public function testAPageIsOrderedOnThePostByDefault(): void {
		$this->builder()->paginate($this->options());

		$this->assertSame(['s.nid < 1790000000123456789'], $this->where);
		$this->assertSame(['s.nid desc'], $this->order);
	}

	public function testATimelineOfOneCollectionIsPagedOnItsRecipientRows(): void {
		$this->paginateOnRecipient(true);

		$this->assertSame(['sd.nid desc'], $this->order);
		$this->assertContains('sd.nid < 1790000000123456789', $this->where);
		// a row still carrying 0 would sort to the bottom for ever
		$this->assertContains('sd.nid > 0', $this->where);
		$this->assertStringNotContainsString('s.nid', implode(' ', $this->where));
	}

	/**
	 * Until the backfill's flag is written a recipient row may still carry 0,
	 * so the page keeps to the post's nid, which is always right.
	 */
	public function testAnUnfinishedBackfillKeepsThePageOnThePost(): void {
		$this->paginateOnRecipient(false);

		$this->assertSame(['s.nid desc'], $this->order);
		$this->assertSame(['s.nid < 1790000000123456789'], $this->where);
	}

	/** Each of the three timelines goes through it. */
	#[DataProvider('provideTimelines')]
	public function testTheTimelineIsPagedOnTheRecipient(string $method): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamTimelines.php');
		$body = preg_split('/function ' . $method . '\(/', $source, 2)[1] ?? '';
		$body = preg_split('/\n\t\}\n/', $body, 2)[0];

		$this->assertStringContainsString('paginateOnRecipient(', $body);
		$this->assertStringNotContainsString('->paginate($options)', $body);
	}

	/** @return array<string, array{string}> */
	public static function provideTimelines(): array {
		return [
			'public' => ['getTimelinePublic'],
			'notifications' => ['notificationFilters'],
			'direct' => ['directTimelineNids'],
		];
	}
}
