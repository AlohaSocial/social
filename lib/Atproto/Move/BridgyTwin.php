<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\PostService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Bluesky account Bridgy Fed made for a local account (§13.3), its
 * "twin": found by the handle Bridgy gives it, and moved here with Bridgy's
 * own `migrate-to` command, which the account sends to Bridgy's bot as a
 * direct message. Bridgy then does what a migration tool does, against
 * this server, and stops bridging the account to Bluesky.
 */
class BridgyTwin {
	/** where Bridgy Fed keeps the repositories of the accounts it bridges */
	public const PDS_HOST = 'atproto.brid.gy';
	/** Bridgy Fed's bot for Bluesky, as the Fediverse addresses it */
	public const BOT = 'bsky.brid.gy@bsky.brid.gy';
	private const SUFFIX = '.ap.brid.gy';

	public function __construct(
		private AppViewClient $appView,
		private PlcClient $plc,
		private PostService $posts,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The handle Bridgy Fed gives an account's twin: `alice@social.example.com`
	 * is `alice.social.example.com.ap.brid.gy`.
	 */
	public static function handleOf(Person $actor): string {
		return str_replace(['@', '_', '~', ':'], ['.', '-', '-', '-'], strtolower(ltrim($actor->getAccount(), '@'))) . self::SUFFIX;
	}

	/** Whether a PDS endpoint is Bridgy Fed's. */
	public static function hosts(string $endpoint): bool {
		return strtolower((string)parse_url($endpoint, PHP_URL_HOST)) === self::PDS_HOST;
	}

	/**
	 * The account's twin, when Bridgy Fed bridges it to Bluesky.
	 *
	 * @return array{handle: string, did: string}|null
	 */
	public function find(Person $actor): ?array {
		$handle = self::handleOf($actor);
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $handle])['did'] ?? '');
			if (!str_starts_with($did, 'did:plc:') || !self::hosts((string)($this->plc->data($did)['services']['atproto_pds']['endpoint'] ?? ''))) {
				return null;
			}
		} catch (Throwable $e) {
			$this->logger->debug('No Bridgy Fed twin found', ['handle' => $handle, 'exception' => $e]);

			return null;
		}

		return ['handle' => $handle, 'did' => $did];
	}

	/**
	 * The `migrate-to` command: this server, an e-mail address and the
	 * handle for the account here, and the one-time code as both the
	 * password and the invite code.
	 */
	public static function command(string $pdsHost, string $email, string $handle, string $code): string {
		return 'migrate-to ' . implode(' ', [$pdsHost, $email, $handle, $code, $code]);
	}

	/**
	 * Sends the command to Bridgy's bot, as a direct message from the account.
	 *
	 * @throws Throwable when the message cannot be sent
	 */
	public function ask(Person $actor, string $command): void {
		$post = new Post($actor);
		$post->setContent('@' . self::BOT . ' ' . $command);
		$post->setType(Stream::TYPE_DIRECT);
		$this->posts->createPost($post);
	}
}
