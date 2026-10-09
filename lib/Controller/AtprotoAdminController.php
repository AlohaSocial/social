<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\BlocklistManager;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCA\Social\Atproto\Service\RelayClient;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Throwable;

/**
 * The Bluesky card of the admin page: the settings, the status, and the
 * crawl request. Administrators only, with the CSRF token, like the Server
 * card.
 */
class AtprotoAdminController extends Controller {
	public function __construct(
		IRequest $request,
		private AtprotoConfig $config,
		private ConfigService $configService,
		private AtprotoStatusService $status,
		private RelayClient $relays,
		private Blocklist $blocklist,
		private BlocklistManager $blocklistManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /admin/bluesky`: the settings, the numbers and the checks, run
	 * fresh.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/admin/bluesky')]
	public function current(): DataResponse {
		$this->status->checks(true);

		return new DataResponse($this->status->current());
	}

	/**
	 * `POST /admin/bluesky`: saves the settings. Switching Bluesky on
	 * needs every requirement met; switching it off is always allowed.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/bluesky', postfix: 'save')]
	public function save(
		?bool $enabled = null,
		?array $relays = null,
		?string $plc_directory = null,
		?string $appview = null,
		?string $jetstream = null,
		?int $sync_ceiling = null,
		?array $trusted_clients = null,
	): DataResponse {
		try {
			if ($relays !== null) {
				$this->config->setRelays(array_values(array_filter(array_map(
					static fn (mixed $relay): string => is_string($relay) ? trim($relay) : '',
					$relays,
				), static fn (string $relay): bool => $relay !== '')));
				foreach ($this->config->relays() as $relay) {
					if (preg_match('#^https?://[^/\s]+$#', $relay) !== 1) {
						throw new \InvalidArgumentException('A relay is an origin, like https://bsky.network');
					}
				}
			}
			foreach ([
				ConfigService::ATPROTO_PLC_DIRECTORY => $plc_directory,
				ConfigService::ATPROTO_APPVIEW => $appview,
			] as $key => $value) {
				if ($value === null) {
					continue;
				}
				$value = rtrim(trim($value), '/');
				if (preg_match('#^https?://[^/\s]+(:\d+)?$#', $value) !== 1) {
					throw new \InvalidArgumentException('An origin is expected, like https://plc.directory');
				}
				$this->configService->setAppValue($key, $value);
			}
			if ($jetstream !== null) {
				$jetstream = trim($jetstream);
				if ($jetstream !== '' && preg_match('#^wss?://[^/\s]+#', $jetstream) !== 1) {
					throw new \InvalidArgumentException('A Jetstream endpoint is a WebSocket URL');
				}
				$this->configService->setAppValue(ConfigService::ATPROTO_JETSTREAM, $jetstream);
			}
			if ($trusted_clients !== null) {
				$clients = array_values(array_filter(array_map(
					static fn (mixed $client): string => is_string($client) ? trim($client) : '',
					$trusted_clients,
				), static fn (string $client): bool => $client !== ''));
				foreach ($clients as $client) {
					if (!str_starts_with($client, 'https://') || filter_var($client, FILTER_VALIDATE_URL) === false) {
						throw new \InvalidArgumentException('A Bluesky app is named by its client ID, an https address');
					}
				}
				$this->config->setTrustedClients($clients);
			}
			if ($sync_ceiling !== null) {
				$this->configService->setAppValue(ConfigService::ATPROTO_SYNC_CEILING, (string)max(1, min(10000, $sync_ceiling)));
			}
			if ($enabled !== null) {
				if ($enabled && !$this->config->isEnabled()) {
					// the checks read the switch; it is set first and taken
					// back when the instance is not a PDS yet
					$this->config->setEnabled(true);
					if (!$this->status->ready(true)) {
						$this->config->setEnabled(false);

						return new DataResponse($this->status->current() + ['error' => 'Not every requirement is met; see the checks'], Http::STATUS_UNPROCESSABLE_ENTITY);
					}
				}
				$this->config->setEnabled($enabled);
			}
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($this->status->current());
	}

	/**
	 * `POST /admin/bluesky/crawl`: tells the relays now.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/bluesky/crawl')]
	public function crawl(): DataResponse {
		try {
			return new DataResponse(['results' => $this->relays->requestCrawl()]);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * `GET /admin/bluesky/blocks`: the block list.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/admin/bluesky/blocks')]
	public function blocks(): DataResponse {
		return new DataResponse(['blocks' => $this->blocklist->list()]);
	}

	/**
	 * `POST /admin/bluesky/blocks`: blocks a DID or a PDS host and purges
	 * the accounts of it that are followed here.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/bluesky/blocks')]
	public function block(string $target, string $reason = ''): DataResponse {
		try {
			$purged = $this->blocklistManager->block($target, $reason);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['blocks' => $this->blocklist->list(), 'purged' => $purged]);
	}

	/**
	 * `DELETE /admin/bluesky/blocks`: takes a target off the list.
	 */
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/bluesky/blocks')]
	public function unblock(string $target): DataResponse {
		try {
			$this->blocklistManager->unblock($target);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['blocks' => $this->blocklist->list()]);
	}
}
