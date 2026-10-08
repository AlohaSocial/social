<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The domain-block and silenced-instance filters on `social_stream.author_host`,
 * and the actor-id patterns they keep for a row the backfill has not reached.
 */
#[AllowMockObjectsWithoutExpectations]
class AuthorHostFiltersTest extends TestCase {
	/** @var string[] */
	private array $wheres = [];

	private function queryBuilder(array $domains, bool $filled): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('hasViewer')->willReturn(true);
		$qb->method('blockedDomains')->willReturn($domains);
		$qb->method('authorHostsAreFilled')->willReturn($filled);
		$qb->method('getDefaultSelectAlias')->willReturn('s');
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value) => is_array($value) ? '[' . implode(',', $value) . ']' : "'" . $value . "'"
		);
		$func = $this->createStub(IFunctionBuilder::class);
		$func->method('lower')->willReturnCallback(function ($column): IQueryFunction {
			$lower = $this->createStub(IQueryFunction::class);
			$lower->method('__toString')->willReturn('LOWER(' . $column . ')');

			return $lower;
		});
		$qb->method('func')->willReturn($func);
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb): SocialQueryBuilder {
			$this->wheres[] = (string)$predicate;

			return $qb;
		});

		return $qb;
	}

	public function testTheHostOfAnActorIdIsItsLowerCaseHostAlone(): void {
		$this->assertSame('social.example', DomainBlocksRequestBuilder::authorHostOf('https://Social.Example:8443/users/a'));
		$this->assertSame('', DomainBlocksRequestBuilder::authorHostOf('not a uri'));
		$this->assertSame('', DomainBlocksRequestBuilder::authorHostOf('https://bücher.example/users/a'));
		$long = str_repeat('a', 250) . '.example';
		$this->assertSame(
			DomainBlocksRequestBuilder::AUTHOR_HOST_LENGTH,
			strlen(DomainBlocksRequestBuilder::authorHostOf('https://' . $long . '/users/a'))
		);
		// a host cut to the column width still meets a block on it, cut the same way
		$this->assertSame(
			[DomainBlocksRequestBuilder::authorHostOf('https://' . $long . '/users/a')],
			DomainBlocksRequestBuilder::hostsToMatch([$long])
		);
	}

	public function testOnceEveryRowHasAHostABlockIsOneNotIn(): void {
		DomainBlocksRequestBuilder::filterDomainBlocked($this->queryBuilder(['spam.example', 'Spam.example', 'evil.test'], true));

		$this->assertSame([
			's.author_host NOT IN [spam.example,evil.test]',
			'(hd_o.author_host IS NULL OR hd_o.author_host NOT IN [spam.example,evil.test])',
		], $this->wheres);
	}

	public function testARowNotReachedYetIsMatchedByItsActorId(): void {
		DomainBlocksRequestBuilder::filterDomainBlocked($this->queryBuilder(['spam.example'], false), '');

		$this->assertSame([
			'((s.author_host IS NOT NULL AND s.author_host NOT IN [spam.example])'
			. ' OR (s.author_host IS NULL AND (LOWER(s.attributed_to) NOT LIKE \'https://spam.example/%\''
			. ' AND LOWER(s.attributed_to) NOT LIKE \'http://spam.example/%\')))',
		], $this->wheres);
	}

	public function testAViewerWhoBlockedNothingAddsNoClause(): void {
		DomainBlocksRequestBuilder::filterDomainBlocked($this->queryBuilder([], false));

		$this->assertSame([], $this->wheres);
	}

	private function silence(array $hosts, bool $filled): void {
		$request = (new \ReflectionClass(StreamRequest::class))->newInstanceWithoutConstructor();
		$fediverse = $this->createStub(FediverseService::class);
		$fediverse->method('getSilencedAddresses')->willReturn($hosts);
		(new \ReflectionProperty(StreamRequest::class, 'fediverseService'))->setValue($request, $fediverse);
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturnCallback(
			static fn (string $key): bool => $key === ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED && $filled
		);
		(new \ReflectionProperty(StreamRequest::class, 'configService'))->setValue($request, $config);

		(new \ReflectionMethod(StreamRequest::class, 'filterSilencedInstances'))
			->invoke($request, $this->queryBuilder([], $filled));
	}

	public function testASilencedInstanceAndItsSubdomainsAreMatchedByHost(): void {
		$this->silence(['Noisy.example.', ' '], true);

		$this->assertSame([
			"(s.author_host NOT IN [noisy.example] AND s.author_host NOT LIKE '%.noisy.example')",
		], $this->wheres);
	}

	public function testASilencedRowNotReachedYetIsMatchedByItsActorId(): void {
		$this->silence(['noisy.example'], false);

		$this->assertSame([
			"((s.author_host IS NOT NULL AND s.author_host NOT IN [noisy.example] AND s.author_host NOT LIKE '%.noisy.example')"
			. " OR (s.author_host IS NULL AND s.attributed_to NOT LIKE '%://noisy.example/%'"
			. " AND s.attributed_to NOT LIKE '%.noisy.example/%'))",
		], $this->wheres);
	}

	public function testNoSilencedInstanceAddsNoClause(): void {
		$this->silence([], false);

		$this->assertSame([], $this->wheres);
	}
}
