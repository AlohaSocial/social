<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

/**
 * What both Loops test classes need: the Loops accounts, a fresh account
 * here, and the follows that make a post travel at all.
 *
 * Each follow is asserted as it is made, both ends of it, so a test that
 * fails in its setup says which half of the handshake never happened.
 */
trait LoopsPairing {
	/** The Loops account that posts. */
	private Loops $loops;
	/** A second Loops account, for the follow tests. */
	private Loops $fan;
	/** A fresh account here, new for each test. */
	private LocalAccount $us;
	private string $video = '';

	private function setUpPairing(string $prefix): void {
		$loops = Loops::fromEnvironment('LOOPS_TOKEN');
		$fan = Loops::fromEnvironment('LOOPS_FAN_TOKEN');
		if ($loops === null || $fan === null) {
			$this->markTestSkipped('no Loops to talk to: set LOOPS_BASE_URL, LOOPS_TOKEN and LOOPS_FAN_TOKEN');
		}

		$this->video = (string)getenv('LOOPS_VIDEO');
		if ($this->video === '' || !is_file($this->video)) {
			$this->markTestSkipped('no test video: set LOOPS_VIDEO to an H.264 MP4 of at least 250 KB');
		}

		$this->loops = $loops;
		$this->fan = $fan;
		$this->us = LocalAccount::create($prefix);
	}

	/** Words nobody has posted before, so a test finds its own post. */
	private static function unique(string $what): string {
		return 'interop ' . $what . ' ' . bin2hex(random_bytes(5));
	}

	/**
	 * Makes `$account` on Loops follow our account, and waits until both ends
	 * agree: the follower is listed here, and Loops has had the `Accept`.
	 *
	 * @return string our account's profile id on that Loops
	 */
	private function loopsFollowsUs(Loops $account): string {
		$ourIdThere = $account->resolve($this->us->handle());
		$account->follow($ourIdThere);

		$theirAcct = ltrim($account->handle(), '@');
		$listed = $account->await(function () use ($theirAcct): ?bool {
			return in_array($theirAcct, $this->us->followerHandles(), true) ? true : null;
		});
		$this->assertTrue($listed, $theirAcct . '\'s Follow never arrived here');

		// the Accept is a delivery like any other, waiting in our queue
		LocalAccount::settle();
		$accepted = $account->await(static function () use ($account, $ourIdThere): ?bool {
			return ($account->relationship($ourIdThere)['following'] ?? false) === true ? true : null;
		});
		$this->assertTrue($accepted, 'Loops never took our Accept: it still does not show ' . $theirAcct . ' following us');

		return $ourIdThere;
	}

	/**
	 * Makes our account follow `$account` on Loops, and waits for the
	 * `Accept` to come back.
	 *
	 * @return string that Loops account's id here
	 */
	private function weFollowLoops(Loops $account): string {
		// Loops drops the first activity it gets from an actor it has never
		// seen: the profile it creates on the spot has no `status` loaded, and
		// its inbox job discards an actor whose status is not 1. Having Loops
		// look this account up first is what any earlier contact would do.
		$account->resolve($this->us->handle());

		$theirIdHere = $this->us->resolve($account->handle());
		$this->us->follow($theirIdHere);
		LocalAccount::settle();

		$accepted = $account->await(function () use ($theirIdHere): ?bool {
			return ($this->us->relationship($theirIdHere)['following'] ?? false) === true ? true : null;
		});
		$this->assertTrue(
			$accepted,
			'our Follow of ' . $account->handle() . ' was never accepted: '
			. json_encode($this->us->relationship($theirIdHere))
		);

		return $theirIdHere;
	}

	/**
	 * Waits for a status of the given account carrying `$words` to be
	 * readable here, running our queues while it waits.
	 *
	 * @return array<string, mixed>|null
	 */
	private function awaitHere(string $accountIdHere, string $words): ?array {
		return $this->loops->await(function () use ($accountIdHere, $words): ?array {
			LocalAccount::settle();
			foreach ($this->us->statusesOf($accountIdHere) as $status) {
				if (str_contains(self::text($status), $words)) {
					return $status;
				}
			}

			return null;
		});
	}

	/**
	 * What a status says, as text: `content` is HTML, and a hashtag or a
	 * mention in it is a link, so the words around it are only contiguous
	 * once the markup is gone.
	 *
	 * @param array<string, mixed> $status
	 */
	private static function text(array $status): string {
		return html_entity_decode(strip_tags((string)($status['content'] ?? '')), ENT_QUOTES | ENT_HTML5);
	}

	/**
	 * Uploads a video on Loops and waits for it to arrive here; the account
	 * here has to follow the Loops one first. Answers the video on Loops and
	 * the status here.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function loopsVideoArrives(string $loopsIdHere, string $words, bool $comments = true): array {
		$video = $this->loops->uploadVideo($this->video, $words, $comments);
		$status = $this->awaitHere($loopsIdHere, $words);
		$this->assertNotNull($status, 'the Loops video "' . $words . '" never arrived here');

		return [$video, $status];
	}

	/**
	 * Posts a video here and waits for it to arrive on Loops; Loops has to
	 * follow our account first. Answers the status here and the video on
	 * Loops.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function ourVideoArrives(string $ourIdOnLoops, string $words): array {
		$status = $this->us->postVideo($this->video, $words);
		LocalAccount::settle();

		$video = $this->loops->await(function () use ($ourIdOnLoops, $words): ?array {
			LocalAccount::settle();
			foreach ($this->loops->videosOf($ourIdOnLoops) as $video) {
				if (str_contains((string)($video['caption'] ?? ''), $words)) {
					return $video;
				}
			}

			return null;
		});
		$this->assertNotNull(
			$video,
			'our video "' . $words . '" never arrived on Loops (status ' . ($status['uri'] ?? '?') . ')'
		);

		return [$status, $video];
	}
}
