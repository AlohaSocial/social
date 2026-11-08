<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Firehose\Subscriber;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StatusDeletionEventsRequest;
use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\NoUserException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/** Serves the Mastodon user, direct-message, and public WebSocket and SSE streams. */
class MastodonStreamingDaemon {
	public const DEFAULT_BIND = '127.0.0.1:8788';
	/** How long one connection may stay open; clients reconnect. */
	public const DEFAULT_MAX_CONNECTION_SECONDS = 86400;
	private const PATH = '/api/v1/streaming';
	private const POLL_IDLE = 0.25;
	private const POLL_INTERVAL = 1;
	/** How often every timeline is read whether or not its newest row moved. */
	private const SWEEP_INTERVAL = 30;
	/** How often a connection's access token and account are checked again. */
	private const TOKEN_RECHECK_INTERVAL = 60;
	private const PING_INTERVAL = 20;
	private const FILTER_POLL_INTERVAL = 5;
	private const ANNOUNCEMENT_POLL_INTERVAL = 5;
	private const NOTIFICATION_MERGE_POLL_INTERVAL = 5;
	private const BATCH = 50;
	private const MAX_HASHTAG_SUBSCRIPTIONS = 20;
	private const MAX_LIST_SUBSCRIPTIONS = 20;
	private const MAX_BATCHES_PER_POLL = 10;
	private const PUBLIC_STREAMS = ['public', 'public:media', 'public:local', 'public:local:media', 'public:remote', 'public:remote:media'];
	private bool $stopping = false;
	private int $maxConnectionSeconds = self::DEFAULT_MAX_CONNECTION_SECONDS;
	/** @var array<string, mixed> values read once per loop pass and shared by every subscriber */
	private array $tick = [];

	public function __construct(
		private ClientService $clientService,
		private AccountService $accountService,
		private StreamRequest $streamRequest,
		private StatusRevisionsRequest $statusRevisionsRequest,
		private StatusDeletionEventsRequest $statusDeletionEventsRequest,
		private FollowsRequest $followsRequest,
		private FiltersRequest $filtersRequest,
		private AnnouncementService $announcements,
		private StreamService $streamService,
		private ConversationService $conversationService,
		private ListsRequest $listsRequest,
		private PostDecorationService $postDecorationService,
		private FilterService $filterService,
		private FeedDisplayService $feedDisplayService,
		private NotificationPolicyService $notificationPolicyService,
		private TimelineRevisionService $timelineRevisionService,
		private ModerationService $moderationService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(string): void $say
	 * @param int $maxConnectionSeconds how long one connection may stay open; 0 for no limit
	 */
	public function run(string $bind, int $maxSeconds, callable $say, int $maxConnectionSeconds = self::DEFAULT_MAX_CONNECTION_SECONDS): int {
		$this->maxConnectionSeconds = max(0, $maxConnectionSeconds);
		$server = new WebSocketServer(
			$bind,
			path: self::PATH,
			binaryFrames: false,
			authorize: \Closure::fromCallable([$this, 'authorize']),
			onMessage: \Closure::fromCallable([$this, 'onMessage']),
			eventStreams: true,
		);
		$this->listenForStop();
		$started = $this->time->getTime();
		$deadline = $maxSeconds > 0 ? $started + $maxSeconds : 0;
		$say('Mastodon streaming listening on ' . $bind);

		while (!$this->stopping) {
			if ($deadline > 0 && $this->time->getTime() >= $deadline) {
				break;
			}
			foreach ($server->serve(self::POLL_IDLE) as $subscriber) {
				$this->initialize($subscriber);
			}
			$this->tick = [];
			foreach ($server->subscribers() as $subscriber) {
				try {
					$this->poll($server, $subscriber);
					if ($this->time->getTime() - (int)($subscriber->context['lastPing'] ?? 0) >= self::PING_INTERVAL) {
						$server->ping($subscriber);
						$subscriber->context['lastPing'] = $this->time->getTime();
					}
				} catch (Throwable $e) {
					$this->logger->warning('Mastodon streaming poll failed', ['exception' => $e]);
				}
			}
		}

		$server->shutdown();
		$say('Mastodon streaming stopped');

		return 0;
	}

	private function authorize(Subscriber $subscriber): bool {
		$stream = $subscriber->query['stream'] ?? '';
		if ($subscriber->eventStream) {
			$path = $subscriber->requestPath;
			if (str_starts_with($path, self::PATH . '/public')) {
				$local = str_ends_with($path, '/local');
				$remote = str_ends_with($path, '/remote');
				$onlyMedia = filter_var($subscriber->query['only_media'] ?? false, FILTER_VALIDATE_BOOL);
				$pathStream = 'public' . ($local ? ':local' : ($remote ? ':remote' : '')) . ($onlyMedia ? ':media' : '');
			} elseif ($path === self::PATH . '/hashtag' || $path === self::PATH . '/hashtag/local') {
				$pathStream = $path === self::PATH . '/hashtag/local' ? 'hashtag:local' : 'hashtag';
				$stream = $pathStream;
			} elseif ($path === self::PATH . '/direct') {
				$pathStream = 'direct';
			} elseif ($path === self::PATH . '/list') {
				$pathStream = 'list';
			} else {
				$pathStream = str_ends_with($path, '/notification') ? 'user:notification' : 'user';
			}
			if ($stream !== '' && $stream !== $pathStream) {
				return false;
			}
			$stream = $pathStream;
		}
		if (!is_string($stream) || ($stream !== '' && !$this->supportsStream($stream))) {
			return false;
		}
		$token = '';
		$authorization = $subscriber->headers['authorization'] ?? '';
		if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $match) === 1) {
			$token = $match[1];
		} elseif (is_string($subscriber->query['access_token'] ?? null)) {
			$token = $subscriber->query['access_token'];
		}
		$subprotocol = '';
		if ($token === '') {
			$protocols = array_map('trim', explode(',', $subscriber->headers['sec-websocket-protocol'] ?? ''));
			foreach ($protocols as $protocol) {
				$candidate = preg_replace('/^Bearer\s+/i', '', $protocol) ?? '';
				if ($candidate !== '' && !in_array(strtolower($candidate), ['bearer', 'access_token', 'mastodon'], true)) {
					$token = $candidate;
					$subprotocol = $protocol;
					break;
				}
			}
		}
		if ($token === '') {
			return false;
		}

		try {
			$client = $this->clientService->getFromToken($token);
			if ($client->getAuthId() <= 0 || $client->getAuthUserId() === '' || ($stream !== '' && !$this->hasRequiredScopes($client, $stream))) {
				return false;
			}
			$viewer = $this->accountService->getActorFromUserId($client->getAuthUserId());
			if ($this->moderationService->isSuspended($viewer->getId())) {
				return false;
			}
			$viewer->setUserId($client->getAuthUserId())->setExportFormat(ACore::FORMAT_LOCAL);
			if ($subprotocol !== '') {
				$subscriber->responseSubprotocol = $subprotocol;
			}
			$now = $this->time->getTime();
			$subscriber->context = [
				'token' => $token,
				'authId' => $client->getAuthId(),
				'connectedAt' => $now,
				'tokenCheckedAt' => $now,
				'sweptAt' => $now,
				'userId' => $client->getAuthUserId(),
				'scopes' => $client->getAuthScopes(),
				'subscriptions' => $stream === '' ? [] : [$stream => true],
				'viewer' => $viewer,
				'homeSince' => $this->streamRequest->newestHomeNid($viewer),
				'notificationSince' => $this->streamRequest->newestNidFor([md5($viewer->getId())], 'notif'),
				'directSince' => $this->streamRequest->newestNidFor([md5($viewer->getId())], 'dm'),
				'revisionSince' => $this->statusRevisionsRequest->newestId(),
				'deleteSince' => $this->statusDeletionEventsRequest->newestId(),
				'filterFingerprint' => $stream === 'user'
					? self::filterFingerprint($this->filtersRequest->getByActor($viewer->getId()))
					: '',
				'filterCheckedAt' => $this->time->getTime(),
				'announcementState' => $stream === 'user'
					? self::reactionState($this->announcementSnapshots($viewer))
					: null,
				'announcementCheckedAt' => $this->time->getTime(),
				'notificationsMergedRevision' => in_array($stream, ['user', 'user:notification'], true)
					? $this->timelineRevisionService->current($client->getAuthUserId(), TimelineRevisionService::NOTIFICATIONS_MERGED)
					: 0,
				'notificationsMergedCheckedAt' => $this->time->getTime(),
				'lastPoll' => 0,
				'lastPing' => $this->time->getTime(),
			];
			$publicSince = $this->streamRequest->newestNidFor([md5(ACore::CONTEXT_PUBLIC)]);
			foreach (self::PUBLIC_STREAMS as $publicStream) {
				$subscriber->context['publicSince:' . $publicStream] = $publicSince;
			}
			if (in_array($stream, ['hashtag', 'hashtag:local'], true)
				&& !$this->addHashtagSubscription($subscriber, $stream, $subscriber->query['tag'] ?? '')) {
				return false;
			}
			if ($stream === 'list' && !$this->addListSubscription($subscriber, $subscriber->query['list'] ?? '')) {
				return false;
			}
			return true;
		} catch (ClientNotFoundException|ActorDoesNotExistException $e) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning('Mastodon streaming authorization failed', ['exception' => $e]);
			return false;
		}
	}

	private function supportsStream(string $stream): bool {
		return in_array($stream, ['user', 'user:notification', 'direct', 'list', 'hashtag', 'hashtag:local', ...self::PUBLIC_STREAMS], true);
	}

	private function onMessage(Subscriber $subscriber, int $opcode, string $payload): ?string {
		if ($opcode !== 0x1) {
			return null;
		}
		$message = json_decode($payload, true);
		if (!is_array($message) || !in_array($message['type'] ?? null, ['subscribe', 'unsubscribe'], true)) {
			return $this->errorMessage((string)($subscriber->context['stream'] ?? ''), 'Invalid subscription message');
		}
		$stream = $message['stream'] ?? '';
		if (!is_string($stream) || !$this->supportsStream($stream)) {
			return $this->errorMessage(is_string($stream) ? $stream : '', 'Unknown stream');
		}
		if (!$this->hasRequiredScopesForContext($subscriber->context, $stream)) {
			return $this->errorMessage($stream, 'Insufficient scope');
		}
		if ($message['type'] === 'subscribe') {
			if ($stream === 'list') {
				$listId = $this->listId($message['list'] ?? '');
				if ($listId !== null && $this->listSubscriptionLimitReached($subscriber, $listId)) {
					return $this->errorMessage($stream, 'Too many list subscriptions');
				}
				if (!$this->addListSubscription($subscriber, $message['list'] ?? '')) {
					return $this->errorMessage($stream, 'A valid list owned by this account is required');
				}
				return null;
			}
			if (in_array($stream, ['hashtag', 'hashtag:local'], true)) {
				$tag = $message['tag'] ?? '';
				$key = $this->hashtagSubscriptionKey($stream, $tag);
				if ($key === '') {
					return $this->errorMessage($stream, 'A valid tag is required');
				}
				$hashtagSubscriptions = $subscriber->context['hashtagSubscriptions'] ?? [];
				if (is_array($hashtagSubscriptions) && !isset($hashtagSubscriptions[$key])
					&& count($hashtagSubscriptions) >= self::MAX_HASHTAG_SUBSCRIPTIONS) {
					return $this->errorMessage($stream, 'Too many hashtag subscriptions');
				}
				$this->addHashtagSubscription($subscriber, $stream, $tag);
				return null;
			}
			$subscriber->context['subscriptions'][$stream] = true;
			if (in_array($stream, ['user', 'user:notification'], true)) {
				$subscriber->context['notificationsMergedRevision'] = $this->timelineRevisionService->current(
					(string)($subscriber->context['userId'] ?? ''), TimelineRevisionService::NOTIFICATIONS_MERGED
				);
				$subscriber->context['notificationsMergedCheckedAt'] = 0;
			}
		} else {
			if ($stream === 'list') {
				$listId = $this->listId($message['list'] ?? '');
				if ($listId !== null && isset($subscriber->context['listSubscriptions'][$listId])) {
					unset($subscriber->context['listSubscriptions'][$listId], $subscriber->context['listSince:' . $listId]);
					if (($subscriber->context['listSubscriptions'] ?? []) === []) {
						unset($subscriber->context['listSubscriptions'], $subscriber->context['subscriptions']['list']);
					}
				}
				return null;
			}
			if (in_array($stream, ['hashtag', 'hashtag:local'], true)) {
				$key = $this->hashtagSubscriptionKey($stream, $message['tag'] ?? '');
				if ($key !== '') {
					unset($subscriber->context['hashtagSubscriptions'][$key]);
				}
				$hasStream = false;
				foreach ($subscriber->context['hashtagSubscriptions'] ?? [] as $subscription) {
					$hasStream = $hasStream || $subscription['stream'] === $stream;
				}
				if (!$hasStream) {
					unset($subscriber->context['subscriptions'][$stream]);
				}
				return null;
			}
			unset($subscriber->context['subscriptions'][$stream]);
		}

		return null;
	}

	private function addHashtagSubscription(Subscriber $subscriber, string $stream, mixed $tag): bool {
		$key = $this->hashtagSubscriptionKey($stream, $tag);
		if ($key === '') {
			return false;
		}
		$subscriptions = $subscriber->context['hashtagSubscriptions'] ?? [];
		if (!is_array($subscriptions)) {
			$subscriptions = [];
		}
		if (!isset($subscriptions[$key]) && count($subscriptions) >= self::MAX_HASHTAG_SUBSCRIPTIONS) {
			return false;
		}
		if (!is_string($tag)) {
			return false;
		}
		$tag = mb_strtolower(ltrim(trim($tag), '#'), 'UTF-8');
		$subscriber->context['subscriptions'][$stream] = true;
		$subscriber->context['hashtagSubscriptions'][$key] = [
			'stream' => $stream,
			'tag' => $tag,
			'local' => $stream === 'hashtag:local',
			'since' => (string)($subscriber->context['publicSince:public'] ?? '0'),
		];

		return true;
	}

	private function hashtagSubscriptionKey(string $stream, mixed $tag): string {
		if (!in_array($stream, ['hashtag', 'hashtag:local'], true) || !is_string($tag)) {
			return '';
		}
		$tag = ltrim(trim($tag), '#');
		if ($tag === '' || mb_strlen($tag, 'UTF-8') > HashtagsRequest::HASHTAG_MAX_LENGTH
			|| preg_match('/[\p{C}\s]/u', $tag) !== 0) {
			return '';
		}

		return hash('sha256', $stream . ':' . mb_strtolower($tag, 'UTF-8'));
	}

	private function hasRequiredScopesForContext(array $context, string $stream): bool {
		$scopes = $context['scopes'] ?? [];
		if (!is_array($scopes)) {
			return false;
		}
		if (in_array('read', $scopes, true)) {
			return true;
		}
		if (in_array($stream, [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local', 'direct', 'list'], true)) {
			return in_array('read:statuses', $scopes, true);
		}

		return in_array('read:statuses', $scopes, true) && in_array('read:notifications', $scopes, true);
	}

	private function errorMessage(string $stream, string $message): string {
		return (string)json_encode([
			'stream' => $stream === '' ? [] : [$stream],
			'event' => 'error',
			'payload' => $message,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private function hasRequiredScopes(SocialClient $client, string $stream): bool {
		$scopes = $client->getAuthScopes();
		if (in_array('read', $scopes, true)) {
			return true;
		}
		if (in_array($stream, [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local', 'direct', 'list'], true)) {
			return in_array('read:statuses', $scopes, true);
		}

		return in_array('read:statuses', $scopes, true)
			&& in_array('read:notifications', $scopes, true);
	}

	private function initialize(Subscriber $subscriber): void {
		$context = $subscriber->context;
		$viewer = $context['viewer'] ?? null;
		if (!$viewer instanceof Person) {
			return;
		}
		$this->streamService->setViewer($viewer);
	}

	private function poll(WebSocketServer $server, Subscriber $subscriber): void {
		$now = $this->time->getTime();
		if ($now - (int)($subscriber->context['lastPoll'] ?? 0) < self::POLL_INTERVAL) {
			return;
		}
		$subscriber->context['lastPoll'] = $now;
		$viewer = $subscriber->context['viewer'] ?? null;
		if (!$viewer instanceof Person) {
			$server->close($subscriber, 'invalid stream state');
			return;
		}
		if (!$this->connectionMayContinue($server, $subscriber, $viewer, $now)) {
			return;
		}
		$this->streamService->setViewer($viewer);
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		$this->sendFilterChanges($server, $subscriber, $viewer, $subscriptions, $now);
		$this->sendAnnouncementChanges($server, $subscriber, $viewer, $subscriptions, $now);
		$this->sendNotificationsMerged($server, $subscriber, $viewer, $subscriptions, $now);

		$sweep = $now - (int)($subscriber->context['sweptAt'] ?? 0) >= self::SWEEP_INTERVAL;
		if ($sweep) {
			$subscriber->context['sweptAt'] = $now;
		}
		$newest = $this->tickValue('newestNid', fn (): string => $this->streamRequest->newestNid());
		if ($this->gateOpens($subscriber, 'stream', $newest, $sweep)) {
			$this->pollTimelines($server, $subscriber, $viewer, $subscriptions, $sweep);
		}

		$newestDeletion = $this->tickValue('newestDeletion', fn (): int => $this->statusDeletionEventsRequest->newestId());
		if ($newestDeletion > (int)($subscriber->context['deleteSince'] ?? 0)) {
			$this->sendDeletedStatuses($server, $subscriber);
		}
		$newestRevision = $this->tickValue('newestRevision', fn (): int => $this->statusRevisionsRequest->newestId());
		if ($newestRevision > (int)($subscriber->context['revisionSince'] ?? 0)) {
			$this->sendEditedPublicStatuses($server, $subscriber, $viewer);
		}
	}

	/**
	 * Reads the subscribed timelines past their cursors. Each probe with an
	 * index-only "newest row" of its own is skipped while that has not moved
	 * since the probe last ran; the sweep reads them all regardless, which
	 * bounds the delay of a row stored below a newest id already seen.
	 */
	private function pollTimelines(WebSocketServer $server, Subscriber $subscriber, Person $viewer, array $subscriptions, bool $sweep): void {
		if (isset($subscriptions['user'])) {
			$this->sendStatuses($server, $subscriber, $viewer);
		}
		if ((isset($subscriptions['user']) || isset($subscriptions['user:notification']))
			&& $this->gateOpens($subscriber, 'notification', $this->streamRequest->newestNidFor([md5($viewer->getId())], 'notif'), $sweep)) {
			$this->sendNotifications($server, $subscriber, $viewer);
		}
		if (isset($subscriptions['direct'])
			&& $this->gateOpens($subscriber, 'direct', $this->streamRequest->newestNidFor([md5($viewer->getId())], 'dm'), $sweep)) {
			$this->sendDirectConversations($server, $subscriber, $viewer);
		}
		if (isset($subscriptions['list'])) {
			foreach ($subscriber->context['listSubscriptions'] ?? [] as $id => $subscription) {
				if (is_array($subscription)) {
					$this->sendListStatuses($server, $subscriber, $viewer, (int)$id, $subscription);
				}
			}
		}
		$hashtagSubscriptions = $subscriber->context['hashtagSubscriptions'] ?? [];
		if ($hashtagSubscriptions === [] && array_intersect(array_keys($subscriptions), self::PUBLIC_STREAMS) === []) {
			return;
		}
		// the public and hashtag timelines show only posts addressed to the public collection
		$newestPublic = $this->tickValue(
			'newestPublic', fn (): string => $this->streamRequest->newestNidFor([md5(ACore::CONTEXT_PUBLIC)])
		);
		foreach (self::PUBLIC_STREAMS as $stream) {
			if (isset($subscriptions[$stream]) && $this->gateOpens($subscriber, $stream, $newestPublic, $sweep)) {
				$this->sendPublicStatuses($server, $subscriber, $viewer, $stream);
			}
		}
		foreach ($hashtagSubscriptions as $key => $subscription) {
			if ($this->gateOpens($subscriber, 'hashtag:' . $key, $newestPublic, $sweep)) {
				$this->sendHashtagStatuses($server, $subscriber, $viewer, $key, $subscription);
			}
		}
	}

	/**
	 * Whether a probe has anything new to read: its newest id moved past the
	 * one recorded when it last ran, or the sweep is due. Records `$newest`
	 * when it opens, before the probe reads, so a row stored meanwhile opens
	 * it again on the next pass.
	 */
	private function gateOpens(Subscriber $subscriber, string $name, string $newest, bool $sweep): bool {
		$seen = $subscriber->context['gate:' . $name] ?? null;
		if (!$sweep && is_string($seen) && Nid::compare($newest, $seen) <= 0) {
			return false;
		}
		$subscriber->context['gate:' . $name] = $newest;

		return true;
	}

	/**
	 * A value read once per pass over the subscribers rather than once per
	 * subscriber.
	 *
	 * @template T
	 * @param callable(): T $read
	 * @return T
	 */
	private function tickValue(string $name, callable $read): mixed {
		if (!array_key_exists($name, $this->tick)) {
			$this->tick[$name] = $read();
		}

		/** @var T */
		return $this->tick[$name];
	}

	/**
	 * Closes a connection that outlived its maximum lifetime, and, at most
	 * once per `TOKEN_RECHECK_INTERVAL`, one whose access token no longer
	 * authorizes it — revoked, expired or idle past its lifetime, which
	 * `ClientService::getFromToken()` decides as it does for a REST request —
	 * or whose account is gone or suspended.
	 */
	private function connectionMayContinue(WebSocketServer $server, Subscriber $subscriber, Person $viewer, int $now): bool {
		$connectedAt = (int)($subscriber->context['connectedAt'] ?? $now);
		if ($this->maxConnectionSeconds > 0 && $now - $connectedAt >= $this->maxConnectionSeconds) {
			$server->close($subscriber, 'connection lifetime reached');
			return false;
		}
		if ($now - (int)($subscriber->context['tokenCheckedAt'] ?? 0) < self::TOKEN_RECHECK_INTERVAL) {
			return true;
		}
		$subscriber->context['tokenCheckedAt'] = $now;

		$token = (string)($subscriber->context['token'] ?? '');
		$userId = (string)($subscriber->context['userId'] ?? '');
		try {
			$client = $this->clientService->getFromToken($token);
			$valid = $token !== ''
				&& $client->getAuthId() === (int)($subscriber->context['authId'] ?? 0)
				&& $client->getAuthUserId() === $userId
				&& $this->accountService->getActorFromUserId($userId)->getId() === $viewer->getId()
				&& !$this->moderationService->isSuspended($viewer->getId());
		} catch (ClientNotFoundException|ActorDoesNotExistException|NoUserException) {
			$valid = false;
		} catch (Throwable $e) {
			// a failing database is not a revoked token: the next check decides
			$this->logger->warning('Mastodon streaming token check failed', ['exception' => $e]);

			return true;
		}
		if (!$valid) {
			$server->close($subscriber, 'access token no longer valid');
		}

		return $valid;
	}

	/** Emits Mastodon's `filters_changed` event when this account's filters change. */
	private function sendFilterChanges(WebSocketServer $server, Subscriber $subscriber, Person $viewer, array $subscriptions, int $now): void {
		$streams = array_values(array_filter(
			['user'],
			static fn (string $stream): bool => isset($subscriptions[$stream])
		));
		if ($streams === [] || $now - (int)($subscriber->context['filterCheckedAt'] ?? 0) < self::FILTER_POLL_INTERVAL) {
			return;
		}

		$subscriber->context['filterCheckedAt'] = $now;
		$fingerprint = self::filterFingerprint($this->filtersRequest->getByActor($viewer->getId()));
		$previous = (string)($subscriber->context['filterFingerprint'] ?? '');
		if ($previous === '' || hash_equals($previous, $fingerprint)) {
			$subscriber->context['filterFingerprint'] = $fingerprint;

			return;
		}
		$subscriber->context['filterFingerprint'] = $fingerprint;
		if ($subscriber->eventStream) {
			$server->send($subscriber, "event: filters_changed\ndata: undefined\n\n");
			return;
		}
		$server->send($subscriber, (string)json_encode([
			'stream' => $streams,
			'event' => 'filters_changed',
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @param Filter[] $filters */
	private static function filterFingerprint(array $filters): string {
		return hash('sha256', serialize(array_map(
			static fn (Filter $filter): array => $filter->jsonSerialize(),
			$filters
		)));
	}

	/** Emits Mastodon's instance-announcement events on user streams. */
	private function sendAnnouncementChanges(WebSocketServer $server, Subscriber $subscriber, Person $viewer, array $subscriptions, int $now): void {
		$streams = array_values(array_filter(
			['user'],
			static fn (string $stream): bool => isset($subscriptions[$stream])
		));
		if ($streams === [] || $now - (int)($subscriber->context['announcementCheckedAt'] ?? 0) < self::ANNOUNCEMENT_POLL_INTERVAL) {
			return;
		}

		$subscriber->context['announcementCheckedAt'] = $now;
		$snapshots = $this->announcementSnapshots($viewer);
		$current = self::reactionState($snapshots);
		$previous = $subscriber->context['announcementState'] ?? null;
		$subscriber->context['announcementState'] = $current;
		if (!is_array($previous)) {
			return;
		}

		foreach ($snapshots as $id => $snapshot) {
			if (!isset($previous[$id])) {
				$this->sendToStreams($server, $subscriber, $streams, 'announcement', $snapshot['payload']);
				continue;
			}
			$before = is_array($previous[$id]) ? $previous[$id] : [];
			$after = $snapshot['reactions'];
			foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
				if (($before[$name] ?? 0) === ($after[$name] ?? 0)) {
					continue;
				}
				$this->sendToStreams($server, $subscriber, $streams, 'announcement.reaction', [
					'name' => $name,
					'count' => $after[$name] ?? 0,
					'announcement_id' => (string)$id,
				]);
			}
		}
		foreach ($previous as $id => $_announcement) {
			if (!isset($current[$id])) {
				$this->sendToStreams($server, $subscriber, $streams, 'announcement.delete', (string)$id);
			}
		}
	}

	/** Emits Mastodon's notification-merge event after a reader accepts requests. */
	private function sendNotificationsMerged(WebSocketServer $server, Subscriber $subscriber, Person $viewer, array $subscriptions, int $now): void {
		$streams = array_values(array_filter(
			['user', 'user:notification'],
			static fn (string $stream): bool => isset($subscriptions[$stream])
		));
		if ($streams === [] || $now - (int)($subscriber->context['notificationsMergedCheckedAt'] ?? 0) < self::NOTIFICATION_MERGE_POLL_INTERVAL) {
			return;
		}

		$subscriber->context['notificationsMergedCheckedAt'] = $now;
		$revision = $this->timelineRevisionService->current(
			$viewer->getUserId(), TimelineRevisionService::NOTIFICATIONS_MERGED
		);
		$previous = (int)($subscriber->context['notificationsMergedRevision'] ?? $revision);
		$subscriber->context['notificationsMergedRevision'] = $revision;
		if ($revision <= $previous) {
			return;
		}

		foreach ($streams as $stream) {
			$this->send($server, $subscriber, [$stream], 'notifications_merged', '');
		}
	}

	/** @return array<string, array<string, mixed>> keyed by announcement id */
	private function announcementSnapshots(Person $viewer): array {
		$snapshots = [];
		foreach ($this->announcements->active($viewer->getId(), $this->time->getTime()) as $announcement) {
			$payload = $announcement->jsonSerialize();
			$snapshots[(string)$announcement->getId()] = [
				'payload' => $payload,
				'reactions' => self::reactionCounts($payload),
			];
		}

		return $snapshots;
	}

	/**
	 * @param array<string, array{payload: array<string, mixed>, reactions: array<string, int>}> $snapshots
	 * @return array<string, array<string, int>> keyed by announcement id and reaction name
	 */
	private static function reactionState(array $snapshots): array {
		$state = [];
		foreach ($snapshots as $id => $snapshot) {
			$state[$id] = $snapshot['reactions'];
		}

		return $state;
	}

	/** @param array<string, mixed> $announcement @return array<string, int> reaction counts keyed by emoji */
	private static function reactionCounts(array $announcement): array {
		$counts = [];
		foreach (is_array($announcement['reactions'] ?? null) ? $announcement['reactions'] : [] as $reaction) {
			if (is_array($reaction) && is_string($reaction['name'] ?? null) && is_int($reaction['count'] ?? null)) {
				$counts[$reaction['name']] = $reaction['count'];
			}
		}

		return $counts;
	}

	/**
	 * Sends one event to each active stream.
	 *
	 * @param string[] $streams
	 */
	private function sendToStreams(WebSocketServer $server, Subscriber $subscriber, array $streams, string $event, array|string $payload): void {
		foreach ($streams as $stream) {
			$this->send($server, $subscriber, [$stream], $event, $payload);
		}
	}

	/** Sends deleted status ids only to streams that could have received the status. */
	private function sendDeletedStatuses(WebSocketServer $server, Subscriber $subscriber): void {
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		$listSubscriptions = $subscriber->context['listSubscriptions'] ?? [];
		$hashtagSubscriptions = $subscriber->context['hashtagSubscriptions'] ?? [];
		if (!isset($subscriptions['user'])
			&& !isset($subscriptions['direct'])
			&& ($listSubscriptions === [])
			&& !array_intersect(array_keys($subscriptions), [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local'])) {
			return;
		}

		$events = $this->statusDeletionEventsRequest->getAfterId(
			(int)($subscriber->context['deleteSince'] ?? 0),
			self::BATCH
		);
		if ($events === []) {
			return;
		}
		$subscriber->context['deleteSince'] = $events[array_key_last($events)]['id'];

		foreach ($events as $event) {
			$matching = [];
			$isPrivate = $event['visibility'] !== Stream::TYPE_PUBLIC;
			$isDirect = $event['visibility'] === Stream::TYPE_DIRECT;
			if ($isPrivate) {
				$viewer = $subscriber->context['viewer'] ?? null;
				if (!$viewer instanceof Person) {
					continue;
				}
				$isRecipient = in_array(md5($viewer->getId()), $event['recipients'], true);
				if (!$isDirect && $event['audience_prim'] !== null) {
					$isRecipient = $isRecipient || $this->followsRequest->followsCollection($viewer->getId(), $event['audience_prim']);
				}
				if (!$isRecipient) {
					continue;
				}
			}
			if (!$isDirect && isset($subscriptions['user'])) {
				$matching[] = 'user';
			}
			if ($isDirect && isset($subscriptions['direct'])) {
				$matching[] = 'direct';
			}
			if (!$isDirect && $listSubscriptions !== []) {
				$matching[] = 'list';
			}
			foreach ($isPrivate ? [] : self::PUBLIC_STREAMS as $stream) {
				if (!isset($subscriptions[$stream]) || !$this->deletedStatusMatchesStream($event, $stream)) {
					continue;
				}
				$matching[] = $stream;
			}
			$eventTags = array_map(static fn (string $tag): string => strtolower(ltrim($tag, '#')), $event['hashtags']);
			foreach ($isPrivate ? [] : $hashtagSubscriptions as $subscription) {
				if (!is_array($subscription)) {
					continue;
				}
				$tag = strtolower(ltrim((string)($subscription['tag'] ?? ''), '#'));
				if ($tag !== '' && in_array($tag, $eventTags, true)
					&& (!($subscription['local'] ?? false) || $event['local'])) {
					$matching[] = (string)($subscription['stream'] ?? 'hashtag');
				}
			}
			foreach (array_unique($matching) as $stream) {
				$this->send($server, $subscriber, [$stream], 'delete', $event['status_nid']);
			}
		}
	}

	/** @param array{id: int, status_nid: string, hashtags: list<string>, local: bool, media: bool, visibility: string, recipients: list<string>, audience_prim: ?string} $event */
	private function deletedStatusMatchesStream(array $event, string $stream): bool {
		return (!str_contains($stream, ':local') || $event['local'])
			&& (!str_contains($stream, ':remote') || !$event['local'])
			&& (!str_contains($stream, ':media') || $event['media']);
	}

	/**
	 * Sends edits without replaying existing revisions: `status.update` to the
	 * public, hashtag, home and list streams whose timeline holds the post,
	 * and the current `conversation` to the direct stream.
	 *
	 * The edited posts are read once per pass for every subscriber, and only
	 * for what decides which streams could hold them; whether this viewer's
	 * home timeline, or the public timeline as this viewer sees it, holds
	 * them is one batched membership query each. The post is then read as
	 * this viewer for each stream it is sent to.
	 */
	private function sendEditedPublicStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		$activeStreams = array_values(array_filter(
			self::PUBLIC_STREAMS,
			static fn (string $name): bool => isset($subscriptions[$name])
		));
		$hashtagSubscriptions = [];
		foreach ($subscriber->context['hashtagSubscriptions'] ?? [] as $key => $subscription) {
			if (is_array($subscription)) {
				$hashtagSubscriptions[$key] = $subscription;
			}
		}
		$listSubscriptions = $subscriber->context['listSubscriptions'] ?? [];
		if ($activeStreams === [] && $hashtagSubscriptions === []
			&& !isset($subscriptions['user'])
			&& !isset($subscriptions['direct'])
			&& $listSubscriptions === []) {
			return;
		}

		$since = (int)($subscriber->context['revisionSince'] ?? 0);
		$revisions = $this->statusRevisionsRequest->getAfterId($since, self::BATCH);
		if ($revisions === []) {
			return;
		}
		$subscriber->context['revisionSince'] = $revisions[array_key_last($revisions)]['id'];

		$edited = [];
		foreach ($revisions as $revision) {
			$status = $this->editedStatus($revision['stream_id_prim']);
			if ($status !== null) {
				$edited[$revision['stream_id_prim']] = $status;
			}
		}
		if ($edited === []) {
			return;
		}
		$nids = array_values(array_map(static fn (Stream $status): string => (string)$status->getNid(), $edited));

		$this->streamRequest->setViewer($viewer);
		$public = ($activeStreams !== [] || $hashtagSubscriptions !== [])
			? array_flip($this->streamRequest->publicNidsAmong($nids))
			: [];
		$home = isset($subscriptions['user']) ? array_flip($this->streamRequest->homeNidsAmong($nids)) : [];
		$hidesReplies = $this->feedDisplayService->hidesReplies($viewer->getUserId());

		foreach ($edited as $streamIdPrim => $status) {
			$nid = (string)$status->getNid();
			$visibility = $status->getVisibility() ?: $this->streamService->visibilityOf($status);
			$matching = [];
			if ($visibility === Stream::TYPE_PUBLIC && isset($public[$nid])) {
				foreach ($activeStreams as $stream) {
					if ($this->editedStatusMatchesStream($status, $stream)) {
						$matching[] = $stream;
					}
				}
				$tags = array_map(static fn (string $tag): string => strtolower(ltrim($tag, '#')), $status->getHashtags());
				foreach ($hashtagSubscriptions as $subscription) {
					if (in_array((string)($subscription['tag'] ?? ''), $tags, true)
						&& (!($subscription['local'] ?? false) || $status->isLocal())) {
						$matching[] = (string)($subscription['stream'] ?? 'hashtag');
					}
				}
			}
			$skipsReply = $hidesReplies && $status->getInReplyTo() !== '';
			if (isset($home[$nid]) && !$skipsReply) {
				$matching[] = 'user';
			}
			if (isset($subscriptions['direct']) && $visibility === Stream::TYPE_DIRECT) {
				$conversation = $this->conversationService->updatedByStatus($viewer, $status->getNid());
				if ($conversation !== null) {
					$this->send($server, $subscriber, ['direct'], 'conversation', $conversation->jsonSerialize());
				}
			}
			foreach ($skipsReply ? [] : $listSubscriptions as $subscription) {
				if (is_array($subscription)
					&& ($subscription['list'] ?? null) instanceof MastodonList
					&& $this->editedStatusIsInList($status, $subscription['list'], $viewer)) {
					$matching[] = 'list';
					break;
				}
			}
			if ($matching === []) {
				continue;
			}

			try {
				$asViewer = $this->streamService->getStreamByIdPrim($streamIdPrim, true);
			} catch (Throwable) {
				continue;
			}
			foreach (array_unique($matching) as $stream) {
				$context = in_array($stream, [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local'], true)
					? Filter::CONTEXT_PUBLIC
					: Filter::CONTEXT_HOME;
				$filtered = $this->filterService->apply([$asViewer], $context, $viewer);
				if ($filtered !== []) {
					$this->send($server, $subscriber, [$stream], 'status.update', $filtered[0]);
				}
			}
		}
	}

	/**
	 * An edited post as stored, read once per pass and shared by every
	 * subscriber: only what is the same for every reader — its nid, origin,
	 * visibility, tags, attachments and reply — is read from it.
	 */
	private function editedStatus(string $streamIdPrim): ?Stream {
		return $this->tickValue('edited:' . $streamIdPrim, function () use ($streamIdPrim): ?Stream {
			try {
				return $this->streamService->getStreamByIdPrim($streamIdPrim);
			} catch (Throwable) {
				return null;
			}
		});
	}

	private function editedStatusIsInList(Stream $status, MastodonList $list, Person $viewer): bool {
		$nid = (string)$status->getNid();
		$options = (new ProbeOptions())
			->setFormat(ACore::FORMAT_LOCAL)
			->setMinId(Nid::decrement($nid))
			->setMaxId(Nid::increment($nid))
			->setLimit(self::BATCH)
			->setInverted(true);
		$this->listsRequest->setViewer($viewer);

		foreach ($this->listsRequest->getTimeline($list, $options) as $candidate) {
			if (Nid::compare((string)$candidate->getNid(), $nid) === 0) {
				return true;
			}
		}

		return false;
	}

	private function editedStatusMatchesStream(Stream $status, string $stream): bool {
		if (str_starts_with($stream, 'hashtag')) {
			return false;
		}
		$local = str_contains($stream, ':local');
		$remote = str_contains($stream, ':remote');
		$onlyMedia = str_ends_with($stream, ':media');

		return (!$local || $status->isLocal())
			&& (!$remote || !$status->isLocal())
			&& (!$onlyMedia || $status->getAttachments() !== []);
	}

	private function sendDirectConversations(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		for ($pageNumber = 0; $pageNumber < self::MAX_BATCHES_PER_POLL && !$subscriber->closed; $pageNumber++) {
			$since = (string)($subscriber->context['directSince'] ?? '0');
			$page = $this->conversationService->getPage($viewer, ConversationService::MAX_LIMIT, 0, 0, $since, false);
			$conversations = $page['conversations'];
			$cursor = (string)$page['prev'];
			if (Nid::compare($cursor, $since) < 1) {
				$cursor = $since;
			}
			foreach ($conversations as $conversation) {
				$nid = $conversation->getLastStatusNid();
				if (Nid::compare((string)$nid, $cursor) > 0) {
					$cursor = (string)$nid;
				}
			}
			if ($cursor === $since && $conversations === []) {
				return;
			}
			foreach (array_reverse($conversations) as $conversation) {
				$this->send($server, $subscriber, ['direct'], 'conversation', $conversation->jsonSerialize());
			}
			$subscriber->context['directSince'] = $cursor;
			if (count($conversations) < ConversationService::MAX_LIMIT) {
				return;
			}
		}
	}

	private function sendListStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer, int $id, array $subscription): void {
		if (!(($subscription['list'] ?? null) instanceof MastodonList)) {
			$server->close($subscriber, 'invalid list stream state');
			return;
		}
		$list = $subscription['list'];
		$this->sendPages($server, $subscriber, 'listSince:' . $id, function (string $since) use ($viewer, $list): array {
			$this->listsRequest->setViewer($viewer);
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setLimit(self::BATCH)
				->setSince($since)
				->setInverted(true);
			$statuses = $this->listsRequest->getTimeline($list, $options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$hasMore = count($statuses) === self::BATCH;
			$statuses = array_reverse($statuses);
			$this->postDecorationService->decorate($statuses, $viewer->getId());
			if ($this->feedDisplayService->hidesReplies($viewer->getUserId())) {
				$statuses = array_values(array_filter($statuses, static fn (Stream $status): bool => $status->getInReplyTo() === ''));
			}
			return [
				'cursor' => $cursor,
				'items' => $this->filterService->apply($statuses, Filter::CONTEXT_HOME, $viewer),
				'hasMore' => $hasMore,
			];
		}, 'update', ['list']);
	}

	private function addListSubscription(Subscriber $subscriber, mixed $rawId): bool {
		$id = $this->listId($rawId);
		$viewer = $subscriber->context['viewer'] ?? null;
		if ($id === null || !$viewer instanceof Person) {
			return false;
		}
		$subscriptions = $subscriber->context['listSubscriptions'] ?? [];
		if (!is_array($subscriptions)) {
			$subscriptions = [];
		}
		if (isset($subscriptions[$id])) {
			return true;
		}
		if ($this->listSubscriptionLimitReached($subscriber, $id)) {
			return false;
		}

		try {
			$list = $this->listsRequest->getOwnedById($viewer->getId(), $id);
			$this->listsRequest->setViewer($viewer);
			$options = (new ProbeOptions())->setFormat(ACore::FORMAT_LOCAL)->setLimit(1);
			$latest = $this->listsRequest->getTimeline($list, $options);
			$subscriber->context['listSubscriptions'][$id] = [
				'id' => $id,
				'list' => $list,
				'since' => isset($latest[0]) ? (string)$latest[0]->getNid() : '0',
			];
			$subscriber->context['listSince:' . $id] = $subscriber->context['listSubscriptions'][$id]['since'];
			$subscriber->context['subscriptions']['list'] = true;
			return true;
		} catch (Throwable) {
			return false;
		}
	}

	private function listSubscriptionLimitReached(Subscriber $subscriber, int $id): bool {
		$subscriptions = $subscriber->context['listSubscriptions'] ?? [];
		if (!is_array($subscriptions)) {
			return false;
		}

		return !isset($subscriptions[$id]) && count($subscriptions) >= self::MAX_LIST_SUBSCRIPTIONS;
	}

	private function listId(mixed $value): ?int {
		if (!is_string($value) && !is_int($value)) {
			return null;
		}
		$id = filter_var((string)$value, FILTER_VALIDATE_INT);

		return is_int($id) && $id > 0 ? $id : null;
	}

	/** @param array{stream: string, tag: string, local: bool, since: string} $subscription */
	private function sendHashtagStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer, string $key, array $subscription): void {
		$cursorKey = 'hashtagSince:' . $key;
		if (!isset($subscriber->context[$cursorKey])) {
			$subscriber->context[$cursorKey] = $subscription['since'];
		}
		$this->sendPages($server, $subscriber, $cursorKey, function (string $since) use ($viewer, $subscription): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::HASHTAG)
				->setArgument($subscription['tag'])
				->setLocal($subscription['local'])
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$statuses = $this->streamService->getTimeline($options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$hasMore = count($statuses) === self::BATCH;
			$statuses = array_reverse($statuses);
			if ($this->feedDisplayService->hidesReplies($viewer->getUserId())) {
				$statuses = array_values(array_filter($statuses, static fn (Stream $status): bool => $status->getInReplyTo() === ''));
			}
			return [
				'cursor' => $cursor,
				'items' => $this->filterService->apply($statuses, Filter::CONTEXT_PUBLIC, $viewer),
				'hasMore' => $hasMore,
			];
		}, 'update', [$subscription['stream']]);
	}

	private function sendPublicStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer, string $stream): void {
		$local = str_contains($stream, ':local');
		$remote = str_contains($stream, ':remote');
		$onlyMedia = str_ends_with($stream, ':media');
		$this->sendPages($server, $subscriber, 'publicSince:' . $stream, function (string $since) use ($viewer, $local, $remote, $onlyMedia): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::PUBLIC)
				->setLocal($local)
				->setRemote($remote)
				->setOnlyMedia($onlyMedia)
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$statuses = $this->streamService->getTimeline($options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$hasMore = count($statuses) === self::BATCH;
			$statuses = array_reverse($statuses);
			if ($this->feedDisplayService->hidesReplies($viewer->getUserId())) {
				$statuses = array_values(array_filter($statuses, static fn (Stream $status): bool => $status->getInReplyTo() === ''));
			}
			$filtered = $this->filterService->apply($statuses, Filter::CONTEXT_PUBLIC, $viewer);
			return ['cursor' => $cursor, 'items' => $filtered, 'hasMore' => $hasMore];
		}, 'update', [$stream]);
	}

	private function sendStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		$this->sendPages($server, $subscriber, 'homeSince', function (string $since) use ($viewer): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::HOME)
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$statuses = $this->streamService->getTimeline($options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$filtered = $this->filterService->apply(array_reverse($statuses), Filter::CONTEXT_HOME, $viewer);
			return ['cursor' => $cursor, 'items' => $filtered, 'hasMore' => count($statuses) === self::BATCH];
		}, 'update', ['user']);
	}

	private function sendNotifications(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		$this->sendPages($server, $subscriber, 'notificationSince', function (string $since) use ($viewer): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::NOTIFICATIONS)
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$page = $this->streamService->getTimeline($options);
			$cursor = $page === [] ? $since : (string)$page[0]->getNid();
			$page = array_reverse($page);
			$shown = $this->notificationPolicyService->partition($viewer, $page)['shown'];
			return [
				'cursor' => $cursor,
				'items' => $this->filterService->applyToNotifications($shown, $viewer),
				'hasMore' => count($page) === self::BATCH,
			];
		}, 'notification', ['user', 'user:notification']);
	}

	/**
	 * Fetches new pages in chronological order and advances the cursor over
	 * filtered rows as well as rows sent to the client.
	 *
	 * @param callable(string): array{cursor: string, items: array, hasMore: bool} $loadPage
	 * @param string[] $streams
	 */
	private function sendPages(WebSocketServer $server, Subscriber $subscriber, string $cursorKey, callable $loadPage, string $event, array $streams): void {
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		$activeStreams = array_values(array_filter($streams, static fn (string $name): bool => isset($subscriptions[$name])));
		if ($activeStreams === []) {
			return;
		}
		for ($pageNumber = 0; $pageNumber < self::MAX_BATCHES_PER_POLL && !$subscriber->closed; $pageNumber++) {
			$since = (string)($subscriber->context[$cursorKey] ?? '0');
			$page = $loadPage($since);
			$items = $page['items'];
			if ($page['cursor'] === $since && $items === []) {
				return;
			}
			foreach ($items as $item) {
				if ($item instanceof Stream) {
					$payload = $event === 'notification' ? $item->exportAsNotification() : $item->jsonSerialize();
				} else {
					$payload = $item;
				}
				foreach ($activeStreams as $activeStream) {
					$this->send($server, $subscriber, [$activeStream], $event, $payload);
				}
			}
			$subscriber->context[$cursorKey] = $page['cursor'];
			if (!$page['hasMore']) {
				return;
			}
		}
	}

	private function send(WebSocketServer $server, Subscriber $subscriber, array $streams, string $event, array|string $payload): void {
		try {
			$status = is_string($payload)
				? $payload
				: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			if ($subscriber->eventStream) {
				$server->send($subscriber, 'event: ' . $event . "\ndata: " . $status . "\n\n");
				return;
			}
			$message = json_encode([
				'stream' => $streams,
				'event' => $event,
				'payload' => $status,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$server->send($subscriber, $message);
		} catch (Throwable $e) {
			$this->logger->warning('Could not serialize Mastodon streaming event', ['exception' => $e]);
		}
	}

	private function listenForStop(): void {
		if (!function_exists('pcntl_signal')) {
			return;
		}
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, fn () => $this->stopping = true);
		pcntl_signal(SIGINT, fn () => $this->stopping = true);
	}
}
