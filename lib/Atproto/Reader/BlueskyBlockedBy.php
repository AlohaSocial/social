<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\BlockedBy\BlockedBySource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Bluesky account's block of a local one (`app.bsky.graph.block`): a
 * public record in the blocker's repository that nothing delivers here.
 * The AppView says it to the blocked account alone, as `viewer.blockedBy`
 * on the blocker's profile, so it is asked as the local account
 * (`app.bsky.actor.getProfiles`), and taken from any answer read as one.
 * A block by the author of a thread's first post keeps a reply anywhere in
 * the thread out, as every AppView holds it.
 */
class BlueskyBlockedBy implements BlockedBySource {
	public const PROFILES = 'app.bsky.actor.getProfiles';
	/** the most accounts one `getProfiles` names */
	private const BATCH = 25;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private IdentityService $identities,
		private BlockedByService $blockedBy,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(string $actorId): bool {
		return BlueskyIds::isActorId($actorId);
	}

	#[\Override]
	public function ask(string $localId, array $actorIds): array {
		if (!$this->config->isEnabled()) {
			return [];
		}
		try {
			$identity = $this->identities->getByActorId($localId);
		} catch (AtprotoIdentityNotFoundException) {
			// nobody on Bluesky can block an account that is not there
			return [];
		}
		if (!$identity->isActive()) {
			return [];
		}
		$key = $this->identities->signingKey($identity);
		$answers = [];
		foreach (array_chunk(array_values(array_filter($actorIds, $this->supports(...))), self::BATCH) as $chunk) {
			$answer = $this->appView->queryAs($identity->did, $key, self::PROFILES, ['actors' => array_map(BlueskyIds::didOf(...), $chunk)]);
			$answers += array_intersect_key(self::blockedByIn($answer, $identity->did), array_flip($chunk));
		}

		return $answers;
	}

	#[\Override]
	public function threadAuthors(Stream $post): array {
		$root = $post->getDetails(PostMapper::DETAIL)['reply_root']['uri'] ?? '';
		$did = is_string($root) ? (string)(Syntax::parseAtUri($root)['authority'] ?? '') : '';

		return Syntax::isDid($did) ? [BlueskyIds::actorId($did)] : [];
	}

	/**
	 * Records the blocks an answer the AppView gave the person carries:
	 * every account in it whose `viewer` says whether it blocks them.
	 */
	public function learn(Person $viewer, array $answer): void {
		try {
			$did = $this->identities->forActor($viewer, false)?->did ?? '';
			$this->blockedBy->record($viewer->getId(), self::blockedByIn($answer, $did));
		} catch (Throwable $e) {
			$this->logger->info('Blocks of an account not recorded', ['actor' => $viewer->getId(), 'exception' => $e]);
		}
	}

	/**
	 * Whether each account an answer shows has blocked the viewer it was
	 * read as: every view with a `did` and a `viewer` — a profile, a post's
	 * author, a blocked post's author — but the viewer's own.
	 *
	 * @return array<string, bool> by actor id
	 */
	public static function blockedByIn(array $answer, string $viewerDid = ''): array {
		$found = [];
		$walk = static function (array $node) use (&$walk, &$found, $viewerDid): void {
			$did = $node['did'] ?? null;
			if (is_string($did) && Syntax::isDid($did) && $did !== $viewerDid && is_array($node['viewer'] ?? null)) {
				$id = BlueskyIds::actorId($did);
				$found[$id] = ($found[$id] ?? false) || ($node['viewer']['blockedBy'] ?? false) === true;
			}
			foreach ($node as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($answer);

		return $found;
	}
}
