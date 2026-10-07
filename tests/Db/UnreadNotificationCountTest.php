<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The notification badge, which every client polls every thirty seconds.
 *
 * It used to read the unseen notifications as whole rows — every column of
 * `social_stream` through a `SELECT DISTINCT`, up to a hundred of them — and
 * count them in PHP. The database is asked for the number: the rows are chosen
 * as one projected column and counted where they are.
 */
#[AllowMockObjectsWithoutExpectations]
class UnreadNotificationCountTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	/** @var string[] */
	private array $pageWhere = [];
	private ?int $pageLimit = null;
	/** @var array<string, mixed> */
	private array $outer = [];
	/** @var string[] the roots of the threads Alice muted */
	private array $mutedRoots = [];

	private function page(): SocialQueryBuilder&MockObject {
		$page = $this->createMock(SocialQueryBuilder::class);
		$page->method('expr')->willReturn(new FakeExpressions());
		$page->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ':' . (is_array($value) ? implode(',', $value) : $value)
		);
		$page->method('andWhere')->willReturnCallback(function ($predicate) use ($page) {
			$this->pageWhere[] = (string)$predicate;

			return $page;
		});
		$page->method('setMaxResults')->willReturnCallback(function (int $limit) use ($page) {
			$this->pageLimit = $limit;

			return $page;
		});
		$page->method('getSQL')->willReturn('SELECT DISTINCT s.nid FROM social_stream s …');
		$page->method('getParameters')->willReturn(['dcValue1' => 'notif']);
		$page->method('getParameterTypes')->willReturn(['dcValue1' => 2]);

		return $page;
	}

	private function outer(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);

		$count = $this->createStub(IQueryFunction::class);
		$count->method('__toString')->willReturn('COUNT(*) AS unread');
		$func = $this->createStub(IFunctionBuilder::class);
		$func->method('count')->willReturn($count);
		$qb->method('func')->willReturn($func);

		$qb->method('createFunction')->willReturnCallback(function (string $call): IQueryFunction {
			$function = $this->createMock(IQueryFunction::class);
			$function->method('__toString')->willReturn($call);

			return $function;
		});
		$qb->method('select')->willReturnCallback(function ($select) use ($qb) {
			$this->outer['select'] = (string)$select;

			return $qb;
		});
		$qb->method('from')->willReturnCallback(function ($from, $alias = null) use ($qb) {
			$this->outer['from'] = (string)$from;
			$this->outer['alias'] = $alias;

			return $qb;
		});
		$qb->method('setParameters')->willReturnCallback(function (array $params, array $types = []) use ($qb) {
			$this->outer['params'] = $params;
			$this->outer['types'] = $types;

			return $qb;
		});

		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturn(['unread' => '7']);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	private function request(): StreamRequest&MockObject {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStreamNidsSelectSql', 'getStreamSelectSql', 'getQueryBuilder'])
			->getMock();
		$request->method('getStreamNidsSelectSql')->willReturn($this->page());
		$request->method('getQueryBuilder')->willReturn($this->outer());
		$request->expects($this->never())->method('getStreamSelectSql');

		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn(true);
		(new ReflectionProperty(StreamRequest::class, 'configService'))->setValue($request, $config);
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))->setValue($request, null);

		$conversations = $this->createStub(ConversationsRequest::class);
		$conversations->method('getMutedRoots')->willReturnCallback(fn (): array => $this->mutedRoots);
		$conversations->method('getThread')->willReturnCallback(
			static fn (string $root): array => [$root => ['id' => $root, 'idPrim' => 'prim-' . $root, 'nid' => 1, 'inReplyTo' => '']]
		);
		(new ReflectionProperty(StreamRequest::class, 'conversationsRequest'))->setValue($request, $conversations);

		return $request;
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE);

		return $actor;
	}

	public function testTheDatabaseCountsTheUnseenNotifications(): void {
		$this->assertSame(7, $this->request()->countNotificationsSince($this->alice(), '0', 99));

		$this->assertSame('COUNT(*) AS unread', $this->outer['select']);
		$this->assertSame('(SELECT DISTINCT s.nid FROM social_stream s …)', $this->outer['from']);
		$this->assertSame('unread_page', $this->outer['alias']);
		// the page's placeholders are in the embedded SQL and bound on the outer query
		$this->assertSame(['dcValue1' => 'notif'], $this->outer['params']);
		$this->assertSame(['dcValue1' => 2], $this->outer['types']);
	}

	public function testTheCountStopsJustPastTheCap(): void {
		$this->request()->countNotificationsSince($this->alice(), '0', 99);

		$this->assertSame(100, $this->pageLimit);
	}

	public function testTheMarkerIsARangeOverTheRecipientRows(): void {
		$this->request()->countNotificationsSince($this->alice(), '1790000000123456789');

		$this->assertContains('sd.nid > :1790000000123456789', $this->pageWhere);
	}

	/** A muted thread's notifications are not listed, so they are not unread either. */
	public function testAMutedThreadIsNotCounted(): void {
		$this->mutedRoots = ['https://cloud.example/@alice/1'];

		$this->request()->countNotificationsSince($this->alice(), '0');

		$this->assertContains('(s.object_id_prim IS NULL OR s.object_id_prim NOT IN :prim-https://cloud.example/@alice/1)', $this->pageWhere);
	}
}
