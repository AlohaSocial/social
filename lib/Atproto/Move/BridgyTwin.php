<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\DnsLookup;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\PostService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Bluesky account Bridgy Fed made for a local account (§13.3), its
 * "twin": found by the handle Bridgy gives it — or, renamed, by its DNS
 * record or Bluesky's search — and moved here with Bridgy's
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

	/** how many search results are looked at */
	private const SEARCHED = 10;

	public function __construct(
		private AppViewClient $appView,
		private PlcClient $plc,
		private PostService $posts,
		private DnsLookup $dns,
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
	 * The account's twin, when Bridgy Fed bridges it to Bluesky: by the
	 * handle Bridgy gives it, through the AppView or the DNS record Bridgy
	 * keeps for it even once the person chose a domain of their own as the
	 * handle. Asked to search, Bluesky's search is given the account's
	 * Fediverse address, and a result is taken only when its DID document
	 * names this account — Bridgy lists the Fediverse actor there.
	 *
	 * @return array{handle: string, did: string}|null the twin, under the handle it has now
	 */
	public function find(Person $actor, bool $search = false): ?array {
		$did = $this->resolve(self::handleOf($actor));
		$twin = $did === '' ? null : $this->twin($did, $actor, false);
		if ($twin === null && $search) {
			foreach ($this->searched(ltrim($actor->getAccount(), '@')) as $candidate) {
				$twin = $this->twin($candidate, $actor, true);
				if ($twin !== null) {
					break;
				}
			}
		}

		return $twin;
	}

	/**
	 * The DID a Bridgy handle names: the AppView's answer, else the DNS record.
	 */
	private function resolve(string $handle): string {
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $handle])['did'] ?? '');
		} catch (Throwable) {
			$did = '';
		}
		if ($did === '') {
			foreach ($this->dns->txt('_atproto.' . $handle) as $text) {
				if (str_starts_with($text, 'did=')) {
					$did = substr($text, 4);
					break;
				}
			}
		}

		return str_starts_with($did, 'did:plc:') ? $did : '';
	}

	/**
	 * @return list<string> the DIDs Bluesky's search answers for a text
	 */
	private function searched(string $text): array {
		try {
			$answer = $this->appView->query('app.bsky.actor.searchActors', ['q' => $text, 'limit' => self::SEARCHED]);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky search for a Bridgy Fed twin not answered', ['exception' => $e]);

			return [];
		}
		$dids = [];
		foreach (is_array($answer['actors'] ?? null) ? $answer['actors'] : [] as $found) {
			$did = is_array($found) && is_string($found['did'] ?? null) ? $found['did'] : '';
			if (str_starts_with($did, 'did:plc:')) {
				$dids[] = $did;
			}
		}

		return $dids;
	}

	/**
	 * A DID as the account's twin: on Bridgy's PDS and, where asked, naming
	 * the account in its document.
	 *
	 * @return array{handle: string, did: string}|null
	 */
	private function twin(string $did, Person $actor, bool $mustNameActor): ?array {
		try {
			$data = $this->plc->data($did);
		} catch (Throwable $e) {
			$this->logger->debug('Bridgy Fed twin not read', ['did' => $did, 'exception' => $e]);

			return null;
		}
		$aka = is_array($data['alsoKnownAs'] ?? null) ? $data['alsoKnownAs'] : [];
		if (!self::hosts((string)($data['services']['atproto_pds']['endpoint'] ?? ''))
			|| ($mustNameActor && !in_array($actor->getId(), $aka, true))) {
			return null;
		}
		foreach ($aka as $uri) {
			if (is_string($uri) && str_starts_with($uri, 'at://')) {
				return ['handle' => substr($uri, 5), 'did' => $did];
			}
		}

		return ['handle' => self::handleOf($actor), 'did' => $did];
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
