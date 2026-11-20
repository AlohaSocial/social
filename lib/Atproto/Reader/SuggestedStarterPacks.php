<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Service\CollectionPackService;
use OCA\Social\Service\StarterPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky's suggested starter packs (`app.bsky.unspecced.getSuggestedStarterPacks`),
 * read in as starter packs beside the fediverse's collections.
 *
 * Asked of the AppView signed out, once a day with the collections
 * (`CollectionPackService::refresh()`). Members come from the pack's list
 * (`app.bsky.graph.getList`), capped at a starter pack's size and cached as
 * actors. Members blocked here or limited by Bluesky moderation are left out,
 * and so is a pack whose creator is. Bluesky starter packs are public lists,
 * so there is no consent to verify as for `featureAuthorization`.
 */
class SuggestedStarterPacks {
	/** The `origin` of a pack read from here. */
	public const ORIGIN = 'bluesky';

	/** How many suggested packs are asked for. */
	public const LIMIT = 12;

	public function __construct(
		private AppViewClient $appView,
		private ActorMapper $actorMapper,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private StarterPacksRequest $starterPacksRequest,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the suggested packs again, stores them, and removes the ones Bluesky
	 * no longer suggests. If the AppView does not answer this throws and the
	 * last list stays; so does a pack whose list could not be read.
	 *
	 * @return array{packs: int, members: int}
	 */
	public function refresh(): array {
		$answer = $this->appView->query('app.bsky.unspecced.getSuggestedStarterPacks', ['limit' => self::LIMIT]);
		$views = is_array($answer['starterPacks'] ?? null) ? $answer['starterPacks'] : [];

		$result = ['packs' => 0, 'members' => 0];
		$seen = [];
		foreach ($views as $view) {
			try {
				$pack = is_array($view) ? $this->pack($view) : null;
			} catch (Throwable $e) {
				$seen[(string)($view['uri'] ?? '')] = true;
				$this->logger->notice('[SuggestedStarterPacks] the members of a suggested starter pack could not be read', [
					'pack' => $view['uri'] ?? '', 'exception' => $e,
				]);
				continue;
			}
			if ($pack === null || !$this->starterPacksRequest->save($pack)) {
				continue;
			}
			$seen[$pack['uri']] = true;
			$result['packs']++;
			$result['members'] += count($pack['members']);
		}

		$this->forgetAllBut($seen);

		return $result;
	}

	/**
	 * Removes every pack read from here but the ones named; with none named,
	 * all of them, as when Bluesky is switched off or no longer a source.
	 *
	 * @param array<string, true> $keep by `at://` URI
	 */
	public function forgetAllBut(array $keep = []): void {
		$this->starterPacksRequest->deleteByIds(array_column(
			$this->starterPacksRequest->staleOf(self::ORIGIN, array_keys($keep)), 'id'
		));
	}

	/**
	 * One suggested pack as a row, or null when it is not one to offer.
	 *
	 * @param array<string, mixed> $view a `starterPackView`
	 *
	 * @return array{uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int}|null
	 *
	 * @throws Throwable when its list could not be read
	 */
	private function pack(array $view): ?array {
		$uri = (string)($view['uri'] ?? '');
		$parsed = Syntax::parseAtUri($uri);
		$creator = is_array($view['creator'] ?? null) ? $view['creator'] : [];
		$did = (string)($creator['did'] ?? '');
		$list = (string)($view['list']['uri'] ?? '');
		if ($parsed === null || ($parsed['collection'] ?? '') !== StarterPacks::COLLECTION
			|| $did === '' || $did !== ($parsed['authority'] ?? '') || $list === ''
			|| !$this->admits($creator)) {
			return null;
		}

		$members = $this->members($list);
		if ($members === []) {
			return null;
		}

		$handle = (string)($creator['handle'] ?? '');
		$name = trim((string)($view['record']['name'] ?? ''));

		return [
			'uri' => $uri,
			'slug' => CollectionPackService::slug($name, $uri),
			'origin' => self::ORIGIN,
			'curator' => BlueskyIds::actorId($did),
			'curator_acct' => ($handle !== '' && $handle !== 'handle.invalid') ? $handle : $did,
			'name' => mb_substr(($name !== '') ? $name : $handle, 0, 255),
			'summary' => mb_substr(trim((string)($view['record']['description'] ?? '')), 0, 1000),
			'topic' => '',
			'language' => '',
			'url' => 'https://bsky.app/starter-pack/' . (($handle !== '') ? $handle : $did) . '/' . (string)($parsed['rkey'] ?? ''),
			'members' => $members,
			'fetched' => $this->timeFactory->getTime(),
		];
	}

	/**
	 * The actor ids of a pack's list, in its order, the ones this instance
	 * admits, each cached from the profile the list answered with.
	 *
	 * @return list<string>
	 */
	private function members(string $list): array {
		$answer = $this->appView->query('app.bsky.graph.getList', ['list' => $list, 'limit' => StarterPackService::MAX_HANDLES]);

		$members = [];
		foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
			$subject = is_array($item['subject'] ?? null) ? $item['subject'] : [];
			$did = (string)($subject['did'] ?? '');
			if ($did === '' || !$this->admits($subject)) {
				continue;
			}
			$actorId = BlueskyIds::actorId($did);
			if (!in_array($actorId, $members, true)) {
				$members[] = $actorId;
			}
		}

		return $members;
	}

	/**
	 * Whether an account may be offered: stored in the actor cache from its
	 * profile if it is not there yet, and neither blocked here nor limited by
	 * Bluesky's moderation — the rule `NotificationPoller` takes a Bluesky
	 * account in under.
	 *
	 * @param array<string, mixed> $profile a `profileView`
	 */
	private function admits(array $profile): bool {
		$did = (string)($profile['did'] ?? '');
		if ($did === '' || $this->blocklist->isBlockedDid($did)) {
			return false;
		}

		$actor = $this->actors->cached($did);
		if ($actor !== null) {
			return !BlueskyActorService::isLimited($actor) && !$this->blocklist->isBlockedActor($actor);
		}

		try {
			$person = $this->actorMapper->person($profile);
			if (BlueskyActorService::isLimited($person)) {
				return false;
			}
			$this->actors->store($person);
		} catch (Throwable $e) {
			$this->logger->debug('[SuggestedStarterPacks] a Bluesky account could not be stored', ['did' => $did, 'exception' => $e->getMessage()]);

			return false;
		}

		return true;
	}
}
