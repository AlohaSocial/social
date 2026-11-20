<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use JsonSerializable;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Details;
use OCA\Social\Tools\Nid;

/**
 * What a keyword filter does to what the viewer is shown (filters are stored
 * by `FiltersRequest`). A `hide` match removes the status; a `warn` match
 * keeps it with a `filtered` key telling the client to blur it. Unmatched
 * statuses carry `filtered: []`, as the key is not optional.
 *
 * Filters are always the viewer's: reads are scoped by actor id in SQL and the
 * cache is keyed by it. The viewer's AI switch (`AiContentService`) is applied
 * here too, in every context.
 */
class FilterService {
	private const HIDDEN_ATPROTO_LABELS = ['!hide', '!takedown'];

	/** @var array<string, Filter[]> actor id => its active filters, for this request */
	private array $cache = [];

	public function __construct(
		private FiltersRequest $filtersRequest,
		private AiContentService $aiContentService,
		private ?LabelerService $labelers = null,
	) {
	}

	/**
	 * The viewer's filters that apply to this context and can still match
	 * something.
	 *
	 * The expiry is checked here as well as in SQL: the cache outlives the
	 * query, and a filter that expires while a request is being answered must
	 * not go on hiding statuses because it was read a moment earlier.
	 *
	 * @return Filter[]
	 */
	public function activeFilters(string $actorId, string $context, ?int $now = null): array {
		if ($actorId === '' || !in_array($context, Filter::CONTEXTS, true)) {
			return [];
		}

		$this->cache[$actorId] ??= $this->filtersRequest->getActiveByActor($actorId, $now);

		$applicable = [];
		foreach ($this->cache[$actorId] as $filter) {
			if ($filter->appliesTo($context) && $filter->isActive($now) && $filter->getKeywords() !== []) {
				$applicable[] = $filter;
			}
		}

		return $applicable;
	}

	/**
	 * A page of statuses as the client should receive it: the ones a `hide`
	 * filter matched are gone, and every one that is left carries `filtered`.
	 *
	 * The items may be `Stream` objects, as a timeline hands them over, or
	 * status entities that have already been exported.
	 *
	 * @param array<Stream|JsonSerializable|array> $items
	 *
	 * @return array<array> status entities
	 */
	public function apply(array $items, string $context, ?Person $viewer): array {
		$filters = ($viewer === null) ? [] : $this->activeFilters($viewer->getId(), $context);
		$hidesAi = $this->hidesAi($viewer);

		$statuses = [];
		foreach ($items as $item) {
			$exported = $this->exportForClient($item);
			if ($this->hasHiddenAtprotoLabel($exported)) {
				continue;
			}
			if ($hidesAi && $this->aiContentService->isLabelled($exported)) {
				continue;
			}
			$labelled = $this->labelResults($exported, $viewer);
			if ($this->isHidden($labelled)) {
				continue;
			}

			$status = $this->applyToExported($exported, $filters, $labelled);
			if ($status !== null) {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * What the labels on a Bluesky post — or on the one a boost carries — do
	 * for this viewer, by the labelers they subscribe to (§12.2).
	 */
	private function labelResults(array $status, ?Person $viewer): array {
		if ($this->labelers === null || $viewer === null) {
			return [];
		}
		$bluesky = $status['bluesky'] ?? (is_array($status['reblog'] ?? null) ? ($status['reblog']['bluesky'] ?? null) : null);
		if (!is_array($bluesky) || !is_array($bluesky['labels'] ?? null) || $bluesky['labels'] === []) {
			return [];
		}

		return $this->labelers->results($viewer->getUserId(), $bluesky['labels']);
	}

	/** Whether this viewer asked for posts made with AI to be dropped. */
	private function hidesAi(?Person $viewer): bool {
		return $viewer !== null && $this->aiContentService->hides($viewer->getUserId());
	}

	/**
	 * One status, for routes that answer with a single one. Null means the
	 * viewer hides it, answered with 404 like a missing status.
	 */
	public function applyToStatus(mixed $item, string $context, ?Person $viewer): ?array {
		$statuses = $this->apply([$item], $context, $viewer);

		return $statuses[0] ?? null;
	}

	/**
	 * The notifications that survive the viewer's `notifications` filters,
	 * returned unexported, as `Stream::exportAsNotification()` builds the
	 * entity. A `hide` filter drops the notification; a `warn` filter leaves
	 * it alone, as that exporter does not emit `filtered`.
	 *
	 * @param array<Stream> $notifications
	 *
	 * @return array<Stream>
	 */
	public function applyToNotifications(array $notifications, ?Person $viewer): array {
		$filters = ($viewer === null)
			? [] : $this->activeFilters($viewer->getId(), Filter::CONTEXT_NOTIFICATIONS);
		$hidesAi = $this->hidesAi($viewer);
		if ($filters === [] && !$hidesAi) {
			return array_values(array_filter(
				$notifications,
				fn (mixed $notification): bool => !$this->hasHiddenAtprotoLabelInItem($notification)
			));
		}

		$kept = [];
		foreach ($notifications as $notification) {
			$matchable = $this->matchable($notification);
			if ($this->hasHiddenAtprotoLabel($matchable)) {
				continue;
			}
			if ($hidesAi && $this->aiContentService->isLabelled($matchable)) {
				continue;
			}
			if (!$this->isHidden($this->results($matchable, $filters))) {
				$kept[] = $notification;
			}
		}

		return $kept;
	}

	/**
	 * What matched, as Mastodon's `FilterResult` entities, one per matching
	 * filter: keyword matches in `keyword_matches`, and per-status filters
	 * ("filter this post") in `status_matches`. A status filter also matches
	 * a boost of that post, which has no words of its own.
	 *
	 * @param array $status a status entity
	 * @param Filter[] $filters
	 *
	 * @return array<array{filter: array, keyword_matches: string[], status_matches: string[]}>
	 */
	public function results(array $status, array $filters): array {
		if ($filters === []) {
			return [];
		}

		$ids = $this->matchableIds($status);
		$text = $this->searchableText($status);

		$results = [];
		foreach ($filters as $filter) {
			$matches = [];
			if ($text !== '') {
				foreach ($filter->getKeywords() as $keyword) {
					$matched = $this->match($keyword, $text);
					if ($matched !== '') {
						$matches[] = $matched;
					}
				}
			}

			$covered = [];
			foreach ($ids as $id) {
				if ($filter->covers($id)) {
					$covered[] = $id;
				}
			}

			if ($matches !== [] || $covered !== []) {
				$results[] = [
					'filter' => $filter->exportAsResultFilter(),
					// the text that matched, as Mastodon reports it: what the
					// keyword found, not the keyword
					'keyword_matches' => array_values(array_unique($matches)),
					'status_matches' => $covered,
				];
			}
		}

		return $results;
	}

	/** A global Bluesky hide/takedown label applies regardless of subscriptions. */
	private function hasHiddenAtprotoLabel(array $status): bool {
		foreach ([$status, $status['reblog'] ?? null] as $entity) {
			if (!is_array($entity)) {
				continue;
			}
			$bluesky = is_array($entity['bluesky'] ?? null) ? $entity['bluesky'] : [];
			if ($this->containsHiddenAtprotoLabel($bluesky['labels'] ?? [])) {
				return true;
			}
		}

		return false;
	}

	/** Notifications are usually Streams; inspect only their saved labels on the fast path. */
	private function hasHiddenAtprotoLabelInItem(mixed $item): bool {
		if (!($item instanceof Stream)) {
			return $this->hasHiddenAtprotoLabel($this->exportForClient($item));
		}
		$object = $item->getObject();
		$subject = ($object instanceof Stream) ? $object : $item;
		$details = $subject->getDetails(Details::ATPROTO);

		return $this->containsHiddenAtprotoLabel($details['label_sources'] ?? []);
	}

	/** @param mixed $labels */
	private function containsHiddenAtprotoLabel(mixed $labels): bool {
		foreach (is_array($labels) ? $labels : [] as $label) {
			if (is_array($label) && in_array($label['val'] ?? '', self::HIDDEN_ATPROTO_LABELS, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The status ids a per-status filter may name: the entity's own, and the
	 * boosted post's where this is a boost.
	 *
	 * @param array $status a status entity
	 *
	 * @return string[] nids, as decimal strings
	 */
	private function matchableIds(array $status): array {
		$ids = [];
		foreach ([$status, $status['reblog'] ?? null] as $entity) {
			$id = is_array($entity) ? (string)($entity['id'] ?? '') : '';
			if (!ctype_digit($id) || Nid::compare($id, '0') < 1) {
				continue;
			}

			$id = Nid::normalize($id);
			if (!in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/** @param array<array{filter: array, ...}> $results */
	public function isHidden(array $results): bool {
		foreach ($results as $result) {
			if (($result['filter']['filter_action'] ?? Filter::ACTION_WARN) === Filter::ACTION_HIDE) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The text a keyword is compared against, as on Mastodon: content warning,
	 * text, attachment descriptions and poll options, without markup. A boost
	 * is matched on the boosted status's text.
	 */
	public function searchableText(array $status): string {
		$status = (isset($status['reblog']) && is_array($status['reblog'])) ? $status['reblog'] : $status;

		$parts = [
			(string)($status['spoiler_text'] ?? ''),
			$this->plainText((string)($status['content'] ?? '')),
		];

		foreach ((array)($status['media_attachments'] ?? []) as $attachment) {
			$parts[] = $this->describedAs($attachment);
		}

		$options = $status['poll']['options'] ?? [];
		foreach ((is_array($options) ? $options : []) as $option) {
			$parts[] = is_array($option) ? (string)($option['title'] ?? '') : '';
		}

		return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
	}

	/**
	 * The comparison a keyword stands for, as Mastodon builds it: always
	 * case-insensitive; `whole_word` adds `\b` only on sides that are word
	 * characters, since `\b` next to `#` or `!` never matches. `/u` makes
	 * `\w` and `\b` Unicode-aware, so "café" does not match inside "cafés".
	 */
	public static function keywordRegex(FilterKeyword $keyword): string {
		$quoted = preg_quote($keyword->getKeyword(), '/');
		if (!$keyword->isWholeWord()) {
			return '/' . $quoted . '/iu';
		}

		$start = (preg_match('/^\w/u', $keyword->getKeyword()) === 1) ? '\b' : '';
		$end = (preg_match('/\w$/u', $keyword->getKeyword()) === 1) ? '\b' : '';

		return '/' . $start . $quoted . $end . '/iu';
	}

	/** The text the keyword matched, or '' for no match. */
	private function match(FilterKeyword $keyword, string $text): string {
		if ($keyword->getKeyword() === '') {
			return '';
		}

		// a keyword is stored as the account typed it and is never run as a
		// pattern: preg_quote() in keywordRegex() is what keeps '(' a bracket
		if (preg_match(self::keywordRegex($keyword), $text, $found) !== 1) {
			return '';
		}

		return $found[0];
	}

	/**
	 * @param Filter[] $filters
	 */
	private function applyToExported(array $status, array $filters, array $extra = []): ?array {
		$results = [...$this->results($status, $filters), ...$extra];
		if ($this->isHidden($results)) {
			return null;
		}

		$status['filtered'] = $results;
		if (isset($status['reblog']) && is_array($status['reblog'])) {
			// a client reads `filtered` off whichever of the two it renders,
			// and for a boost that is the boosted status
			$status['reblog']['filtered'] = $results;
		}

		return $status;
	}

	/** The status entity a client would be handed for this item. */
	private function exportForClient(mixed $item): array {
		if (is_array($item)) {
			return $item;
		}

		if ($item instanceof ACore) {
			$item->setExportFormat(ACore::FORMAT_LOCAL);
		}

		if ($item instanceof JsonSerializable) {
			$exported = $item->jsonSerialize();

			return is_array($exported) ? $exported : [];
		}

		return [];
	}

	/**
	 * A copy of the item to compare keywords against, leaving the item itself
	 * unchanged for the client.
	 */
	private function matchable(mixed $item): array {
		if (is_array($item)) {
			return $item;
		}

		if ($item instanceof Stream) {
			$object = $item->getObject();
			$subject = ($object instanceof Stream) ? $object : $item;
			$details = $subject->getDetails(Details::ATPROTO);

			return [
				'spoiler_text' => $subject->getSpoilerText(),
				'content' => $subject->getContent(),
				'media_attachments' => $subject->getAttachments(),
				'tags' => $subject->getHashtags(),
				'bluesky' => [
					'labels' => is_array($details['label_sources'] ?? null) ? $details['label_sources'] : [],
				],
			];
		}

		return $this->exportForClient($item);
	}

	/** The alt text of an attachment, however the entity carries it. */
	private function describedAs(mixed $attachment): string {
		if (is_array($attachment)) {
			return (string)($attachment['description'] ?? '');
		}

		if (is_object($attachment) && method_exists($attachment, 'getDescription')) {
			return (string)$attachment->getDescription();
		}

		return '';
	}

	/**
	 * The text of a rendered status, with the markup taken out and the
	 * entities put back: a post saying `don&apos;t` is a post saying "don't",
	 * and a filter on "don't" has to match it.
	 */
	private function plainText(string $html): string {
		if ($html === '') {
			return '';
		}

		$text = preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html) ?? $html;

		return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
