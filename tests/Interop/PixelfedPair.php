<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;

/**
 * One account here and one on a real Pixelfed, following each other.
 *
 * Every test starts from that: a post reaches the other side only because
 * somebody there follows its author, so both follows are made (or found
 * standing) before each test, and a test that takes one away leaves the next
 * one to make it again. Both accounts are found by handle through each
 * side's own search, which is also the first proof that each side will fetch
 * and accept the other's actor.
 *
 * Our side is driven through this app's own client API over HTTP, so a post
 * takes the route a real one does. Only the delivery queue is touched
 * directly: it is drained here and now rather than waited for from cron.
 */
trait PixelfedPair {
	protected Pixelfed $pixelfed;
	protected AlohaApi $aloha;
	protected Person $actor;

	/** `username@host` of our account */
	protected string $ourHandle = '';
	/** Pixelfed's id for our account */
	protected string $ourIdThere = '';
	/** `username@host` of Pixelfed's account */
	protected string $theirHandle = '';
	/** our id for Pixelfed's account */
	protected string $theirIdHere = '';

	/** @var string[] pictures made for this test, removed afterwards */
	private array $pictures = [];

	protected function setUpPair(): void {
		$pixelfed = Pixelfed::fromEnvironment();
		$aloha = AlohaApi::fromEnvironment();
		if ($pixelfed === null || $aloha === null) {
			$this->markTestSkipped(
				'no Pixelfed to talk to: set PIXELFED_BASE_URL, PIXELFED_TOKEN, NEXTCLOUD_URL, NEXTCLOUD_USER and NEXTCLOUD_PASSWORD'
			);
		}

		$this->pixelfed = $pixelfed;
		$this->aloha = $aloha;

		// the way the app makes an account, so the actor is cached too:
		// webfinger and the actor document are answered from the cache
		$this->actor = Server::get(AccountService::class)->getActorFromUserId($aloha->user(), true);
		$this->ourHandle = $this->actor->getPreferredUsername() . '@'
			. Server::get(ConfigService::class)->getCloudAuthority();
		$this->theirHandle = $this->pixelfed->selfHandle();

		$this->ourIdThere = $this->pixelfed->resolveAccount($this->ourHandle);
		$this->theirIdHere = $this->aloha->resolveAccount($this->theirHandle);

		$this->pixelfedFollowsUs();
		$this->weFollowPixelfed();
	}

	protected function tearDownPair(): void {
		foreach ($this->pictures as $file) {
			@unlink($file);
		}
		$this->pictures = [];
	}

	/** Makes Pixelfed follow our account, unless it already does. */
	protected function pixelfedFollowsUs(): void {
		if ($this->pixelfed->relationship($this->ourIdThere)['following'] === true) {
			return;
		}

		$this->pixelfed->follow($this->ourIdThere);
		$followed = $this->pixelfed->await(function (): ?bool {
			// the Accept is ours to send: it waits in the queue
			$this->drainQueue();

			return ($this->pixelfed->relationship($this->ourIdThere)['following'] === true) ? true : null;
		});

		$this->assertTrue($followed, 'Pixelfed asked to follow ' . $this->ourHandle . ' and never got to');
	}

	/** Makes our account follow Pixelfed's, unless it already does. */
	protected function weFollowPixelfed(): void {
		if ($this->aloha->relationship($this->theirIdHere)['following'] === true) {
			return;
		}

		$this->aloha->post('/api/v1/accounts/' . $this->theirIdHere . '/follow');
		$followed = $this->aloha->await(function (): ?bool {
			$this->drainQueue();

			return ($this->aloha->relationship($this->theirIdHere)['following'] === true) ? true : null;
		});

		$this->assertTrue($followed, 'this side asked to follow ' . $this->theirHandle . ' and was never accepted');
	}

	/**
	 * Sends whatever is waiting in our delivery queue, here and now.
	 *
	 * The queue is drained by cron on a real instance; a test that waited for
	 * cron would wait five minutes, and one that skipped the queue would not
	 * be testing the delivery path at all.
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

	/**
	 * A JPEG nobody has posted before: Pixelfed and this app both recognise a
	 * file they have seen, and a test must not pass on a picture an earlier
	 * run left behind.
	 */
	protected function picture(): string {
		$width = 480;
		$height = 360;
		$image = imagecreatetruecolor($width, $height);
		for ($block = 0; $block < 48; $block++) {
			$colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
			$x = random_int(0, $width - 40);
			$y = random_int(0, $height - 40);
			imagefilledrectangle($image, $x, $y, $x + random_int(20, 160), $y + random_int(20, 120), (int)$colour);
		}

		$file = tempnam(sys_get_temp_dir(), 'interop-') . '.jpg';
		imagejpeg($image, $file, 85);
		imagedestroy($image);
		$this->pictures[] = $file;

		return $file;
	}

	/** Words nobody has posted before, so a match is this test's post. */
	protected function words(string $prefix = 'interop'): string {
		return $prefix . ' ' . bin2hex(random_bytes(5));
	}

	/**
	 * Waits for a status we wrote to be on Pixelfed, under our account.
	 *
	 * @param array<string, mixed> $ours our status entity
	 * @return array<string, mixed>
	 */
	protected function awaitOnPixelfed(array $ours): array {
		$theirs = $this->pixelfed->await(function () use ($ours): ?array {
			$this->drainQueue();
			foreach ($this->pixelfed->statusesOf($this->ourIdThere) as $status) {
				if (($status['uri'] ?? '') === $ours['uri']) {
					return $status;
				}
			}

			return null;
		});

		$this->assertNotNull($theirs, 'Pixelfed never showed ' . $ours['uri'] . ' under ' . $this->ourHandle);

		return $theirs;
	}

	/**
	 * Waits for a status Pixelfed wrote to be readable here.
	 *
	 * @param array<string, mixed> $theirs Pixelfed's status entity
	 * @param callable(): array<int, array<string, mixed>>|null $list where to look; the home timeline by default
	 * @return array<string, mixed>
	 */
	protected function awaitHere(array $theirs, ?callable $list = null): array {
		$list ??= fn (): array => $this->aloha->timeline('home');
		$ours = $this->aloha->awaitOn($list, (string)$theirs['uri']);

		$this->assertNotNull($ours, 'Pixelfed\'s ' . $theirs['uri'] . ' never reached this side');

		return $ours;
	}

	/**
	 * Has Pixelfed write a photo post, and answers it.
	 *
	 * @param array<string, mixed> $fields anything else for `POST /api/v1/statuses`
	 * @return array<string, mixed>
	 */
	protected function pixelfedPostsPhotos(string $caption, int $pictures = 1, array $fields = [], string $alt = 'interop picture'): array {
		$ids = [];
		for ($i = 1; $i <= $pictures; $i++) {
			$ids[] = $this->pixelfed->uploadPhoto($this->picture(), $alt . ' ' . $i);
		}

		return $this->pixelfed->publish($fields + ['status' => $caption, 'media_ids' => $ids]);
	}

	/**
	 * Writes a photo post here, and answers it.
	 *
	 * @param array<string, mixed> $fields anything else for `POST /api/v1/statuses`
	 * @return array<string, mixed>
	 */
	protected function wePostPhotos(string $caption, int $pictures = 1, array $fields = [], string $alt = 'interop picture'): array {
		$ids = [];
		for ($i = 1; $i <= $pictures; $i++) {
			$ids[] = $this->aloha->uploadPhoto($this->picture(), $alt . ' ' . $i);
		}

		$status = $this->aloha->publish($fields + ['status' => $caption, 'media_ids' => $ids, 'visibility' => 'public']);
		$this->drainQueue();

		return $status;
	}
}
