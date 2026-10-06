<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;

/**
 * What every interop test needs from this side of the wire.
 */
trait LocalSide {
	/**
	 * Sends whatever is waiting, here and now.
	 *
	 * The queue is drained by cron on a real instance; a test that waited for
	 * cron would be a test that waits for five minutes, and one that skipped
	 * the queue would not be testing the delivery path at all.
	 */
	protected function drainQueue(): void {
		$queueService = Server::get(RequestQueueService::class);
		$activityService = Server::get(ActivityService::class);
		$activityService->manageInit();

		$total = 0;
		foreach ($queueService->getRequestStandby($total) as $request) {
			$activityService->manageRequest($request);
		}
	}

	/** `host[:port]`, as a handle has to name a server on a non-default port. */
	protected function cloudHost(): string {
		return Server::get(ConfigService::class)->getCloudAuthority();
	}

	/** The fediverse handle of a local user, `@name@host`. */
	protected function localHandle(string $userId): string {
		$actor = Server::get(AccountService::class)->getActorFromUserId($userId, true);

		return '@' . $actor->getPreferredUsername() . '@' . $this->cloudHost();
	}

	/** The ActivityPub id of a local user's actor. */
	protected function localActorId(string $userId): string {
		return Server::get(AccountService::class)->getActorFromUserId($userId, true)->getId();
	}

	/** Words nobody else wrote, so a test finds its own post and nothing else. */
	protected function unique(string $prefix = 'interop'): string {
		return $prefix . ' ' . bin2hex(random_bytes(5));
	}

	/**
	 * A small picture, written to a temporary file, different every time so
	 * that no server can answer it from a cache keyed on the bytes.
	 */
	protected function picture(): string {
		$image = imagecreatetruecolor(64, 48);
		imagefilledrectangle($image, 0, 0, 63, 47, (int)imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
		imagefilledellipse($image, 32, 24, 30, 20, (int)imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));

		$path = sys_get_temp_dir() . '/interop-' . bin2hex(random_bytes(6)) . '.png';
		imagepng($image, $path);

		return $path;
	}
}
