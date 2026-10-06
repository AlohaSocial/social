<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * A real PeerTube's videos, followed from here, and the conversation around
 * them in both directions.
 *
 * PeerTube's `interop` account uploads a real video file to its default
 * channel, which our `admin` follows; what is asserted is what the client
 * API here shows of it, and what PeerTube's own REST API shows of what is
 * done to it from here — a comment, a like, a reply to a reply.
 *
 * Skipped, with a reason, without a PeerTube or without the sample video the
 * workflow makes with PeerTube's own ffmpeg.
 */
class PeerTubeInboundTest extends TestCase {
	use LocalSide;

	private const USER = 'admin';
	/** follows and unfollows the channel, so the others keep following it */
	private const WATCHER = 'watcher';

	private PeerTube $peertube;
	private Here $here;
	private string $sample = '';
	private string $channelName = '';
	/** our id for PeerTube's channel */
	private string $channelHere = '';

	protected function setUp(): void {
		$peertube = PeerTube::fromEnvironment();
		if ($peertube === null) {
			$this->markTestSkipped('no PeerTube to talk to: set PEERTUBE_BASE_URL, PEERTUBE_USER and PEERTUBE_PASSWORD');
		}
		$this->sample = (string)getenv('PEERTUBE_SAMPLE_VIDEO');
		if ($this->sample === '' || !is_file($this->sample)) {
			$this->markTestSkipped('no video to upload to PeerTube: set PEERTUBE_SAMPLE_VIDEO to an mp4 file');
		}

		$this->peertube = $peertube;
		$this->channelName = (string)getenv('PEERTUBE_USER') . '_channel';
		$this->here = Here::forUser(self::USER);
		$this->channelHere = $this->here->resolveAccount($this->channelHandle());

		$this->here->follow($this->channelHere);
		$this->drainQueue();
		$this->assertTrue(
			$this->here->awaitRelationship($this->channelHere, 'following', true, 60),
			'PeerTube never accepted our follow of ' . $this->channelHandle()
		);
	}

	/** The title, the description, the duration and the poster, all of it. */
	public function testAVideoArrivesOnOurVideosTimeline(): void {
		$title = $this->unique('video');
		$description = $this->unique('described');
		$video = $this->upload($title, $description);

		$status = $this->awaitVideoHere($video['uuid']);

		$this->assertNotNull($status, 'the video never reached the videos timeline of a follower here: ' . $this->whatIsHere($video['uuid']));
		$this->assertStringContainsString($title, (string)$status['content'], 'the title was lost');
		$this->assertStringContainsString($description, (string)$status['content'], 'the description was lost');
		$attachment = $status['media_attachments'][0] ?? null;
		$this->assertIsArray($attachment, 'the video arrived with nothing to play');
		$this->assertSame('video', $attachment['type'] ?? '');
		$this->assertNotSame('', (string)($attachment['preview_url'] ?? ''), 'the thumbnail was lost');
		$this->assertGreaterThan(0, (int)($status['video']['duration'] ?? 0), 'the duration was lost');
	}

	public function testOurCommentArrivesAsAPeerTubeComment(): void {
		$video = $this->upload($this->unique('video'), $this->unique());
		$status = $this->awaitVideoHere($video['uuid']);
		$this->assertNotNull($status, 'the video never reached this side');

		$words = $this->unique('comment');
		$this->here->publish($words, ['visibility' => 'public', 'in_reply_to_id' => $status['id']]);
		$this->drainQueue();

		$this->assertNotNull(
			$this->peertube->awaitComment($video['uuid'], $words),
			'the reply written here is not a comment on the PeerTube video'
		);
	}

	/** PeerTube's answer to a comment written here reaches its author as a notification. */
	public function testAReplyOnPeerTubeNotifiesUs(): void {
		$video = $this->upload($this->unique('video'), $this->unique());
		$status = $this->awaitVideoHere($video['uuid']);
		$this->assertNotNull($status, 'the video never reached this side');

		$words = $this->unique('comment');
		$this->here->publish($words, ['visibility' => 'public', 'in_reply_to_id' => $status['id']]);
		$this->drainQueue();
		$comment = $this->peertube->awaitComment($video['uuid'], $words);
		$this->assertNotNull($comment, 'the reply written here is not a comment on the PeerTube video');

		$answer = $this->unique('answer');
		$this->peertube->replyToComment($video['uuid'], (int)$comment['id'], $this->localHandle(self::USER) . ' ' . $answer);

		$notification = $this->here->await(function () use ($answer): ?array {
			foreach ($this->here->notifications() as $notification) {
				if (str_contains((string)($notification['status']['content'] ?? ''), $answer)) {
					return $notification;
				}
			}

			return null;
		}, 60);
		$this->assertNotNull($notification, 'the answer on PeerTube raised no notification here');
	}

	public function testOurLikeIsCountedThere(): void {
		$video = $this->upload($this->unique('video'), $this->unique());
		$status = $this->awaitVideoHere($video['uuid']);
		$this->assertNotNull($status, 'the video never reached this side');

		$this->here->post('/api/v1/statuses/' . $status['id'] . '/favourite');
		$this->drainQueue();

		$this->assertNotNull(
			$this->peertube->await(fn (): ?bool => ((int)($this->peertube->video($video['uuid'])['likes'] ?? 0) >= 1) ? true : null),
			'the like given here is not counted on PeerTube'
		);
	}

	/** PeerTube's edit of the video is the video here too. */
	public function testAnEditOnPeerTubeIsApplied(): void {
		$video = $this->upload($this->unique('video'), $this->unique());
		$status = $this->awaitVideoHere($video['uuid']);
		$this->assertNotNull($status, 'the video never reached this side');

		$renamed = $this->unique('renamed');
		$this->peertube->put('/api/v1/videos/' . $video['uuid'], ['name' => $renamed]);

		$this->assertNotNull(
			$this->here->awaitStatusThat(
				(string)$status['id'],
				static fn (array $now): bool => str_contains((string)($now['content'] ?? ''), $renamed),
				90
			),
			'the video was renamed on PeerTube and is still shown here under its old title'
		);
	}

	/** A video deleted on PeerTube is gone here as well. */
	public function testADeleteOnPeerTubeRemovesTheVideo(): void {
		$video = $this->upload($this->unique('video'), $this->unique());
		$status = $this->awaitVideoHere($video['uuid']);
		$this->assertNotNull($status, 'the video never reached this side');

		$this->peertube->delete('/api/v1/videos/' . $video['uuid']);

		$this->assertTrue(
			$this->here->await(fn (): ?bool => ($this->here->status((string)$status['id']) === null) ? true : null, 90) === true,
			'the video deleted on PeerTube can still be read here'
		);
	}

	/** Following a channel puts us among its followers there; unfollowing takes us out. */
	public function testFollowingAndUnfollowingAChannel(): void {
		$watcher = Here::forUser(self::WATCHER);
		$channelForWatcher = $watcher->resolveAccount($this->channelHandle());
		$watcherId = $this->localActorId(self::WATCHER);

		$watcher->follow($channelForWatcher);
		$this->drainQueue();
		$this->assertTrue(
			$watcher->awaitRelationship($channelForWatcher, 'following', true, 60),
			'PeerTube never accepted the follow of its channel'
		);
		$this->assertNotNull(
			$this->peertube->await(fn (): ?bool => in_array($watcherId, $this->peertube->channelFollowers($this->channelName), true) ? true : null),
			'PeerTube does not list our account among the channel\'s followers'
		);

		$watcher->unfollow($channelForWatcher);
		$this->drainQueue();
		$this->assertNotNull(
			$this->peertube->await(fn (): ?bool => in_array($watcherId, $this->peertube->channelFollowers($this->channelName), true) ? null : true),
			'PeerTube still lists our account among the channel\'s followers after the unfollow'
		);
	}

	// --- the harness ------------------------------------------------------

	/** What this side holds of a PeerTube video, for a failure message. */
	private function whatIsHere(string $uuid): string {
		$uri = rtrim((string)getenv('PEERTUBE_BASE_URL'), '/') . '/videos/watch/' . $uuid;
		$home = array_map(
			static fn (array $s): string => (string)($s['uri'] ?? '') . ' -> ' . (string)($s['reblog']['uri'] ?? ''),
			array_slice($this->here->timeline('home'), 0, 5)
		);
		$found = $this->here->resolveStatus($uri);

		return json_encode([
			'stored' => ($found === null) ? null : [
				'id' => $found['id'] ?? null,
				'visibility' => $found['visibility'] ?? null,
				'account' => $found['account']['acct'] ?? null,
				'media' => array_map(static fn (array $m): string => (string)($m['type'] ?? ''), $found['media_attachments'] ?? []),
			],
			'home' => $home,
		], JSON_UNESCAPED_SLASHES) ?: '';
	}

	private function channelHandle(): string {
		$host = (string)getenv('PEERTUBE_HOST');
		if ($host === '') {
			$host = (string)parse_url((string)getenv('PEERTUBE_BASE_URL'), PHP_URL_HOST);
		}

		return $this->channelName . '@' . $host;
	}

	/** @return array{id: int, uuid: string, shortUUID: string} */
	private function upload(string $title, string $description): array {
		$channel = $this->peertube->channel($this->channelName);

		return $this->peertube->uploadVideo((int)$channel['id'], $this->sample, $title, $description);
	}

	/**
	 * Waits for a PeerTube video on the videos timeline here.
	 *
	 * @return array<string, mixed>|null
	 */
	private function awaitVideoHere(string $uuid): ?array {
		$uri = rtrim((string)getenv('PEERTUBE_BASE_URL'), '/') . '/videos/watch/' . $uuid;

		return $this->here->await(function () use ($uri): ?array {
			foreach ($this->here->timeline('home', ['only_video' => 'true']) as $status) {
				// a channel announces its videos to its followers, so the video
				// may be on the timeline as the channel's boost of it
				foreach ([$status, $status['reblog'] ?? null] as $candidate) {
					if (is_array($candidate) && ($candidate['uri'] ?? '') === $uri) {
						return $candidate;
					}
				}
			}

			return null;
		}, 90);
	}
}
