<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether a Bluesky post may be quoted (`app.bsky.feed.postgate`): the gate
 * its author keeps beside it, under the post's own key, in their repository.
 * A quote of a post whose author turned quoting off is shown detached by
 * every AppView, so it is refused here, with the reason, rather than made.
 * The gate is read from the author's own PDS — the AppView says it only to
 * a signed-in viewer — and a post read as such a viewer that says so is
 * marked when it is stored (`PostMapper`), so the quote is not even offered.
 */
class Postgates {
	public const GATE = 'app.bsky.feed.postgate';
	public const REFUSAL = 'The author of this post on Bluesky does not allow quotes';

	public function __construct(
		private AtprotoConfig $config,
		private PlcClient $plc,
		private PdsClient $pds,
		private IdentityService $identities,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Why the account may not quote the post, or '' when it may — also when
	 * the post is not a Bluesky one, or the gate cannot be read.
	 */
	public function refusal(Person $quoter, Stream $quoted): string {
		if (!$this->config->isEnabled() || !BlueskyIds::isPostId($quoted->getId())) {
			return '';
		}
		$author = (string)(Syntax::parseAtUri(self::uriOf($quoted))['authority'] ?? '');
		if (($this->identities->forActor($quoter, false)?->did ?? '') === $author) {
			return '';
		}
		foreach ($this->gateOf($quoted)['embeddingRules'] ?? [] as $rule) {
			if (is_array($rule) && ($rule['$type'] ?? '') === self::GATE . '#disableRule') {
				return self::REFUSAL;
			}
		}

		return '';
	}

	/**
	 * Whether the post's author detached the quote with this `at://` URI:
	 * their postgate lists it in `detachedEmbeddingUris`.
	 */
	public function detached(Stream $quoted, string $quotingUri): bool {
		if (!$this->config->isEnabled() || !BlueskyIds::isPostId($quoted->getId()) || $quotingUri === '') {
			return false;
		}
		$uris = $this->gateOf($quoted)['detachedEmbeddingUris'] ?? [];

		return is_array($uris) && in_array($quotingUri, $uris, true);
	}

	/**
	 * The post's gate as its author's PDS holds it, `[]` for none or when it
	 * cannot be read.
	 */
	private function gateOf(Stream $quoted): array {
		$uri = self::uriOf($quoted);
		$parsed = Syntax::parseAtUri($uri);
		$author = (string)($parsed['authority'] ?? '');
		$rkey = (string)($parsed['rkey'] ?? '');
		if (!str_starts_with($author, 'did:plc:') || $rkey === '') {
			return [];
		}
		try {
			$endpoint = (string)($this->plc->data($author)['services']['atproto_pds']['endpoint'] ?? '');
			$answer = $this->pds->call($this->pds->origin($endpoint), 'com.atproto.repo.getRecord', 'GET', ['repo' => $author, 'collection' => self::GATE, 'rkey' => $rkey]);
		} catch (Throwable $e) {
			$this->logger->info('Post gate not read', ['post' => $uri, 'exception' => $e]);

			return [];
		}
		$gate = $answer['status'] === 200 && is_array($answer['body']['value'] ?? null) ? $answer['body']['value'] : [];

		return ($gate['post'] ?? '') === $uri ? $gate : [];
	}

	private static function uriOf(Stream $post): string {
		return (string)($post->getDetails(PostMapper::DETAIL)['uri'] ?? '');
	}
}
