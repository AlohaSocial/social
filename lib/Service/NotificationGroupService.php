<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;

/**
 * Mastodon 4.3's grouped notifications: "eight people favourited your post"
 * instead of eight rows saying the same thing.
 *
 * Grouped is what a reader sees as one event: several people doing the same
 * thing to the same post, or several people following the reader. Mentions,
 * polls, edits and moderation decisions are never grouped.
 *
 * The group key must name the same group tomorrow, so it is built from the
 * type and the post (or the type alone), never from notification ids. An
 * ungrouped notification gets `ungrouped-{id}`, Mastodon's convention.
 */
class NotificationGroupService {
	/**
	 * The notification types that group. Everything else stands alone; two
	 * people writing to you are two things to read.
	 */
	public const GROUPED_TYPES = ['favourite', 'reblog'];

	/** Types that group with no status behind them — one group of their own. */
	public const GROUPED_BY_TYPE = ['follow', 'follow_request'];

	/** How many accounts a group carries as a sample. Mastodon's own ceiling. */
	public const SAMPLE_ACCOUNTS = 8;

	/**
	 * Groups a page of notifications, newest group first. Only the page is
	 * grouped, so a group counts what is on this page (`page_min_id`,
	 * `page_max_id`).
	 *
	 * @param Stream[] $notifications newest first, as the timeline serves them
	 * @param string[] $groupedTypes the types the client asked to have grouped;
	 *                               an empty list means the default set
	 *
	 * @return array{notification_groups: array<int, array<string, mixed>>, accounts: array<int, mixed>, statuses: array<int, mixed>}
	 */
	public function group(array $notifications, array $groupedTypes = []): array {
		$grouping = ($groupedTypes === [])
			? self::GROUPED_TYPES
			: array_values(array_intersect($groupedTypes, self::GROUPED_TYPES));

		/** @var array<string, array<string, mixed>> $groups */
		$groups = [];
		$accounts = [];
		$statuses = [];

		foreach ($notifications as $notification) {
			$type = Stream::notificationTypeOfSubType($notification->getSubType());
			if ($type === '') {
				continue;
			}

			$status = $notification->getObject();
			$statusId = ($status instanceof Stream) ? (string)$status->getNid() : '';
			$key = $this->keyFor($notification, $type, $statusId, $grouping);
			$id = (string)$notification->getNid();
			$at = gmdate('Y-m-d\TH:i:s', $notification->getPublishedTime()) . '.000Z';

			if (!isset($groups[$key])) {
				$groups[$key] = [
					'group_key' => $key,
					'notifications_count' => 0,
					'type' => $type,
					// the page is newest first, so the first notification of a
					// group is its most recent one
					'most_recent_notification_id' => $id,
					'page_min_id' => $id,
					'page_max_id' => $id,
					'latest_page_notification_at' => $at,
					'sample_account_ids' => [],
				];

				if ($statusId !== '') {
					$groups[$key]['status_id'] = $statusId;
					$status->setExportFormat(ACore::FORMAT_LOCAL);
					$statuses[$statusId] = $status;
				}
			}

			$groups[$key]['notifications_count']++;
			// ids here are snowflakes and are compared as numbers, not as
			// strings ('9' is not larger than '10'), and not as PHP ints either,
			// which a wide one does not fit
			if (Nid::compare($id, (string)$groups[$key]['page_min_id']) < 0) {
				$groups[$key]['page_min_id'] = $id;
			}
			if (Nid::compare($id, (string)$groups[$key]['page_max_id']) > 0) {
				$groups[$key]['page_max_id'] = $id;
			}

			if ($notification->hasActor()) {
				$actor = $notification->getActor();
				$actorId = (string)$actor->getNid();
				if (count($groups[$key]['sample_account_ids']) < self::SAMPLE_ACCOUNTS
					&& !in_array($actorId, $groups[$key]['sample_account_ids'], true)) {
					$groups[$key]['sample_account_ids'][] = $actorId;
				}

				$actor->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[$actorId] = $actor;
			}
		}

		return [
			'notification_groups' => array_values($groups),
			// the accounts and statuses the groups refer to, each once, as the
			// v2 shape intends
			'accounts' => array_values($accounts),
			'statuses' => array_values($statuses),
		];
	}

	/**
	 * The notifications of one group, out of a page.
	 *
	 * @param Stream[] $notifications
	 * @param string[] $groupedTypes
	 *
	 * @return Stream[]
	 */
	public function membersOf(array $notifications, string $groupKey, array $groupedTypes = []): array {
		$grouping = ($groupedTypes === []) ? self::GROUPED_TYPES : $groupedTypes;

		$members = [];
		foreach ($notifications as $notification) {
			$type = Stream::notificationTypeOfSubType($notification->getSubType());
			if ($type === '') {
				continue;
			}

			$status = $notification->getObject();
			$statusId = ($status instanceof Stream) ? (string)$status->getNid() : '';
			if ($this->keyFor($notification, $type, $statusId, $grouping) === $groupKey) {
				$members[] = $notification;
			}
		}

		return $members;
	}

	/**
	 * @param string[] $grouping
	 */
	private function keyFor(Stream $notification, string $type, string $statusId, array $grouping): string {
		if (in_array($type, $grouping, true) && $statusId !== '') {
			return $type . '-' . $statusId;
		}

		if (in_array($type, self::GROUPED_BY_TYPE, true)) {
			return $type . '-all';
		}

		return 'ungrouped-' . $notification->getNid();
	}
}
