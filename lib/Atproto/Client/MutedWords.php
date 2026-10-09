<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Service\TimelineRevisionService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * A Bluesky app's muted words, which are this person's filters here: the
 * app reads every keyword of the filters that apply as its muted words, and
 * what it adds, changes or takes away is done to those filters. A word the
 * app adds goes into a filter of its own, which hides what it matches
 * everywhere; one with an expiry into one that expires then. A muted
 * hashtag is the keyword `#tag`.
 */
class MutedWords {
	public const PREF = 'app.bsky.actor.defs#mutedWordsPref';
	/**
	 * The filter a word the app adds goes into: one name in every language,
	 * as it is how the filter is found again
	 */
	public const TITLE = 'Muted words from Bluesky';
	/** a word's id, the filter and the keyword it stands for */
	private const ID = '/^f(\d+)k(\d+)$/';

	public function __construct(
		private FiltersRequest $filters,
		private TimelineRevisionService $revisions,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The person's filters as the app's muted words, or null for none.
	 */
	public function pref(Person $viewer): ?array {
		$items = [];
		foreach ($this->filters->getActiveByActor($viewer->getId(), $this->time->getTime()) as $filter) {
			foreach ($filter->getKeywords() as $keyword) {
				$items[] = self::word($filter, $keyword);
			}
		}

		return $items === [] ? null : ['$type' => self::PREF, 'items' => $items];
	}

	/**
	 * What the app saved as its muted words, done to the filters: a word
	 * gone takes its keyword away (and the filter with it, once the app's
	 * own filter is empty), a word changed rewrites it, a new one is added.
	 */
	public function apply(Person $viewer, array $pref): void {
		$wanted = [];
		$new = [];
		foreach (is_array($pref['items'] ?? null) ? $pref['items'] : [] as $item) {
			$value = is_array($item) ? self::keywordOf($item) : '';
			if ($value === '') {
				continue;
			}
			if (preg_match(self::ID, (string)($item['id'] ?? ''), $m) === 1) {
				$wanted[(int)$m[2]] = $value;
			} else {
				$new[] = [$value, self::expiryOf($item)];
			}
		}

		$changed = false;
		foreach ($this->filters->getActiveByActor($viewer->getId(), $this->time->getTime()) as $filter) {
			$left = count($filter->getKeywords());
			foreach ($filter->getKeywords() as $keyword) {
				$value = $wanted[$keyword->getId()] ?? null;
				if ($value === null) {
					$this->filters->deleteKeyword($keyword->getId(), $viewer->getId());
					$left--;
					$changed = true;
				} elseif ($value !== $keyword->getKeyword()) {
					$this->filters->updateKeyword($keyword->setKeyword($value));
					$changed = true;
				}
			}
			if ($left === 0 && $filter->getStatuses() === [] && $filter->getTitle() === self::TITLE) {
				$this->filters->delete($filter->getId(), $viewer->getId());
			}
		}
		foreach ($new as [$value, $expiresAt]) {
			$this->add($viewer, $value, $expiresAt);
			$changed = true;
		}
		if ($changed) {
			$this->revisions->bumpForActor($viewer->getId());
		}
	}

	private function add(Person $viewer, string $value, int $expiresAt): void {
		foreach ($this->filters->getActiveByActor($viewer->getId(), $this->time->getTime()) as $filter) {
			if ($filter->getTitle() === self::TITLE && $filter->getExpiresAt() === $expiresAt) {
				$this->filters->saveKeyword((new FilterKeyword())->setFilterId($filter->getId())->setKeyword($value)->setWholeWord(true));

				return;
			}
		}
		$filter = (new Filter())
			->setActorId($viewer->getId())
			->setTitle(self::TITLE)
			->setContexts(Filter::CONTEXTS)
			->setAction(Filter::ACTION_HIDE)
			->setExpiresAt($expiresAt);
		$filter->addKeyword((new FilterKeyword())->setKeyword($value)->setWholeWord(true));
		$this->filters->save($filter);
	}

	/**
	 * @return array{id: string, value: string, targets: list<string>, actorTarget: string, expiresAt?: string}
	 */
	private static function word(Filter $filter, FilterKeyword $keyword): array {
		$value = $keyword->getKeyword();
		$tag = str_starts_with($value, '#') && strlen($value) > 1;
		$word = [
			'id' => 'f' . $filter->getId() . 'k' . $keyword->getId(),
			'value' => $tag ? substr($value, 1) : $value,
			'targets' => $tag ? ['tag'] : ['content', 'tag'],
			'actorTarget' => 'all',
		];
		if ($filter->getExpiresAt() > 0) {
			$word['expiresAt'] = Syntax::datetime($filter->getExpiresAt());
		}

		return $word;
	}

	/** A muted word as the keyword it is here: a hashtag only, as `#tag`. */
	private static function keywordOf(array $item): string {
		$value = trim((string)($item['value'] ?? ''));
		$value = mb_substr(ltrim($value, '#'), 0, 255);
		$targets = is_array($item['targets'] ?? null) ? $item['targets'] : [];

		return $value === '' ? '' : (($targets === ['tag']) ? '#' . $value : $value);
	}

	private static function expiryOf(array $item): int {
		$at = strtotime((string)($item['expiresAt'] ?? ''));

		return $at === false ? 0 : $at;
	}
}
