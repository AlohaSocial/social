<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Service\DocumentService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What a real Mastodon sends here, read back through this app's own client
 * API.
 *
 * The other direction from `MastodonDeliveryTest`: Mastodon's `interop`
 * account writes, edits, deletes, favourites, boosts, votes, follows and
 * blocks, through Mastodon's API, and Mastodon's workers deliver it the way
 * they deliver to any server. What is asserted is what our `admin` sees
 * through the API a phone app would read — the home timeline, a status, the
 * notifications — because an activity that was taken in and stored in a form
 * the client API will not show has not arrived for anybody.
 *
 * `admin` follows `interop` first, which is how a post of theirs reaches this
 * server at all. That makes the follow, and Mastodon's `Accept` of it, both
 * the setup and the first assertion.
 */
class MastodonInboundTest extends TestCase {
	use LocalSide;

	private const USER = 'admin';
	private const BYSTANDER = 'bystander';

	private Mastodon $mastodon;
	private Here $here;
	/** our id for Mastodon's `interop` */
	private string $interopHere = '';
	/** Mastodon's id for our `admin` */
	private string $adminThere = '';

	protected function setUp(): void {
		$mastodon = Mastodon::fromEnvironment();
		if ($mastodon === null) {
			$this->markTestSkipped('no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN');
		}

		$this->mastodon = $mastodon;
		$this->here = Here::forUser(self::USER);
		$this->interopHere = $this->here->resolveAccount($this->mastodon->handle());
		$this->adminThere = $this->mastodon->resolveAccount($this->localHandle(self::USER));

		$this->here->follow($this->interopHere);
		$this->drainQueue();
		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'following', true),
			'Mastodon never accepted our follow of ' . $this->mastodon->handle()
		);
	}

	public function testAPostArrivesOnTheHomeTimeline(): void {
		$words = $this->unique();
		$sent = $this->mastodon->publish($words, ['visibility' => 'public']);

		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);

		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');
		$this->assertStringContainsString($words, (string)$status['content']);
		$this->assertSame('public', $status['visibility'] ?? '');
		$this->assertSame(strtolower($this->mastodon->handle()), strtolower((string)($status['account']['acct'] ?? '')));
	}

	/** A content warning travels as `summary`, the field most easily dropped. */
	public function testAContentWarningArrivesAsOne(): void {
		$sent = $this->mastodon->publish($this->unique(), [
			'visibility' => 'public',
			'spoiler_text' => 'mind how you go',
			'sensitive' => true,
		]);

		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);

		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');
		$this->assertSame('mind how you go', $status['spoiler_text'] ?? '');
		$this->assertTrue((bool)($status['sensitive'] ?? false), 'the post is no longer marked sensitive');
	}

	/** An `Update` that is taken in and not applied leaves the replaced words on show. */
	public function testAnEditIsApplied(): void {
		$words = $this->unique();
		$sent = $this->mastodon->publish($words, ['visibility' => 'public']);
		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');

		$changed = $words . ' corrected';
		$this->mastodon->put('/api/v1/statuses/' . $sent['id'], ['status' => $changed]);

		$edited = $this->here->awaitStatusThat(
			(string)$status['id'],
			static fn (array $now): bool => str_contains((string)($now['content'] ?? ''), 'corrected')
		);
		$this->assertNotNull($edited, 'this side still shows the words that were replaced on Mastodon');
	}

	/** A `Delete` that is ignored is a post its author believes is gone. */
	public function testADeleteRemovesThePost(): void {
		$sent = $this->mastodon->publish($this->unique(), ['visibility' => 'public']);
		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');

		$this->mastodon->delete('/api/v1/statuses/' . $sent['id']);

		$this->assertTrue(
			$this->here->await(fn (): ?bool => ($this->here->status((string)$status['id']) === null) ? true : null) === true,
			'the post deleted on Mastodon can still be read here'
		);
		$this->assertNull(
			ClientApi::findByUri($this->here->timeline('home'), (string)$sent['uri']),
			'the post deleted on Mastodon is still on the home timeline'
		);
	}

	public function testAMentionOfOurUserNotifiesThem(): void {
		$words = $this->unique();
		$sent = $this->mastodon->publish($this->localHandle(self::USER) . ' ' . $words, ['visibility' => 'public']);

		$notification = $this->here->awaitNotification(
			'mention',
			static fn (array $n): bool => ($n['status']['uri'] ?? '') === $sent['uri']
		);

		$this->assertNotNull($notification, 'a mention from Mastodon raised no notification here');
		$this->assertSame(strtolower($this->mastodon->handle()), strtolower((string)($notification['account']['acct'] ?? '')));
	}

	/**
	 * A direct message is read by the account it was written to and by nobody
	 * else: not on a public timeline, and not by another account here.
	 */
	public function testADirectMessageStaysDirect(): void {
		$words = $this->unique();
		$sent = $this->mastodon->publish($this->localHandle(self::USER) . ' ' . $words, ['visibility' => 'direct']);

		$notification = $this->here->awaitNotification(
			'mention',
			static fn (array $n): bool => ($n['status']['uri'] ?? '') === $sent['uri']
		);
		$this->assertNotNull($notification, 'the direct message never reached the account it was written to');
		$this->assertSame('direct', $notification['status']['visibility'] ?? '');

		$this->assertNull(
			ClientApi::findByUri($this->here->timeline('public'), (string)$sent['uri']),
			'a direct message is on the public timeline'
		);
		$this->assertNull(
			Here::forUser(self::BYSTANDER)->status((string)$notification['status']['id']),
			'another account here can read a direct message that was not written to it'
		);
	}

	/** A reply from Mastodon to a post written here threads under it. */
	public function testAReplyThreadsUnderOurPost(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->mastodon->awaitResolvedStatus((string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'Mastodon could not fetch the post written here');

		$sent = $this->mastodon->publish($this->unique('reply'), [
			'visibility' => 'public',
			'in_reply_to_id' => $theirCopy['id'],
		]);

		$reply = $this->here->await(function () use ($ours, $sent): ?array {
			$context = $this->here->get('/api/v1/statuses/' . $ours['id'] . '/context');

			return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], (string)$sent['uri']);
		});

		$this->assertNotNull($reply, 'the reply from Mastodon is not in the thread of the post it answers');
		$this->assertSame((string)$ours['id'], (string)($reply['in_reply_to_id'] ?? ''));
	}

	public function testAFavouriteIsCountedAndNotified(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->mastodon->awaitResolvedStatus((string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'Mastodon could not fetch the post written here');

		$this->mastodon->post('/api/v1/statuses/' . $theirCopy['id'] . '/favourite');

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['favourites_count'] ?? 0) >= 1),
			'the favourite from Mastodon was not counted'
		);
		$by = array_map(
			static fn (array $account): string => strtolower((string)($account['acct'] ?? '')),
			array_filter($this->here->get('/api/v1/statuses/' . $ours['id'] . '/favourited_by'), 'is_array')
		);
		$this->assertContains(strtolower($this->mastodon->handle()), $by, 'the favourite is not credited to who gave it');
		$this->assertNotNull(
			$this->here->awaitNotification('favourite', static fn (array $n): bool => (string)($n['status']['id'] ?? '') === (string)$ours['id']),
			'the favourite raised no notification'
		);
	}

	public function testABoostIsCountedAndNotified(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->mastodon->awaitResolvedStatus((string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'Mastodon could not fetch the post written here');

		$this->mastodon->post('/api/v1/statuses/' . $theirCopy['id'] . '/reblog');

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['reblogs_count'] ?? 0) >= 1),
			'the boost from Mastodon was not counted'
		);
		$this->assertNotNull(
			$this->here->awaitNotification('reblog', static fn (array $n): bool => (string)($n['status']['id'] ?? '') === (string)$ours['id']),
			'the boost raised no notification'
		);
	}

	/** A poll from Mastodon can be read here, and a vote from here is counted there. */
	public function testAPollCanBeReadAndOurVoteCountsThere(): void {
		$sent = $this->mastodon->publish($this->unique('poll'), [
			'visibility' => 'public',
			'poll' => ['options' => ['tea', 'coffee'], 'expires_in' => 3600],
		]);

		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($status, 'the poll never reached the home timeline of a follower here');
		$poll = $status['poll'] ?? null;
		$this->assertIsArray($poll, 'the poll arrived without its options');
		$this->assertSame(
			['tea', 'coffee'],
			array_map(static fn (array $option): string => (string)$option['title'], $poll['options'] ?? [])
		);

		$this->here->post('/api/v1/polls/' . $poll['id'] . '/votes', ['choices' => [1]]);
		$this->drainQueue();

		$counted = $this->mastodon->await(function () use ($sent): ?array {
			$there = $this->mastodon->get('/api/v1/polls/' . $sent['poll']['id']);

			return ((int)($there['options'][1]['votes_count'] ?? 0) === 1) ? $there : null;
		});
		$this->assertNotNull($counted, 'the vote cast here was not counted on Mastodon');
		$this->assertSame(0, (int)($counted['options'][0]['votes_count'] ?? -1), 'the vote went to the wrong option');
	}

	/** The description of a picture is what a screen reader reads out. */
	public function testAPictureArrivesWithItsDescription(): void {
		$mediaId = $this->mastodon->uploadMedia($this->picture(), 'image/png', 'a red square, for the record');
		$sent = $this->mastodon->publish($this->unique(), ['visibility' => 'public', 'media_ids' => [$mediaId]]);

		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);

		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');
		$attachment = $status['media_attachments'][0] ?? null;
		$this->assertIsArray($attachment, 'the picture was dropped');
		$this->assertSame('image', $attachment['type'] ?? '');
		$this->assertSame('a red square, for the record', $attachment['description'] ?? '');
	}

	public function testAHashtagPostIsOnTheTagTimeline(): void {
		$tag = 'interop' . bin2hex(random_bytes(3));
		$sent = $this->mastodon->publish($this->unique() . ' #' . $tag, ['visibility' => 'public']);

		$this->assertNotNull(
			$this->here->awaitOnTimeline('tag/' . $tag, (string)$sent['uri']),
			'a post tagged #' . $tag . ' on Mastodon is not on that tag\'s timeline here'
		);
	}

	public function testAQuoteArrivesAsAQuote(): void {
		$version = (string)($this->mastodon->get('/api/v1/instance')['version'] ?? '');
		if (version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?? '', '4.4', '<')) {
			$this->markTestSkipped('Mastodon ' . $version . ' cannot write a quote: quote posts arrived in Mastodon 4.4');
		}

		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->mastodon->awaitResolvedStatus((string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'Mastodon could not fetch the post written here');

		$sent = $this->mastodon->publish($this->unique('quote'), ['visibility' => 'public', 'quoted_status_id' => $theirCopy['id']]);
		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);

		$this->assertNotNull($status, 'the quote never reached the home timeline of a follower here');
		$this->assertSame((string)$ours['uri'], (string)($status['quote']['quoted_status']['uri'] ?? $status['quote']['uri'] ?? ''));
	}

	/** An `Update` of the actor refreshes the copy of it held here. */
	public function testAProfileUpdateRefreshesOurCopy(): void {
		$before = $this->here->account($this->interopHere);
		$name = 'Interop ' . bin2hex(random_bytes(3));
		$bio = 'A bio written at ' . bin2hex(random_bytes(3));

		$this->mastodon->upload(
			'/api/v1/accounts/update_credentials',
			['display_name' => $name, 'note' => $bio],
			['avatar' => [$this->picture(), 'image/png']],
			'PATCH'
		);

		$after = $this->here->await(function () use ($name): ?array {
			$account = $this->here->account($this->interopHere);

			return (($account['display_name'] ?? '') === $name) ? $account : null;
		});
		$this->assertNotNull($after, 'the new display name never reached this side');
		$this->assertStringContainsString($bio, (string)($after['note'] ?? ''), 'the new bio never reached this side');

		// a remote picture is copied here by the cache job cron runs, and
		// shown once it has been
		$documents = Server::get(DocumentService::class);
		$this->assertNotNull(
			$this->here->await(function () use ($before, $documents): ?bool {
				$documents->manageCacheDocuments();

				return (($this->here->account($this->interopHere)['avatar'] ?? '') !== ($before['avatar'] ?? '')) ? true : null;
			}),
			'the new avatar never reached this side: ' . json_encode([
				'before' => $before['avatar'] ?? null,
				'after' => $this->here->account($this->interopHere)['avatar'] ?? null,
				'there' => $this->mastodon->get('/api/v1/accounts/verify_credentials')['avatar'] ?? null,
			], JSON_UNESCAPED_SLASHES)
		);
	}

	/** An `Undo` of Mastodon's follow ends it here, so nothing more is sent there. */
	public function testAnUnfollowEndsTheFollowHere(): void {
		$this->mastodon->follow($this->adminThere);
		$this->drainQueue();
		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', true),
			'Mastodon\'s follow of our account never arrived'
		);

		try {
			$this->mastodon->unfollow($this->adminThere);

			$this->assertTrue(
				$this->here->awaitRelationship($this->interopHere, 'followed_by', false),
				'Mastodon unfollowed our account and this side still counts it as a follower'
			);
		} finally {
			$this->mastodon->follow($this->adminThere);
			$this->drainQueue();
		}
	}

	/**
	 * A block from Mastodon ends both follows: Mastodon rejects ours and
	 * withdraws its own, and this side has to let go of both.
	 */
	public function testABlockFromMastodonEndsBothFollows(): void {
		$this->mastodon->follow($this->adminThere);
		$this->drainQueue();
		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', true),
			'Mastodon\'s follow of our account never arrived'
		);

		try {
			$this->mastodon->post('/api/v1/accounts/' . $this->adminThere . '/block');

			$this->assertTrue(
				$this->here->awaitRelationship($this->interopHere, 'following', false),
				'Mastodon blocked our account and this side still believes it follows them'
			);
			$this->assertTrue(
				$this->here->awaitRelationship($this->interopHere, 'followed_by', false),
				'Mastodon blocked our account and this side still counts them as a follower'
			);
		} finally {
			$this->mastodon->post('/api/v1/accounts/' . $this->adminThere . '/unblock');
			$this->mastodon->follow($this->adminThere);
			$this->drainQueue();
		}
	}
}
