<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * What a real Pixelfed sends, as this app takes it in.
 *
 * Everything is written on Pixelfed through its own client API, delivered by
 * its own queue workers, and read back here through this app's client API —
 * the same answer the web page would show the reader.
 *
 * Skipped, with a reason, when there is no Pixelfed to talk to: see
 * `tests/Interop/README.md` and `.github/workflows/interop-pixelfed.yml`.
 */
class PixelfedInboundTest extends TestCase {
	use PixelfedPair;

	protected function setUp(): void {
		$this->setUpPair();
	}

	protected function tearDown(): void {
		$this->tearDownPair();
	}

	/** Pixelfed's `Follow` is accepted here, and Pixelfed reads our `Accept`. */
	public function testPixelfedsFollowIsAcceptedHere(): void {
		$this->pixelfed->unfollow($this->ourIdThere);
		$this->assertTrue(
			$this->aloha->await(fn (): ?bool => ($this->aloha->relationship($this->theirIdHere)['followed_by'] === false) ? true : null),
			'this side still lists ' . $this->theirHandle . ' as a follower after the unfollow'
		);
		$this->assertTrue(
			$this->pixelfed->await(fn (): ?bool => ($this->pixelfed->relationship($this->ourIdThere)['following'] === false) ? true : null),
			'Pixelfed still counts the follow it took back'
		);

		$this->pixelfedFollowsUs();

		$this->assertTrue(
			$this->aloha->relationship($this->theirIdHere)['followed_by'] === true,
			'Pixelfed counts the follow as accepted, this side does not list ' . $this->theirHandle . ' as a follower'
		);
	}

	/**
	 * A Pixelfed photo is on the Photos page here, with its picture and its
	 * description.
	 */
	public function testAPixelfedPhotoIsOnOurPhotosTimeline(): void {
		$words = $this->words();
		$theirs = $this->pixelfedPostsPhotos($words, 1, [], 'described ' . $words);

		$ours = $this->awaitHere($theirs, fn (): array => $this->aloha->photos());

		$this->assertStringContainsString($words, (string)($ours['content'] ?? ''));
		$media = $ours['media_attachments'] ?? [];
		$this->assertCount(1, $media, 'the picture did not come with the post');
		$this->assertSame('image', $media[0]['type'] ?? '');
		$this->assertNotSame('', (string)($media[0]['url'] ?? ''), 'the picture has nothing to show');
		$this->assertSame('described ' . $words . ' 1', $media[0]['description'] ?? null, 'the description was lost');
	}

	/** A Pixelfed album arrives as one post with every picture, in order. */
	public function testAPixelfedAlbumArrivesWithEveryPicture(): void {
		$words = $this->words();
		$theirs = $this->pixelfedPostsPhotos($words, 3, [], 'album ' . $words);

		$ours = $this->awaitHere($theirs, fn (): array => $this->aloha->photos());

		$descriptions = array_map(static fn (array $media): string => (string)($media['description'] ?? ''), $ours['media_attachments'] ?? []);
		$this->assertSame(
			['album ' . $words . ' 1', 'album ' . $words . ' 2', 'album ' . $words . ' 3'],
			$descriptions,
			'the album did not arrive whole and in order'
		);
	}

	/** Hashtags in a Pixelfed caption are tags here. */
	public function testPixelfedHashtagsArriveAsTags(): void {
		$tag = 'interop' . bin2hex(random_bytes(4));
		$theirs = $this->pixelfedPostsPhotos($this->words() . ' #' . $tag);

		$ours = $this->awaitHere($theirs);

		$names = array_map(static fn (array $t): string => strtolower((string)($t['name'] ?? '')), $ours['tags'] ?? []);
		$this->assertContains($tag, $names, 'the post arrived without its hashtag');
	}

	/** A Pixelfed post behind a warning arrives behind the same warning. */
	public function testAPixelfedSensitivePostArrivesBehindItsWarning(): void {
		$warning = 'mind how you go ' . bin2hex(random_bytes(3));
		$theirs = $this->pixelfedPostsPhotos($this->words(), 1, ['sensitive' => true, 'spoiler_text' => $warning]);
		$this->assertTrue((bool)($theirs['sensitive'] ?? false), 'Pixelfed did not mark its own post');

		$ours = $this->awaitHere($theirs);

		$this->assertTrue((bool)($ours['sensitive'] ?? false), 'the post arrived unmarked');
		$this->assertSame($warning, $ours['spoiler_text'] ?? '', 'the warning was lost');
	}

	/** A like on Pixelfed is counted here and tells the author. */
	public function testAPixelfedLikeIsCountedAndNotifiedHere(): void {
		$ours = $this->wePostPhotos($this->words());
		$theirs = $this->awaitOnPixelfed($ours);

		$this->pixelfed->favourite((string)$theirs['id']);

		$counted = $this->aloha->await(
			fn (): ?bool => ((int)($this->aloha->status((string)$ours['id'])['favourites_count'] ?? 0) >= 1) ? true : null
		);
		$this->assertTrue($counted, 'the like from Pixelfed was never counted here');

		$notified = $this->aloha->awaitNotification(fn (array $n): bool => ($n['type'] ?? '') === 'favourite'
			&& (string)($n['account']['id'] ?? '') === $this->theirIdHere
			&& ($n['status']['uri'] ?? '') === $ours['uri']);
		$this->assertNotNull($notified, 'the like from Pixelfed never made a notification');
	}

	/** A Pixelfed comment is threaded under our post. */
	public function testAPixelfedCommentIsThreadedUnderOurPost(): void {
		$ours = $this->wePostPhotos($this->words());
		$theirs = $this->awaitOnPixelfed($ours);

		$words = $this->words('comment');
		$comment = $this->pixelfed->publish(['status' => $words, 'in_reply_to_id' => (string)$theirs['id']]);

		$threaded = $this->aloha->await(function () use ($ours, $comment): ?array {
			foreach ($this->aloha->descendants((string)$ours['id']) as $reply) {
				if (($reply['uri'] ?? '') === $comment['uri']) {
					return $reply;
				}
			}

			return null;
		});

		$this->assertNotNull($threaded, 'the comment from Pixelfed never appeared under our post');
		$this->assertStringContainsString($words, (string)($threaded['content'] ?? ''));
		$this->assertSame((string)$ours['id'], (string)($threaded['in_reply_to_id'] ?? ''));
	}

	/**
	 * Pixelfed delivers stories to Pixelfed servers only.
	 */
	public function testAPixelfedStoryReachesOurStoryBar(): void {
		$this->markTestSkipped(
			'Pixelfed fans a story out only to followers on servers whose nodeinfo says "pixelfed"'
			. ' (StoryFanout: FollowerService::softwareAudience($id, \'pixelfed\')), so it never sends one here'
		);
	}

	/**
	 * Pixelfed sends no `Update` when a profile is edited; its administrators
	 * send every local actor to one server with `ap:update-actors`. That is
	 * the one way a Pixelfed profile change is ever pushed, so it is the one
	 * tested — through `docker exec`, which only the CI job can do.
	 */
	public function testAPixelfedProfileUpdateReachesUs(): void {
		$container = (string)getenv('PIXELFED_CONTAINER');
		if ($container === '') {
			$this->markTestSkipped('set PIXELFED_CONTAINER to the Pixelfed container to run its ap:update-actors');
		}

		$name = 'Pixelfed ' . bin2hex(random_bytes(3));
		$this->pixelfed->patch('/api/v1/accounts/update_credentials', ['display_name' => $name]);

		$host = (string)parse_url((string)getenv('NEXTCLOUD_URL'), PHP_URL_HOST);
		$process = proc_open(
			['docker', 'exec', '-i', $container, 'php', 'artisan', 'ap:update-actors', '--force'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		$this->assertIsResource($process);
		fwrite($pipes[0], "Send updates to an instance\n" . $host . "\nyes\n");
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		$this->assertSame(0, proc_close($process), 'ap:update-actors failed: ' . $output);

		$updated = $this->aloha->await(
			fn (): ?array => (($account = $this->aloha->get('/api/v1/accounts/' . $this->theirIdHere))['display_name'] ?? '') === $name ? $account : null
		);
		$this->assertNotNull($updated, 'this side still shows the old Pixelfed display name. ap:update-actors said: ' . $output);
	}

	/** A Pixelfed direct message is a direct message here. */
	public function testAPixelfedDirectMessageArrivesAsDirect(): void {
		$words = $this->words('dm');
		$this->pixelfed->post('/api/v1.1/direct/thread/send', ['to_id' => $this->ourIdThere, 'message' => $words, 'type' => 'text']);

		$message = $this->aloha->await(function () use ($words): ?array {
			foreach ($this->aloha->timeline('direct') as $status) {
				if (str_contains((string)($status['content'] ?? ''), $words)) {
					return $status;
				}
			}

			return null;
		});

		$this->assertNotNull($message, 'the direct message from Pixelfed never arrived');
		$this->assertSame('direct', $message['visibility'] ?? '', 'the direct message arrived as something else');
		foreach ($this->aloha->timeline('home') as $status) {
			if (($status['visibility'] ?? '') !== 'direct') {
				$this->assertStringNotContainsString($words, (string)($status['content'] ?? ''), 'the direct message is on the home timeline as a post');
			}
		}
	}

	/** A post deleted on Pixelfed is gone here. */
	public function testAPixelfedDeleteTakesThePostAwayHere(): void {
		$theirs = $this->pixelfedPostsPhotos($this->words());
		$ours = $this->awaitHere($theirs);

		$this->pixelfed->deleteStatus((string)$theirs['id']);

		$gone = $this->aloha->await(fn (): ?bool => $this->aloha->status((string)$ours['id']) === null ? true : null);
		$this->assertTrue($gone, 'this side still serves a post deleted on Pixelfed');
	}

	/** Pixelfed unfollowing takes it off our followers. */
	public function testAPixelfedUnfollowReachesUs(): void {
		$this->pixelfed->unfollow($this->ourIdThere);

		$this->assertTrue(
			$this->aloha->await(fn (): ?bool => ($this->aloha->relationship($this->theirIdHere)['followed_by'] === false) ? true : null),
			'this side still lists ' . $this->theirHandle . ' as a follower'
		);
	}
}
