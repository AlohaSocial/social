<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Chat\ChatPoller;
use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Conversation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Tools\Nid;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Mastodon's conversations, out of a direct timeline that has none.
 *
 * Mastodon stores a conversation: a row per thread, a row per participant, and
 * an id that exists before anybody asks for it. This app stores messages, and
 * the only thing the messages of one exchange have in common is the chain of
 * `in_reply_to` they hang off. A conversation here is therefore *derived*: the
 * thread root — the message with no parent, or the topmost parent this
 * instance stores — is the conversation, and its nid is the conversation's id.
 *
 * That choice is what makes `POST /api/v1/conversations/{id}/read` possible at
 * all. The id has to survive the round trip: a client reads the list, the user
 * taps a row minutes later, and the id they send back has to name the same
 * thread. The root's nid does — it is a stored, immutable, already-unique id,
 * and every message of the thread walks up to the same one. An id derived from
 * the page instead (a position, a hash of the participants, the newest
 * message) would name something else as soon as a message arrived.
 *
 * What it costs:
 *
 *  - Grouping is work at read time. A page of conversations is one direct
 *    timeline query plus one query per level of `in_reply_to` that the page's
 *    messages have above them — bounded, and a direct message is rarely deep.
 *  - A thread whose root is not stored here (the exchange started on another
 *    server and this instance was brought in later) groups under the topmost
 *    message it *does* store. If the missing parent arrives afterwards, the
 *    conversation's id changes. Mastodon has the same hole in a different
 *    place: it threads on the `conversation`/`context` the sender sent, and an
 *    exchange whose sender omits it splits there too.
 *  - Two pages of one list can show the same conversation twice: paging is by
 *    message, so a thread with messages on both sides of the cursor is built
 *    twice, once with an older last message. A client keys on `id`, so it
 *    updates the row rather than growing a second one. Storing conversations
 *    would fix it, and would mean a table written on every incoming direct
 *    message and a backfill of every message already here.
 *
 * Read and dismissed state does have a row — see ConversationsRequest — since
 * neither can be derived from the messages.
 */
class ConversationService {
	/**
	 * How many messages one page of conversations is built from.
	 *
	 * `ProbeOptions::MAX_LIMIT`, because that is the most one timeline query
	 * may ask for: a page of 20 conversations is not 20 messages, and reading
	 * the widest window the timeline allows is what keeps a busy thread from
	 * filling the page on its own.
	 */
	public const WINDOW = ProbeOptions::MAX_LIMIT;

	/** What Mastodon defaults and caps a page of conversations at. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	/** The most the unread badge counts to; past it the answer is "lots". */
	public const UNREAD_CAP = 99;

	/**
	 * How many participants one conversation reports. A direct message can be
	 * addressed to a crowd, and `accounts` is a header line in every client
	 * that draws it.
	 */
	private const MAX_ACCOUNTS = 20;

	public function __construct(
		private StreamService $streamService,
		private CacheActorService $cacheActorService,
		private ConversationsRequest $conversationsRequest,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * One page of the viewer's conversations, newest message first, with the
	 * cursors the `Link` header is built from.
	 *
	 * `next` is the nid below which nothing in this page has been considered:
	 * the last conversation's newest message when the page filled up — every
	 * conversation left out of it is older than that one — and otherwise the
	 * oldest message the window reached. Both are messages, not conversations,
	 * which is what `max_id` means everywhere else in this API.
	 *
	 * There is no `next` once the window came back short: that is the end of
	 * the direct timeline, and a page of no conversations at that point is the
	 * end of the list rather than a gap to page past.
	 *
	 * @return array{conversations: Conversation[], next: int|string, prev: int|string}
	 */
	public function getPage(Person $viewer, int $limit, int|string $maxId = '0', int|string $minId = 0, int|string $sinceId = '0'): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		// somebody reading their messages: the ones on Bluesky are read next
		// run, not when the backoff says; resolved here, as the Bluesky side
		// needs services that need this one
		try {
			$this->container?->get(ChatPoller::class)->wakeActor($viewer);
		} catch (Throwable) {
		}
		$messages = $this->directMessages($viewer, $maxId, $minId, $sinceId);
		if ($messages === []) {
			return ['conversations' => [], 'next' => 0, 'prev' => 0];
		}

		$conversations = $this->entities($viewer, $this->group($messages));

		$filled = (count($conversations) > $limit);
		$conversations = array_slice($conversations, 0, $limit);

		$nids = [];
		foreach ($messages as $message) {
			$nids[] = $message->getNid();
		}

		$last = end($conversations);
		if ($filled && $last !== false) {
			$next = $last->getLastStatusNid();
		} else {
			// a window that came back full may have older messages behind it,
			// including ones belonging to conversations nothing here has seen
			$next = (count($messages) < self::WINDOW) ? 0 : $this->oldestOf($nids);
		}

		return [
			'conversations' => $conversations,
			'next' => $next,
			'prev' => $this->newestNidOf($nids),
		];
	}

	/**
	 * How many conversations have something in them the account has not read.
	 *
	 * What the sidebar badge shows. It walks the same window, the same
	 * grouping and the same `isUnread()` rule the list itself uses, rather
	 * than counting messages with a query of its own -- a badge saying two
	 * beside a list showing one is worse than no badge, and the only way to be
	 * sure the two agree is for them to decide it in the same place.
	 *
	 * Capped, because the number is drawn in a circle a few millimetres wide:
	 * past the cap the reader is told "lots", which is all a badge can say.
	 * Dismissed conversations are left out, as they are from the list.
	 *
	 * @param int $cap the most it will count to
	 */
	public function countUnread(Person $viewer, int $cap = self::UNREAD_CAP): int {
		$messages = $this->directMessages($viewer, 0, 0, 0);
		if ($messages === []) {
			return 0;
		}

		$count = 0;
		foreach ($this->entities($viewer, $this->group($messages)) as $conversation) {
			if ($conversation->isUnread()) {
				$count++;
				if ($count >= $cap) {
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Marks every unread conversation read, and says how many that was.
	 *
	 * Walks the same window the list draws from, so "every conversation" means
	 * the same set in both places. Only the unread ones are written: moving a
	 * marker that is already where it belongs is a write for nothing, and this
	 * runs every time somebody looks at the page.
	 */
	public function markAllRead(Person $viewer): int {
		$messages = $this->directMessages($viewer, 0, 0, 0);
		if ($messages === []) {
			return 0;
		}

		$marked = 0;
		$ids = [];
		foreach ($this->entities($viewer, $this->group($messages)) as $conversation) {
			if (!$conversation->isUnread()) {
				continue;
			}

			$this->conversationsRequest->markRead(
				$viewer->getId(), $conversation->getRootId(), $conversation->getLastStatusNid()
			);
			$marked++;
			$ids[] = $conversation->getRootId();
			$ids[] = $conversation->getLastStatus()?->getId() ?? '';
		}
		$this->chatState()?->read($viewer, $ids, true);

		return $marked;
	}

	/**
	 * Marks the conversation read up to its newest message, and answers with
	 * it as the client will now draw it.
	 *
	 * @throws ItemNotFoundException the id names no conversation of the
	 *                               viewer's — which is the same answer as no
	 *                               conversation at all
	 */
	public function markRead(Person $viewer, int|string $id): Conversation {
		[$root, $thread] = $this->threadOf($viewer, $id);

		$newest = $this->newestOf($thread);
		$this->conversationsRequest->markRead(
			$viewer->getId(), $root['id'], Nid::fromStorage($newest)
		);
		$this->chatState()?->read($viewer, [$root['id'], $this->newestIdOf($thread)]);

		return $this->single($viewer, $root, $newest, false);
	}

	/**
	 * Marks the conversation a message is in read up to that message, as the
	 * account read it somewhere else; never back to an older one.
	 *
	 * @return bool whether the message is in one of the viewer's conversations
	 */
	public function markReadUpTo(Person $viewer, string $messageId): bool {
		$this->readAs($viewer);
		try {
			$message = $this->streamService->getStreamById($messageId);
			[$root] = $this->threadOf($viewer, $message->getNid());
		} catch (Throwable) {
			return false;
		}
		$this->conversationsRequest->markRead($viewer->getId(), $root['id'], Nid::fromStorage($message->getNid()));

		return true;
	}

	/**
	 * Dismisses the conversation.
	 *
	 * Nothing is deleted: Mastodon drops the conversation row and leaves the
	 * statuses, and a later message in the same thread brings the conversation
	 * back. Here the dismissal is recorded as the newest message it covered,
	 * so a later message — which has a higher nid — is outside it and the
	 * conversation returns on its own.
	 *
	 * @throws ItemNotFoundException
	 */
	public function remove(Person $viewer, int|string $id): void {
		[$root, $thread] = $this->threadOf($viewer, $id);

		$this->conversationsRequest->markHidden(
			$viewer->getId(), $root['id'], Nid::fromStorage($this->newestOf($thread))
		);
		// a request on Bluesky is turned down there, not accepted
		$this->chatState()?->dismissed($viewer, [$root['id'], $this->newestIdOf($thread)]);
	}

	/**
	 * Where a conversation's state goes to Bluesky; resolved when needed, as
	 * the Bluesky side needs services that need this one.
	 */
	private function chatState(): ?ChatState {
		try {
			$state = $this->container?->get(ChatState::class);
		} catch (Throwable) {
			return null;
		}

		return $state instanceof ChatState ? $state : null;
	}

	/**
	 * The window of direct messages a page is built from.
	 *
	 * @return Stream[] newest first
	 */
	private function directMessages(Person $viewer, int|string $maxId, int|string $minId, int|string $sinceId): array {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL);
		$options->setProbe(ProbeOptions::DIRECT)
			->setLimit(self::WINDOW)
			->setMaxId($maxId)
			->setMinId($minId)
			->setSince($sinceId);

		$this->readAs($viewer);

		return $this->streamService->getTimeline($options);
	}

	/**
	 * Says who is reading before anything is read.
	 *
	 * Every lookup this service makes goes through `StreamService`, and a
	 * stream read with no viewer set is read as an anonymous caller: only
	 * posts addressed to the public collection come back. A direct message
	 * names no such collection, so a conversation could never be looked up at
	 * all — `markRead()` and `remove()` answered 404 for every real
	 * conversation while `getPage()`, which happened to say who was reading,
	 * listed them.
	 */
	private function readAs(Person $viewer): void {
		$this->streamService->setViewer($viewer);
	}

	/**
	 * The window's messages grouped under the thread root each belongs to, in
	 * the order the roots were first seen — which, the window being ordered
	 * newest first, is the order of their newest message.
	 *
	 * @param Stream[] $messages
	 *
	 * @return array<string, Stream[]>
	 */
	private function group(array $messages): array {
		$links = [];
		foreach ($messages as $message) {
			$links[$message->getId()] = [
				'nid' => $message->getNid(),
				'inReplyTo' => $message->getInReplyTo(),
			];
		}

		$links = $this->withAncestors($links);

		$groups = [];
		foreach ($messages as $message) {
			$groups[$this->rootOf($message->getId(), $links)][] = $message;
		}

		return $groups;
	}

	/**
	 * The same map with every ancestor of its posts added to it.
	 *
	 * One query a level rather than one a post: every parent still missing is
	 * asked for at once, and the walk ends when a level adds nothing — which
	 * is what a thread that starts here, or a parent this instance never
	 * stored, both look like.
	 *
	 * @param array<string, array{nid: string, inReplyTo: string}> $links
	 *
	 * @return array<string, array{nid: string, inReplyTo: string}>
	 */
	private function withAncestors(array $links): array {
		for ($depth = 0; $depth < ConversationsRequest::MAX_THREAD_DEPTH; $depth++) {
			$wanted = [];
			foreach ($links as $link) {
				$parent = $link['inReplyTo'];
				if ($parent !== '' && !array_key_exists($parent, $links)) {
					$wanted[$parent] = $parent;
				}
			}

			if ($wanted === []) {
				break;
			}

			$found = $this->conversationsRequest->getThreadLinks(array_values($wanted));
			if ($found === []) {
				break;
			}

			foreach ($found as $id => $link) {
				$links[$id] = ['nid' => $link['nid'], 'inReplyTo' => $link['inReplyTo']];
			}
		}

		return $links;
	}

	/**
	 * The topmost post of the thread `$id` is in, out of what is known about
	 * it: the first post with no parent, or whose parent is not stored here.
	 *
	 * @param array<string, array{nid: string, inReplyTo: string}> $links
	 */
	private function rootOf(string $id, array $links): string {
		$seen = [];
		while (!array_key_exists($id, $seen)) {
			$seen[$id] = true;

			$parent = $links[$id]['inReplyTo'] ?? '';
			if ($parent === '' || !array_key_exists($parent, $links)) {
				return $id;
			}

			$id = $parent;
		}

		// a cycle in in_reply_to, which only a remote server can create
		return $id;
	}

	/**
	 * The grouped messages as Conversation entities, dismissed ones left out.
	 *
	 * @param array<string, Stream[]> $groups
	 *
	 * @return Conversation[]
	 */
	private function entities(Person $viewer, array $groups): array {
		$markers = $this->conversationsRequest->getMarkers($viewer->getId(), array_keys($groups));

		$conversations = [];
		foreach ($groups as $rootId => $messages) {
			$last = $messages[0];
			$marker = $markers[$rootId] ?? ['readNid' => 0, 'hiddenNid' => 0];

			if (Nid::compare($last->getNid(), $marker['hiddenNid']) <= 0) {
				continue;
			}

			$rootNid = $this->nidOf($rootId, $messages);
			if (Nid::compare($rootNid, '0') < 1) {
				// the root is not a post this instance can name, so the
				// conversation has no id a client could send back
				continue;
			}

			$conversation = new Conversation();
			$conversation->setId($rootNid)
				->setRootId($rootId)
				->setLastStatus($last)
				->setUnread($this->isUnread($viewer, $last, $marker['readNid']))
				->setAccounts($this->accounts($viewer, $messages));

			$conversations[] = $conversation;
		}

		return $conversations;
	}

	/**
	 * A conversation is unread while its newest message is newer than the
	 * point the account read it to — and never for a message the account sent
	 * itself, which is Mastodon's rule too: writing a message is having read
	 * the conversation.
	 */
	private function isUnread(Person $viewer, Stream $last, int|string $readNid): bool {
		if ($last->getAttributedTo() === $viewer->getId()) {
			return false;
		}

		return Nid::compare($last->getNid(), $readNid) > 0;
	}

	/**
	 * The other participants of the exchange, the newest message's own
	 * correspondents first.
	 *
	 * Taken from the author and the recipients of the messages rather than
	 * from their mentions: `to`, `cc` and `bcc` are what
	 * `StreamDestRequest::generateStreamDirect()` wrote the `dm` rows from, so
	 * they name exactly the accounts this exchange was delivered to. An id
	 * that is not a cached actor — the public collection, a followers
	 * collection, an account this instance has never seen — is dropped by the
	 * cache lookup rather than filtered here.
	 *
	 * @param Stream[] $messages
	 *
	 * @return Person[]
	 */
	private function accounts(Person $viewer, array $messages): array {
		$ids = [];
		foreach ($messages as $message) {
			$correspondents = array_merge(
				[$message->getAttributedTo()], $message->getToAll(), $message->getCcArray()
			);

			foreach ($correspondents as $id) {
				if ($id === '' || $id === $viewer->getId() || $id === Stream::CONTEXT_PUBLIC) {
					continue;
				}

				$ids[$id] = $id;
			}
		}

		$ids = array_slice(array_values($ids), 0, self::MAX_ACCOUNTS);
		if ($ids === []) {
			return [];
		}

		$cached = $this->cacheActorService->getCachedFromIds($ids);

		$accounts = [];
		foreach ($ids as $id) {
			if (array_key_exists($id, $cached)) {
				$accounts[] = $cached[$id];
			}
		}

		return $accounts;
	}

	/**
	 * The nid of the thread root, from the window when the root is in it and
	 * from what the ancestor walk read when it is not.
	 *
	 * @param Stream[] $messages
	 */
	private function nidOf(string $rootId, array $messages): string {
		foreach ($messages as $message) {
			if ($message->getId() === $rootId) {
				return (string)$message->getNid();
			}
		}

		$links = $this->conversationsRequest->getThreadLinks([$rootId]);

		return (string)($links[$rootId]['nid'] ?? '0');
	}

	/**
	 * The conversation a client named, as its root and the messages of it the
	 * viewer may see.
	 *
	 * The id is canonicalised on the way in: a client that sends the nid of a
	 * reply — an id this API never handed out, but one a client could have
	 * kept from an older page whose root was not stored yet — is answered
	 * about the thread that reply is in, not refused.
	 *
	 * @return array{0: array{id: string, idPrim: string, nid: int|string, inReplyTo: string}, 1: array<string, array{id: string, idPrim: string, nid: int|string, inReplyTo: string}>}
	 *
	 * @throws ItemNotFoundException
	 */
	private function threadOf(Person $viewer, int|string $id): array {
		if (!ctype_digit((string)$id) || Nid::compare($id, '0') < 1) {
			throw new ItemNotFoundException('Record not found');
		}

		$this->readAs($viewer);

		try {
			$named = $this->streamService->getStreamByNid($id);
		} catch (Throwable $e) {
			throw new ItemNotFoundException('Record not found');
		}

		$links = $this->withAncestors([
			$named->getId() => ['nid' => $named->getNid(), 'inReplyTo' => $named->getInReplyTo()],
		]);
		$rootId = $this->rootOf($named->getId(), $links);

		$thread = $this->conversationsRequest->getThreadFor($viewer->getId(), $rootId);
		if ($thread === []) {
			// not one of the viewer's direct messages, or not there at all
			throw new ItemNotFoundException('Record not found');
		}

		$root = $this->conversationsRequest->getThreadLinks([$rootId])[$rootId] ?? null;
		if ($root === null) {
			throw new ItemNotFoundException('Record not found');
		}

		return [$root, $thread];
	}

	/**
	 * The newest message of a thread, as its nid.
	 *
	 * @param array<string, array{id: string, idPrim: string, nid: int|string, inReplyTo: string}> $thread
	 */
	private function newestOf(array $thread): string {
		$newest = '0';
		foreach ($thread as $link) {
			if (Nid::compare($link['nid'], $newest) > 0) {
				$newest = (string)$link['nid'];
			}
		}

		return $newest;
	}

	/**
	 * The newest message of a thread, as its id.
	 *
	 * @param array<string, array{id: string, idPrim: string, nid: int|string, inReplyTo: string}> $thread
	 */
	private function newestIdOf(array $thread): string {
		$newest = ['id' => '', 'nid' => '0'];
		foreach ($thread as $link) {
			if (Nid::compare($link['nid'], $newest['nid']) > 0) {
				$newest = $link;
			}
		}

		return $newest['id'];
	}

	/**
	 * One conversation, read back after it was written to.
	 *
	 * @param array{id: string, idPrim: string, nid: int|string, inReplyTo: string} $root
	 *
	 * @throws ItemNotFoundException
	 */
	private function single(Person $viewer, array $root, int|string $lastNid, bool $unread): Conversation {
		$conversation = new Conversation();
		$conversation->setId($root['nid'])
			->setRootId($root['id'])
			->setUnread($unread);

		try {
			$last = $this->streamService->getStreamByNid($lastNid);
			$last->setExportFormat(ACore::FORMAT_LOCAL);
			$conversation->setLastStatus($last)
				->setAccounts($this->accounts($viewer, [$last]));
		} catch (Throwable $e) {
			// `last_status` is nullable on Mastodon's entity, and a client has
			// to cope with it; refusing the whole answer would leave the
			// conversation unread instead
		}

		return $conversation;
	}
	/** The newest decimal identifier in a page of messages. */
	private function newestNidOf(array $nids): int|string {
		usort($nids, static fn (string $left, string $right): int => Nid::compare($left, $right));

		return $nids === [] ? 0 : Nid::fromStorage($nids[count($nids) - 1]);
	}

	/** The oldest decimal identifier, compared without native integer coercion. */
	private function oldestOf(array $nids): int|string {
		usort($nids, static fn (string $left, string $right): int => Nid::compare($left, $right));

		return $nids === [] ? 0 : Nid::fromStorage($nids[0]);
	}

}
