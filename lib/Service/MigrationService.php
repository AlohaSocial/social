<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a local account away, and bringing one here.
 *
 * Outbound: the account declares where it went (`movedTo`), tells every
 * follower with a `Move`, and the followers' servers re-follow the new
 * account. The new account has to list this one in its `alsoKnownAs` first —
 * that back-reference is the only thing that stops a Move from re-pointing
 * somebody else's followers at an account the sender controls, and
 * MoveInterface refuses an incoming Move without it just the same.
 *
 * Inbound: the alias is set here (`alsoKnownAs`), the Move is started on the
 * old server, and the follows the old server exported as CSV are re-created
 * from here through the ordinary follow path.
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
	 * How long after a move the account may move again: Mastodon's thirty
	 * days. A move tells every server that knows the account to re-point its
	 * followers; two of them in an afternoon is a mistake being made twice.
	 */
	public const COOLDOWN_SECONDS = 30 * 24 * 3600;
	/** The user setting that records when the account last moved. */
	private const MOVED_AT = 'moved_at';

	/**
	 * The most rows one CSV export carries.
	 *
	 * A cap rather than a promise of everything: the whole file is built in
	 * memory and handed to a browser, and it is also what makes the paging
	 * terminate when a query ignores its offset.
	 */
	private const EXPORT_MAX = 5000;

	/**
	 * The most followers one Move will re-follow.
	 *
	 * Also what makes the paging terminate no matter what the query does: a
	 * source that ignored the offset would hand back a full page forever, and
	 * the loop below cannot tell that from a very popular account.
	 */
	private const REFOLLOW_MAX = 20000;

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
	 * @throws InvalidResourceException when `$alias` is not an actor id
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
	 * Moves this user's actor to `$targetId`.
	 *
	 * The target is fetched fresh — the `alsoKnownAs` that counts is the one
	 * its server publishes right now — and has to list our actor. Then a
	 * `Move{object: ours, target: theirs}` is queued for every follower's
	 * inbox and the new home, `movedTo` is recorded (which puts it on the actor
	 * document and as `moved` on the account entity), and the followers on
	 * this instance, who never receive the Move, are re-followed on their
	 * behalf.
	 *
	 * @param bool $enforceCooldown whether a move within COOLDOWN_SECONDS of the
	 *                              last is refused; the person's own button says
	 *                              yes, an administrator's command may say no
	 *
	 * @return Person the target as fetched
	 * @throws InvalidResourceException when the target does not list the actor, or is the actor,
	 *                                  or the account moved too recently
	 */
	public function move(string $userId, string $targetId, bool $enforceCooldown = true): Person {
		$actor = $this->accountService->getActorFromUserId($userId);
		if ($enforceCooldown) {
			$status = $this->moveStatus($userId);
			if ($status['can_move_at'] > time()) {
				throw new InvalidResourceException(
					'this account moved on ' . gmdate('Y-m-d', (int)$status['moved_at'])
					. ' and can move again on ' . gmdate('Y-m-d', $status['can_move_at'])
				);
			}
		}
		$target = $this->resolveActor($targetId);

		if ($target->getId() === $actor->getId()) {
			throw new InvalidResourceException('an account cannot be moved onto itself');
		}

		if (!in_array($actor->getId(), $target->getAlsoKnownAs(), true)) {
			throw new InvalidResourceException(
				$target->getId() . ' does not list ' . $actor->getId() . ' in its alsoKnownAs:'
				. ' add the alias on the new account first, then try again'
			);
		}

		$move = new Move();
		$move->setId($actor->getId() . '#moves/' . time());
		$move->setActorId($actor->getId());
		$move->setObjectId($actor->getId());
		$move->setTarget($target->getId());

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

		return $target;
	}

	/**
	 * Takes the redirect off the account: `movedTo` is cleared, so the actor
	 * document and the account entity stop saying it moved and it may post
	 * and follow again.
	 *
	 * The followers do not come back by themselves: their servers acted on
	 * the Move when it arrived, and nothing in ActivityPub takes a Move back.
	 * The cooldown stays, because the move did happen.
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

		$handles = self::parseFollows($csv);
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
	 * Re-creates the blocks of an export — Mastodon's `blocked_accounts.csv`,
	 * which is a bare list of handles.
	 *
	 * A block is not a relationship two servers agree on the way a follow is:
	 * it is this account's own decision, so it is applied here directly. One
	 * that cannot be applied — an account that does not resolve — is reported
	 * rather than dropped, because a block that silently did not happen is the
	 * failure that matters in this file.
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
	 * Marks again the posts a `bookmarks.csv` lists — Mastodon's, one post
	 * address a line — fetching the ones this server has never seen.
	 *
	 * Fetching is the point here, where the account export's restore
	 * deliberately skips what is not here: a person's bookmarks are mostly
	 * other people's posts on other servers, and a mark on a post this
	 * server does not hold is no mark at all. A bookmark is a local flag;
	 * nothing is sent to anybody. A line that is not an address is skipped.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{bookmarked: int, skipped: int, failed: array<string, string>}
	 *                                                                             `failed` maps an address to the reason
	 */
	public function importBookmarks(string $userId, string $csv, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['bookmarked' => 0, 'skipped' => 0, 'failed' => []];

		[$urls, $result['skipped']] = self::addressesOf($csv);
		$total = count($urls);
		$handled = 0;
		foreach ($urls as $url) {
			if ($progress !== null) {
				$progress($handled++, $total);
			}

			try {
				$post = $this->searchService->resolveStatus($url);
				if ($post === null) {
					$result['failed'][$url] = 'not a post this server could fetch';
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
	 * Blocks the servers a `blocked_domains.csv` lists — Mastodon's, one
	 * domain a line. A domain block is this account's own decision and
	 * federates nothing, so each one applies the moment it is read; the
	 * instance itself cannot be blocked and is reported as failed, as the
	 * API would.
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{blocked: int, skipped: int, failed: array<string, string>}
	 *                                                                          `failed` maps a domain to the reason
	 */
	public function importDomainBlocks(string $userId, string $csv, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['blocked' => 0, 'skipped' => 0, 'failed' => []];

		[$domains, $result['skipped']] = self::domainsOf($csv);
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
	 * The addresses of the posts this account bookmarked, newest first — the
	 * shape Mastodon's `bookmarks.csv` has, and the only one that means
	 * anything off this server.
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
	 * Re-creates the mutes of an export — Mastodon's `muted_accounts.csv`,
	 * which carries `Hide notifications` beside each handle and is the one
	 * thing a mute stores besides its target.
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
	 * Re-creates the lists of an export — Mastodon's `lists.csv`, one
	 * `list name,account address` per row.
	 *
	 * A list here can only hold accounts this one follows, which is Mastodon's
	 * rule as well, so an account that is not followed yet is **skipped** and
	 * not followed on the quiet: this button says it imports lists. Import the
	 * follows first and run this after — the order the Migration page asks for
	 * them in, and what the skipped count is telling you when it is not zero.
	 *
	 * A list whose title is already there is filled rather than duplicated, so
	 * importing the same file twice changes nothing the second time. A list
	 * that follows a Nextcloud group is left alone: its members are the
	 * group's.
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

		$parsed = self::parseListsCsv($csv);
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
	 * One of this account's lists of accounts, as the CSV the network it came
	 * from would have written — so it can be carried on to the next one.
	 *
	 * The names are Mastodon's, because a file called `following_accounts.csv`
	 * is one every other implementation's importer already recognises, and the
	 * point of writing it is that it is read somewhere else.
	 *
	 * @return array{0: string, 1: string} the file name and its contents
	 * @throws InvalidResourceException when `$kind` is not one of CSV_KINDS
	 */
	public function exportCsv(string $userId, string $kind): array {
		$actor = $this->accountService->getActorFromUserId($userId);

		return match ($kind) {
			'following' => ['following_accounts.csv', self::exportFollowsCsv($this->handlesOfFollows(
				$actor,
				fn (int $offset): array => $this->followsRequest->getFollowingByActorId(
					$actor->getId(), self::EXPORT_PAGE, $offset
				)
			))],
			'followers' => ['followers.csv', self::exportFollowsCsv($this->handlesOfFollows(
				$actor,
				fn (int $offset): array => $this->followsRequest->getFollowersByActorId(
					$actor->getId(), self::EXPORT_PAGE, $offset
				)
			))],
			'blocks' => ['blocked_accounts.csv', self::csvOf($this->handlesOfRelations($actor, ActorRelation::TYPE_BLOCK))],
			'mutes' => ['muted_accounts.csv', $this->exportMutesCsv($actor)],
			'lists' => ['lists.csv', $this->exportListsCsv($actor)],
			'bookmarks' => ['bookmarks.csv', self::csvOf($this->bookmarkUrls($actor))],
			'domain_blocks' => ['blocked_domains.csv', self::csvOf(
				$this->domainBlockService->getBlocked($actor, self::EXPORT_DOMAINS)
			)],
			default => throw new InvalidResourceException(
				'"' . $kind . '" is not something this account keeps a list of'
			),
		};
	}

	/**
	 * The handles in a bare or Mastodon-shaped CSV, applied one at a time.
	 *
	 * Shared by the block and mute imports, which differ only in what they do
	 * with each account. One that fails does not stop the rest — an export is
	 * a file whose author cannot fix it, and half of a block list is better
	 * than none of it.
	 *
	 * @param callable(Person, Person, string): void $apply
	 *
	 * @param callable(int, int): void|null $progress told how many entries are done, of how many
	 * @return array{done: int, skipped: int, failed: array<string, string>}
	 */
	private function relate(string $userId, string $csv, callable $apply, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['done' => 0, 'skipped' => 0, 'failed' => []];

		$handles = self::parseFollows($csv);
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
	 * The account an export entry names, by handle or by actor URL.
	 *
	 * Resolving may fetch the actor — a plain signed GET, which tells that
	 * server nothing a follow would not have told it — because without an
	 * actor id there is no row to write.
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
			$title = ListsRequest::normaliseTitle((string)($cells[0] ?? ''));
			$handle = ltrim(trim((string)($cells[1] ?? '')), '@');
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
			$handle = strtolower(ltrim(trim((string)($cells[0] ?? '')), '@'));
			$hidden[$handle] = strtolower(trim((string)($cells[1] ?? ''))) === 'true';
		}

		return $hidden;
	}

	/**
	 * The handles of a paged follow query, in order, without the ones whose
	 * account this server never cached: a bare actor URL is not a handle, and
	 * writing one into the address column produces a row every reader of the
	 * format skips anyway.
	 *
	 * The account's own handle is left out too. Every local actor holds a
	 * loopback follow of itself (`FollowsRequest::generateLoopbackAccount()`),
	 * so both lists name the exporter — and a `following_accounts.csv` naming
	 * you is a row Mastodon's importer tries to follow you with.
	 *
	 * @param callable(int): Follow[] $page
	 *
	 * @return string[]
	 */
	private function handlesOfFollows(Person $actor, callable $page): array {
		$handles = [];
		$offset = 0;

		while (count($handles) < self::EXPORT_MAX) {
			$follows = $page($offset);
			if ($follows === []) {
				break;
			}

			foreach ($follows as $follow) {
				$account = $follow->hasActor() ? $follow->getActor()?->getAccount() ?? '' : '';
				if ($account !== '' && strcasecmp($account, $actor->getAccount()) !== 0) {
					$handles[] = $account;
				}
			}

			$offset += count($follows);
		}

		return array_slice($handles, 0, self::EXPORT_MAX);
	}

	/**
	 * The handles this actor blocks or mutes.
	 *
	 * Read in one query — `getByActor()` takes no offset — so it is capped,
	 * the same cap the account export uses.
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
				$lines[] = self::csvCell($handle) . ',' . ($mute->isNotifications() ? 'false' : 'true');
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
					$lines[] = self::csvCell($list->getTitle()) . ',' . self::csvCell($handle);
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
	 * The accounts a follows export names, whichever network wrote it.
	 *
	 * Mastodon, GoToSocial and Akkoma hand out `following_accounts.csv`;
	 * Pixelfed hands out `pixelfed-following.json`, a JSON array of actor
	 * URLs — and the Migration page told Pixelfed users to fetch a CSV that
	 * Pixelfed does not offer, then read their JSON as a one-line CSV with no
	 * handle in it, so the import quietly followed nobody. A file is read as
	 * JSON when it parses as JSON, and as CSV otherwise.
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
	 * The entries of a JSON follows export.
	 *
	 * Pixelfed writes a bare array of actor URLs. Read generously beyond that,
	 * because an export is the one file its author cannot fix: an entry may be
	 * a handle instead of a URL, an object naming the account under `url`,
	 * `acct`, `account` or `id`, and the array may sit under `following` or
	 * `orderedItems` rather than at the root.
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
	 * The handles as a Mastodon `following_accounts.csv`: the header Mastodon
	 * writes, then one row per handle.
	 *
	 * The column values are what a fresh Mastodon follow carries — boosts
	 * shown, no notification, no language filter — because neither this app nor
	 * the file format it is borrowing stores them per follow. What matters is
	 * the shape: the file an export produces has to be one that Mastodon's own
	 * "Import follows" accepts, and one that parseFollowsCsv() reads back.
	 *
	 * @param string[] $handles
	 */
	public static function exportFollowsCsv(array $handles): string {
		// written by hand rather than with fputcsv(), which quotes a field
		// containing a space and would put the header out in a shape no other
		// implementation writes
		$lines = ['Account address,Show boosts,Notify on new posts,Languages'];
		foreach ($handles as $handle) {
			$lines[] = self::csvCell($handle) . ',true,false,';
		}

		return implode("\n", $lines) . "\n";
	}

	/** A value as one CSV cell: quoted only where it has to be. */
	private static function csvCell(string $value): string {
		if (strpbrk($value, ",\"\r\n") === false) {
			return $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}

	/**
	 * The handles in a Mastodon `following_accounts.csv`.
	 *
	 * Current exports carry the header `Account address,Show boosts,Notify on
	 * new posts,Languages`; older ones are a bare list of handles. Either way
	 * a leading `@` is dropped, and a handle that is not `user@host` or that
	 * repeats (case-insensitively) is left out.
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
			$handle = ltrim(trim((string)($cells[$column] ?? '')), '@');
			// `name@host`, with the host allowed to be a single label: a
			// fediverse host is usually dotted, but an instance reached as
			// `cloud` or `devel` on a private network is not, and insisting on
			// a dot silently dropped every handle on such a server — including
			// the ones in this app's own export. A handle that resolves to
			// nothing is reported as failed, which says more than skipping it.
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
	 * The actor id an alias names: the id itself, or the one a handle resolves
	 * to. A handle is what every other server's form asks for, so it is what
	 * people type; the id is what the wire carries.
	 *
	 * @throws InvalidResourceException
	 */
	private function actorIdOrThrow(string $alias, Person $actor): string {
		$alias = trim($alias);
		if (!self::isActorUrl($alias)) {
			$alias = $this->resolveActor($alias)->getId();
		}

		if ($alias === $actor->getId()) {
			throw new InvalidResourceException('an account is not an alias of itself');
		}

		return $alias;
	}

	/**
	 * The account a person named, fetched fresh from its server: by its
	 * `@user@host` handle through WebFinger, or by its actor id.
	 *
	 * @throws InvalidResourceException when it is neither, or nobody answers
	 */
	public function resolveActor(string $reference): Person {
		$reference = trim($reference);
		if (self::isActorUrl($reference)) {
			return $this->cacheActorService->getFromId($reference, true);
		}

		$handle = ltrim($reference, '@');
		if (preg_match('/^[^@\s\/]+@[^@\s\/]+\.[^@\s\/]+$/', $handle) !== 1) {
			throw new InvalidResourceException(
				'"' . $reference . '" is neither a handle like @you@old.example'
				. ' nor the https:// address of an account'
			);
		}

		try {
			return $this->cacheActorService->getFromAccount($handle);
		} catch (Throwable $e) {
			throw new InvalidResourceException(self::whyNobodyAnswered($handle, $e), 0, $e);
		}
	}

	/**
	 * What to tell the person when a handle resolved to nobody.
	 *
	 * A 401 or 403 from the actor fetch is not "nobody there": that server
	 * answers only requests it can verify — Mastodon's AUTHORIZED_FETCH,
	 * GoToSocial always — and could not verify this one, which is what
	 * happens when this Nextcloud cannot be reached from the internet over
	 * https. Said as such, because "no account answers" sends the person off
	 * to check a handle that was right.
	 */
	private static function whyNobodyAnswered(string $handle, Throwable $e): string {
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof RequestContentException
				&& in_array($cause->getCode(), [Http::STATUS_UNAUTHORIZED, Http::STATUS_FORBIDDEN], true)) {
				$host = substr($handle, (int)strrpos($handle, '@') + 1);

				return $host . ' only answers servers it can verify, and it could not verify this one:'
					. ' this Nextcloud has to be reachable from the internet over https before '
					. $handle . ' can be read from here';
			}
		}

		return 'no account answers to ' . $handle . ': ' . $e->getMessage();
	}

	/**
	 * Follows the new account on behalf of everyone on this instance who
	 * followed the old one. They never receive the Move — a delivery to
	 * ourselves is dropped — so nothing else would do it for them.
	 *
	 * Paged. getFollowersByActorId() takes a limit and this used to call it
	 * without one, so a popular account's entire follower set was loaded into
	 * memory before the first re-follow, and each row then costs a lookup and an
	 * outbound follow. The page size bounds the memory; the work itself is still
	 * one account's followers, which is what a Move is.
	 */
	private function refollowLocalFollowers(Person $actor, Person $target): void {
		$offset = 0;

		while ($offset < self::REFOLLOW_MAX) {
			$page = $this->followsRequest->getFollowersByActorId(
				$actor->getId(), self::REFOLLOW_PAGE, $offset
			);
			if ($page === []) {
				return;
			}

			$this->refollowPage($page, $target);
			$offset += count($page);

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
