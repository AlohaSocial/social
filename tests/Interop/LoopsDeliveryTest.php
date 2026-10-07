<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * What this app sends, as a real Loops takes it in.
 *
 * Loops is a short-video server with a validator of its own: a remote post is
 * a video post only when it is a `Note` carrying a `Document` of `video/mp4`
 * whose URL answers a HEAD request, and Loops then downloads, probes and
 * re-encodes that file itself. A comment is a `Note` whose `inReplyTo` leads
 * back to one of its videos. Anything else is refused on its side, in its log,
 * while the delivery from here looks like a success — so every assertion is
 * what Loops' own API answers.
 *
 * Each test makes its own account here and its own posts under words nobody
 * used before, so none depends on another having run.
 */
class LoopsDeliveryTest extends TestCase {
	use LoopsPairing;

	protected function setUp(): void {
		$this->setUpPairing('ld');
	}

	public function testOurFollowIsAcceptedByLoops(): void {
		$this->weFollowLoops($this->loops);

		$ourIdThere = $this->loops->resolve($this->us->handle());
		$this->assertContains(
			$ourIdThere,
			$this->loops->followerIds((string)$this->loops->self()['id']),
			'Loops accepted the Follow but does not list us among the followers'
		);
	}

	public function testOurUnfollowReachesLoops(): void {
		$theirIdHere = $this->weFollowLoops($this->fan);
		$ourIdThere = $this->fan->resolve($this->us->handle());
		$fanId = (string)$this->fan->self()['id'];
		$this->assertContains($ourIdThere, $this->fan->followerIds($fanId), 'the follow never showed on Loops');

		$this->us->unfollow($theirIdHere);
		LocalAccount::settle();

		$gone = $this->fan->await(function () use ($ourIdThere, $fanId): ?bool {
			return in_array($ourIdThere, $this->fan->followerIds($fanId), true) ? null : true;
		});
		$this->assertTrue($gone, 'Loops still lists us as a follower after the Undo');
	}

	/**
	 * The one that matters most: a video posted here, re-hosted by Loops with
	 * its caption, a file of its own and a poster it made from it.
	 */
	public function testOurVideoArrivesOnLoops(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		$words = self::unique('video');

		[, $video] = $this->ourVideoArrives($ourIdThere, $words);

		$this->assertFalse((bool)($video['is_local'] ?? true), 'Loops took our video for one of its own');
		$this->assertStringContainsString($words, (string)$video['caption']);
		$this->assertNotEmpty($video['media']['src_url'] ?? '', 'Loops kept the post but has no file to play');
		$this->assertNotEmpty($video['media']['thumbnail'] ?? '', 'Loops made no poster for our video');
		$this->assertGreaterThan(0, (int)($video['media']['duration'] ?? 0), 'Loops could not read the duration');
	}

	public function testOurCaptionHashtagsArriveAsLoopsTags(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		$tag = 'interop' . bin2hex(random_bytes(4));
		$words = self::unique('tagged');

		[, $video] = $this->ourVideoArrives($ourIdThere, $words . ' #' . $tag);

		$this->assertContains($tag, array_map('strtolower', (array)($video['tags'] ?? [])), 'the hashtag was lost: ' . json_encode($video['tags'] ?? null));
	}

	/** A like counts on the video and tells its author who it was. */
	public function testOurLikeCountsAndNotifiesOnLoops(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		$words = self::unique('liked');
		[$video, $status] = $this->loopsVideoArrives($loopsIdHere, $words);

		$this->us->favourite((string)$status['id']);
		LocalAccount::settle();

		$liked = $this->loops->await(function () use ($video): ?array {
			$now = $this->loops->video((string)$video['id']);

			return ((int)($now['likes'] ?? 0) >= 1) ? $now : null;
		});
		$this->assertNotNull($liked, 'the like never counted on Loops');

		$ourIdThere = $this->loops->resolve($this->us->handle());
		$this->assertNotNull(
			$this->loops->awaitNotification('video.like', $ourIdThere),
			'Loops counted the like but never told the author'
		);
	}

	public function testOurUnlikeTakesTheLikeBack(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[$video, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('unliked'));

		$this->us->favourite((string)$status['id']);
		LocalAccount::settle();
		$this->assertNotNull($this->loops->await(function () use ($video): ?bool {
			return ((int)($this->loops->video((string)$video['id'])['likes'] ?? 0) >= 1) ? true : null;
		}), 'the like never counted on Loops');

		$this->us->unfavourite((string)$status['id']);
		LocalAccount::settle();
		$this->assertNotNull($this->loops->await(function () use ($video): ?bool {
			return ((int)($this->loops->video((string)$video['id'])['likes'] ?? 1) === 0) ? true : null;
		}), 'Loops still counts the like after the Undo');
	}

	/** A reply here is a comment there. */
	public function testOurReplyArrivesAsAComment(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[$video, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('commented'));
		$videoId = (string)$video['id'];

		$comment = self::unique('our comment');
		$this->us->postStatus($comment, (string)$status['id']);
		LocalAccount::settle();

		$this->assertNotNull(
			$this->awaitComment($videoId, $comment),
			'our reply never arrived as a comment on the Loops video'
		);
	}

	/**
	 * A reply to a Loops comment ought to be filed under that comment. Loops
	 * (v1.0.0-beta.14) files a remote reply to a comment as a new comment on
	 * the video: `CreateHandler::createReply()` threads only an `inReplyTo`
	 * naming one of its `/reply/` objects. The answer has to arrive either
	 * way; where it arrives flat the threading is Loops' to fix.
	 */
	public function testOurAnswerToALoopsCommentIsFiledUnderIt(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[$video] = $this->loopsVideoArrives($loopsIdHere, self::unique('discussed'));
		$videoId = (string)$video['id'];

		// a comment of Loops' own, which reaches us because we follow its author
		$theirWords = self::unique('their comment');
		$theirs = $this->loops->comment($videoId, $theirWords);
		$theirsHere = $this->awaitHere($loopsIdHere, $theirWords);
		$this->assertNotNull($theirsHere, 'the Loops comment never arrived here, so there is nothing to answer');

		$answer = self::unique('our answer');
		$this->us->postStatus($answer, (string)$theirsHere['id']);
		LocalAccount::settle();

		$where = $this->loops->await(function () use ($videoId, $theirs, $answer): ?string {
			foreach ($this->loops->replies($videoId, (string)$theirs['id']) as $reply) {
				if (str_contains((string)($reply['caption'] ?? ''), $answer)) {
					return 'threaded';
				}
			}
			foreach ($this->loops->comments($videoId) as $found) {
				if (str_contains((string)($found['caption'] ?? ''), $answer)) {
					return 'flat';
				}
			}

			return null;
		});
		$this->assertNotNull($where, 'our answer to the Loops comment never arrived on Loops at all');
		if ($where === 'flat') {
			$this->markTestSkipped('Loops (v1.0.0-beta.14) files a remote answer to a comment as a new comment on the video');
		}
		$this->assertSame('threaded', $where);
	}

	/**
	 * Waits for a top-level comment on a Loops video carrying `$words`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function awaitComment(string $videoId, string $words): ?array {
		return $this->loops->await(function () use ($videoId, $words): ?array {
			foreach ($this->loops->comments($videoId) as $found) {
				if (str_contains((string)($found['caption'] ?? ''), $words)) {
					return $found;
				}
			}

			return null;
		});
	}

	public function testOurEditReachesLoops(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		$words = self::unique('before');
		[$status, $video] = $this->ourVideoArrives($ourIdThere, $words);

		$changed = self::unique('after');
		$this->us->editStatus((string)$status['id'], $changed);
		LocalAccount::settle();

		$edited = $this->loops->await(function () use ($video, $changed): ?bool {
			return str_contains((string)($this->loops->video((string)$video['id'])['caption'] ?? ''), $changed) ? true : null;
		});
		$this->assertTrue($edited, 'Loops still shows the caption that was replaced');
	}

	public function testOurDeleteTakesTheVideoAwayOnLoops(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		[$status, $video] = $this->ourVideoArrives($ourIdThere, self::unique('deleted'));

		$this->us->deleteStatus((string)$status['id']);
		LocalAccount::settle();

		$gone = $this->loops->await(function () use ($video): ?bool {
			return ($this->loops->video((string)$video['id']) === null) ? true : null;
		});
		$this->assertTrue($gone, 'Loops still shows a video that was deleted here');
	}

	/** A name and a bio changed here reach the followers' copy on Loops. */
	public function testOurProfileUpdateReachesLoops(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		$name = 'Interop ' . bin2hex(random_bytes(3));
		$bio = self::unique('bio');

		$this->us->updateCredentials($name, $bio);
		LocalAccount::settle();

		$updated = $this->loops->await(function () use ($ourIdThere, $name, $bio): ?array {
			$account = $this->loops->account($ourIdThere);

			return (($account['name'] ?? '') === $name && str_contains((string)($account['bio'] ?? ''), $bio)) ? $account : null;
		});
		$this->assertNotNull(
			$updated,
			'Loops still shows the old profile: ' . json_encode(array_intersect_key(
				$this->loops->account($ourIdThere), ['name' => 1, 'bio' => 1]
			))
		);
	}
}
