<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Moderation;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoLabelerRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Model\Client\Filter;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky labelers a person subscribes to (§12.2), and what their labels do.
 *
 * Bluesky's own moderation service is always applied, at the moment a post
 * is read (`PostMapper`), and cannot be removed; this is for the others. A
 * labeler's labels reach this instance because every feed read asks for the
 * labelers anybody here subscribes to (`atproto-accept-labelers`); the
 * choice per label — ignore, warn, hide — is each person's own and applies
 * when they read, the way their word filters do. A label's default is the
 * one its labeler declares.
 */
class LabelerService {
	public const SETTINGS = ['ignore', 'warn', 'hide'];
	/** labelers asked for in one read; the AppView has its own limit */
	public const ACCEPT_LIMIT = 20;
	private const DEFINITIONS_TTL = 86400;

	private ICache $cache;
	/** @var array<string, array<string, array<string, string>>> user → labeler → label → setting */
	private array $memo = [];

	public function __construct(
		private AtprotoConfig $config,
		private AtprotoLabelerRequest $request,
		private AppViewClient $appView,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.atproto.labelers');
	}

	/**
	 * A person's labelers, Bluesky's moderation service first, each with its
	 * name, its label definitions and the person's settings.
	 *
	 * @return list<array{did: string, name: string, removable: bool, labels: list<array{value: string, name: string, description: string, setting: string}>}>
	 */
	public function forUser(string $userId): array {
		$subscribed = $this->settingsOf($userId);
		$dids = array_values(array_unique([$this->config->moderationDid(), ...array_keys($subscribed)]));
		$definitions = $this->definitions($dids);
		$labelers = [];
		foreach ($dids as $did) {
			$definition = $definitions[$did] ?? ['name' => '', 'labels' => []];
			$labels = [];
			foreach ($definition['labels'] as $value => $label) {
				$labels[] = [
					'value' => $value,
					'name' => $label['name'],
					'description' => $label['description'],
					'setting' => $subscribed[$did][$value] ?? $label['default'],
				];
			}
			$labelers[] = [
				'did' => $did,
				'name' => $definition['name'],
				'removable' => $did !== $this->config->moderationDid(),
				'labels' => $labels,
			];
		}

		return $labelers;
	}

	/**
	 * @throws InvalidArgumentException when it is no labeler
	 */
	public function subscribe(string $userId, string $handleOrDid): string {
		$did = $this->didOf($handleOrDid);
		if ($did === $this->config->moderationDid()) {
			return $did;
		}
		if (($this->definitions([$did])[$did] ?? null) === null) {
			throw new InvalidArgumentException($handleOrDid . ' is not a Bluesky labeler');
		}
		$this->request->subscribe($userId, $did);
		unset($this->memo[$userId]);

		return $did;
	}

	public function unsubscribe(string $userId, string $did): bool {
		unset($this->memo[$userId]);

		return $this->request->unsubscribe($userId, $did);
	}

	/**
	 * @throws InvalidArgumentException for an unknown setting or a labeler not subscribed to
	 */
	public function setSetting(string $userId, string $did, string $label, string $setting): void {
		if (!in_array($setting, self::SETTINGS, true) || preg_match('/^[a-z-]{1,100}$/', $label) !== 1) {
			throw new InvalidArgumentException('a setting is ignore, warn or hide, for a label value');
		}
		$settings = $this->settingsOf($userId);
		if (!array_key_exists($did, $settings)) {
			throw new InvalidArgumentException('not subscribed to ' . $did);
		}
		$settings[$did][$label] = $setting;
		$this->request->setSettings($userId, $did, $settings[$did]);
		unset($this->memo[$userId]);
	}

	/**
	 * The filter results a post's labels give for this person: one per label
	 * of a subscribed labeler whose setting is warn or hide.
	 *
	 * @param list<array{src: string, val: string}> $labels
	 * @return list<array{filter: array, keyword_matches: list<string>, status_matches: list<string>}>
	 */
	public function results(string $userId, array $labels): array {
		if ($userId === '' || $labels === []) {
			return [];
		}
		$subscribed = $this->settingsOf($userId);
		if ($subscribed === []) {
			return [];
		}
		$results = [];
		foreach ($labels as $label) {
			$src = (string)($label['src'] ?? '');
			$val = (string)($label['val'] ?? '');
			if (!array_key_exists($src, $subscribed) || $val === '' || str_starts_with($val, '!')) {
				continue;
			}
			$definition = $this->definitions([$src])[$src]['labels'][$val] ?? null;
			$setting = $subscribed[$src][$val] ?? ($definition['default'] ?? 'warn');
			if ($setting === 'ignore') {
				continue;
			}
			$results[] = [
				'filter' => [
					'id' => 'bluesky-label:' . $src . ':' . $val,
					'title' => ($definition['name'] ?? '') !== '' ? $definition['name'] : $val,
					'context' => Filter::CONTEXTS,
					'expires_at' => null,
					'filter_action' => $setting === 'hide' ? Filter::ACTION_HIDE : Filter::ACTION_WARN,
				],
				'keyword_matches' => [],
				'status_matches' => [],
			];
		}

		return $results;
	}

	/**
	 * The `atproto-accept-labelers` value for the reads made for everybody:
	 * Bluesky's moderation service and the labelers people here subscribe to.
	 */
	public function acceptHeader(): string {
		try {
			$dids = $this->request->getSubscribedDids(self::ACCEPT_LIMIT);
		} catch (Throwable $e) {
			$this->logger->notice('Subscribed labelers not read', ['exception' => $e]);
			$dids = [];
		}

		return implode(', ', array_values(array_unique([$this->config->moderationDid(), ...$dids])));
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function settingsOf(string $userId): array {
		return $this->memo[$userId] ??= $this->request->getByUser($userId);
	}

	/**
	 * What each labeler declares, read from the AppView once a day: its name
	 * and, per label value, a name, a description and a default setting.
	 *
	 * @param string[] $dids
	 * @return array<string, array{name: string, labels: array<string, array{name: string, description: string, default: string}>}|null>
	 */
	private function definitions(array $dids): array {
		$found = [];
		$missing = [];
		foreach ($dids as $did) {
			$cached = $this->cache->get($did);
			if (is_array($cached)) {
				$found[$did] = $cached;
			} else {
				$missing[] = $did;
			}
		}
		if ($missing === []) {
			return $found;
		}
		try {
			$answer = $this->appView->query('app.bsky.labeler.getServices', ['dids' => $missing, 'detailed' => 'true']);
		} catch (Throwable $e) {
			$this->logger->notice('Labeler definitions not read', ['dids' => $missing, 'exception' => $e]);

			return $found;
		}
		foreach (is_array($answer['views'] ?? null) ? $answer['views'] : [] as $view) {
			$did = (string)($view['creator']['did'] ?? '');
			if (!in_array($did, $missing, true)) {
				continue;
			}
			$definition = ['name' => (string)($view['creator']['displayName'] ?? $view['creator']['handle'] ?? ''), 'labels' => self::labelsOf($view)];
			$this->cache->set($did, $definition, self::DEFINITIONS_TTL);
			$found[$did] = $definition;
		}

		return $found;
	}

	/**
	 * @return array<string, array{name: string, description: string, default: string}>
	 */
	private static function labelsOf(array $view): array {
		$definitions = [];
		foreach (is_array($view['policies']['labelValueDefinitions'] ?? null) ? $view['policies']['labelValueDefinitions'] : [] as $definition) {
			$value = (string)($definition['identifier'] ?? '');
			if (preg_match('/^[a-z-]{1,100}$/', $value) !== 1) {
				continue;
			}
			$strings = self::strings($definition['locales'] ?? []);
			$default = (string)($definition['defaultSetting'] ?? 'warn');
			$definitions[$value] = [
				'name' => $strings['name'] !== '' ? $strings['name'] : $value,
				'description' => $strings['description'],
				'default' => in_array($default, self::SETTINGS, true) ? $default : 'warn',
			];
		}
		foreach (is_array($view['policies']['labelValues'] ?? null) ? $view['policies']['labelValues'] : [] as $value) {
			if (is_string($value) && !str_starts_with($value, '!') && !isset($definitions[$value])) {
				$definitions[$value] = ['name' => $value, 'description' => '', 'default' => 'warn'];
			}
		}

		return $definitions;
	}

	/**
	 * The English strings of a label definition, else the first.
	 *
	 * @return array{name: string, description: string}
	 */
	private static function strings(mixed $locales): array {
		$chosen = null;
		foreach (is_array($locales) ? $locales : [] as $locale) {
			if (!is_array($locale)) {
				continue;
			}
			$chosen ??= $locale;
			if (str_starts_with((string)($locale['lang'] ?? ''), 'en')) {
				$chosen = $locale;
				break;
			}
		}

		return ['name' => trim((string)($chosen['name'] ?? '')), 'description' => trim((string)($chosen['description'] ?? ''))];
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function didOf(string $handleOrDid): string {
		$value = strtolower(trim(ltrim(trim($handleOrDid), '@')));
		if (Syntax::isDid($value)) {
			return $value;
		}
		if (!Syntax::isHandle($value)) {
			throw new InvalidArgumentException('not a handle or a DID: ' . $handleOrDid);
		}
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $value])['did'] ?? '');
		} catch (AppViewNotFoundException) {
			$did = '';
		}
		if (!Syntax::isDid($did)) {
			throw new InvalidArgumentException('no Bluesky account ' . $handleOrDid);
		}

		return $did;
	}
}
