<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamService;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * This app's activities, delivered to a real Mastodon and read back out of it.
 *
 * The Pixelfed work found three silent drops by running our payloads through
 * Pixelfed's own validators; nothing equivalent had ever been done for
 * Mastodon, and Mastodon is the implementation almost everybody on the other
 * end is running. A unit test can prove this app emits the document it meant
 * to. Only this can prove Mastodon *accepts* it — the difference between the
 * two being exactly where a silent drop lives.
 *
 * **Nothing is stubbed.** The post is written through `PostService`, the
 * delivery is the real queue signed by the real signer, and what is asserted is
 * what Mastodon's own API answers — not a row in its database, because a row
 * its serialiser refuses to render has not arrived either.
 *
 * **Mastodon follows us first.** A post reaches an instance because somebody
 * there follows its author; making Mastodon follow our account is therefore
 * both the setup and the first assertion, since Mastodon will only follow an
 * actor whose document it could fetch and understand.
 *
 * Skipped, with a reason, when there is no Mastodon to talk to: see
 * `tests/Interop/README.md` and `.github/workflows/interop-mastodon.yml`.
 */
class MastodonDeliveryTest extends TestCase {
	private Mastodon $mastodon;
	private Person $actor;
	private string $handle = '';
	private string $accountId = '';

	/** @var string[] the posts this test wrote, removed at the end */
	private array $written = [];

	protected function setUp(): void {
		$mastodon = Mastodon::fromEnvironment();
		if ($mastodon === null) {
			$this->markTestSkipped(
				'no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN'
			);
		}

		$this->mastodon = $mastodon;
		$this->actor = $this->localActor();
		$this->handle = '@' . $this->actor->getPreferredUsername() . '@' . $this->cloudHost();

		$this->accountId = $this->mastodon->resolveAccount($this->handle);
		$this->mastodon->follow($this->accountId);
		$this->awaitFollower();
	}

	protected function tearDown(): void {
		$streamRequest = Server::get(StreamRequest::class);
		foreach ($this->written as $id) {
			try {
				$streamRequest->deleteById($id);
			} catch (\Throwable $e) {
				// the test that deleted it got there first
			}
		}
	}

	/**
	 * A `Create` is the one that matters most: if this fails, nothing anybody
	 * writes here is ever read anywhere else.
	 */
	public function testAPublicPostArrivesAsAStatus(): void {
		$words = 'interop ' . bin2hex(random_bytes(4));
		$post = $this->publish($words);

		$status = $this->mastodon->awaitStatus($this->accountId, $post->getId());

		$this->assertNotNull($status, 'the post never reached Mastodon');
		$this->assertStringContainsString($words, (string)($status['content'] ?? ''));
		$this->assertSame('public', $status['visibility'] ?? '');
		$this->assertFalse((bool)($status['sensitive'] ?? true));
	}

	/**
	 * A content warning is the field most likely to be dropped quietly: it
	 * travels as `summary`, which several implementations use for something
	 * else entirely.
	 */
	public function testAContentWarningArrivesAsASpoiler(): void {
		$words = 'interop ' . bin2hex(random_bytes(4));
		$post = $this->publish($words, static function (Post $post): void {
			$post->setSpoilerText('mind how you go');
			$post->setSensitive(true);
		});

		$status = $this->mastodon->awaitStatus($this->accountId, $post->getId());

		$this->assertNotNull($status, 'the post never reached Mastodon');
		$this->assertSame('mind how you go', $status['spoiler_text'] ?? '');
		$this->assertTrue((bool)($status['sensitive'] ?? false));
	}

	/**
	 * An `Update` has to carry `updated`, or Mastodon takes the edit in and
	 * shows the words that were replaced — the defect that shipped once and is
	 * invisible from this side.
	 */
	public function testAnEditReachesMastodonAsAnEdit(): void {
		$words = 'interop ' . bin2hex(random_bytes(4));
		$post = $this->publish($words);
		$this->assertNotNull(
			$this->mastodon->awaitStatus($this->accountId, $post->getId()),
			'the post never reached Mastodon'
		);

		$changed = $words . ' (corrected)';
		Server::get(PostService::class)->editPost($post->getNid(), $this->actor, $changed);
		$this->drainQueue();

		$edited = $this->mastodon->await(function () use ($post, $changed): ?array {
			$status = $this->mastodon->awaitStatus($this->accountId, $post->getId());
			if ($status === null || !str_contains((string)($status['content'] ?? ''), $changed)) {
				return null;
			}

			return $status;
		});

		$this->assertNotNull($edited, 'Mastodon is still showing the words that were replaced');
		$this->assertNotNull($edited['edited_at'] ?? null, 'the edit arrived without a date on it');
	}

	/**
	 * A `Delete` that a peer ignores is a post the author believes is gone and
	 * everybody else can still read.
	 */
	public function testADeleteTakesThePostAwayThere(): void {
		$post = $this->publish('interop ' . bin2hex(random_bytes(4)));
		$this->assertNotNull(
			$this->mastodon->awaitStatus($this->accountId, $post->getId()),
			'the post never reached Mastodon'
		);

		Server::get(StreamService::class)->deleteLocalItem($post);
		$this->drainQueue();

		$this->assertTrue(
			$this->mastodon->awaitStatusGone($this->accountId, $post->getId()),
			'Mastodon is still showing a deleted post'
		);
	}

	/**
	 * A boost of a post Mastodon already holds: the `Announce` has to name it
	 * in a way Mastodon can resolve, or the boost arrives pointing at nothing
	 * and is dropped.
	 */
	public function testABoostArrivesAsAReblog(): void {
		$post = $this->publish('interop ' . bin2hex(random_bytes(4)));
		$this->assertNotNull(
			$this->mastodon->awaitStatus($this->accountId, $post->getId()),
			'the post never reached Mastodon'
		);

		$token = '';
		Server::get(BoostService::class)->create($this->actor, $post->getId(), $token);
		$this->drainQueue();

		$reblog = $this->mastodon->await(function () use ($post): ?array {
			foreach ($this->mastodon->statusesOf($this->accountId) as $status) {
				$of = $status['reblog']['uri'] ?? '';
				if ($of === $post->getId()) {
					return $status;
				}
			}

			return null;
		});

		$this->assertNotNull($reblog, 'the boost never reached Mastodon');
	}

	// --- the harness ------------------------------------------------------

	/**
	 * Writes a post as our actor and delivers it, synchronously.
	 *
	 * @param callable(Post): void|null $shape anything else to set on it
	 */
	private function publish(string $words, ?callable $shape = null): Stream {
		$post = new Post($this->actor);
		$post->setContent($words);
		$post->setType(Stream::TYPE_PUBLIC);
		if ($shape !== null) {
			$shape($post);
		}

		$token = '';
		// the Create activity comes back; the note is read back the way the
		// status API reads it, which also gives it its nid
		$activity = Server::get(PostService::class)->createPost($post, $token);
		$this->assertNotNull($activity, 'the post was not written');
		$note = Server::get(StreamService::class)->getStreamById($activity->getObjectId());
		$this->assertInstanceOf(Stream::class, $note, 'the post was not written');

		$this->written[] = $note->getId();
		$this->drainQueue();

		return $note;
	}

	/**
	 * Sends whatever is waiting, here and now.
	 *
	 * The queue is drained by cron on a real instance; a test that waited for
	 * cron would be a test that waits for five minutes, and one that skipped
	 * the queue would not be testing the delivery path at all.
	 */
	private function drainQueue(): void {
		$queueService = Server::get(RequestQueueService::class);
		$activityService = Server::get(ActivityService::class);
		$activityService->manageInit();

		$total = 0;
		foreach ($queueService->getRequestStandby($total) as $request) {
			/** @var RequestQueue $request */
			$activityService->manageRequest($request);
		}
	}

	/** Waits for Mastodon's Follow to have arrived and been accepted here. */
	private function awaitFollower(): void {
		$followsRequest = Server::get(FollowsRequest::class);
		$arrived = $this->mastodon->await(function () use ($followsRequest): ?bool {
			// the fan-out reads accepted follows, so an unanswered one is a
			// post that goes nowhere — this waits for the acceptance too
			return ($followsRequest->countFollowers($this->actor->getId()) > 0) ? true : null;
		});

		$this->assertTrue($arrived, 'Mastodon never managed to follow this account');
		$this->drainQueue();
	}

	/**
	 * The account this instance posts as.
	 *
	 * Built through the real `AccountService` where it can be, because what is
	 * being proved includes that the actor document this app publishes is one
	 * Mastodon will accept — a hand-made row would prove nothing about that.
	 */
	private function localActor(): Person {
		$users = Server::get(IUserManager::class)->search('', 1);
		if ($users === []) {
			$this->markTestSkipped('no account on this instance to post as');
		}

		// the way the app makes an account, so the actor is cached too:
		// webfinger and the actor document are answered from the cache
		return Server::get(AccountService::class)->getActorFromUserId((string)array_key_first($users), true);
	}

	/** `host[:port]`, as a handle has to name a server on a non-default port. */
	private function cloudHost(): string {
		return Server::get(ConfigService::class)->getCloudAuthority();
	}
}
