<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\ServerBlockRequest;
use OCA\Social\Model\Client\AdminDomainBlock;

/**
 * Reading a published block list, and applying it.
 *
 * Three things use this: `occ social:fediverse import`, the upload on the
 * admin page, and the subscriptions that fetch a list on a timer. They read
 * the same formats, refuse the same rows and report the same counts, because
 * an admin who imports a file by hand and one who subscribes to it should not
 * discover that the two disagreed.
 *
 * Nothing here decides policy. What the list says is applied as the list says
 * it: a row marked `suspend` blocks the instance and a row marked `silence`
 * silences it. Reading a column and ignoring it would be worse than not
 * reading it — a silence is a decision to keep a server out of the public
 * timelines, and applying it as a block deletes everything this one holds of
 * it instead. Thirty of mastodon.social's two hundred and seventy-six entries
 * are silences; they are not the majority, and they are not nothing.
 */
class BlocklistImportService {
	/** A Mastodon-style CSV export: `#domain,#severity,…` or one domain a line. */
	public const FORMAT_CSV = 'csv';

	/** Mastodon's `GET /api/v1/instance/domain_blocks`, as JSON. */
	public const FORMAT_MASTODON = 'mastodon';

	public const SEVERITY_SUSPEND = 'suspend';
	public const SEVERITY_SILENCE = 'silence';

	/** A row that says to do nothing is a row about a decision, not a decision. */
	public const SEVERITY_NOOP = 'noop';

	/**
	 * The biggest list worth reading, in bytes.
	 *
	 * Ten thousand hostnames is a few hundred kilobytes, and a published list
	 * with its comment column is well inside this. The cap is here rather than
	 * only on the number of domains because the count is reached by parsing
	 * the whole thing first.
	 */
	public const MAX_BYTES = 8 * 1024 * 1024;

	/** How many distinct instances one list may name. */
	public const MAX_DOMAINS = 10000;

	/** What a CSV calls its domain column, where it names one. */
	private const DOMAIN_HEADERS = ['#domain', 'domain', 'host', 'hostname'];

	/** What a CSV with a header row calls its severity column. */
	private const SEVERITY_HEADERS = ['#severity', 'severity'];

	/** What it calls the reason it publishes; Mastodon's export writes `#public_comment`. */
	private const COMMENT_HEADERS = ['#public_comment', 'public_comment', '#comment', 'comment'];

	public function __construct(
		private FediverseService $fediverseService,
		private ServerBlockRequest $serverBlockRequest,
	) {
	}

	/**
	 * Reads a list into the decisions it describes.
	 *
	 * @param string $body the list as it was uploaded or fetched
	 * @param string $format one of the FORMAT_* constants
	 *
	 * @return array{entries: array<string, string>, comments: array<string, string>, rejected: string[], skipped: int}
	 *                                                                                                                  `entries` is domain => severity in the order they were read;
	 *                                                                                                                  `comments` is domain => the public reason the list gives, for
	 *                                                                                                                  the entries that give one; `rejected` names the rows that
	 *                                                                                                                  describe no instance this list may hold; `skipped` counts the
	 *                                                                                                                  rows that asked for nothing to be done
	 *
	 * @throws InvalidArgumentException the list is too big, or is not that format
	 */
	public function parse(string $body, string $format): array {
		if (strlen($body) > self::MAX_BYTES) {
			throw new InvalidArgumentException(
				'the list is larger than ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB'
			);
		}

		$rows = match ($format) {
			self::FORMAT_MASTODON => $this->rowsFromMastodon($body),
			self::FORMAT_CSV => $this->rowsFromCsv($body),
			default => throw new InvalidArgumentException('unknown list format: ' . $format),
		};

		$entries = [];
		$comments = [];
		$rejected = [];
		$skipped = 0;
		foreach ($rows as [$domain, $severity, $comment]) {
			$domain = rtrim(strtolower(trim($domain)), '.');
			if ($domain === '' || str_starts_with($domain, '#')) {
				continue;
			}

			if ($severity === self::SEVERITY_NOOP) {
				$skipped++;

				continue;
			}

			if (!$this->isImportableDomain($domain) || $this->fediverseService->isLocal($domain)) {
				$rejected[] = $domain;

				continue;
			}

			// the first decision about an instance stands: a published list is
			// ordered, and a later duplicate is a second entry rather than a
			// correction
			if (!isset($entries[$domain])) {
				$entries[$domain] = $severity;
				$comment = mb_substr(trim($comment), 0, AdminDomainBlock::MAX_COMMENT);
				if ($comment !== '') {
					$comments[$domain] = $comment;
				}
			}

			if (count($entries) > self::MAX_DOMAINS) {
				throw new InvalidArgumentException(
					'the list names more than ' . self::MAX_DOMAINS . ' instances'
				);
			}
		}

		return [
			'entries' => $entries,
			'comments' => $comments,
			'rejected' => array_values(array_unique($rejected)),
			'skipped' => $skipped,
		];
	}

	/**
	 * Applies what a list says, or counts what applying it would do.
	 *
	 * The public reasons a list gives are recorded for the domains this
	 * import blocks or silences, and for no other: a domain that was listed
	 * already keeps the reasons a moderator gave it here.
	 *
	 * @param array<string, string> $entries domain => severity, as parse() returns
	 * @param bool $dryRun whether to count without changing anything
	 * @param array<string, string> $comments domain => public reason, as parse() returns
	 *
	 * @return array{blocked: int, silenced: int, alreadyBlocked: int, alreadySilenced: int}
	 *
	 * @throws InvalidArgumentException this server is running an allow list
	 */
	public function apply(array $entries, bool $dryRun = false, array $comments = []): array {
		if ($this->fediverseService->getAccessType() !== 'all_but') {
			throw new InvalidArgumentException('a block list cannot be imported into an allow list');
		}

		// both lists read once and looked up in memory, and each written once
		// at the end: asking FediverseService per domain decoded the whole list
		// for every entry of a list that is a couple of thousand long
		$silencedHosts = $this->fediverseService->silencedHosts();
		$listedHosts = $this->fediverseService->exactlyListedHosts();

		$toBlock = [];
		$toSilence = [];
		$alreadyBlocked = 0;
		$alreadySilenced = 0;

		foreach ($entries as $domain => $severity) {
			if ($severity === self::SEVERITY_SILENCE) {
				if (FediverseService::covers($silencedHosts, $domain)) {
					$alreadySilenced++;

					continue;
				}

				$silencedHosts[FediverseService::normalizeHost($domain)] = true;
				$toSilence[] = $domain;

				continue;
			}

			if (isset($listedHosts[FediverseService::normalizeHost($domain)])) {
				$alreadyBlocked++;

				continue;
			}

			$toBlock[] = $domain;
		}

		$silenced = count($toSilence);
		if (!$dryRun && $toSilence !== []) {
			$this->fediverseService->silenceAddresses($toSilence);
		}

		$blocked = count($toBlock);
		if (!$dryRun && $toBlock !== []) {
			$blocked = $this->fediverseService->addAddresses($toBlock);
		}

		if (!$dryRun) {
			foreach (array_merge($toSilence, $toBlock) as $domain) {
				if (($comments[$domain] ?? '') !== '') {
					$this->serverBlockRequest->save(FediverseService::normalizeHost($domain), null, $comments[$domain]);
				}
			}
		}

		return [
			'blocked' => $blocked,
			'silenced' => $silenced,
			'alreadyBlocked' => $alreadyBlocked,
			'alreadySilenced' => $alreadySilenced,
		];
	}

	/**
	 * Whether a row names a host this list can sensibly hold.
	 *
	 * At least two labels, because an entry covers itself *and everything
	 * under it* — `FediverseService::isListed()` matches a suffix — so a
	 * single-label row in a published list refuses a whole top-level domain.
	 * `com` in a reviewed CSV would block every `.com` server this instance
	 * has ever met and queue a purge for each; `localhost` and `intranet` get
	 * in the same way.
	 *
	 * Two labels is not a public-suffix check: `co.uk` still passes, and no
	 * list of suffixes ships with this app. Reading a list before applying it
	 * is what the preview is for.
	 */
	public function isImportableDomain(string $domain): bool {
		if ($domain === '' || strlen($domain) > 253 || !str_contains($domain, '.')) {
			return false;
		}

		$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

		return preg_match('/^(?:' . $label . ')(?:\.(?:' . $label . '))+$/D', $domain) === 1;
	}

	/**
	 * @param string $body the JSON Mastodon answers with
	 *
	 * @return array<array{0: string, 1: string, 2: string}> domain, severity and public reason, in order
	 *
	 * @throws InvalidArgumentException it is not that
	 */
	private function rowsFromMastodon(string $body): array {
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			throw new InvalidArgumentException('that server did not answer with a list');
		}

		$rows = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			// a server that publishes its list with the domains hashed says
			// `digest` and no `domain`; there is nothing to import from that
			$domain = (string)($entry['domain'] ?? '');
			if ($domain === '' || str_contains($domain, '*')) {
				continue;
			}

			$rows[] = [
				$domain,
				$this->severityOf((string)($entry['severity'] ?? '')),
				is_string($entry['comment'] ?? null) ? $entry['comment'] : '',
			];
		}

		return $rows;
	}

	/**
	 * A CSV with a header row is read by the names of its columns, so
	 * Mastodon's export — `#domain,#severity,#reject_media,#reject_reports,
	 * #public_comment,#obfuscate` — gives its reasons too; one without is a
	 * domain and, optionally, a severity.
	 *
	 * @param string $body the CSV as it was uploaded
	 *
	 * @return array<array{0: string, 1: string, 2: string}> domain, severity and public reason, in order
	 */
	private function rowsFromCsv(string $body): array {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			throw new InvalidArgumentException('the list could not be read');
		}

		fwrite($handle, $body);
		rewind($handle);

		$rows = [];
		$first = true;
		$columns = ['domain' => 0, 'severity' => 1, 'comment' => null];
		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			// a blank line comes back as one null field
			if ($row === [null]) {
				continue;
			}

			if ($first) {
				$first = false;
				$row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0] ?? '') ?? '';
				$names = array_map(static fn (?string $cell): string => strtolower(trim((string)$cell)), $row);
				if (array_intersect($names, self::DOMAIN_HEADERS) !== []) {
					$columns = $this->columnsOf($row);

					continue;
				}
			}

			$domain = trim((string)($row[$columns['domain']] ?? ''));

			// a list with no severity column is a list of blocks, which is
			// what a bare one-domain-per-line file has always meant here
			$severity = ($columns['severity'] !== null && array_key_exists($columns['severity'], $row))
				? $this->severityOf(trim((string)$row[$columns['severity']]))
				: self::SEVERITY_SUSPEND;

			$comment = ($columns['comment'] !== null) ? (string)($row[$columns['comment']] ?? '') : '';

			$rows[] = [$domain, $severity, $comment];
		}

		fclose($handle);

		return $rows;
	}

	/**
	 * Where a header row puts the columns this reads.
	 *
	 * @param array<int, string|null> $header
	 *
	 * @return array{domain: int, severity: int|null, comment: int|null}
	 */
	private function columnsOf(array $header): array {
		$columns = ['domain' => 0, 'severity' => null, 'comment' => null];
		foreach ($header as $index => $name) {
			$name = strtolower(trim((string)$name));
			if (in_array($name, self::DOMAIN_HEADERS, true)) {
				$columns['domain'] = $index;
			} elseif (in_array($name, self::SEVERITY_HEADERS, true)) {
				$columns['severity'] = $index;
			} elseif (in_array($name, self::COMMENT_HEADERS, true) && $columns['comment'] === null) {
				$columns['comment'] = $index;
			}
		}

		// a header that names no severity column still reads the second
		// column as one, as a headerless file does, unless it is another
		// column this reads
		if ($columns['severity'] === null && !in_array(1, [$columns['domain'], $columns['comment']], true)) {
			$columns['severity'] = 1;
		}

		return $columns;
	}

	/**
	 * What a list's word for a decision means here.
	 *
	 * Mastodon writes `suspend`, `silence` and `noop`; a column that says
	 * something else, or nothing, is read as a block — which is what a list
	 * with no severity column has always meant.
	 */
	private function severityOf(string $severity): string {
		return match (strtolower(trim($severity))) {
			'silence', 'limit' => self::SEVERITY_SILENCE,
			'noop', 'none' => self::SEVERITY_NOOP,
			default => self::SEVERITY_SUSPEND,
		};
	}
}
