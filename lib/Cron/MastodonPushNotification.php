<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\MastodonPushContent;
use OCA\Social\Service\PushSubscriptionService;
use OCA\Social\Service\SafeWebPushClient;
use OCA\Social\Service\VapidService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/** Delivers one eligible Mastodon notification to that account's subscriptions. */
class MastodonPushNotification extends QueuedJob {
	private const MAX_TRIES = 5;
	private const TTL = 172800;

	public function __construct(
		ITimeFactory $time,
		private StreamRequest $streamRequest,
		private ActorsRequest $actorsRequest,
		private FollowsRequest $followsRequest,
		private PushSubscriptionService $subscriptions,
		private VapidService $vapid,
		private SafeWebPushClient $client,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}
		$userId = (string)($argument['user'] ?? '');
		$nid = (string)($argument['nid'] ?? '');
		if ($userId === '' || $nid === '' || !$this->subscriptions->hasAny($userId)) {
			return;
		}
		// a disabled account's devices are told nothing; its subscriptions
		// stay for when it is enabled again
		$user = $this->userManager->get($userId);
		if (!$user instanceof IUser || !$user->isEnabled()) {
			return;
		}

		try {
			$notification = $this->streamRequest->getStreamByNid($nid, false);
			if (!$notification instanceof SocialAppNotification) {
				return;
			}
			$recipient = $this->actorsRequest->getFromId($notification->getTo());
			if ($recipient->getUserId() !== $userId) {
				return;
			}
			$actor = $notification->getActor();
			$account = $actor->getDisplayName() ?: ($actor->getName() ?: $notification->getAttributedTo());
			$post = null;
			if ($notification->getObjectId() !== '') {
				try {
					$post = $this->streamRequest->getStreamById($notification->getObjectId());
				} catch (Throwable) {
				}
			}
			$subject = Stream::notificationTypeOfSubType($notification->getSubType());
			$locale = $this->l10nFactory->getUserLanguage($user);
			$l10n = $this->l10nFactory->get('social', $locale);
			$title = $this->title($l10n, $subject, $account);
			if ($title === null) {
				return;
			}
			$auth = ['VAPID' => $this->vapid->auth()];
			$webPush = new WebPush(
				$auth,
				['TTL' => self::TTL, 'urgency' => 'normal'],
				$this->client,
				new HttpFactory(),
				new HttpFactory(),
			);
			$onlyId = (string)($argument['subscription'] ?? '');

			foreach ($this->subscriptions->forUser($userId) as $subscription) {
				if ($onlyId !== '' && $subscription['id'] !== $onlyId) {
					continue;
				}
				if (!($subscription['alerts'][$subject] ?? false)
					|| !$this->policyAllows($subscription['policy'], $recipient->getId(), $notification->getAttributedTo())) {
					continue;
				}
				$payload = json_encode([
					'access_token' => $subscription['accessToken'],
					'preferred_locale' => $locale,
					'notification_id' => (string)$notification->getNid(),
					'notification_type' => $subject,
					'icon' => $actor->getAvatar(),
					'title' => $title,
					'body' => $post === null
						? MastodonPushContent::profileBody($actor)
						: MastodonPushContent::body($post, $actor),
				], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$encoding = $subscription['standard'] ? ContentEncoding::aes128gcm : ContentEncoding::aesgcm;
				$endpoint = new Subscription($subscription['endpoint'], $subscription['p256dh'], $subscription['auth'], $encoding);
				try {
					$report = $webPush->sendOneNotification($endpoint, $payload);
					$status = $report->getResponse()?->getStatusCode();
					if ($report->isSubscriptionExpired()
						|| ($status !== null && $status >= 400 && $status < 500 && !in_array($status, [408, 429], true))) {
						$this->subscriptions->deleteById($userId, $subscription['id']);
					} elseif (!$report->isSuccess()) {
						$this->retrySubscription($argument, $subscription['id'], $status);
					}
				} catch (Throwable $e) {
					$this->retrySubscription($argument, $subscription['id'], null, $e);
				}
			}
		} catch (Throwable $e) {
			$this->retry($argument, $e);
		}
	}

	private function policyAllows(string $policy, string $recipientId, string $actorId): bool {
		if ($policy === 'none') {
			return false;
		}
		if ($policy === 'all') {
			return true;
		}
		try {
			$follow = $policy === 'followed'
				? $this->followsRequest->getByPersons($recipientId, $actorId)
				: $this->followsRequest->getByPersons($actorId, $recipientId);

			return $follow->isAccepted();
		} catch (Throwable) {
			return false;
		}
	}

	private function title(IL10N $l10n, string $subject, string $account): ?string {
		return match ($subject) {
			'mention' => $l10n->t('%s mentioned you in a post', [$account]),
			'favourite' => $l10n->t('%s favourited your post', [$account]),
			'reblog' => $l10n->t('%s boosted your post', [$account]),
			'follow' => $l10n->t('%s is now following you', [$account]),
			'follow_request' => $l10n->t('%s wants to follow you', [$account]),
			'poll' => $l10n->t('The poll by %s has ended', [$account]),
			'status' => $l10n->t('%s posted', [$account]),
			'update' => $l10n->t('%s edited a post you boosted', [$account]),
			default => null,
		};
	}

	private function retry(array $argument, Throwable $error): void {
		$tries = (int)($argument['tries'] ?? 0) + 1;
		if ($tries >= self::MAX_TRIES) {
			$this->logger->error('Mastodon Web Push delivery exhausted retries', [
				'user' => (string)($argument['user'] ?? ''),
				'notification' => (string)($argument['nid'] ?? ''),
				'exception' => $error,
			]);

			return;
		}
		$this->jobList->scheduleAfter(self::class, $this->time->getTime() + min(3600, 30 * (int)(2 ** $tries)), ['tries' => $tries] + $argument);
	}

	private function retrySubscription(array $argument, string $subscriptionId, ?int $status, ?Throwable $error = null): void {
		$tries = (int)($argument['tries'] ?? 0) + 1;
		$context = [
			'user' => (string)($argument['user'] ?? ''),
			'notification' => (string)($argument['nid'] ?? ''),
			'subscription' => $subscriptionId,
		];
		if ($tries >= self::MAX_TRIES) {
			$this->logger->error('Mastodon Web Push subscription delivery exhausted retries', $context + ['status' => $status, 'exception' => $error]);

			return;
		}
		$this->jobList->scheduleAfter(
			self::class,
			$this->time->getTime() + min(3600, 30 * (int)(2 ** $tries)),
			['tries' => $tries, 'subscription' => $subscriptionId] + $argument,
		);
	}
}
