<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationRequest;
use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * What a reader's notification list holds once the policy has had its say:
 * the page, the senders waiting in the requests inbox, what is unread, and
 * what accepting a sender releases.
 *
 * Kept apart from `NotificationService`, which raises the Nextcloud
 * notification when a row is stored, because the digest needs these answers
 * too and the digest is a dependency of that service.
 */
class NotificationInboxService {
	/** The marker the unread count and the digest read. */
	public const TIMELINE = 'notifications';

	public function __construct(
		private StreamService $streamService,
		private StreamRequest $streamRequest,
		private NotificationPolicyService $notificationPolicyService,
		private MarkerService $markerService,
		private ConversationsRequest $conversationsRequest,
	) {
	}

	/**
	 * A page of the viewer's notifications, newest first, as
	 * `/api/v1/notifications` serves them before the policy and the keyword
	 * filters are applied.
	 *
	 * The grouped list, the requests inbox, the unread count and the digest
	 * all read this one query, so they agree about what the account's
	 * notifications are.
	 *
	 * Notifications whose sub-type Mastodon has no name for are dropped here
	 * rather than by each caller: they serialise as `"type": ""`, which a
	 * client with a closed enum cannot decode, and one undecodable entry
	 * loses the whole page.
	 *
	 * @param string[] $types
	 * @param string[] $excludeTypes
	 *
	 * @return Stream[]
	 */
	public function timeline(
		Person $viewer,
		int $limit,
		int|string $maxId = '0',
		int|string $minId = 0,
		int|string $sinceId = '0',
		array $types = [],
		array $excludeTypes = [],
		string $accountId = '',
	): array {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL);
		$options->setProbe(ProbeOptions::NOTIFICATIONS)
			->setLimit($limit)
			->setMaxId($maxId)
			->setMinId($minId)
			->setSince($sinceId)
			->setTypes($types)
			->setExcludeTypes($excludeTypes)
			->setAccountId($accountId);

		$this->streamService->setViewer($viewer);

		return array_values(
			array_filter(
				$this->streamService->getTimeline($options),
				static fn (Stream $post): bool
					=> Stream::notificationTypeOfSubType($post->getSubType()) !== ''
			)
		);
	}

	/**
	 * The notifications the policy is holding back, newest first, over the
	 * last `NotificationPolicyService::LOOKBACK` the viewer received — or
	 * over those newer than `$sinceId`.
	 *
	 * Nothing is read for an account whose policy holds nothing, which is
	 * every account that never stored one.
	 *
	 * @return Stream[]
	 */
	public function held(Person $viewer, int|string $sinceId = '0'): array {
		if ($this->notificationPolicyService->of($viewer->getUserId())->isEverythingAccepted()) {
			return [];
		}

		return $this->notificationPolicyService->partition(
			$viewer, $this->timeline($viewer, NotificationPolicyService::LOOKBACK, '0', 0, $sinceId)
		)['held'];
	}

	/**
	 * The requests inbox: one row per sender whose notifications are held,
	 * leaving out the senders the reader dismissed.
	 *
	 * @return NotificationRequest[]
	 */
	public function requests(Person $viewer): array {
		$held = $this->held($viewer);
		if ($held === []) {
			return [];
		}

		$senders = [];
		foreach ($held as $notification) {
			if ($notification->hasActor()) {
				$senders[$notification->getActor()->getId()] = true;
			}
		}

		$decided = $this->notificationPolicyService->decisionsAbout($viewer->getId(), array_keys($senders));

		return $this->notificationPolicyService->requestsFrom($held, $decided['dismissed']);
	}

	/**
	 * "Show me this account's notifications after all": the sender is always
	 * allowed from now on, and what they sent while held joins the list.
	 *
	 * Nothing is raised for what is released. It is old news, and a bell for
	 * each of a stranger's ten replies the moment the reader says yes would be
	 * the interruption the policy kept away. It counts as unread instead, even
	 * where the reader's marker moved past it while it was hidden.
	 */
	public function release(Person $viewer, Person $sender): void {
		$released = [];
		foreach ($this->held($viewer) as $notification) {
			if ($notification->hasActor() && $notification->getActor()->getId() === $sender->getId()) {
				$released[] = (string)$notification->getNid();
			}
		}

		$this->notificationPolicyService->accept($viewer, $sender);
		$this->markerService->markUnread($viewer->getUserId(), self::TIMELINE, $released);
	}

	/**
	 * How many notifications the viewer has not seen: those past the marker
	 * that are listed — neither held by the policy nor from a muted thread —
	 * and those released behind the marker since the reader last looked.
	 *
	 * Counted by the database when the policy holds nothing; otherwise the
	 * page past the marker is read and the policy applied to it, up to the
	 * cap. Past the cap the badge says "lots", which is all anyone reads from
	 * a two-digit number anyway.
	 *
	 * @param string $userId the Nextcloud user behind the viewer, whose marker and policy these are
	 */
	public function unreadCount(Person $viewer, string $userId, int $cap = 99): int {
		$marker = $this->markerService->lastReadId($userId, self::TIMELINE);
		$released = count($this->markerService->unreadBehind($userId, self::TIMELINE));

		if ($this->notificationPolicyService->of($userId)->isEverythingAccepted()) {
			$listed = $this->streamRequest->countNotificationsSince($viewer, $marker, $cap);
		} else {
			$listed = count(
				$this->notificationPolicyService->partition(
					$viewer, $this->timeline($viewer, $cap + 1, '0', 0, $marker)
				)['shown']
			);
		}

		return min($cap + 1, $listed + $released);
	}

	/**
	 * What, besides a new notification and the marker, changes the unread
	 * count: the policy, what was released behind the marker and the muted
	 * threads. For the count's `ETag`; a decision about a sender moves the
	 * timeline revision.
	 */
	public function unreadState(Person $viewer, string $userId): string {
		return md5(
			(string)json_encode([
				$this->notificationPolicyService->of($userId)->getDecisions(),
				$this->markerService->unreadBehind($userId, self::TIMELINE),
				$this->conversationsRequest->getMutedRoots($viewer->getId()),
			])
		);
	}
}
