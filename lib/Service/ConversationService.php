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
use OCA\Social\Db\StreamRequest;
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
 * Mastodon's conversations, derived from the direct timeline.
 *
 * A conversation is the thread root (the message with no parent, or the
 * topmost parent stored here) and its id is the root's nid, which stays
 * stable for `POST /api/v1/conversations/{id}/read` as messages arrive.
 *
 * - Direct messages store their root (`social_stream.root_prim`,
 *   `ConversationsRequest::storeRoot()`); older ones not yet reached by
 *   `Cron\ThreadRoots` are walked up to `MAX_THREAD_DEPTH`.
 * - A thread whose root is not stored here groups under the topmost message
 *   it does store, and its id changes if the parent arrives later.
 * - Paging is by message, so two pages can show the same conversation; a
 *   client keys on `id` and updates the row.
 *
 * Read and dismissed state is stored (see ConversationsRequest), since it
 * cannot be derived from the messages.
 */
class ConversationService {
	/**
	 * How many messages one page of conversations is built from: the most one
	 * timeline query allows, so a busy thread does not fill the page alone.
	 */
	public const WINDOW = ProbeOptions::MAX_LIMIT;

	/** What Mastodon defaults and caps a page of conversations at. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	/** The most the unread badge counts to; past it the answer is "lots". */
	public const UNREAD_CAP = 99;

	/**
	 * How many participants one conversation reports; a direct message can be
	 * addressed to a crowd.
	 */
	private const MAX_ACCOUNTS = 20;

	public function __construct(
		private StreamService $streamService,
		private CacheActorService $cacheActorService,
		private ConversationsRequest $conversationsRequest,
		private StreamRequest $streamRequest,
		private TimelineRevisionService $timelineRevisionService,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The `ETag` of the unread count: the newest direct message, the
	 * conversations' and timeline revisions (`TimelineRevisionService`), and the
	 * notification policy's state. One index-only probe and a few user settings
	 * instead of the count.
	 */
	public function unreadTag(Person $viewer): string {
		$policy = $this->notificationPolicy();

		return $this->streamRequest->newestNidFor([md5($viewer->getId())], 'dm')
			. '-' . $this->timelineRevisionService->of($viewer->getUserId())
			. '-' . $this->timelineRevisionService->of($viewer->getUserId(), TimelineRevisionService::CONVERSATIONS)
			. (($policy === null) ? '' : '-' . $policy->stateOf($viewer->getUserId()));
	}

	/**
	 * One page of the viewer's conversations, newest message first, with the
	 * cursors the `Link` header is built from.
	 *
	 * `next` is a message nid, as `max_id` is everywhere in this API: the last
	 * conversation's newest message when the page filled up, otherwise the
	 * oldest message the window reached, and none once the window came back
	 * short. `min_id` answers the oldest conversations above the cursor, still
	 * newest first, with `prev` at the newest message on the page.
	 *
	 * @return array{conversations: Conversation[], next: int|string, prev: int|string}
	 */
	public function getPage(Person $viewer, int $limit, int|string $maxId = '0', int|string $minId = 0, int|string $sinceId = '0', bool $pollChats = true): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		// The reader is looking, so Bluesky chats are polled next run regardless of
		// backoff; resolved lazily because of a service cycle.
		if ($pollChats) {
			try {
				$this->container?->get(ChatPoller::class)->wakeActor($viewer);
			} catch (Throwable) {
			}
		}
		$messages = $this->directMessages($viewer, $maxId, $minId, $sinceId);
		if ($messages === []) {
			return ['conversations' => [], 'next' => 0, 'prev' => 0];
		}

		$listed = $this->listed($viewer, $this->group($messages));

		$filled = (count($listed) > $limit);
		// A `min_id` page is the conversations next to the cursor: the oldest above it.
		$adjacent = ((string)$minId !== '0' && (string)$minId !== '');
		$conversations = $this->entities($viewer, array_slice($listed, $adjacent ? -$limit : 0, $limit));

		$nids = [];
		foreach ($messages as $message) {
			$nids[] = $message->getNid();
		}

		$last = end($conversations);
		if ($filled && $last !== false) {
			$next = $last->getLastStatusNid();
		} else {
			// A full window may have older messages behind it.
			$next = (count($messages) < self::WINDOW) ? 0 : $this->oldestOf($nids);
		}

		$first = reset($conversations);
		$prev = ($adjacent && $filled && $first !== false) ? $first->getLastStatusNid() : $this->newestNidOf($nids);

		return [
			'conversations' => $conversations,
			'next' => $next,
			'prev' => $prev,
		];
	}

	/**
	 * The current conversation after one of its messages was edited.
	 *
	 * @return Conversation|null null when the viewer cannot read the message,
	 *                           has dismissed its conversation or the
	 *                           notification policy holds it
	 */
	public function updatedByStatus(Person $viewer, int|string $statusNid): ?Conversation {
		try {
			[$root, $thread] = $this->threadOf($viewer, $statusNid);
		} catch (ItemNotFoundException) {
			return null;
		}

		$lastNid = $this->newestOf($thread);
		$markers = $this->conversationsRequest->getMarkers($viewer->getId(), [$root['id']]);
		$marker = $markers[$root['id']] ?? ['readNid' => 0, 'hiddenNid' => 0];
		if ($this->isDismissed($lastNid, $marker['hiddenNid'])
			|| $this->isHeldThread($viewer, $root['id'], array_keys($thread))) {
			return null;
		}

		$last = $this->streamService->getStreamByNid($lastNid);
		return $this->single(
			$viewer,
			$root,
			$lastNid,
			$this->isUnread($viewer, $last->getAttributedTo(), $lastNid, $marker['readNid'])
		);
	}

	/**
	 * How many conversations have something in them the account has not read,
	 * for the badge.
	 *
	 * Applies the list's own window, grouping and rules (dismissed and held left
	 * out, never for the account's own message) so badge and list agree, but
	 * reads only thread links (`StreamService::getDirectTimelineLinks()`), not
	 * the posts. Capped at `$cap`.
	 *
	 * @param int $cap the most it will count to
	 */
	public function countUnread(Person $viewer, int $cap = self::UNREAD_CAP): int {
		$this->readAs($viewer);
		$messages = $this->streamService->getDirectTimelineLinks($this->windowOptions(0, 0, 0));
		if ($messages === []) {
			return 0;
		}

		$links = [];
		foreach ($messages as $message) {
			$links[$message['id']] = ['nid' => $message['nid'], 'inReplyTo' => $message['inReplyTo']];
		}
		$links = $this->withAncestors($links);

		// newest first, so the first message seen of a thread is its newest
		$newest = [];
		$threads = [];
		foreach ($messages as $message) {
			$rootId = $this->rootOf($message['id'], $links);
			$newest[$rootId] ??= $message;
			$threads[$rootId] ??= ['written' => false, 'author' => ''];
			if ($message['attributedTo'] === $viewer->getId()) {
				$threads[$rootId]['written'] = true;
			}
			if ($message['id'] === $rootId) {
				$threads[$rootId]['author'] = $message['attributedTo'];
			}
		}

		$markers = $this->conversationsRequest->getMarkers($viewer->getId(), array_keys($newest));
		$unread = [];
		foreach ($newest as $rootId => $last) {
			$marker = $markers[$rootId] ?? ['readNid' => 0, 'hiddenNid' => 0];
			if ($this->isDismissed($last['nid'], $marker['hiddenNid'])
				|| Nid::compare((string)($links[$rootId]['nid'] ?? '0'), '0') < 1
				|| !$this->isUnread($viewer, $last['attributedTo'], $last['nid'], $marker['readNid'])) {
				continue;
			}

			$unread[$rootId] = $threads[$rootId];
		}

		$held = $this->heldRoots($viewer, $unread);

		return min($cap, count(array_diff_key($unread, $held)));
	}

	/**
	 * Marks every unread conversation in the list's window read, and says how
	 * many that was; only the unread ones are written.
	 */
	public function markAllRead(Person $viewer): int {
		$messages = $this->directMessages($viewer, 0, 0, 0);
		if ($messages === []) {
			return 0;
		}

		$marked = 0;
		$ids = [];
		foreach ($this->listed($viewer, $this->group($messages)) as $conversation) {
			if (!$conversation['unread']) {
				continue;
			}

			$last = $conversation['messages'][0];
			$this->conversationsRequest->markRead($viewer->getId(), $conversation['rootId'], $last->getNid());
			$marked++;
			$ids[] = $conversation['rootId'];
			$ids[] = $last->getId();
		}
		if ($marked > 0) {
			$this->changed($viewer);
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
		$this->changed($viewer);
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
		$this->changed($viewer);

		return true;
	}

	/**
	 * Dismisses the conversation, recorded as the newest message it covered, so
	 * a later message brings it back as in Mastodon. Nothing is deleted.
	 *
	 * @throws ItemNotFoundException
	 */
	public function remove(Person $viewer, int|string $id): void {
		[$root, $thread] = $this->threadOf($viewer, $id);

		$this->conversationsRequest->markHidden(
			$viewer->getId(), $root['id'], Nid::fromStorage($this->newestOf($thread))
		);
		$this->changed($viewer);
		// a request on Bluesky is turned down there, not accepted
		$this->chatState()?->dismissed($viewer, [$root['id'], $this->newestIdOf($thread)]);
	}

	/** The account marked a conversation read or dismissed one. */
	private function changed(Person $viewer): void {
		$this->timelineRevisionService->bump($viewer->getUserId(), TimelineRevisionService::CONVERSATIONS);
	}

	/**
	 * Where a conversation's state goes to Bluesky; resolved lazily because of a
	 * service cycle.
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
	 * Who may reach the account; resolved when needed, like `chatState()`.
	 */
	private function notificationPolicy(): ?NotificationPolicyService {
		try {
			$policy = $this->container?->get(NotificationPolicyService::class);
		} catch (Throwable) {
			return null;
		}

		return $policy instanceof NotificationPolicyService ? $policy : null;
	}

	/**
	 * The window of direct messages a page is built from.
	 *
	 * @return Stream[] newest first
	 */
	private function directMessages(Person $viewer, int|string $maxId, int|string $minId, int|string $sinceId): array {
		$this->readAs($viewer);

		return $this->streamService->getTimeline($this->windowOptions($maxId, $minId, $sinceId));
	}

	/** What the window of direct messages is read with. */
	private function windowOptions(int|string $maxId, int|string $minId, int|string $sinceId): ProbeOptions {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL);
		$options->setProbe(ProbeOptions::DIRECT)
			->setLimit(self::WINDOW)
			->setMaxId($maxId)
			->setMinId($minId)
			->setSince($sinceId);

		return $options;
	}

	/**
	 * Sets the viewer on `StreamService`: an anonymous read returns only public
	 * posts, so no direct message would be found.
	 */
	private function readAs(Person $viewer): void {
		$this->streamService->setViewer($viewer);
	}

	/**
	 * The window's messages grouped by thread root, in order of their newest
	 * message, with the thread links read on the way (which name the nid of a
	 * root outside the window).
	 *
	 * @param Stream[] $messages
	 *
	 * @return array{groups: array<string, Stream[]>, links: array<string, array{nid: int|string, inReplyTo: string}>}
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

		return ['groups' => $groups, 'links' => $links];
	}

	/**
	 * The same map with what names the root of each of its posts added.
	 *
	 * Stored roots (`ConversationsRequest::storeRoot()`) are read in one
	 * statement. Posts without one walk up `ConversationsRequest::ANCESTOR_LEVELS`
	 * levels per statement until a stored root is reached or a round adds nothing.
	 *
	 * @param array<string, array{nid: int|string, inReplyTo: string, root?: string}> $links
	 *
	 * @return array<string, array{nid: int|string, inReplyTo: string, root?: string}>
	 */
	private function withAncestors(array $links): array {
		$links = $this->withStoredRoots($links, array_keys($links));

		for ($depth = 0; $depth < ConversationsRequest::MAX_THREAD_DEPTH; $depth += ConversationsRequest::ANCESTOR_LEVELS) {
			$wanted = [];
			foreach ($links as $link) {
				$parent = $link['inReplyTo'];
				if (!isset($link['root']) && $parent !== '' && !array_key_exists($parent, $links)) {
					$wanted[$parent] = $parent;
				}
			}

			if ($wanted === []) {
				break;
			}

			$found = $this->conversationsRequest->getAncestorLinks(
				array_values($wanted),
				min(ConversationsRequest::ANCESTOR_LEVELS, ConversationsRequest::MAX_THREAD_DEPTH - $depth)
			);
			if ($found === []) {
				break;
			}

			$rooted = [];
			foreach ($found as $id => $link) {
				$links[$id] ??= ['nid' => $link['nid'], 'inReplyTo' => $link['inReplyTo']];
				if (($link['rootPrim'] ?? '') !== '') {
					$rooted[] = (string)$id;
				}
			}
			$links = $this->withStoredRoots($links, $rooted);
		}

		return $links;
	}

	/**
	 * Marks which of `$ids` have a stored root, and adds those roots.
	 *
	 * @param array<string, array{nid: int|string, inReplyTo: string, root?: string}> $links
	 * @param array<array-key, string|int> $ids
	 *
	 * @return array<string, array{nid: int|string, inReplyTo: string, root?: string}>
	 */
	private function withStoredRoots(array $links, array $ids): array {
		if ($ids === []) {
			return $links;
		}

		foreach ($this->conversationsRequest->getStoredRoots(array_map('strval', $ids)) as $id => $root) {
			$links[$root['id']] ??= ['nid' => $root['nid'], 'inReplyTo' => $root['inReplyTo']];
			$links[$root['id']]['root'] = $root['id'];
			$links[$id]['root'] = $root['id'];
		}

		return $links;
	}

	/**
	 * The root of the thread `$id` is in: the root its post stored, or the
	 * topmost post the walk reached.
	 *
	 * @param array<string, array{nid: int|string, inReplyTo: string, root?: string}> $links
	 */
	private function rootOf(string $id, array $links): string {
		$seen = [];
		while (!array_key_exists($id, $seen)) {
			$seen[$id] = true;
			if (isset($links[$id]['root'])) {
				return $links[$id]['root'];
			}

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
	 * The grouped messages that are listed, newest first, leaving out dismissed
	 * ones, held ones (`heldRoots()`) and those whose root this instance cannot
	 * name. Decided before any accounts are looked up.
	 *
	 * @param array{groups: array<string, Stream[]>, links: array<string, array{nid: int|string, inReplyTo: string}>} $grouped
	 *
	 * @return list<array{rootId: string, rootNid: string, messages: Stream[], unread: bool}>
	 */
	private function listed(Person $viewer, array $grouped): array {
		$markers = $this->conversationsRequest->getMarkers($viewer->getId(), array_keys($grouped['groups']));

		$listed = [];
		foreach ($grouped['groups'] as $rootId => $messages) {
			$last = $messages[0];
			$marker = $markers[$rootId] ?? ['readNid' => 0, 'hiddenNid' => 0];

			if ($this->isDismissed($last->getNid(), $marker['hiddenNid'])) {
				continue;
			}

			// the root is in the window, or the walk up read it
			$rootNid = (string)($grouped['links'][$rootId]['nid'] ?? '0');
			if (Nid::compare($rootNid, '0') < 1) {
				// No id a client could send back.
				continue;
			}

			$listed[] = [
				'rootId' => (string)$rootId,
				'rootNid' => $rootNid,
				'messages' => $messages,
				'unread' => $this->isUnread($viewer, $last->getAttributedTo(), $last->getNid(), $marker['readNid']),
			];
		}

		$threads = [];
		foreach ($listed as $conversation) {
			$thread = ['written' => false, 'author' => ''];
			foreach ($conversation['messages'] as $message) {
				if ($message->getAttributedTo() === $viewer->getId()) {
					$thread['written'] = true;
				}
				if ($message->getId() === $conversation['rootId']) {
					$thread['author'] = $message->getAttributedTo();
				}
			}
			$threads[$conversation['rootId']] = $thread;
		}
		$held = $this->heldRoots($viewer, $threads);
		if ($held === []) {
			return $listed;
		}

		return array_values(array_filter(
			$listed,
			static fn (array $conversation): bool => !isset($held[$conversation['rootId']])
		));
	}

	/**
	 * Whether the notification policy holds the thread under `$rootId` back
	 * (`heldRoots()`), with its authors read from storage.
	 *
	 * @param string[] $ids the viewer's messages in the thread
	 */
	private function isHeldThread(Person $viewer, string $rootId, array $ids): bool {
		if (!$this->holdsConversations($viewer)) {
			return false;
		}

		$authors = $this->conversationsRequest->getAuthorsOf(array_merge([$rootId], $ids));

		return $this->heldRoots($viewer, [
			$rootId => [
				'written' => in_array($viewer->getId(), $authors, true),
				'author' => $authors[$rootId] ?? '',
			],
		]) !== [];
	}

	/**
	 * The conversations held back from a reader who takes direct messages from
	 * nobody: started by somebody whose direct messages are held
	 * (`NotificationPolicyService::heldDirectSenders()`) and not written in by
	 * the reader. The first message waits among the requests until accepted.
	 *
	 * @param array<string, array{written: bool, author: string}> $threads by root id; `author` is '' where the root was not read
	 *
	 * @return array<string, true> the held root ids
	 */
	private function heldRoots(Person $viewer, array $threads): array {
		if ($threads === [] || !$this->holdsConversations($viewer)) {
			return [];
		}

		$authors = [];
		$unknown = [];
		foreach ($threads as $rootId => $thread) {
			if ($thread['written']) {
				continue;
			}
			if ($thread['author'] === '') {
				$unknown[] = (string)$rootId;
			} else {
				$authors[(string)$rootId] = $thread['author'];
			}
		}
		if ($unknown !== []) {
			$authors += $this->conversationsRequest->getAuthorsOf($unknown);
		}
		$authors = array_filter(
			$authors,
			static fn (string $author): bool => $author !== '' && $author !== $viewer->getId()
		);
		if ($authors === []) {
			return [];
		}

		$cached = $this->cachedActors(array_values($authors));
		$senders = [];
		foreach ($authors as $author) {
			$senders[$author] = isset($cached[$author]) ? $cached[$author]->getCreation() : 0;
		}
		$heldSenders = $this->notificationPolicy()?->heldDirectSenders($viewer, $senders) ?? [];

		$held = [];
		foreach ($authors as $rootId => $author) {
			if (isset($heldSenders[$author])) {
				$held[(string)$rootId] = true;
			}
		}

		return $held;
	}

	private function holdsConversations(Person $viewer): bool {
		return $this->notificationPolicy()?->holdsConversations($viewer->getUserId()) ?? false;
	}

	/**
	 * Listed conversations as Conversation entities, their participants
	 * looked up together.
	 *
	 * @param list<array{rootId: string, rootNid: string, messages: Stream[], unread: bool}> $listed
	 *
	 * @return Conversation[]
	 */
	private function entities(Person $viewer, array $listed): array {
		$participants = [];
		foreach ($listed as $index => $conversation) {
			$participants[$index] = $this->participantsOf($viewer, $conversation['messages']);
		}
		$cached = $this->cachedActors(array_merge([], ...$participants));

		$conversations = [];
		foreach ($listed as $index => $conversation) {
			$entity = new Conversation();
			$entity->setId($conversation['rootNid'])
				->setRootId($conversation['rootId'])
				->setLastStatus($conversation['messages'][0])
				->setUnread($conversation['unread'])
				->setAccounts($this->accountsOf($participants[$index], $cached));

			$conversations[] = $entity;
		}

		return $conversations;
	}

	/**
	 * A conversation is unread while its newest message is past the read marker,
	 * never when the account wrote it (Mastodon's rule too).
	 *
	 * @param string $author who wrote the newest message
	 */
	private function isUnread(Person $viewer, string $author, int|string $lastNid, int|string $readNid): bool {
		if ($author === $viewer->getId()) {
			return false;
		}

		return Nid::compare($lastNid, $readNid) > 0;
	}

	/**
	 * A conversation is dismissed while its newest message is one the
	 * dismissal covered; a newer one brings it back.
	 */
	private function isDismissed(int|string $lastNid, int|string $hiddenNid): bool {
		return Nid::compare($lastNid, $hiddenNid) <= 0;
	}

	/**
	 * The other participants of the exchange, the newest message's own
	 * correspondents first.
	 *
	 * Taken from author, `to`, `cc` and `bcc`, which are what the `dm` rows were
	 * written from (`StreamDestRequest::generateStreamDirect()`); ids that are
	 * not cached actors are dropped by the cache lookup.
	 *
	 * @param Stream[] $messages
	 *
	 * @return string[] actor ids
	 */
	private function participantsOf(Person $viewer, array $messages): array {
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

		return array_slice(array_values($ids), 0, self::MAX_ACCOUNTS);
	}

	/**
	 * @param string[] $ids
	 *
	 * @return array<string, Person> by actor id
	 */
	private function cachedActors(array $ids): array {
		$ids = array_values(array_unique($ids));

		return ($ids === []) ? [] : $this->cacheActorService->getCachedFromIds($ids);
	}

	/**
	 * @param string[] $ids
	 * @param array<string, Person> $cached
	 *
	 * @return Person[]
	 */
	private function accountsOf(array $ids, array $cached): array {
		$accounts = [];
		foreach ($ids as $id) {
			if (array_key_exists($id, $cached)) {
				$accounts[] = $cached[$id];
			}
		}

		return $accounts;
	}

	/**
	 * The conversation a client named, as its root and the messages of it the
	 * viewer may see. The nid of a reply is answered with that reply's thread.
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
			$participants = $this->participantsOf($viewer, [$last]);
			$conversation->setLastStatus($last)
				->setAccounts($this->accountsOf($participants, $this->cachedActors($participants)));
		} catch (Throwable $e) {
			// `last_status` is nullable in Mastodon's entity.
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
