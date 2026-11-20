<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Move\BlueskyAfterMoveOut;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Csv;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a local account away, and bringing one here.
 *
 * Outbound: the account records `movedTo` and sends followers a `Move`, and
 * their servers re-follow the new account, which must list this one in its
 * `alsoKnownAs` first (MoveInterface requires the same of incoming Moves).
 * Inbound: the alias is set here, the Move is started on the old server, and
 * the exported follows are re-created through the ordinary follow path.
 */
class MigrationService {
	/** The header Mastodon writes over its `following_accounts.csv`. */
	private const CSV_ADDRESS_COLUMN = 'account address';

	/** How many followers of a moved account to re-follow at a time. */
	private const REFOLLOW_PAGE = 200;

	/** The lists of accounts `exportCsv()` will write, and their names. */
	public const CSV_KINDS = ['following', 'followers', 'blocks', 'mutes', 'lists', 'bookmarks', 'domain_blocks'];

	/** How many blocked domains an export lists at most: every one anybody has. */
	private const EXPORT_DOMAINS = 10000;

	/** How many rows one page of a CSV export reads. */
	private const EXPORT_PAGE = 200;

	/**
	 * How long after a move the account may move again: Mastodon's thirty days.
	 */
	public const COOLDOWN_SECONDS = 30 * 24 * 3600;
	/** The user setting that records when the account last moved. */
	private const MOVED_AT = 'moved_at';

	/**
	 * The most rows one CSV export carries: the file is built in memory, and the
	 * cap ends the paging even if a query ignores its offset.
	 */
	private const EXPORT_MAX = 5000;

	/**
	 * The most followers one Move will re-follow; also ends the paging if a
	 * source ignores the offset.
	 */
	private const REFOLLOW_MAX = 20000;

	/**
	 * The most entries one import of accounts or posts works through, since each
	 * may mean a WebFinger lookup, an actor fetch and a delivery to a server the
	 * file names. The rest is counted as skipped.
	 */
	public const MAX_IMPORT_ENTRIES = MoveInService::MAX_FOLLOWS;

	public function __construct(
		private AccountService $accountService,
		private ActorsRequest $actorsRequest,
		private FollowsRequest $followsRequest,
		private CacheActorService $cacheActorService,
		private FollowService $followService,
		private ActivityService $activityService,
		private SignatureService $signatureService,
		private ActorRelationRequest $actorRelationRequest,
		private ListsRequest $listsRequest,
		private RelationshipService $relationshipService,
		private StreamRequest $streamRequest,
		private SearchService $searchService,
		private StreamActionService $streamActionService,
		private DomainBlockService $domainBlockService,
		private IConfig $config,
		private LoggerInterface $logger,
		private BlueskyAfterMoveOut $blueskyAfterMove,
	) {
	}

	/**
	 * @return string[] the actor ids this user's actor also answers to
	 */
	public function listAliases(string $userId): array {
		return $this->accountService->getActorFromUserId($userId)->getAlsoKnownAs();
	}

	/**
	 * Adds an actor id to `alsoKnownAs`. Idempotent.
	 *
	 * @return string[] the list afterwards
	 * @throws MoveErrorException when `$alias` is not an actor id, or is this account's own
	 */
	public function addAlias(string $userId, string $alias): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$alias = $this->actorIdOrThrow($alias, $actor);

		$aliases = $actor->getAlsoKnownAs();
		if (in_array($alias, $aliases, true)) {
			return $aliases;
		}

		$aliases[] = $alias;
		$this->accountService->setAlsoKnownAs($userId, $aliases);

		return $aliases;
	}

	/**
	 * Removes an actor id from `alsoKnownAs`. Idempotent.
	 *
	 * @return string[] the list afterwards
	 */
	public function removeAlias(string $userId, string $alias): array {
		$actor = $this->accountService->getActorFromUserId($userId);

		$aliases = $actor->getAlsoKnownAs();
		if (!in_array($alias, $aliases, true)) {
			return $aliases;
		}

		$aliases = array_values(array_diff($aliases, [$alias]));
		$this->accountService->setAlsoKnownAs($userId, $aliases);

		return $aliases;
	}

	/**
	 * Moves this user's actor to `$targetId`. The target is fetched fresh and
	 * must list our actor in `alsoKnownAs`; then a `Move` is queued for every
	 * follower and the new home, `movedTo` is recorded, and local followers, who
	 * never receive the Move, are re-followed on their behalf.
	 *
	 * @param bool $enforceCooldown whether a move within COOLDOWN_SECONDS of the
	 *                              last is refused; the person's own button says
	 *                              yes, an administrator's command may say no
	 * @param string $bluesky what becomes of the account's Bluesky identity,
	 *                        one of BlueskyAfterMoveOut::CHOICES
	 * @param string $targetHost when not empty, the host the target's actor id
	 *                           has to be on (`move-target-host` otherwise)
	 *
	 * @return Person the target as fetched
	 * @throws InvalidResourceException when the target does not list the actor, or is the actor,
	 *                                  or the account moved too recently, or the
	 *                                  Bluesky choice cannot be followed — a
	 *                                  MoveErrorException with its code for these
	 */
	public function move(string $userId, string $targetId, bool $enforceCooldown = true, string $bluesky = BlueskyAfterMoveOut::DEACTIVATE, string $targetHost = ''): Person {
		$actor = $this->accountService->getActorFromUserId($userId);
		if ($enforceCooldown) {
			$status = $this->moveStatus($userId);
			if ($status['can_move_at'] > time()) {
				throw new MoveErrorException(
					'this account moved on ' . gmdate('Y-m-d', (int)$status['moved_at'])
					. ' and can move again on ' . gmdate('Y-m-d', $status['can_move_at']),
					'move-cooldown'
				);
			}
		}
		$this->blueskyAfterMove->assertPossible($actor, $bluesky);
		$target = $this->resolveActor($targetId);
		if ($targetHost !== '' && strcasecmp((string)parse_url($target->getId(), PHP_URL_HOST), $targetHost) !== 0) {
			throw new MoveErrorException(
				$target->getId() . ' is not on ' . $targetHost . ', the server this move was granted to',
				'move-target-host'
			);
		}

		if ($target->getId() === $actor->getId()) {
			throw new MoveErrorException('an account cannot be moved onto itself', 'move-onto-self');
		}

		if (!in_array($actor->getId(), $target->getAlsoKnownAs(), true)) {
			throw new MoveErrorException(
				$target->getId() . ' does not list ' . $actor->getId() . ' in its alsoKnownAs:'
				. ' add the alias on the new account first, then try again',
				'alias-missing'
			);
		}

		$move = new Move();
		$move->setId($actor->getId() . '#moves/' . time());
		$move->setActorId($actor->getId());
		$move->setObjectId($actor->getId());
		$move->setTarget($target->getId());
		// A Move is also addressed to the followers in ActivityPub terms: some
		// servers validate the audience before applying it.
		$move->setTo($actor->getFollowers());

		$move->addInstancePath(
			new InstancePath($actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_MEDIUM)
		);
		if ($target->getInbox() !== '') {
			$move->addInstancePath(
				new InstancePath($target->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
			);
		}

		$this->signatureService->signObject($actor, $move);
		$this->activityService->request($move);

		// only once the Move is on its way: an actor marked moved whose
		// followers were never told is stuck
		$this->accountService->setMovedTo($userId, $target->getId());
		$this->config->setUserValue($userId, Application::APP_ID, self::MOVED_AT, (string)time());

		$this->refollowLocalFollowers($actor, $target);
		try {
			$this->blueskyAfterMove->apply($actor, $bluesky);
		} catch (Throwable $e) {
			$this->logger->warning('the Bluesky identity was not handled after the move', ['actor' => $actor->getId(), 'exception' => $e]);
		}

		return $target;
	}

	/**
	 * Takes the redirect off the account: `movedTo` is cleared and it may post
	 * and follow again. Followers do not come back, since a Move cannot be taken
	 * back, and the cooldown stays.
	 */
	public function undoMove(string $userId): void {
		$this->accountService->setMovedTo($userId, '');
	}

	/**
	 * Where the account stands: whether it moved, where, when, and when it may
	 * move (again).
	 *
	 * @return array{moved_to: string, moved_at: int|null, can_move_at: int}
	 */
	public function moveStatus(string $userId): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$movedAt = (int)$this->config->getUserValue($userId, Application::APP_ID, self::MOVED_AT, '0');

		return [
			'moved_to' => $actor->getMovedTo(),
			'moved_at' => ($movedAt > 0) ? $movedAt : null,
			'can_move_at' => ($movedAt > 0) ? $movedAt + self::COOLDOWN_SECONDS : 0,
		];
	}

	/**
	 * Re-creates the follows of an export — Mastodon's `following_accounts.csv`
	 * or Pixelfed's `pixelfed-following.json` — from this user's actor, one
	 * account at a time through the ordinary follow path. One that fails does
	 * not stop the rest.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{followed: int, skipped: int, failed: array<string, string>}
	 *                                                                           `failed` maps a handle to the reason
	 */
	public function importFollows(string $userId, string $csv, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['followed' => 0, 'skipped' => 0, 'failed' => []];

		[$handles, $result['skipped']] = self::capped(self::parseFollows($csv));
		$total = count($handles);
		$handled = 0;
		foreach ($handles as $handle) {
			if ($progress !== null) {
				$progress($handled++, $total);
			}
			if (strcasecmp($handle, $actor->getAccount()) === 0 || strcasecmp($handle, $actor->getId()) === 0) {
				$result['skipped']++;
				continue;
			}

			try {
				if (self::isActorUrl($handle)) {
					// Pixelfed's export names actors by URL rather than by
					// handle: fetch the actor by it, then follow what came back
					$this->followService->followActor(
						$actor, $this->cacheActorService->getFromId($handle, true)
					);
				} else {
					$this->followService->followAccount($actor, $handle);
				}
				$result['followed']++;
			} catch (FollowSameAccountException $e) {
				$result['skipped']++;
			} catch (Throwable $e) {
				$result['failed'][$handle] = $e->getMessage();
				$this->logger->notice('cannot import a follow', [
					'actor' => $actor->getId(), 'handle' => $handle, 'exception' => $e,
				]);
			}
		}

		if ($progress !== null) {
			$progress($handled, $total);
		}

		return $result;
	}

	/**
	 * Re-creates the blocks of a Mastodon `blocked_accounts.csv` (bare handles),
	 * applied here directly as the account's own decision. A block that cannot be
	 * applied is reported, never dropped silently.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{blocked: int, skipped: int, failed: array<string, string>}
	 *                                                                          `failed` maps a handle to the reason
	 */
	public function importBlocks(string $userId, string $csv, ?callable $progress = null): array {
		$result = $this->relate($userId, $csv, function (Person $actor, Person $target): void {
			$this->relationshipService->block($actor, $target);
		}, $progress);

		return ['blocked' => $result['done'], 'skipped' => $result['skipped'], 'failed' => $result['failed']];
	}

	/**
	 * Marks again the posts a Mastodon `bookmarks.csv` lists, one address a line,
	 * fetching the ones this server has never seen, since bookmarks are mostly
	 * remote posts. Nothing is sent to anybody. Each address is resolved as the
	 * importing account, so a post it may not read fails like a missing one.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{bookmarked: int, skipped: int, failed: array<string, string>}
	 *                                                                             `failed` maps an address to the reason
	 */
	public function importBookmarks(string $userId, string $csv, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$this->streamRequest->setViewer($actor);
		$result = ['bookmarked' => 0, 'skipped' => 0, 'failed' => []];

		[$urls, $result['skipped']] = self::addressesOf($csv);
		[$urls, $over] = self::capped($urls);
		$result['skipped'] += $over;
		$total = count($urls);
		$handled = 0;
		foreach ($urls as $url) {
			if ($progress !== null) {
				$progress($handled++, $total);
			}

			try {
				$post = $this->searchService->resolveStatus($url, true);
				if ($post === null) {
					$result['failed'][$url] = 'not a post this server could fetch or this account may read';
					continue;
				}
				$this->streamActionService->setActionBool($actor->getId(), $post->getId(), StreamAction::BOOKMARKED, true);
				$result['bookmarked']++;
			} catch (Throwable $e) {
				$result['failed'][$url] = $e->getMessage();
				$this->logger->notice('cannot import a bookmark', [
					'actor' => $actor->getId(), 'url' => $url, 'exception' => $e,
				]);
			}
		}

		if ($progress !== null) {
			$progress($handled, $total);
		}

		return $result;
	}

	/**
	 * Blocks the servers a Mastodon `blocked_domains.csv` lists, one domain a
	 * line, each applied as read; this instance itself is reported as failed, as
	 * the API would.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{blocked: int, skipped: int, failed: array<string, string>}
	 *                                                                          `failed` maps a domain to the reason
	 */
	public function importDomainBlocks(string $userId, string $csv, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['blocked' => 0, 'skipped' => 0, 'failed' => []];

		[$domains, $result['skipped']] = self::domainsOf($csv);
		[$domains, $over] = self::capped($domains);
		$result['skipped'] += $over;
		$total = count($domains);
		$handled = 0;
		foreach ($domains as $domain) {
			if ($progress !== null) {
				$progress($handled++, $total);
			}

			try {
				$this->domainBlockService->block($actor, $domain);
				$result['blocked']++;
			} catch (Throwable $e) {
				$result['failed'][$domain] = $e->getMessage();
			}
		}

		if ($progress !== null) {
			$progress($handled, $total);
		}

		return $result;
	}

	/**
	 * The post addresses in a bookmarks file, and how many lines were not one.
	 *
	 * @return array{0: string[], 1: int}
	 */
	private static function addressesOf(string $csv): array {
		$urls = [];
		$skipped = 0;
		foreach (self::linesOf($csv) as $line) {
			if (!str_starts_with($line, 'https://') && !str_starts_with($line, 'http://')) {
				$skipped++;
				continue;
			}
			$urls[$line] = true;
		}

		return [array_keys($urls), $skipped];
	}

	/**
	 * The domains in a blocked-domains file, lowercased and de-duplicated, and
	 * how many lines were not one. A header line is not one.
	 *
	 * @return array{0: string[], 1: int}
	 */
	private static function domainsOf(string $csv): array {
		$domains = [];
		$skipped = 0;
		foreach (self::linesOf($csv) as $line) {
			$domain = strtolower(ltrim($line, '#'));
			if (preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z0-9-]+$/', $domain) !== 1) {
				$skipped++;
				continue;
			}
			$domains[$domain] = true;
		}

		return [array_keys($domains), $skipped];
	}

	/**
	 * The first MAX_IMPORT_ENTRIES entries of an import, and how many more
	 * there were.
	 *
	 * @param string[] $entries
	 * @return array{0: string[], 1: int}
	 */
	private static function capped(array $entries): array {
		$kept = array_slice($entries, 0, self::MAX_IMPORT_ENTRIES);

		return [$kept, count($entries) - count($kept)];
	}

	/** @return string[] the non-empty lines, trimmed */
	private static function linesOf(string $csv): array {
		$lines = [];
		foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
			$line = trim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * The addresses of the posts this account bookmarked, newest first, as
	 * Mastodon's `bookmarks.csv` has them.
	 *
	 * @return string[]
	 */
	private function bookmarkUrls(Person $actor): array {
		$this->streamRequest->setViewer($actor);
		$urls = [];
		$maxId = 0;
		while (true) {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe(ProbeOptions::BOOKMARKS)
				->setAccountId($actor->getId())
				->setLimit(self::EXPORT_PAGE);
			if ($maxId > 0) {
				$options->setMaxId($maxId);
			}

			$posts = $this->streamRequest->getTimeline($options);
			if ($posts === []) {
				return $urls;
			}
			foreach ($posts as $post) {
				$urls[] = $post->getId();
			}

			$last = end($posts);
			$nid = ($last === false) ? 0 : $last->getNid();
			if ($nid <= 0 || ($maxId > 0 && $nid >= $maxId)) {
				// a row that cannot be paged on: stop rather than ask for the same page again
				return $urls;
			}
			$maxId = $nid;
		}
	}

	/**
	 * Re-creates the mutes of a Mastodon `muted_accounts.csv`, with its
	 * `Hide notifications` column.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{muted: int, skipped: int, failed: array<string, string>}
	 *                                                                        `failed` maps a handle to the reason
	 */
	public function importMutes(string $userId, string $csv, ?callable $progress = null): array {
		$hidden = self::parseMuteNotifications($csv);

		$result = $this->relate($userId, $csv, function (Person $actor, Person $target, string $handle) use ($hidden): void {
			// the column says whether notifications are *hidden*; the relation
			// stores whether they are shown, so it is read the other way round
			$this->relationshipService->mute($actor, $target, !($hidden[strtolower($handle)] ?? false));
		}, $progress);

		return ['muted' => $result['done'], 'skipped' => $result['skipped'], 'failed' => $result['failed']];
	}

	/**
	 * Re-creates the lists of a Mastodon `lists.csv`, one
	 * `list name,account address` per row.
	 *
	 * A list holds only followed accounts, as on Mastodon, so others are skipped,
	 * not followed: the Migration page imports follows first. A list with the
	 * same title is filled rather than duplicated; a list following a Nextcloud
	 * group is left alone.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{lists: int, added: int, skipped: int, failed: array<string, string>}
	 *                                                                                    `failed` maps `list/handle` to the reason
	 */
	public function importLists(string $userId, string $csv, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['lists' => 0, 'added' => 0, 'skipped' => 0, 'failed' => []];

		$existing = [];
		foreach ($this->listsRequest->getByActor($actor->getId()) as $list) {
			$existing[mb_strtolower($list->getTitle())] = $list;
		}

		$parsed = [];
		$room = self::MAX_IMPORT_ENTRIES;
		foreach (self::parseListsCsv($csv) as $title => $handles) {
			$kept = array_slice($handles, 0, max(0, $room));
			$result['skipped'] += count($handles) - count($kept);
			$room -= count($kept);
			if ($kept !== []) {
				$parsed[$title] = $kept;
			}
		}
		$total = array_sum(array_map('count', $parsed));
		$handled = 0;
		foreach ($parsed as $title => $handles) {
			$list = $existing[mb_strtolower($title)] ?? null;
			if ($list === null) {
				$list = new MastodonList();
				$list->setOwnerId($actor->getId())->setTitle($title);
				$list = $this->listsRequest->create($list);
				$existing[mb_strtolower($title)] = $list;
				$result['lists']++;
			} elseif ($list->getGroupId() !== '') {
				// a group list's members are the group's; adding to it would be
				// undone by the next reconcile
				$result['skipped'] += count($handles);
				continue;
			}

			foreach ($handles as $handle) {
				if ($progress !== null) {
					$progress($handled++, $total);
				}
				try {
					$target = $this->resolveEntry($handle);
					if ($target->getId() !== $actor->getId() && !$this->follows($actor, $target)) {
						$result['skipped']++;
						continue;
					}

					$this->listsRequest->addMember($list, $target->getId());
					$result['added']++;
				} catch (Throwable $e) {
					$result['failed'][$title . '/' . $handle] = $e->getMessage();
					$this->logger->notice('cannot put an account in an imported list', [
						'actor' => $actor->getId(), 'list' => $title, 'handle' => $handle, 'exception' => $e,
					]);
				}
			}
		}

		if ($progress !== null) {
			$progress($handled, $total);
		}

		return $result;
	}

	/**
	 * One of this account's lists of accounts as a CSV under Mastodon's file
	 * names, which other implementations' importers recognise.
	 *
	 * @return array{0: string, 1: string} the file name and its contents
	 * @throws MoveErrorException list-unknown when `$kind` is not one of CSV_KINDS
	 */
	public function exportCsv(string $userId, string $kind): array {
		$actor = $this->accountService->getActorFromUserId($userId);

		return match ($kind) {
			'following' => ['following_accounts.csv', self::exportFollowsCsv($this->handlesOfFollows(
				$actor,
				fn (string $cursor): array => $this->followsRequest->getFollowingByActorId(
					$actor->getId(), self::EXPORT_PAGE, 0, $cursor
				)
			))],
			'followers' => ['followers.csv', self::exportFollowsCsv($this->handlesOfFollows(
				$actor,
				fn (string $cursor): array => $this->followsRequest->getFollowersByActorId(
					$actor->getId(), self::EXPORT_PAGE, 0, $cursor
				)
			))],
			'blocks' => ['blocked_accounts.csv', self::csvOf(array_map(
				Csv::cell(...), $this->handlesOfRelations($actor, ActorRelation::TYPE_BLOCK)
			))],
			'mutes' => ['muted_accounts.csv', $this->exportMutesCsv($actor)],
			'lists' => ['lists.csv', $this->exportListsCsv($actor)],
			'bookmarks' => ['bookmarks.csv', self::csvOf($this->bookmarkUrls($actor))],
			'domain_blocks' => ['blocked_domains.csv', self::csvOf(
				$this->domainBlockService->getBlocked($actor, self::EXPORT_DOMAINS)
			)],
			default => throw new MoveErrorException(
				'"' . $kind . '" is not something this account keeps a list of', 'list-unknown'
			),
		};
	}

	/**
	 * The handles in a bare or Mastodon-shaped CSV, applied one at a time, for
	 * the block and mute imports. One failure does not stop the rest.
	 *
	 * @param callable(Person, Person, string): void $apply
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{done: int, skipped: int, failed: array<string, string>}
	 */
	private function relate(string $userId, string $csv, callable $apply, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['done' => 0, 'skipped' => 0, 'failed' => []];

		[$handles, $result['skipped']] = self::capped(self::parseFollows($csv));
		$total = count($handles);
		$handled = 0;
		foreach ($handles as $handle) {
			if ($progress !== null) {
				$progress($handled++, $total);
			}
			if (strcasecmp($handle, $actor->getAccount()) === 0 || strcasecmp($handle, $actor->getId()) === 0) {
				$result['skipped']++;
				continue;
			}

			try {
				$apply($actor, $this->resolveEntry($handle), $handle);
				$result['done']++;
			} catch (Throwable $e) {
				$result['failed'][$handle] = $e->getMessage();
				$this->logger->notice('cannot import a block or a mute', [
					'actor' => $actor->getId(), 'handle' => $handle, 'exception' => $e,
				]);
			}
		}

		if ($progress !== null) {
			$progress($handled, $total);
		}

		return $result;
	}

	/**
	 * The account an export entry names, by handle or actor URL; resolving may
	 * fetch the actor with a plain signed GET.
	 */
	private function resolveEntry(string $entry): Person {
		if (self::isActorUrl($entry)) {
			return $this->cacheActorService->getFromId($entry, true);
		}

		return $this->cacheActorService->getFromAccount($entry);
	}

	/**
	 * Whether the actor follows the target, a follow that is still waiting for
	 * an answer included — as `POST /api/v1/lists/{id}/accounts` counts it,
	 * so a locked account can be put in a list the moment it is asked for.
	 */
	private function follows(Person $actor, Person $target): bool {
		try {
			$this->followsRequest->getByPersons($actor->getId(), $target->getId());

			return true;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * `list name,account address` per row, which is what Mastodon writes and
	 * what it reads back. There is no header in the file it exports, so one is
	 * only skipped where it is there.
	 *
	 * @return array<string, string[]> the handles of each list, by title, in file order
	 */
	public static function parseListsCsv(string $csv): array {
		$lists = [];
		/** @var array<string, string> the spelling of each title the file used first */
		$titles = [];
		/** @var array<string, array<string, true>> the handles already in each list */
		$seen = [];

		foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $index => $line) {
			if (trim($line) === '') {
				continue;
			}

			$cells = str_getcsv($line, ',', '"', '');
			$title = ListsRequest::normaliseTitle(Csv::restore(trim((string)($cells[0] ?? ''))));
			$handle = ltrim(Csv::restore(trim((string)($cells[1] ?? ''))), '@');
			if ($index === 0 && strtolower($title) === 'list name') {
				continue;
			}

			if ($title === '' || preg_match('/^[^@\s]+@[^@\s]+$/', $handle) !== 1) {
				continue;
			}

			// a title that repeats with different capitalisation is one list,
			// kept under the spelling the file used first
			$key = mb_strtolower($title);
			$title = $titles[$key] ??= $title;
			$lists[$title] ??= [];
			$seen[$key] ??= [];

			$already = strtolower($handle);
			if (isset($seen[$key][$already])) {
				continue;
			}

			$seen[$key][$already] = true;
			$lists[$title][] = $handle;
		}

		return $lists;
	}

	/**
	 * Which handles of a `muted_accounts.csv` asked for their notifications to
	 * be hidden, by lower-cased handle.
	 *
	 * @return array<string, bool>
	 */
	public static function parseMuteNotifications(string $csv): array {
		$hidden = [];
		foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
			if (trim($line) === '') {
				continue;
			}

			$cells = str_getcsv($line, ',', '"', '');
			$handle = strtolower(ltrim(Csv::restore(trim((string)($cells[0] ?? ''))), '@'));
			$hidden[$handle] = strtolower(trim((string)($cells[1] ?? ''))) === 'true';
		}

		return $hidden;
	}

	/**
	 * The handles of a paged follow query, in order, leaving out accounts never
	 * cached (a bare URL is no handle) and the account's own loopback follow
	 * (`FollowsRequest::generateLoopbackAccount()`), which Mastodon's importer
	 * would try to follow.
	 *
	 * @param callable(string): Follow[] $page the page after a cursor, '' for the first
	 *
	 * @return string[]
	 */
	private function handlesOfFollows(Person $actor, callable $page): array {
		$handles = [];
		$cursor = '';

		while (count($handles) < self::EXPORT_MAX) {
			$follows = $page($cursor);
			if ($follows === []) {
				break;
			}

			foreach ($follows as $follow) {
				$account = $follow->hasActor() ? $follow->getActor()?->getAccount() ?? '' : '';
				if ($account !== '' && strcasecmp($account, $actor->getAccount()) !== 0) {
					$handles[] = $account;
				}
			}

			$cursor = FollowsRequest::cursorAfter(end($follows));
		}

		return array_slice($handles, 0, self::EXPORT_MAX);
	}

	/**
	 * The handles this actor blocks or mutes, read in one query
	 * (`getByActor()` takes no offset) and capped like the account export.
	 *
	 * @return string[]
	 */
	private function handlesOfRelations(Person $actor, string $type): array {
		$handles = [];
		foreach ($this->actorRelationRequest->getByActor($actor->getId(), $type, self::EXPORT_MAX) as $relation) {
			$handle = $this->handleOf($relation->getObjectId());
			if ($handle !== '') {
				$handles[] = $handle;
			}
		}

		return $handles;
	}

	private function exportMutesCsv(Person $actor): string {
		$lines = ['Account address,Hide notifications'];
		foreach ($this->actorRelationRequest->getByActor($actor->getId(), ActorRelation::TYPE_MUTE, self::EXPORT_MAX) as $mute) {
			$handle = $this->handleOf($mute->getObjectId());
			if ($handle !== '') {
				$lines[] = Csv::cell($handle) . ',' . ($mute->isNotifications() ? 'false' : 'true');
			}
		}

		return self::csvOf($lines);
	}

	/**
	 * `list name,account address` per row, with no header — the shape
	 * Mastodon's own `lists.csv` has, which is also what its importer expects.
	 */
	private function exportListsCsv(Person $actor): string {
		$lines = [];
		foreach ($this->listsRequest->getByActor($actor->getId()) as $list) {
			foreach ($this->listsRequest->getMemberIds($list) as $memberId) {
				$handle = $this->handleOf($memberId);
				if ($handle !== '') {
					$lines[] = Csv::cell($list->getTitle()) . ',' . Csv::cell($handle);
				}
			}
		}

		return self::csvOf($lines);
	}

	/** The handle of a cached actor, or '' where this server has never seen it. */
	private function handleOf(string $actorId): string {
		try {
			return $this->cacheActorService->getFromId($actorId)->getAccount();
		} catch (Throwable $e) {
			$this->logger->debug('cannot resolve an account for a CSV export, leaving it out', [
				'actorId' => $actorId, 'exception' => $e,
			]);

			return '';
		}
	}

	/**
	 * @param string[] $lines
	 */
	private static function csvOf(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}

	/**
	 * The accounts a follows export names: Mastodon's, GoToSocial's and
	 * Akkoma's `following_accounts.csv`, or Pixelfed's `pixelfed-following.json`
	 * of actor URLs. A file that parses as JSON is read as JSON, else as CSV.
	 *
	 * @return string[] `name@host` handles and actor URLs, deduplicated, in file order
	 */
	public static function parseFollows(string $file): array {
		$trimmed = trim($file);
		if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded)) {
				return self::parseFollowsJson($decoded);
			}
		}

		return self::parseFollowsCsv($file);
	}

	/**
	 * Whether a follows entry names an actor by URL rather than by handle.
	 */
	public static function isActorUrl(string $entry): bool {
		return preg_match('#^https?://[^\s/]+/\S+$#i', $entry) === 1;
	}

	/**
	 * The entries of a JSON follows export. Pixelfed writes a bare array of actor
	 * URLs; read generously beyond that: handles, objects naming the account
	 * under `url`, `acct`, `account` or `id`, and arrays under `following` or
	 * `orderedItems`.
	 *
	 * @param array<mixed> $decoded
	 * @return string[]
	 */
	private static function parseFollowsJson(array $decoded): array {
		foreach (['following', 'orderedItems', 'items', 'accounts'] as $key) {
			if (isset($decoded[$key]) && is_array($decoded[$key])) {
				$decoded = $decoded[$key];
				break;
			}
		}

		$entries = [];
		$seen = [];
		foreach ($decoded as $entry) {
			if (is_array($entry)) {
				$entry = $entry['url'] ?? $entry['acct'] ?? $entry['account'] ?? $entry['id'] ?? '';
			}
			if (!is_string($entry)) {
				continue;
			}

			$entry = ltrim(trim($entry), '@');
			if (self::isActorUrl($entry)) {
				$entry = rtrim($entry, '/');
			} elseif (preg_match('/^[^@\s]+@[^@\s]+$/', $entry) !== 1) {
				continue;
			}

			$key = strtolower($entry);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * The handles as a Mastodon `following_accounts.csv`, header and one row per
	 * handle, with a fresh follow's default columns, so Mastodon's "Import
	 * follows" and parseFollowsCsv() both read it.
	 *
	 * @param string[] $handles
	 */
	public static function exportFollowsCsv(array $handles): string {
		// written by hand: fputcsv() would quote the header's spaced fields
		$lines = ['Account address,Show boosts,Notify on new posts,Languages'];
		foreach ($handles as $handle) {
			$lines[] = Csv::cell($handle) . ',true,false,';
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * The handles in a Mastodon `following_accounts.csv`, with the header
	 * `Account address,Show boosts,Notify on new posts,Languages` or as a bare
	 * list. A leading `@` is dropped; anything not `user@host`, or repeated
	 * case-insensitively, is left out.
	 *
	 * @return string[]
	 */
	public static function parseFollowsCsv(string $csv): array {
		$lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
		$column = 0;

		$first = array_map('trim', str_getcsv((string)($lines[0] ?? ''), ',', '"', ''));
		$header = array_search(self::CSV_ADDRESS_COLUMN, array_map('strtolower', $first), true);
		if ($header !== false) {
			$column = $header;
			array_shift($lines);
		}

		$handles = [];
		$seen = [];
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			$cells = str_getcsv($line, ',', '"', '');
			$handle = ltrim(Csv::restore(trim((string)($cells[$column] ?? ''))), '@');
			// `name@host`, where the host may be a single label (`cloud` on a private
			// network); a handle that resolves to nothing is reported as failed
			if (preg_match('/^[^@\s]+@[^@\s]+$/', $handle) !== 1) {
				continue;
			}

			$key = strtolower($handle);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$handles[] = $handle;
		}

		return $handles;
	}

	/**
	 * The actor id an alias names: what a handle resolves to, or the id served
	 * at a URL, which may differ from it (Mastodon 4.5 serves `/users/<name>`
	 * with the id `/ap/users/<number>`, which its Move check looks for). A URL
	 * nobody answers is kept as typed.
	 *
	 * @throws MoveErrorException
	 */
	private function actorIdOrThrow(string $alias, Person $actor): string {
		$alias = trim($alias);
		if (!self::isActorUrl($alias)) {
			$alias = $this->resolveActor($alias)->getId();
		} else {
			try {
				$alias = $this->resolveActor($alias)->getId();
			} catch (Throwable $e) {
				$this->logger->debug('an alias given as a URL could not be fetched, kept as typed', [
					'alias' => $alias, 'exception' => $e,
				]);
			}
		}

		if ($alias === $actor->getId()) {
			throw new MoveErrorException('an account is not an alias of itself', 'alias-self');
		}

		return $alias;
	}

	/**
	 * The account a person named, fetched fresh from its server: by its
	 * `@user@host` handle through WebFinger, or by its actor id.
	 *
	 * @throws MoveErrorException account-address-invalid when it is neither,
	 *                            account-not-found or account-not-verifiable when nobody answers
	 */
	public function resolveActor(string $reference): Person {
		$reference = trim($reference);
		if (self::isActorUrl($reference)) {
			try {
				return $this->cacheActorService->getFromId($reference, true);
			} catch (Throwable $e) {
				throw self::nobodyAnswered($reference, (string)parse_url($reference, PHP_URL_HOST), $e);
			}
		}

		$handle = ltrim($reference, '@');
		if (preg_match('/^[^@\s\/]+@[^@\s\/]+\.[^@\s\/]+$/', $handle) !== 1) {
			throw new MoveErrorException(
				'"' . $reference . '" is neither a handle like @you@old.example'
				. ' nor the https:// address of an account',
				'account-address-invalid'
			);
		}

		try {
			return $this->cacheActorService->getFromAccount($handle);
		} catch (Throwable $e) {
			throw self::nobodyAnswered($handle, substr($handle, (int)strrpos($handle, '@') + 1), $e);
		}
	}

	/**
	 * What to tell the person when a handle or address resolved to nobody. A 401
	 * or 403 means the server could not verify this one's signed fetch (usually
	 * this Nextcloud is not reachable over https), and is said as such rather
	 * than as a wrong handle.
	 */
	private static function nobodyAnswered(string $name, string $host, Throwable $e): MoveErrorException {
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof RequestContentException
				&& in_array($cause->getCode(), [Http::STATUS_UNAUTHORIZED, Http::STATUS_FORBIDDEN], true)) {
				return new MoveErrorException(
					$host . ' only answers servers it can verify, and it could not verify this one:'
					. ' this Nextcloud has to be reachable from the internet over https before '
					. $name . ' can be read from here',
					'account-not-verifiable',
					$e
				);
			}
		}

		return new MoveErrorException('no account answers to ' . $name . ': ' . $e->getMessage(), 'account-not-found', $e);
	}

	/**
	 * Follows the new account on behalf of everyone here who followed the old
	 * one, since a delivery to ourselves is dropped and they never receive the
	 * Move. Paged after the last follow of each page, so memory stays bounded
	 * and every follower is read once.
	 */
	private function refollowLocalFollowers(Person $actor, Person $target): void {
		$done = 0;
		$cursor = '';

		while ($done < self::REFOLLOW_MAX) {
			$page = $this->followsRequest->getFollowersByActorId(
				$actor->getId(), self::REFOLLOW_PAGE, 0, $cursor
			);
			if ($page === []) {
				return;
			}

			$this->refollowPage($page, $target);
			$done += count($page);
			$cursor = FollowsRequest::cursorAfter(end($page));

			if (count($page) < self::REFOLLOW_PAGE) {
				return;
			}
		}

		$this->logger->warning(
			'stopped re-following the followers of a moved account at the ceiling of '
			. self::REFOLLOW_MAX . '; the rest keep following the old account',
			['actor' => $actor->getId(), 'target' => $target->getId()]
		);
	}

	/**
	 * @param Follow[] $page
	 */
	private function refollowPage(array $page, Person $target): void {
		foreach ($page as $follow) {
			try {
				// only a local account has a row here
				$follower = $this->actorsRequest->getFromId($follow->getActorId());
			} catch (Exception $e) {
				continue;
			}

			try {
				$this->followService->followAccount($follower, $target->getAccount());
			} catch (Throwable $e) {
				// one unreachable target must not stop the others
				$this->logger->warning('cannot re-follow a moved account', [
					'follower' => $follower->getId(),
					'target' => $target->getId(),
					'exception' => $e,
				]);
			}
		}
	}
}
