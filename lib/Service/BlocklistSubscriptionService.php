<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Published block lists this instance follows.
 *
 * An admin turns one on, and from then on what that list says is applied here
 * too, once a day. Three are offered to begin with — two servers that publish
 * their own moderation, and one shared list — and an admin can point a source
 * at any other server that publishes one.
 *
 * **Off until somebody says otherwise.** A server's moderation is its own, and
 * adopting another server's is a decision an admin makes rather than one they
 * inherit by installing this app. Turning one on is also not reversible by
 * turning it off: a block purges what this instance holds of that server, so
 * the source can stop saying so and the content does not come back.
 *
 * What the list says is applied as the list says it — see
 * `BlocklistImportService`. Nothing here removes an entry the source dropped,
 * either: an admin who looked at an instance and disagreed should not have
 * their decision undone by somebody else's list changing.
 */
class BlocklistSubscriptionService {
	/** How long one fetch may take. A published list is a static file. */
	private const TIMEOUT = 30;

	/**
	 * The sources an admin is offered.
	 *
	 * `mastodon.social` publishes its own moderated-servers list through
	 * Mastodon's API — an endpoint a server answers only when its admins have
	 * turned publishing on. Most have not, which is why there is one such
	 * server here and not a row of them: a source that can only ever say "that
	 * server did not hand over a list" is a switch that does nothing, and
	 * offering it invites somebody to turn it on and wonder why nothing
	 * happened. `url` re-points a source at any server that does publish.
	 *
	 * The Bad Space is a shared list rather than one server's, and publishes
	 * CSV exports by how many of its participating servers agree: `/80` is
	 * what eighty per cent of them block. The higher the number, the less the
	 * list says and the more agreement there is behind it.
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
			$stream = $this->curl->openStream($url, ['Accept' => 'application/json, text/csv, text/plain'], '', self::TIMEOUT)['stream'];
			// one byte past the ceiling at most, which is enough for parse()
			// to refuse the list as too large without it all being in memory
			$body = (string)stream_get_contents($stream, BlocklistImportService::MAX_BYTES + 1);
			fclose($stream);
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
	 * Writes down what a run did, and hands it back.
	 *
	 * A dry run is not written down: it is a question, and the answer to it is
	 * not what this source last did.
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
	 * An address a list may be read from.
	 *
	 * HTTPS and a host with a dot in it: a source is a published file on the
	 * internet, and an admin typing a private address into this field would
	 * have the server fetch it on a timer for them.
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
