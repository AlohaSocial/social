<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * What this app sends, as a real Pixelfed takes it in.
 *
 * Pixelfed is stricter on the way in than anything else this app talks to:
 * a `Note` without an attachment it can verify is dropped whole, a story is
 * fetched through a capability rather than read from the activity, and none
 * of it says so to the sender — the delivery answers 200 either way. So every
 * assertion here is made on Pixelfed's own client API, as a Pixelfed user
 * would see it.
 *
 * Skipped, with a reason, when there is no Pixelfed to talk to: see
 * `tests/Interop/README.md` and `.github/workflows/interop-pixelfed.yml`.
 */
class PixelfedDeliveryTest extends TestCase {
	use PixelfedPair;

	protected function setUp(): void {
		$this->setUpPair();
	}

	protected function tearDown(): void {
		$this->tearDownPair();
	}

	/**
	 * Our `Follow` is one Pixelfed accepts, and its `Accept` is one we read:
	 * both ends have to agree the follow stands.
	 */
	public function testOurFollowIsAcceptedByPixelfed(): void {
		$this->aloha->post('/api/v1/accounts/' . $this->theirIdHere . '/unfollow');
		$this->drainQueue();
		$this->assertTrue(
			$this->pixelfed->await(fn (): ?bool => ($this->pixelfed->relationship($this->ourIdThere)['followed_by'] === false) ? true : null),
			'Pixelfed still lists ' . $this->ourHandle . ' as a follower after the unfollow'
		);

		$this->weFollowPixelfed();

		$this->assertTrue(
			$this->pixelfed->await(fn (): ?bool => ($this->pixelfed->relationship($this->ourIdThere)['followed_by'] === true) ? true : null),
			'this side counts the follow as accepted, Pixelfed does not list ' . $this->ourHandle . ' as a follower'
		);
	}

	/**
	 * The one that matters most: a photo with its description. Pixelfed drops
	 * the whole post when an attachment fails its checks, so arriving at all
	 * is half of this test.
	 */
	public function testAPhotoPostArrivesWithItsImageAndDescription(): void {
		$words = $this->words();
		$ours = $this->wePostPhotos($words, 1, [], 'a description for ' . $words);

		$theirs = $this->awaitOnPixelfed($ours);

		$this->assertStringContainsString($words, (string)($theirs['content'] ?? ''));
		$media = $theirs['media_attachments'] ?? [];
		$this->assertCount(1, $media, 'the picture did not come with the post');
		$this->assertSame('image', $media[0]['type'] ?? '');
		$this->assertNotSame('', (string)($media[0]['url'] ?? ''), 'the picture has nothing to show');
		$this->assertSame('a description for ' . $words . ' 1', $media[0]['description'] ?? null, 'the description was lost');
	}

	/** An album is one post with every picture, in the order it was made. */
	public function testAnAlbumArrivesWithEveryPictureInOrder(): void {
		$words = $this->words();
		$ours = $this->wePostPhotos($words, 3, [], 'album ' . $words);

		$theirs = $this->awaitOnPixelfed($ours);

		$descriptions = array_map(static fn (array $media): string => (string)($media['description'] ?? ''), $theirs['media_attachments'] ?? []);
		$this->assertSame(
			['album ' . $words . ' 1', 'album ' . $words . ' 2', 'album ' . $words . ' 3'],
			$descriptions,
			'the album did not arrive whole and in order'
		);
	}

	/** Hashtags in a caption are what Pixelfed's discovery is built on. */
	public function testCaptionHashtagsArriveAsTags(): void {
		$tag = 'interop' . bin2hex(random_bytes(4));
		$ours = $this->wePostPhotos($this->words() . ' #' . $tag);

		$this->awaitOnPixelfed($ours);
		$tagged = $this->pixelfed->await(function () use ($ours, $tag): ?array {
			foreach ($this->pixelfed->statusesOf($this->ourIdThere) as $status) {
				if (($status['uri'] ?? '') !== $ours['uri']) {
					continue;
				}
				$names = array_map(static fn (array $t): string => strtolower((string)($t['name'] ?? '')), $status['tags'] ?? []);

				return in_array($tag, $names, true) ? $status : null;
			}

			return null;
		});

		$this->assertNotNull($tagged, 'Pixelfed holds the post but not the hashtag #' . $tag);
	}

	/**
	 * A content warning has to arrive as one: Pixelfed blurs a sensitive post
	 * and shows its warning in place of the picture.
	 */
	public function testASensitivePhotoArrivesBehindItsWarning(): void {
		$warning = 'mind how you go ' . bin2hex(random_bytes(3));
		$ours = $this->wePostPhotos($this->words(), 1, ['sensitive' => true, 'spoiler_text' => $warning]);

		$theirs = $this->awaitOnPixelfed($ours);

		$this->assertTrue((bool)($theirs['sensitive'] ?? false), 'the post arrived unmarked');
		$this->assertSame($warning, $theirs['spoiler_text'] ?? '', 'the warning was lost');
	}

	/**
	 * A like from here is counted on the Pixelfed post and tells its author.
	 */
	public function testOurLikeIsCountedAndNotifiedThere(): void {
		$theirs = $this->pixelfedPostsPhotos($this->words());
		$ours = $this->awaitHere($theirs);

		$this->aloha->post('/api/v1/statuses/' . $ours['id'] . '/favourite');
		$this->drainQueue();

		$counted = $this->pixelfed->await(function () use ($theirs): ?bool {
			$this->drainQueue();

			return ((int)($this->pixelfed->status((string)$theirs['id'])['favourites_count'] ?? 0) >= 1) ? true : null;
		});
		$this->assertTrue($counted, 'Pixelfed never counted the like');

		$notified = $this->pixelfed->awaitNotification(fn (array $n): bool => ($n['type'] ?? '') === 'favourite'
			&& (string)($n['account']['id'] ?? '') === $this->ourIdThere
			&& (string)($n['status']['id'] ?? '') === (string)$theirs['id']);
		$this->assertNotNull($notified, 'the author on Pixelfed was never told about the like');
	}

	/** A comment from here is threaded under the Pixelfed post it answers. */
	public function testOurCommentIsThreadedUnderThePixelfedPost(): void {
		$theirs = $this->pixelfedPostsPhotos($this->words());
		$ours = $this->awaitHere($theirs);

		$words = $this->words('comment');
		$comment = $this->aloha->publish([
			'status' => '@' . $this->theirHandle . ' ' . $words,
			'in_reply_to_id' => $ours['id'],
			'visibility' => 'public',
		]);
		$this->drainQueue();

		$threaded = $this->pixelfed->await(function () use ($theirs, $comment): ?array {
			$this->drainQueue();
			foreach ($this->pixelfed->descendants((string)$theirs['id']) as $reply) {
				if (($reply['uri'] ?? '') === $comment['uri']) {
					return $reply;
				}
			}

			return null;
		});

		$this->assertNotNull($threaded, 'the comment never appeared under the Pixelfed post');
		$this->assertStringContainsString($words, (string)($threaded['content'] ?? ''));
		$this->assertSame((string)$theirs['id'], (string)($threaded['in_reply_to_id'] ?? ''));
	}

	/**
	 * A story from here reaches the story bar of a Pixelfed follower.
	 * Pixelfed reads nothing out of the activity: it fetches the story through
	 * the bearcap the activity carries, so this proves the capability, the
	 * route that honours it and the document behind it.
	 *
	 * The picture is 1080 by 1920, the frame Pixelfed's own composer crops
	 * every story to: Pixelfed drops a fetched story of any other size
	 * ("Image dimensions out of range").
	 */
	public function testOurStoryReachesPixelfedsStoryBar(): void {
		$media = $this->aloha->uploadPhoto($this->picture(1080, 1920), 'a story picture');
		$story = $this->aloha->post('/api/v1/stories', ['media_id' => $media, 'caption' => $this->words('story'), 'duration' => 5]);
		$this->assertNotSame('', (string)($story['id'] ?? ''), 'the story was not written: ' . json_encode($story));
		$this->drainQueue();

		$node = 'pfs:' . $this->ourIdThere;
		$shown = $this->pixelfed->await(function () use ($node): ?bool {
			$this->drainQueue();
			foreach ($this->pixelfed->get('/api/v1.2/stories/carousel')['nodes'] ?? [] as $entry) {
				if ((string)($entry['id'] ?? '') === $node && ($entry['stories'] ?? []) !== []) {
					return true;
				}
			}

			return null;
		});

		$this->assertTrue($shown, 'the story never reached the Pixelfed story bar');
	}

	/**
	 * Collections are this app's and Pixelfed's both, but neither federates
	 * them in a way the other reads: Pixelfed's inbox takes `Add` for a story
	 * only, and its collections API lists only collections made on Pixelfed.
	 */
	public function testACollectionIsNotSomethingPixelfedFederates(): void {
		$this->markTestSkipped(
			'Pixelfed does not federate collections: its inbox handles `Add` only for a `Story`,'
			. ' and /api/v1.1/collections/accounts/{id} reads only collections written on that instance'
		);
	}

	/** A new display name and bio reach Pixelfed as an `Update` of the actor. */
	public function testAProfileUpdateReachesPixelfed(): void {
		$name = 'Interop ' . bin2hex(random_bytes(3));
		$bio = 'bio ' . bin2hex(random_bytes(4));
		$this->aloha->patch('/api/v1/accounts/update_credentials', ['display_name' => $name, 'note' => $bio]);
		$this->drainQueue();

		$updated = $this->pixelfed->await(function () use ($name): ?array {
			$this->drainQueue();
			$account = $this->pixelfed->account($this->ourIdThere);

			return (($account['display_name'] ?? '') === $name) ? $account : null;
		});

		$this->assertNotNull($updated, 'Pixelfed still shows the old display name');
		$this->assertStringContainsString($bio, (string)($updated['note'] ?? ''), 'the bio did not come with the name');
	}

	/**
	 * A direct message from here is a direct message there: in the
	 * conversation with us, not on anybody's timeline.
	 */
	public function testADirectMessageArrivesAsADirectMessage(): void {
		$words = $this->words('dm');
		$this->aloha->publish(['status' => '@' . $this->theirHandle . ' ' . $words, 'visibility' => 'direct']);
		$this->drainQueue();

		$message = $this->pixelfed->await(function () use ($words): ?array {
			$this->drainQueue();
			foreach ($this->pixelfed->get('/api/v1.1/direct/thread', ['pid' => $this->ourIdThere])['messages'] ?? [] as $message) {
				if (str_contains((string)($message['text'] ?? ''), $words)) {
					return $message;
				}
			}

			return null;
		});

		$this->assertNotNull($message, 'the direct message never reached the Pixelfed conversation');
		foreach ($this->pixelfed->statusesOf($this->ourIdThere) as $status) {
			$this->assertStringNotContainsString($words, (string)($status['content'] ?? ''), 'the direct message is on our public profile there');
		}
	}

	/** A post deleted here is gone from Pixelfed. */
	public function testADeleteTakesThePostAwayThere(): void {
		$ours = $this->wePostPhotos($this->words());
		$theirs = $this->awaitOnPixelfed($ours);

		$this->aloha->delete('/api/v1/statuses/' . $ours['id']);
		$this->drainQueue();

		$gone = $this->pixelfed->await(function () use ($theirs): ?bool {
			$this->drainQueue();

			return $this->pixelfed->status((string)$theirs['id']) === null ? true : null;
		});
		$this->assertTrue($gone, 'Pixelfed still serves a post deleted here');
	}

	/** Unfollowing from here takes us off the Pixelfed account's followers. */
	public function testOurUnfollowReachesPixelfed(): void {
		$this->aloha->post('/api/v1/accounts/' . $this->theirIdHere . '/unfollow');
		$this->drainQueue();

		$this->assertTrue(
			$this->pixelfed->await(function (): ?bool {
				$this->drainQueue();

				return ($this->pixelfed->relationship($this->ourIdThere)['followed_by'] === false) ? true : null;
			}),
			'Pixelfed still lists ' . $this->ourHandle . ' as a follower'
		);
	}
}
