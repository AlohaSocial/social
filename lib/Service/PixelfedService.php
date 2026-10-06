<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;

/**
 * What Pixelfed's own app asks for, in the shapes it reads.
 *
 * Pixelfed speaks Mastodon's client API for almost everything and its own
 * `v1.1`/`v1.2` surface for the rest. The rest is mostly the same data this
 * app already serves under Mastodon's paths, arranged the way the app's
 * screens expect: a story carousel grouped by account, a collection with a
 * `thumb` and a `post_count`, a report with a `report_type`. Nothing here is a
 * second implementation of anything — every method reads through the service
 * that owns the data and rearranges the answer.
 *
 * Two answers are honest rather than convenient. `pushState()` says push is
 * off and there is no token, because there is no Web Push here; `nagState()`
 * says there is nothing to nag about. Both keep the app's settings screens
 * from erroring without promising anything the server does not do.
 */
class PixelfedService {
	/** The report reasons Pixelfed's app offers, and which Mastodon category each is. */
	private const REPORT_TYPES = [
		'spam' => Report::CATEGORY_SPAM,
		'sensitive' => Report::CATEGORY_OTHER,
		'abusive' => Report::CATEGORY_VIOLATION,
		'underage' => Report::CATEGORY_VIOLATION,
		'violence' => Report::CATEGORY_VIOLATION,
		'copyright' => Report::CATEGORY_LEGAL,
		'impersonation' => Report::CATEGORY_OTHER,
		'scam' => Report::CATEGORY_OTHER,
		'terrorism' => Report::CATEGORY_VIOLATION,
	];

	/** What a report can be about. */
	private const REPORT_OBJECTS = ['post', 'user', 'story'];

	/** Pixelfed's licence ids: 1 is "all rights reserved", which is what a post here carries. */
	private const LICENSE_ALL_RIGHTS_RESERVED = 1;

	/** How many accounts a mention autocomplete or a mutuals list names at most. */
	public const ACCOUNTS_LIMIT = 24;

	public function __construct(
		private StoryService $storyService,
		private StoriesRequest $storiesRequest,
		private CollectionService $collectionService,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
		private SearchService $searchService,
		private ReportService $reportService,
		private StreamService $streamService,
		private AvatarService $avatarService,
		private AccountService $accountService,
		private InstanceService $instanceService,
		private ConfigService $configService,
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private PostService $postService,
	) {
	}

	/**
	 * The story carousel as Pixelfed's app draws it: one node per account,
	 * the viewer's own first, each holding its stories oldest first.
	 *
	 * @return array{self: array<string, mixed>|null, nodes: list<array<string, mixed>>}
	 */
	public function carousel(Person $viewer): array {
		$groups = [];
		foreach ($this->storyService->carousel($viewer) as $story) {
			$owner = $story->getOwnerId();
			if (!isset($groups[$owner])) {
				$account = $story->getAuthor();
				$groups[$owner] = [
					'id' => ($account === null) ? '' : (string)$account->getNid(),
					'user' => $this->storyUser($account, $owner === $viewer->getId()),
					'nodes' => [],
					'url' => ($account === null) ? $owner : $account->getId(),
					'seen' => true,
				];
			}

			$groups[$owner]['nodes'][] = $this->storyNode($story);
			if (!$story->isSeen()) {
				$groups[$owner]['seen'] = false;
			}
		}

		$self = $groups[$viewer->getId()] ?? null;
		unset($groups[$viewer->getId()]);

		return [
			'self' => $self,
			'nodes' => array_values($groups),
		];
	}

	/**
	 * Who watched one of the viewer's own stories, as Account entities.
	 *
	 * @return list<Person>
	 */
	public function storyViewers(Person $viewer, int $storyId): array {
		return $this->accounts($this->storyService->viewers($viewer, $storyId));
	}

	/**
	 * The accounts a `@` in a story caption could mean.
	 *
	 * @return list<Person>
	 */
	public function mentionAutocomplete(string $q, int $limit = self::ACCOUNTS_LIMIT): array {
		$q = trim($q);
		if ($q === '') {
			return [];
		}

		return $this->accounts($this->searchService->searchAccounts($q, max(1, min(self::ACCOUNTS_LIMIT, $limit))));
	}

	/**
	 * The viewer's collections in Pixelfed's shape.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function collections(Person $viewer): array {
		$collections = [];
		foreach ($this->collectionService->forProfile($viewer, $viewer) as $collection) {
			$collections[] = $this->collectionEntity($this->collectionService->withPreview($collection));
		}

		return $collections;
	}

	/**
	 * An account by whatever the app has to name it with: a client id, a
	 * `name@host`, a bare local username, or an actor URL.
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	public function resolveAccount(string $reference): Person {
		return $this->cacheActorService->resolve($reference);
	}

	/**
	 * Pixelfed's "mutuals": the accounts the viewer follows that also follow
	 * the account named — which is exactly Mastodon's familiar followers.
	 *
	 * @return list<Person>
	 */
	public function mutuals(Person $viewer, string $reference): array {
		$target = $this->resolveAccount($reference);

		return $this->accounts($this->followService->familiarFollowers($viewer, $target, self::ACCOUNTS_LIMIT));
	}

	/**
	 * Removes the viewer's avatar and answers with the account as it now is.
	 */
	public function removeAvatar(Person $viewer): Person {
		$this->avatarService->remove($viewer->getUserId());

		$account = $this->accountService->getActorFromUserId($viewer->getUserId());
		$account->setExportFormat(ACore::FORMAT_LOCAL);

		return $account;
	}

	/**
	 * A report as the app files one — a reason, a thing and a kind of thing —
	 * turned into the report this server keeps.
	 *
	 * The reason becomes Mastodon's category and is kept in the comment as
	 * well, because `violation` says less than `underage` did. A report about
	 * a post names its author and carries the post; one about a story names
	 * the story's owner and nothing else, since a story has no client id a
	 * moderator could open.
	 *
	 * @throws InvalidResourceException
	 */
	public function report(Person $viewer, string $reportType, string $objectId, string $objectType, string $message): void {
		$reportType = strtolower(trim($reportType));
		if (!array_key_exists($reportType, self::REPORT_TYPES)) {
			throw new InvalidResourceException('unknown report_type');
		}

		$objectType = strtolower(trim($objectType));
		if (!in_array($objectType, self::REPORT_OBJECTS, true)) {
			throw new InvalidResourceException('unknown object_type');
		}

		$statusIds = [];
		switch ($objectType) {
			case 'post':
				$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($objectId));
				$target = $this->cacheActorService->getFromId($post->getAttributedTo());
				$statusIds = [(string)$post->getNid()];
				break;
			case 'story':
				$story = $this->storiesRequest->getLiveById((int)$objectId);
				$target = $this->cacheActorService->getFromId($story->getOwnerId());
				break;
			default:
				$target = $this->resolveAccount($objectId);
		}

		if ($target->getId() === $viewer->getId()) {
			throw new InvalidResourceException('you cannot report yourself');
		}

		$message = trim($message);
		$comment = ($message === '') ? $reportType : $reportType . ': ' . $message;

		$this->reportService->reportFromLocal(
			$viewer, $target, $statusIds, $comment, self::REPORT_TYPES[$reportType], false
		);
	}

	/**
	 * What the app's composer reads before it lets anybody write.
	 *
	 * @return array<string, mixed>
	 */
	public function composeSettings(Person $viewer): array {
		return [
			'allowed_media_types' => $this->instanceService->supportedMimeTypes(),
			'max_caption_length' => InstanceService::MAX_CHARACTERS,
			// the only licence a post here carries
			'default_license' => self::LICENSE_ALL_RIGHTS_RESERVED,
			// whether alt text is *required*; it is asked for, not demanded
			'media_descriptions' => false,
			'max_file_size' => $this->instanceService->imageSizeLimit(),
			'max_media_attachments' => Stream::MAX_ATTACHMENTS,
			'max_altext_length' => PixelfedConfigService::MAX_ALTTEXT_LENGTH,
			'default_scope' => $this->defaultScope($viewer),
		];
	}

	/**
	 * The push settings screen, told the truth: there is no Web Push here,
	 * so nothing is enabled and there is no token to compare against.
	 *
	 * @return array<string, mixed>
	 */
	public function pushState(Person $viewer): array {
		return [
			'version' => '1',
			'username' => $viewer->getPreferredUsername(),
			'profile_id' => $this->profileId($viewer),
			'notify_enabled' => false,
			'has_token' => false,
			'notify_like' => false,
			'notify_follow' => false,
			'notify_mention' => false,
			'notify_comment' => false,
		];
	}

	/**
	 * The answer to "is this the token you have": no token, so no match.
	 *
	 * @return array<string, mixed>
	 */
	public function pushCompare(Person $viewer): array {
		return [
			'version' => '1',
			'username' => $viewer->getPreferredUsername(),
			'profile_id' => $this->profileId($viewer),
			'notify_enabled' => false,
			'match' => false,
			'has_existing' => false,
		];
	}

	/**
	 * Pixelfed's nag — a banner the app shows when its server wants something
	 * of the user. This one never does.
	 *
	 * @return array{active: false}
	 */
	public function nagState(): array {
		return ['active' => false];
	}

	/**
	 * The preferences the Pixelfed app keeps on its server rather than on the
	 * phone, so that a person who reinstalls it finds their app as they left
	 * it.
	 *
	 * Eight switches, and not one of them is a setting of this app's: they
	 * decide what *that client* draws. They are stored as one blob per account
	 * under this app's user config and handed back unread, which is what
	 * Pixelfed does with them too — the server's part is to remember, not to
	 * interpret.
	 *
	 * The defaults are Pixelfed's own, so an app talking to this server behaves
	 * on first run the way it does against the server it was written for.
	 *
	 * @return array<string, mixed>
	 */
	public function appSettings(Person $viewer): array {
		$stored = (string)$this->configService->getValueForUser(
			$viewer->getUserId(), ConfigService::APP_SETTINGS
		);
		$decoded = ($stored === '') ? null : json_decode($stored, true);

		return [
			'id' => $this->profileId($viewer),
			'username' => $viewer->getPreferredUsername(),
			// null until the account has saved them once, which is how the app
			// tells "never set" from "set to the defaults"
			'updated_at' => is_array($decoded) ? ($decoded['updated_at'] ?? null) : null,
			'common' => is_array($decoded) ? ($decoded['common'] ?? self::appSettingsDefault())
				: self::appSettingsDefault(),
		];
	}

	/**
	 * Stores them, keeping only the switches that are Pixelfed's.
	 *
	 * Anything else a client sends is dropped rather than stored: this is a
	 * blob written by a client and handed back to a client, and one that kept
	 * whatever it was given would be a place to park arbitrary content under
	 * somebody's account — the same rule `ScheduledStatus::setParams()` keeps.
	 *
	 * @param array<string, mixed> $common what the client sent
	 * @return array<string, mixed>
	 */
	public function saveAppSettings(Person $viewer, array $common): array {
		$kept = [];
		foreach (self::appSettingsDefault() as $group => $switches) {
			foreach ($switches as $name => $fallback) {
				$value = $common[$group][$name] ?? $fallback;
				$kept[$group][$name] = ($group === 'appearance' && $name === 'theme')
					? (in_array($value, ['light', 'dark', 'system'], true) ? $value : $fallback)
					: (bool)$value;
			}
		}

		$updated = gmdate('Y-m-d\TH:i:s') . '.000Z';
		$this->configService->setValueForUser(
			$viewer->getUserId(),
			ConfigService::APP_SETTINGS,
			(string)json_encode(['updated_at' => $updated, 'common' => $kept])
		);

		return [
			'id' => $this->profileId($viewer),
			'username' => $viewer->getPreferredUsername(),
			'updated_at' => $updated,
			'common' => $kept,
		];
	}

	/**
	 * Pixelfed's own defaults, in Pixelfed's own order.
	 *
	 * @return array<string, array<string, bool|string>>
	 */
	private static function appSettingsDefault(): array {
		return [
			'timelines' => [
				'show_public' => false,
				'show_network' => false,
				'hide_likes_shares' => false,
			],
			'media' => [
				'hide_public_behind_cw' => true,
				'always_show_cw' => false,
				'show_alt_text' => false,
			],
			'appearance' => [
				'links_use_in_app_browser' => true,
				'theme' => 'system',
			],
		];
	}

	/** How many messages one page of a thread holds. */
	public const THREAD_PAGE = 20;

	/** How long a message the app may send at once, as Pixelfed caps it. */
	public const MESSAGE_MAX = 500;

	/** How many accounts the compose screen is offered. */
	public const MUTUALS_LIMIT = 50;

	/**
	 * One direct-message thread as Pixelfed's app draws it: the other party
	 * on top, then the messages between the two, oldest first.
	 *
	 * The messages are this app's direct posts, read through the same
	 * destination rows the direct timeline uses; nothing is stored twice. A
	 * message's `text` is the post's text without its markup, and one that
	 * carries a picture is a `photo` (or `video`) with the file as `media`.
	 *
	 * @return array<string, mixed>
	 */
	public function thread(Person $viewer, string $pid, int|string $maxId = '0', int|string $minId = 0): array {
		$other = $this->resolveAccount($pid);
		$other->setExportFormat(ACore::FORMAT_LOCAL);
		$account = $other->exportAsLocal();

		$posts = $this->streamRequest->directBetween($viewer, $other->getId(), self::THREAD_PAGE, $maxId, $minId);
		usort($posts, static fn (Stream $a, Stream $b): int => $a->getNid() <=> $b->getNid());

		$messages = [];
		foreach ($posts as $post) {
			$messages[] = $this->message($viewer, $post);
		}

		$last = ($messages === []) ? null : $messages[count($messages) - 1];

		return [
			'id' => (string)($account['id'] ?? ''),
			'name' => (string)($account['display_name'] ?? ''),
			'username' => (string)($account['acct'] ?? ''),
			'avatar' => (string)($account['avatar'] ?? ''),
			'url' => (string)($account['url'] ?? $other->getId()),
			'muted' => false,
			'isLocal' => $other->isLocal(),
			'domain' => $other->isLocal() ? null : (string)parse_url($other->getId(), PHP_URL_HOST),
			'created_at' => ($last === null) ? null : $last['created_at'],
			'updated_at' => ($last === null) ? null : $last['created_at'],
			'timeAgo' => ($last === null) ? '' : $last['timeAgo'],
			'lastMessage' => ($last === null) ? '' : $last['text'],
			'messages' => $messages,
		];
	}

	/**
	 * Sends one message: a direct post addressed to the other account, through
	 * the same path the composer's own direct messages take.
	 *
	 * The message goes out with the recipient's handle in front of it, which
	 * is how a direct post names who it is for on this network — the app
	 * shows the thread by account, so the handle is not in its way.
	 *
	 * @return array<string, mixed> the message as the thread shows it
	 * @throws InvalidResourceException
	 */
	public function sendMessage(Person $viewer, string $toId, string $message, string $type = 'text'): array {
		$message = trim($message);
		if ($message === '' || mb_strlen($message) > self::MESSAGE_MAX) {
			throw new InvalidResourceException('a message is between 1 and ' . self::MESSAGE_MAX . ' characters');
		}
		if (!in_array($type, ['text', 'emoji'], true)) {
			throw new InvalidResourceException('unknown message type');
		}

		$other = $this->resolveAccount($toId);
		if ($other->getId() === $viewer->getId()) {
			throw new InvalidResourceException('a message needs somebody to send it to');
		}

		$post = new Post($viewer);
		$post->setType('direct');
		$post->setContent('@' . $other->getAccount() . ' ' . $message);

		$created = $this->postService->createPost($post);
		if (!$created instanceof Stream) {
			throw new InvalidResourceException('the message could not be sent');
		}

		return $this->message($viewer, $created);
	}

	/**
	 * Takes one of the viewer's own messages back.
	 *
	 * Somebody else's message is the same 404 as one that never existed:
	 * whether it exists is not this route's to tell.
	 *
	 * @throws ItemNotFoundException
	 */
	public function deleteMessage(Person $viewer, int $id): void {
		try {
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($id));
		} catch (\Throwable $e) {
			throw new ItemNotFoundException('unknown message');
		}

		if ($post->getAttributedTo() !== $viewer->getId()) {
			throw new ItemNotFoundException('unknown message');
		}

		$this->streamService->deleteLocalItem($post, $post->getType());
	}

	/**
	 * The accounts the viewer follows that follow them back — who the app's
	 * "new message" screen offers, since a direct message to a stranger is
	 * the thing most people never want to receive.
	 *
	 * @return list<Person>
	 */
	public function composeMutuals(Person $viewer): array {
		$following = [];
		foreach ($this->followsRequest->getFollowingByActorId($viewer->getId()) as $follow) {
			$following[$follow->getObjectId()] = true;
		}

		$mutual = [];
		foreach ($this->followsRequest->getFollowersByActorId($viewer->getId()) as $follow) {
			if (isset($following[$follow->getActorId()])) {
				$mutual[] = $follow->getActorId();
			}
			if (count($mutual) >= self::MUTUALS_LIMIT) {
				break;
			}
		}

		return $this->accounts(array_values($this->cacheActorsRequest->getFromIds($mutual)));
	}

	/**
	 * One direct post as a message of the thread.
	 *
	 * @return array<string, mixed>
	 */
	private function message(Person $viewer, Stream $post): array {
		$attachments = $post->getAttachments();
		$first = ($attachments === []) ? null : $attachments[0];
		$type = 'text';
		if ($first !== null) {
			$type = ($first->getType() === 'video') ? 'video' : 'photo';
		}

		$text = trim(html_entity_decode(strip_tags($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$published = $post->getPublishedTime();

		return [
			'id' => (string)$post->getNid(),
			'hidden' => false,
			'isAuthor' => $post->getAttributedTo() === $viewer->getId(),
			'type' => $type,
			'text' => $text,
			'media' => ($first === null) ? null : $first->getUrl(),
			'carousel' => array_values(array_filter(array_map(
				static fn ($attachment): ?string => $attachment->getUrl(),
				$attachments
			))),
			'created_at' => ($published > 0) ? gmdate('Y-m-d\TH:i:s', $published) . '.000Z' : null,
			'timeAgo' => ($published > 0) ? $this->timeAgo($published) : '',
			'seen' => true,
			'reportId' => (string)$post->getNid(),
			'meta' => null,
		];
	}

	/**
	 * "3m", "2h", "5d": the short relative time Pixelfed's app prints beside
	 * a message.
	 */
	private function timeAgo(int $timestamp): string {
		$seconds = max(0, time() - $timestamp);

		return match (true) {
			$seconds < 60 => $seconds . 's',
			$seconds < 3600 => intdiv($seconds, 60) . 'm',
			$seconds < 86400 => intdiv($seconds, 3600) . 'h',
			$seconds < 86400 * 7 => intdiv($seconds, 86400) . 'd',
			default => intdiv($seconds, 86400 * 7) . 'w',
		};
	}

	/**
	 * One collection as Pixelfed's app expects it.
	 *
	 * `visibility` uses Pixelfed's words: a followers-only collection is what
	 * Pixelfed calls `private`. `thumb` is the first picture of the first
	 * post, or nothing, and `url` is this app's own page for the collection.
	 *
	 * @return array<string, mixed>
	 */
	private function collectionEntity(Collection $collection): array {
		$thumb = '';
		foreach ($collection->getPreview() as $post) {
			foreach ($post->getAttachments() as $attachment) {
				$thumb = $attachment->getPreviewUrl() ?: $attachment->getUrl();
				break 2;
			}
		}

		$created = date('c', $collection->getCreation());

		return [
			'id' => (string)$collection->getId(),
			'pid' => $this->ownerNid($collection->getOwnerId()),
			'visibility' => $collection->isPublic() ? 'public' : 'private',
			'title' => $collection->getTitle(),
			'description' => $collection->getDescription(),
			'thumb' => $thumb,
			'url' => rtrim($this->configService->getSocialUrl(), '/') . '/collections/' . $collection->getId(),
			'post_count' => $collection->getSize(),
			'published_at' => $collection->isPublic() ? $created : null,
			'created_at' => $created,
			'updated_at' => date('c', ($collection->getUpdated() === 0) ? $collection->getCreation() : $collection->getUpdated()),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storyNode(Story $story): array {
		$media = $story->getMedia();

		return [
			'id' => (string)$story->getId(),
			'pid' => $this->ownerNid($story->getOwnerId()),
			'type' => ($media !== null && $media->getType() === 'video') ? 'video' : 'photo',
			'src' => ($media === null) ? '' : $media->getUrl(),
			'duration' => $story->getDuration(),
			'seen' => $story->isSeen(),
			'created_at' => date('c', $story->getCreation()),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storyUser(?Person $account, bool $isViewer): array {
		if ($account === null) {
			return ['id' => '', 'username' => '', 'username_acct' => '', 'avatar' => '', 'local' => false, 'is_author' => $isViewer];
		}

		$account->setExportFormat(ACore::FORMAT_LOCAL);
		$entity = $account->exportAsLocal();

		return [
			'id' => (string)($entity['id'] ?? ''),
			'username' => (string)($entity['username'] ?? ''),
			'username_acct' => (string)($entity['acct'] ?? ''),
			'avatar' => (string)($entity['avatar'] ?? ''),
			'local' => $account->isLocal(),
			'is_author' => $isViewer,
		];
	}

	/**
	 * The account id the app keys its own per-account state on.
	 *
	 * The viewer comes from `social_actor`, which is what this instance
	 * decides about an account and carries no numeric id; the id every client
	 * sees is the cached actor's. Without this every account answered `0`, so
	 * two accounts on one phone shared whatever the app stored under it.
	 */
	private function profileId(Person $viewer): string {
		if ($viewer->getNid() > 0) {
			return (string)$viewer->getNid();
		}

		$nid = $this->ownerNid($viewer->getId());

		return ($nid === '') ? '0' : $nid;
	}

	private function ownerNid(string $actorId): string {
		try {
			return (string)$this->cacheActorService->getFromId($actorId)->getNid();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * The audience a new post starts with, in Pixelfed's words.
	 */
	private function defaultScope(Person $viewer): string {
		$privacy = $viewer->getPrivacy();

		return match ($privacy) {
			'', Stream::TYPE_PUBLIC => 'public',
			Stream::TYPE_UNLISTED => 'unlisted',
			Stream::TYPE_FOLLOWERS, 'private' => 'private',
			default => 'public',
		};
	}

	/**
	 * @param array<Person> $accounts
	 * @return list<Person>
	 */
	private function accounts(array $accounts): array {
		$entities = [];
		foreach ($accounts as $account) {
			$entities[] = $account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return $entities;
	}
}
