<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Published block lists this instance follows, applied once a day when an
 * admin turns one on (all are off by default).
 *
 * Turning one off does not undo it: a block purges what was held of that
 * server. Entries a source drops are not removed here either, so an admin's
 * own decision is never undone by somebody else's list. Rows are applied as
 * `BlocklistImportService` reads them.
 */
class BlocklistSubscriptionService {
	/** How long one fetch may take. A published list is a static file. */
	private const TIMEOUT = 30;

	/**
	 * The sources an admin is offered. `mastodon.social` publishes its
	 * moderated servers through Mastodon's API (few servers enable this; `url`
	 * re-points a source at any that does). The Bad Space is a shared list,
	 * exported by agreement level: `/80` is what 80% of its participants
	 * block.
	 */
	private const DEFAULTS = [
		[
			'id' => 'mastodon.social',
			'label' => 'mastodon.social',
			'url' => 'https://mastodon.social/api/v1/instance/domain_blocks',
			'format' => BlocklistImportService::FORMAT_MASTODON,
		],
		[
			'id' => 'thebad.space',
			'label' => 'The Bad Space (80% agreement)',
			'url' => 'https://tweaking.thebad.space/exports/mastodon/80',
			'format' => BlocklistImportService::FORMAT_CSV,
		],
	];

	public function __construct(
		private ConfigService $configService,
		private BlocklistImportService $importService,
		private CurlService $curl,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every source, the ones nobody has touched included.
	 *
	 * @return array<array<string, mixed>> the sources, in the order above
	 */
	public function sources(): array {
		$stored = [];
		foreach ($this->storedSources() as $source) {
			$id = (string)($source['id'] ?? '');
			if ($id !== '') {
				$stored[$id] = $source;
			}
		}

		$sources = [];
		foreach (self::DEFAULTS as $default) {
			$sources[] = $this->merge($default, $stored[$default['id']] ?? []);
			unset($stored[$default['id']]);
		}

		// whatever an admin added themselves, after the ones that ship
		foreach ($stored as $source) {
			$sources[] = $this->merge(
				['id' => (string)$source['id'], 'label' => (string)$source['id'], 'url' => '', 'format' => BlocklistImportService::FORMAT_CSV],
				$source
			);
		}

		return $sources;
	}

	/**
	 * Turns a source on or off, and where it is given, changes where it reads.
	 *
	 * @param string $id which source
	 * @param bool $enabled whether it is followed
	 * @param string|null $url where it reads, or null to leave that alone
	 *
	 * @return array<array<string, mixed>> every source, as sources() gives them
	 *
	 * @throws InvalidArgumentException no such source, or an address it may not read
	 */
	public function configure(string $id, bool $enabled, ?string $url = null): array {
		$known = false;
		foreach ($this->sources() as $source) {
			if ($source['id'] === $id) {
				$known = true;
				break;
			}
		}

		if (!$known) {
			throw new InvalidArgumentException('no such block list source');
		}

		if ($url !== null && $url !== '' && !$this->isFetchable($url)) {
			throw new InvalidArgumentException('a block list is fetched over HTTPS from a named host');
		}

		$stored = [];
		foreach ($this->storedSources() as $source) {
			$sourceId = (string)($source['id'] ?? '');
			if ($sourceId !== '') {
				$stored[$sourceId] = $source;
			}
		}

		$entry = $stored[$id] ?? ['id' => $id];
		$entry['enabled'] = $enabled;
		if ($url !== null && $url !== '') {
			$entry['url'] = $url;
		}
		$stored[$id] = $entry;

		$this->store(array_values($stored));

		return $this->sources();
	}

	/**
	 * Reads one source and applies what it says.
	 *
	 * @param string $id which source
	 * @param bool $dryRun whether to report what it would do without doing it
	 *
	 * @return array<string, mixed> what happened, as the admin page shows it
	 */
	public function fetch(string $id, bool $dryRun = false): array {
		$source = null;
		foreach ($this->sources() as $candidate) {
			if ($candidate['id'] === $id) {
				$source = $candidate;
				break;
			}
		}

		if ($source === null) {
			throw new InvalidArgumentException('no such block list source');
		}

		$url = $source['url'];
		if (!$this->isFetchable($url)) {
			return $this->recorded($id, $dryRun, ['error' => 'this source has no address to read']);
		}

		try {
			$stream = $this->curl->openStream($url, ['Accept' => 'application/json, text/csv, text/plain'], '', self::TIMEOUT, BlocklistImportService::MAX_BYTES)['stream'];
			// one byte past the ceiling at most, which is enough for parse()
			// to refuse the list as too large without it all being in memory
			$body = (string)stream_get_contents($stream, BlocklistImportService::MAX_BYTES + 1);
			fclose($stream);
		} catch (RequestResultSizeException $e) {
			return $this->recorded($id, $dryRun, [
				'error' => 'the list is larger than ' . intdiv(BlocklistImportService::MAX_BYTES, 1024 * 1024) . ' MB',
			]);
		} catch (Throwable $e) {
			$this->logger->notice('could not read a subscribed block list', [
				'source' => $id, 'url' => $url, 'exception' => $e,
			]);

			return $this->recorded($id, $dryRun, [
				// a server that does not publish its list answers 404, which
				// is a thing an admin can act on rather than a failure here
				'error' => 'that server did not hand over a list',
			]);
		}

		try {
			$read = $this->importService->parse($body, $source['format']);
			$applied = $this->importService->apply($read['entries'], $dryRun, $read['comments']);
		} catch (InvalidArgumentException $e) {
			return $this->recorded($id, $dryRun, ['error' => $e->getMessage()]);
		}

		return $this->recorded($id, $dryRun, $applied + [
			'read' => count($read['entries']),
			'rejected' => count($read['rejected']),
			'skipped' => $read['skipped'],
		]);
	}

	/**
	 * Reads every source that is on. What the daily job does.
	 *
	 * @return array<string, array<string, mixed>> what each one did
	 */
	public function fetchEnabled(): array {
		$results = [];
		foreach ($this->sources() as $source) {
			if ($source['enabled'] !== true) {
				continue;
			}

			$results[(string)$source['id']] = $this->fetch((string)$source['id']);
		}

		return $results;
	}

	/** Whether any source is on, which is what decides if the job has work. */
	public function anyEnabled(): bool {
		foreach ($this->sources() as $source) {
			if ($source['enabled'] === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A source as the admin page reads it: what it ships with, under what an
	 * admin changed, under what the last run recorded.
	 *
	 * @param array<string, mixed> $default
	 * @param array<string, mixed> $stored
	 *
	 * @return array<string, mixed>
	 */
	private function merge(array $default, array $stored): array {
		return [
			'id' => (string)$default['id'],
			'label' => (string)($stored['label'] ?? $default['label']),
			'url' => (string)($stored['url'] ?? $default['url']),
			'format' => (string)($stored['format'] ?? $default['format']),
			'enabled' => ($stored['enabled'] ?? false) === true,
			'lastRun' => (int)($stored['lastRun'] ?? 0),
			'lastResult' => is_array($stored['lastResult'] ?? null) ? $stored['lastResult'] : [],
		];
	}

	/**
	 * Records what a run did, and returns it; dry runs are not recorded.
	 *
	 * @param array<string, mixed> $result
	 *
	 * @return array<string, mixed>
	 */
	private function recorded(string $id, bool $dryRun, array $result): array {
		if ($dryRun) {
			return $result + ['id' => $id, 'dryRun' => true];
		}

		$stored = [];
		foreach ($this->storedSources() as $source) {
			$sourceId = (string)($source['id'] ?? '');
			if ($sourceId !== '') {
				$stored[$sourceId] = $source;
			}
		}

		$entry = $stored[$id] ?? ['id' => $id];
		$entry['lastRun'] = time();
		$entry['lastResult'] = $result;
		$stored[$id] = $entry;
		$this->store(array_values($stored));

		return $result + ['id' => $id, 'dryRun' => false];
	}

	/**
	 * An address a list may be read from: HTTPS and a dotted host, so the
	 * server is not made to fetch private addresses on a timer.
	 */
	private function isFetchable(string $url): bool {
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
			return false;
		}

		$host = strtolower($parts['host'] ?? '');

		return $host !== '' && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false;
	}

	/** @return array<array<string, mixed>> */
	private function storedSources(): array {
		$stored = json_decode(
			(string)$this->configService->getAppValue(ConfigService::SOCIAL_BLOCKLIST_SOURCES), true
		);

		return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
	}

	/** @param array<array<string, mixed>> $sources */
	private function store(array $sources): void {
		$this->configService->setAppValue(
			ConfigService::SOCIAL_BLOCKLIST_SOURCES, (string)json_encode($sources)
		);
	}
}
