<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\IAppConfig;

/**
 * The settings and the state of the Aloha Directory integration, all in app
 * config. The keys of the concept (A.5) are the administrator's settings;
 * everything else is state this app keeps for itself.
 */
class DirectoryConfig {
	/** Take part in the server list. */
	public const ADVERTISE = 'directory_advertise';
	/** Upper limit for the free accounts the heartbeat reports; empty for none. */
	public const ADVERTISED_ACCOUNTS = 'directory_advertised_accounts';
	/** Use the curated search providers of `search.json`. */
	public const ALOHA_LIST = 'directory_aloha_list';
	/** Fetch the lists from a mirror instead of the directory itself. */
	public const LIST_MIRROR = 'directory_list_mirror';
	/** The directory's address; changed only for a staging directory. */
	public const URL = 'directory_url';
	/** JSON object kid => base64url public key, used instead of the pinned root keys. */
	public const ROOT_KEYS = 'directory_root_keys';

	/** The administrator's part of the listing profile, JSON. */
	public const PROFILE = 'directory_profile';
	/** Enrolment state and the last answers of the directory, JSON. */
	public const STATE = 'directory_state';
	public const KEY = 'directory_key';
	public const PENDING_KEY = 'directory_pending_key';
	/** The challenge the well-known handler serves while an enrolment waits. */
	public const CHALLENGE = 'directory_challenge';
	public const LIST_PREFIX = 'directory_list_';

	public const DEFAULT_URL = 'https://directory.aloha.social';

	/**
	 * The root keys apps and servers pin, kid => base64url public key. Lists
	 * are not fetched while there is none, here or in `directory_root_keys`:
	 * a list nobody can verify is not used.
	 */
	public const PINNED_ROOT_KEYS = [];

	public function __construct(
		private IAppConfig $appConfig,
		private PrivateKeyCipher $cipher,
	) {
	}

	public function advertises(): bool {
		return $this->switch(self::ADVERTISE, false);
	}

	public function usesAlohaList(): bool {
		return $this->switch(self::ALOHA_LIST, true);
	}

	/** The cap on the advertised free accounts, or null for none. */
	public function advertisedAccounts(): ?int {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, self::ADVERTISED_ACCOUNTS, ''));

		return ($raw === '' || !ctype_digit($raw)) ? null : (int)$raw;
	}

	public function directoryUrl(): string {
		return rtrim($this->appConfig->getValueString(Application::APP_ID, self::URL, self::DEFAULT_URL), '/') ?: self::DEFAULT_URL;
	}

	/** The host the directory signs as and every request is signed for. */
	public function authority(): string {
		$host = parse_url($this->directoryUrl(), PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}

	/** Where the lists are read from: the mirror, or the directory. */
	public function listBaseUrl(): string {
		$mirror = rtrim(trim($this->appConfig->getValueString(Application::APP_ID, self::LIST_MIRROR, '')), '/');

		return $mirror !== '' ? $mirror : $this->directoryUrl();
	}

	/**
	 * @return array<string, string> kid => raw public key
	 */
	public function rootKeys(): array {
		$configured = json_decode($this->appConfig->getValueString(Application::APP_ID, self::ROOT_KEYS, ''), true);
		$encoded = is_array($configured) && $configured !== [] ? $configured : self::PINNED_ROOT_KEYS;

		$keys = [];
		foreach ($encoded as $kid => $key) {
			if (is_string($kid) && is_string($key)) {
				try {
					$keys[$kid] = ListVerifier::publicKey($key);
				} catch (ListVerificationException) {
					continue;
				}
			}
		}

		return $keys;
	}

	/**
	 * @param array{advertise?: bool, advertisedAccounts?: ?int, alohaList?: bool, listMirror?: string} $settings
	 */
	public function saveSettings(array $settings): void {
		if (array_key_exists('advertise', $settings)) {
			$this->appConfig->setValueString(Application::APP_ID, self::ADVERTISE, $settings['advertise'] ? '1' : '0');
		}
		if (array_key_exists('advertisedAccounts', $settings)) {
			$cap = $settings['advertisedAccounts'];
			if ($cap !== null && $cap < 0) {
				throw new InvalidArgumentException('The number of advertised accounts cannot be negative');
			}
			$this->appConfig->setValueString(Application::APP_ID, self::ADVERTISED_ACCOUNTS, $cap === null ? '' : (string)$cap);
		}
		if (array_key_exists('alohaList', $settings)) {
			$this->appConfig->setValueString(Application::APP_ID, self::ALOHA_LIST, $settings['alohaList'] ? '1' : '0');
		}
		if (array_key_exists('listMirror', $settings)) {
			$mirror = trim($settings['listMirror']);
			if ($mirror !== '' && (!str_starts_with($mirror, 'https://') || filter_var($mirror, FILTER_VALIDATE_URL) === false)) {
				throw new InvalidArgumentException('The mirror must be an https:// address');
			}
			$this->appConfig->setValueString(Application::APP_ID, self::LIST_MIRROR, $mirror);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function json(string $key): array {
		$value = json_decode($this->appConfig->getValueString(Application::APP_ID, $key, '', lazy: true), true);

		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<string, mixed> $value
	 */
	public function setJson(string $key, array $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, (string)json_encode($value, JSON_UNESCAPED_SLASHES), lazy: true);
	}

	public function key(string $which = self::KEY): ?DirectoryKey {
		$stored = $this->cipher->open($this->appConfig->getValueString(Application::APP_ID, $which, '', lazy: true));
		if ($stored === '') {
			return null;
		}
		$secret = base64_decode($stored, true);
		try {
			return $secret === false ? null : DirectoryKey::fromSecretKey($secret);
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	public function setKey(?DirectoryKey $key, string $which = self::KEY): void {
		if ($key === null) {
			$this->appConfig->deleteKey(Application::APP_ID, $which);

			return;
		}
		$this->appConfig->setValueString(
			Application::APP_ID, $which, $this->cipher->seal(base64_encode($key->secretKey())),
			lazy: true, sensitive: true,
		);
	}

	public function challenge(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CHALLENGE, '', lazy: true);
	}

	public function setChallenge(string $challenge): void {
		if ($challenge === '') {
			$this->appConfig->deleteKey(Application::APP_ID, self::CHALLENGE);

			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::CHALLENGE, $challenge, lazy: true);
	}

	private function switch(string $key, bool $default): bool {
		$raw = strtolower(trim($this->appConfig->getValueString(Application::APP_ID, $key, $default ? '1' : '0')));

		return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
	}
}
