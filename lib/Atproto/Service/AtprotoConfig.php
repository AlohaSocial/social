<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;

/**
 * What the Bluesky side of this instance is configured as: on or off, which
 * host its handles live under, where its PDS is reached, and the services
 * it talks to.
 */
class AtprotoConfig {
	public function __construct(
		private ConfigService $configService,
	) {
	}

	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::ATPROTO_ENABLED);
	}

	public function setEnabled(bool $enabled): void {
		$this->configService->setAppValue(ConfigService::ATPROTO_ENABLED, $enabled ? '1' : '0');
	}

	/**
	 * The host handles are issued under, `alice.<this>`: the Social address.
	 *
	 * @throws SocialAppConfigException
	 */
	public function handleHost(): string {
		return strtolower($this->configService->getSocialAddress());
	}

	/**
	 * The PDS endpoint named in every DID document: this instance's origin.
	 * `https` on any real instance; a development server on plain `http`
	 * is still named truthfully, which is what the interop job relies on.
	 *
	 * @throws SocialAppConfigException
	 */
	public function pdsEndpoint(): string {
		$scheme = parse_url($this->configService->getCloudUrl(true), PHP_URL_SCHEME);

		return ($scheme === 'http' ? 'http' : 'https') . '://' . $this->configService->getCloudAuthority();
	}

	public function isSecure(): bool {
		return str_starts_with($this->pdsEndpoint(), 'https://');
	}

	/**
	 * The instance's own identity, `did:web:<host>`.
	 *
	 * @throws SocialAppConfigException
	 */
	public function serviceDid(): string {
		return 'did:web:' . str_replace(':', '%3A', $this->configService->getCloudAuthority());
	}

	public function plcDirectory(): string {
		return rtrim($this->configService->getAppValue(ConfigService::ATPROTO_PLC_DIRECTORY), '/');
	}

	public function appView(): string {
		return rtrim($this->configService->getAppValue(ConfigService::ATPROTO_APPVIEW), '/');
	}

	/** The AppView asked with a service-auth token, as a local user. */
	public function appViewAuth(): string {
		return rtrim($this->configService->getAppValue(ConfigService::ATPROTO_APPVIEW_AUTH), '/');
	}

	/** Bluesky's moderation service, whose labels always apply and which reports go to. */
	public function moderationDid(): string {
		return $this->configService->getAppValue(ConfigService::ATPROTO_MODERATION_DID);
	}

	/** The DID of that AppView: the audience of the service-auth tokens. */
	public function appViewDid(): string {
		return $this->configService->getAppValue(ConfigService::ATPROTO_APPVIEW_DID);
	}

	/**
	 * Bluesky's video service, which turns a video into the stream Bluesky's
	 * apps play: its address, '' to publish a video post as a link instead.
	 */
	public function videoService(): string {
		return rtrim($this->configService->getAppValue(ConfigService::ATPROTO_VIDEO_SERVICE), '/');
	}

	/** The DID of that video service: the audience of the tokens it is handed. */
	public function videoServiceDid(): string {
		return $this->configService->getAppValue(ConfigService::ATPROTO_VIDEO_SERVICE_DID);
	}

	public function jetstream(): string {
		return $this->configService->getAppValue(ConfigService::ATPROTO_JETSTREAM);
	}

	/**
	 * @return string[] the relays to tell about this PDS
	 */
	public function relays(): array {
		$relays = json_decode($this->configService->getAppValue(ConfigService::ATPROTO_RELAYS), true);
		if (!is_array($relays)) {
			return [];
		}

		return array_values(array_filter(array_map(
			static fn (mixed $relay): string => is_string($relay) ? rtrim(trim($relay), '/') : '',
			$relays,
		), static fn (string $relay): bool => $relay !== '' && preg_match('#^https?://#', $relay) === 1));
	}

	/**
	 * @param string[] $relays
	 */
	public function setRelays(array $relays): void {
		$this->configService->setAppValue(ConfigService::ATPROTO_RELAYS, (string)json_encode(array_values($relays)));
	}

	/**
	 * The Bluesky apps an administrator vouches for, by client ID: the
	 * consent page shows their own name and logo, which it never takes from
	 * an app on its word.
	 *
	 * @return string[]
	 */
	public function trustedClients(): array {
		$clients = json_decode($this->configService->getAppValue(ConfigService::ATPROTO_OAUTH_TRUSTED), true);

		return is_array($clients) ? array_values(array_filter($clients, 'is_string')) : [];
	}

	/**
	 * @param string[] $clients
	 */
	public function setTrustedClients(array $clients): void {
		$this->configService->setAppValue(ConfigService::ATPROTO_OAUTH_TRUSTED, (string)json_encode(array_values(array_unique($clients)), JSON_UNESCAPED_SLASHES));
	}

	public function isTrustedClient(string $clientId): bool {
		return in_array($clientId, $this->trustedClients(), true);
	}

	public function syncCeiling(): int {
		return max(1, $this->configService->getAppValueInt(ConfigService::ATPROTO_SYNC_CEILING));
	}

	/**
	 * Bluesky's chat service, which a Bluesky app signed in here reaches its
	 * direct messages through; '' when the administrator turned them off.
	 */
	public function chat(): string {
		return rtrim($this->configService->getAppValue(ConfigService::ATPROTO_CHAT), '/');
	}

	/**
	 * The chat service's DID, `did:web:` its host, as `atproto-proxy` names it.
	 */
	public function chatDid(): string {
		$host = (string)parse_url($this->chat(), PHP_URL_HOST);

		return $host === '' ? '' : 'did:web:' . strtolower($host);
	}

	/**
	 * The settings as the admin page shows and saves them.
	 */
	public function export(): array {
		return [
			'enabled' => $this->isEnabled(),
			'relays' => $this->relays(),
			'plc_directory' => $this->plcDirectory(),
			'appview' => $this->appView(),
			'jetstream' => $this->jetstream(),
			'chat' => $this->chat(),
			'sync_ceiling' => $this->syncCeiling(),
			'trusted_clients' => $this->trustedClients(),
		];
	}
}
