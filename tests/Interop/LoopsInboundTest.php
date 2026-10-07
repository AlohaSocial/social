<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * What a real Loops sends, as this app takes it in.
 *
 * A Loops video arrives as a `Note` carrying one `Document` of `video/mp4`,
 * a poster under `preview` (whose `url` is an object, not a string), its
 * hashtags, and GoToSocial's `interactionPolicy`; its `id` is the ActivityPub
 * object and its `url` is the page a person watches it on. Its comments are
 * `Note`s in reply, and its likes are `Like`s naming whatever it holds as our
 * post's address. Every assertion is what this app's own client API answers,
 * which is what somebody reading the Videos page or Shorts would be shown.
 *
 * Each test makes its own account here and its own posts on Loops under
 * words nobody used before, so none depends on another having run.
 */
class LoopsInboundTest extends TestCase {
	use LoopsPairing;

	protected function setUp(): void {
		$this->setUpPairing('li');
	}

	public function testALoopsFollowIsAcceptedHere(): void {
		$this->loopsFollowsUs($this->fan);

		$this->assertTrue(
			(bool)($this->us->relationship($this->us->resolve($this->fan->handle()))['followed_by'] ?? false),
			'the follower is listed, but the relationship here does not say so'
		);
	}

	public function testALoopsUnfollowReachesUs(): void {
		$ourIdThere = $this->loopsFollowsUs($this->fan);
		$fanAcct = ltrim($this->fan->handle(), '@');

		$this->fan->unfollow($ourIdThere);

		$gone = $this->fan->await(function () use ($fanAcct): ?bool {
			return in_array($fanAcct, $this->us->followerHandles(), true) ? null : true;
		});
		$this->assertTrue($gone, $fanAcct . ' unfollowed on Loops and is still listed as a follower here');
	}

	/**
	 * The one that matters most: a Loops video in the Videos timeline — the
	 * one Shorts reads too — with its caption, a file to play and a poster.
	 */
	public function testALoopsVideoArrivesOnVideosWithItsFileAndPoster(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		$words = self::unique('loops video');
		$this->loops->uploadVideo($this->video, $words);

		$status = $this->loops->await(function () use ($words): ?array {
			LocalAccount::settle();
			foreach ($this->us->videos() as $status) {
				if (str_contains(self::text($status), $words)) {
					return $status;
				}
			}

			return null;
		});
		$this->assertNotNull($status, 'the Loops video never reached the Videos timeline here');

		$this->assertSame($loopsIdHere, (string)($status['account']['id'] ?? ''), 'the video arrived under the wrong account');
		$media = $status['media_attachments'][0] ?? [];
		$this->assertSame('video', $media['type'] ?? '', 'the attachment is not a video here: ' . json_encode($media));
		$this->assertNotEmpty($media['url'] ?? '', 'the video arrived with nothing to play');
		$this->assertNotEmpty($media['preview_url'] ?? '', 'the video arrived without its poster');
	}

	public function testCaptionHashtagsArriveAsTags(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		$tag = 'interop' . bin2hex(random_bytes(4));

		[, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('tagged') . ' #' . $tag);

		$this->assertContains(
			$tag,
			array_map(static fn ($t): string => strtolower((string)($t['name'] ?? '')), (array)($status['tags'] ?? [])),
			'the hashtag was lost: ' . json_encode($status['tags'] ?? null)
		);
	}

	/**
	 * `uri` is the object Loops serves to servers and `url` is the page it
	 * serves to people: "open original" has to open the page, not the JSON.
	 */
	public function testUriIsTheObjectAndUrlIsThePage(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);

		[$video, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('addressed'));

		$this->assertMatchesRegularExpression(
			'#^' . preg_quote('https://' . $this->loops->host(), '#') . '/ap/users/\d+/video/' . preg_quote((string)$video['id'], '#') . '$#',
			(string)($status['uri'] ?? ''),
			'the status does not carry the ActivityPub id'
		);
		$this->assertSame((string)$video['url'], (string)($status['url'] ?? ''), 'the status does not link to the page Loops serves people');
	}

	/**
	 * A public Loops video with comments open says anybody may quote it; one
	 * with comments closed says only its author may. The first is quoted and
	 * Loops approves it; the second is refused here before anything is sent.
	 */
	public function testAVideoThatAllowsQuotesIsQuotedAndApproved(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('quotable'));

		$quote = $this->us->postStatus(self::unique('quoting'), null, ['quote_id' => (string)$status['id']]);
		LocalAccount::settle();

		$state = $this->loops->await(function () use ($quote): ?string {
			LocalAccount::settle();
			$state = (string)($this->us->status((string)$quote['id'])['quote']['state'] ?? '');

			return ($state === 'accepted') ? $state : null;
		});
		$this->assertSame(
			'accepted',
			$state,
			'Loops never approved the quote: ' . json_encode($this->us->status((string)$quote['id'])['quote'] ?? null)
		);
	}

	public function testAVideoThatRefusesQuotesCannotBeQuoted(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('unquotable'), false);

		try {
			$this->us->postStatus(self::unique('quoting'), null, ['quote_id' => (string)$status['id']]);
			$this->fail('a video whose author allows nobody to quote it was quoted here');
		} catch (\RuntimeException $e) {
			$this->assertSame(422, $e->getCode(), $e->getMessage());
		}
	}

	/** A like on Loops counts on our post and tells us who it was. */
	public function testALoopsLikeCountsAndNotifiesHere(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		[$status, $video] = $this->ourVideoArrives($ourIdThere, self::unique('liked'));

		$this->loops->like((string)$video['id']);

		$counted = $this->loops->await(function () use ($status): ?bool {
			return ((int)($this->us->status((string)$status['id'])['favourites_count'] ?? 0) >= 1) ? true : null;
		});
		$this->assertTrue($counted, 'the Loops like never counted here');

		$loopsAcct = ltrim($this->loops->handle(), '@');
		$notified = $this->loops->await(function () use ($loopsAcct, $status): ?bool {
			foreach ($this->us->notifications() as $notification) {
				if (($notification['type'] ?? '') === 'favourite'
					&& ($notification['account']['acct'] ?? '') === $loopsAcct
					&& (string)($notification['status']['id'] ?? '') === (string)$status['id']) {
					return true;
				}
			}

			return null;
		});
		$this->assertTrue($notified, 'the like counted, but nobody here was told');
	}

	public function testALoopsUnlikeTakesTheLikeBack(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		[$status, $video] = $this->ourVideoArrives($ourIdThere, self::unique('unliked'));

		$this->loops->like((string)$video['id']);
		$this->assertNotNull($this->loops->await(function () use ($status): ?bool {
			return ((int)($this->us->status((string)$status['id'])['favourites_count'] ?? 0) >= 1) ? true : null;
		}), 'the Loops like never counted here');

		$this->loops->unlike((string)$video['id']);
		$this->assertNotNull($this->loops->await(function () use ($status): ?bool {
			return ((int)($this->us->status((string)$status['id'])['favourites_count'] ?? 1) === 0) ? true : null;
		}), 'the like still counts here after Loops took it back');
	}

	/**
	 * A comment on Loops is a reply to our post here, and an answer to that
	 * comment is a reply to the comment, not to the post.
	 */
	public function testLoopsCommentsArriveThreadedUnderOurPost(): void {
		$ourIdThere = $this->loopsFollowsUs($this->loops);
		[$status, $video] = $this->ourVideoArrives($ourIdThere, self::unique('commented'));
		$videoId = (string)$video['id'];

		$commentWords = self::unique('loops comment');
		$comment = $this->loops->comment($videoId, $commentWords);
		$answerWords = self::unique('loops answer');
		$this->loops->comment($videoId, $answerWords, (string)$comment['id']);

		$thread = $this->loops->await(function () use ($status, $commentWords, $answerWords): ?array {
			LocalAccount::settle();
			$found = [];
			foreach ($this->us->context((string)$status['id'])['descendants'] as $reply) {
				foreach (['comment' => $commentWords, 'answer' => $answerWords] as $key => $words) {
					if (str_contains(self::text($reply), $words)) {
						$found[$key] = $reply;
					}
				}
			}

			return (count($found) === 2) ? $found : null;
		});
		$this->assertNotNull(
			$thread,
			'the Loops comment and its answer are not both under our post: '
			. json_encode(array_map(
				static fn (array $s): array => array_intersect_key($s, ['uri' => 1, 'in_reply_to_id' => 1, 'content' => 1]),
				$this->us->context((string)$status['id'])['descendants']
			))
		);
		$this->assertSame((string)$status['id'], (string)$thread['comment']['in_reply_to_id'], 'the comment is not a reply to our post');
		$this->assertSame(
			(string)$thread['comment']['id'],
			(string)$thread['answer']['in_reply_to_id'],
			'the answer is not filed under the comment it answers'
		);
	}

	public function testALoopsEditArrives(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[$video, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('before'));

		$changed = self::unique('after');
		$this->loops->editVideo((string)$video['id'], $changed);

		$edited = $this->loops->await(function () use ($status, $changed): ?bool {
			return str_contains(self::text($this->us->status((string)$status['id']) ?? []), $changed) ? true : null;
		});
		$this->assertTrue($edited, 'this app still shows the caption Loops replaced');
	}

	public function testALoopsDeleteArrives(): void {
		$loopsIdHere = $this->weFollowLoops($this->loops);
		[$video, $status] = $this->loopsVideoArrives($loopsIdHere, self::unique('deleted'));

		$this->loops->deleteVideo((string)$video['id']);

		$gone = $this->loops->await(function () use ($status): ?bool {
			return ($this->us->status((string)$status['id']) === null) ? true : null;
		});
		$this->assertTrue($gone, 'this app still shows a video Loops deleted');
	}

	/**
	 * Loops changes a profile in place and tells nobody: v1.0.0-beta.14 has an
	 * `Update` builder for a profile but nothing that ever delivers it, so a
	 * follower's copy only changes when it next fetches the actor.
	 */
	public function testALoopsProfileUpdateArrives(): void {
		$this->markTestSkipped('Loops (v1.0.0-beta.14) never delivers an Update for a profile: a changed name or bio stays on Loops');
	}
}
