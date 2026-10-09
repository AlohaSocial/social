<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\MutedWords;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Service\TimelineRevisionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MutedWordsTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';

	/** @var Filter[] */
	private array $stored = [];
	/** @var FiltersRequest&MockObject */
	private FiltersRequest $filters;
	/** @var TimelineRevisionService&MockObject */
	private TimelineRevisionService $revisions;
	private MutedWords $words;
	private Person $alice;

	protected function setUp(): void {
		$this->filters = $this->createMock(FiltersRequest::class);
		$this->filters->method('getActiveByActor')->willReturnCallback(fn (): array => $this->stored);
		$this->revisions = $this->createMock(TimelineRevisionService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->words = new MutedWords($this->filters, $this->revisions, $time);
		$this->alice = (new Person())->setId(self::ALICE);
	}

	private function filter(int $id, string $title, array $keywords, int $expiresAt = 0): Filter {
		$filter = (new Filter())->setId($id)->setActorId(self::ALICE)->setTitle($title)->setExpiresAt($expiresAt);
		foreach ($keywords as $keywordId => $keyword) {
			$filter->addKeyword((new FilterKeyword())->setId($keywordId)->setFilterId($id)->setKeyword($keyword));
		}

		return $filter;
	}

	public function testTheFiltersAreTheMutedWords(): void {
		$this->assertNull($this->words->pref($this->alice), 'no filters, no muted words');
		$this->stored = [$this->filter(1, 'Spoilers', [2 => 'finale', 3 => '#tvshow'], 1791622400)];

		$this->assertSame(['$type' => MutedWords::PREF, 'items' => [
			['id' => 'f1k2', 'value' => 'finale', 'targets' => ['content', 'tag'], 'actorTarget' => 'all', 'expiresAt' => '2026-10-10T08:53:20.000Z'],
			['id' => 'f1k3', 'value' => 'tvshow', 'targets' => ['tag'], 'actorTarget' => 'all', 'expiresAt' => '2026-10-10T08:53:20.000Z'],
		]], $this->words->pref($this->alice));
	}

	public function testWhatTheAppChangedIsDoneToTheFilters(): void {
		$this->stored = [$this->filter(1, 'Spoilers', [2 => 'finale', 3 => '#tvshow', 4 => 'ending'])];
		$this->filters->expects($this->once())->method('deleteKeyword')->with(4, self::ALICE);
		$this->filters->expects($this->once())->method('updateKeyword')->with($this->callback(static fn (FilterKeyword $k): bool => $k->getId() === 2 && $k->getKeyword() === 'season finale'));
		$this->filters->expects($this->once())->method('save')->with($this->callback(static fn (Filter $f): bool => $f->getTitle() === MutedWords::TITLE
			&& $f->getAction() === Filter::ACTION_HIDE && $f->getContexts() === Filter::CONTEXTS
			&& array_map(static fn (FilterKeyword $k): string => $k->getKeyword(), $f->getKeywords()) === ['#crypto']));
		$this->filters->expects($this->never())->method('delete');
		$this->revisions->expects($this->once())->method('bumpForActor')->with(self::ALICE);

		$this->words->apply($this->alice, ['$type' => MutedWords::PREF, 'items' => [
			['id' => 'f1k2', 'value' => 'season finale', 'targets' => ['content', 'tag']],
			['id' => 'f1k3', 'value' => 'tvshow', 'targets' => ['tag']],
			['value' => 'crypto', 'targets' => ['tag']],
		]]);
	}

	public function testAWordWithAnExpiryGoesIntoAFilterThatExpiresThenAndTheAppsEmptyFilterGoes(): void {
		$this->stored = [$this->filter(5, MutedWords::TITLE, [6 => 'gone']), $this->filter(7, MutedWords::TITLE, [8 => 'week'], 1792136000)];
		$this->filters->expects($this->once())->method('delete')->with(5, self::ALICE);
		$this->filters->expects($this->once())->method('saveKeyword')->with($this->callback(static fn (FilterKeyword $k): bool => $k->getFilterId() === 7 && $k->getKeyword() === 'month'));

		$this->words->apply($this->alice, ['items' => [
			['id' => 'f7k8', 'value' => 'week', 'targets' => ['content']],
			['value' => 'month', 'targets' => ['content'], 'expiresAt' => '2026-10-16T07:33:20.000Z'],
		]]);
	}

	public function testNothingChangedChangesNothing(): void {
		$this->stored = [$this->filter(1, 'Spoilers', [2 => 'finale'])];
		$this->filters->expects($this->never())->method('deleteKeyword');
		$this->filters->expects($this->never())->method('updateKeyword');
		$this->revisions->expects($this->never())->method('bumpForActor');

		$this->words->apply($this->alice, $this->words->pref($this->alice));
	}
}
