<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What this app does through its client API, read back out of a real
 * Mastodon.
 *
 * `MastodonDeliveryTest` proves the basic shapes — a post, an edit, a delete,
 * a boost — written through the services. This writes through the same API a
 * phone app writes through, and covers what that set does not: follows both
 * ways and their answers, a locked account here, pictures with descriptions,
 * polls and the votes that come back, replies, favourites, audiences other
 * than public, links in the text, profile edits and a deleted account.
 *
 * Three Mastodon accounts take part: `interop` follows our `admin`;
 * `stranger` follows nobody here, so it shows what a non-follower sees; and
 * `guarded` is locked, so a follow of it has to wait for an answer.
 */
class MastodonOutboundTest extends TestCase {
	use LocalSide;

	private const USER = 'admin';
	/** locked here, for the follow request Mastodon has to wait on */
	private const LOCKED = 'shy';
	/** edits its profile, so the others are not renamed under them */
	private const PROFILE = 'profile';
	/** deletes itself */
	private const GONER = 'goner';

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

		// a post reaches Mastodon because somebody there follows its author
		$this->mastodon->follow($this->adminThere);
		$this->drainQueue();
		$this->assertTrue(
			$this->mastodon->awaitRelationship($this->adminThere, 'following', true),
			'Mastodon never managed to follow ' . $this->localHandle(self::USER)
		);
	}

	public function testOurFollowIsAcceptedByMastodon(): void {
		$this->endFollow($this->here, $this->interopHere, $this->mastodon, $this->adminThere);

		$this->here->follow($this->interopHere);
		$this->drainQueue();

		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'following', true),
			'Mastodon\'s Accept never turned the follow into a follow here'
		);
		$this->assertTrue(
			$this->mastodon->awaitRelationship($this->adminThere, 'followed_by', true),
			'Mastodon does not count our account as a follower'
		);
	}

	/** A locked Mastodon account answers a follow when its owner does, not before. */
	public function testAFollowOfALockedAccountWaitsForItsAnswer(): void {
		$guarded = $this->other('MASTODON_TOKEN_GUARDED', 'guarded');
		$guardedHere = $this->here->resolveAccount($guarded->handle());
		$adminThereForGuarded = $guarded->resolveAccount($this->localHandle(self::USER));
		$this->endFollow($this->here, $guardedHere, $guarded, $adminThereForGuarded);

		$asked = $this->here->follow($guardedHere);
		$this->drainQueue();
		$this->assertFalse((bool)($asked['following'] ?? true), 'a locked account counted as followed before it answered');

		$request = $guarded->await(function () use ($guarded): ?array {
			foreach ($guarded->get('/api/v1/follow_requests') as $account) {
				if (is_array($account) && strtolower((string)($account['acct'] ?? '')) === strtolower(ltrim($this->localHandle(self::USER), '@'))) {
					return $account;
				}
			}

			return null;
		});
		$this->assertNotNull($request, 'the follow request never reached the locked Mastodon account');
		$this->assertTrue((bool)($this->here->relationship($guardedHere)['requested'] ?? false), 'the follow is not shown as asked for');

		$guarded->post('/api/v1/follow_requests/' . $request['id'] . '/authorize');

		$this->assertTrue(
			$this->here->awaitRelationship($guardedHere, 'following', true),
			'the locked account said yes and this side never heard it'
		);
	}

	/** A locked account here: Mastodon's follow waits until it is said yes to. */
	public function testOurLockedAccountDecidesWhoFollowsIt(): void {
		$shy = Here::forUser(self::LOCKED);
		$shy->patch('/api/v1/accounts/update_credentials', ['locked' => true]);

		$shyThere = $this->mastodon->resolveAccount($this->localHandle(self::LOCKED));
		$interopHereForShy = $shy->resolveAccount($this->mastodon->handle());
		$this->endFollow($this->mastodon, $shyThere, $shy, $interopHereForShy);
		$this->assertTrue(
			(bool)($this->mastodon->account($shyThere)['locked'] ?? false),
			'Mastodon does not see the account as locked'
		);

		$this->mastodon->follow($shyThere);
		$request = $shy->await(function () use ($shy): ?array {
			foreach ($shy->get('/api/v1/follow_requests') as $account) {
				if (is_array($account) && strtolower((string)($account['acct'] ?? '')) === strtolower($this->mastodon->handle())) {
					return $account;
				}
			}

			return null;
		});
		$this->assertNotNull($request, 'Mastodon\'s follow is not waiting for an answer here');
		$this->drainQueue();
		$this->assertFalse(
			(bool)($this->mastodon->relationship($shyThere)['following'] ?? true),
			'Mastodon follows a locked account nobody here has said yes to'
		);

		$shy->post('/api/v1/follow_requests/' . rawurlencode((string)$request['id']) . '/authorize');
		$this->drainQueue();

		$this->assertTrue(
			$this->mastodon->awaitRelationship($shyThere, 'following', true),
			'the yes given here never reached Mastodon'
		);
	}

	public function testAPictureArrivesWithItsDescription(): void {
		$mediaId = $this->here->uploadMedia($this->picture(), 'image/png', 'a square of colour, described');
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public', 'media_ids' => [$mediaId]]);
		$this->drainQueue();

		$status = $this->mastodon->awaitStatus($this->adminThere, (string)$ours['uri']);

		$this->assertNotNull($status, 'the post never reached Mastodon');
		$attachment = $status['media_attachments'][0] ?? null;
		$this->assertIsArray($attachment, 'the picture was dropped on the way');
		$this->assertSame('image', $attachment['type'] ?? '', 'Mastodon could not make a picture of it');
		$this->assertSame('a square of colour, described', $attachment['description'] ?? '');
	}

	/** Our poll reaches Mastodon as one, and a vote there comes back to our count. */
	public function testOurPollCountsMastodonsVote(): void {
		$ours = $this->here->publish($this->unique('poll'), [
			'visibility' => 'public',
			'poll' => ['options' => ['yes', 'no'], 'expires_in' => 3600],
		]);
		$this->drainQueue();

		$status = $this->mastodon->awaitStatus($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($status, 'the poll never reached Mastodon');
		$this->assertIsArray($status['poll'] ?? null, 'the poll reached Mastodon as a post without a poll');
		$this->assertSame(
			['yes', 'no'],
			array_map(static fn (array $option): string => (string)$option['title'], $status['poll']['options'] ?? [])
		);

		$this->mastodon->post('/api/v1/polls/' . $status['poll']['id'] . '/votes', ['choices' => [0]]);

		$counted = $this->here->await(function () use ($ours): ?array {
			$poll = $this->here->get('/api/v1/polls/' . $ours['poll']['id']);

			return ((int)($poll['options'][0]['votes_count'] ?? 0) === 1) ? $poll : null;
		});
		$this->assertNotNull($counted, 'the vote cast on Mastodon was not counted here');
		$this->assertSame(0, (int)($counted['options'][1]['votes_count'] ?? -1), 'the vote went to the wrong option');
	}

	public function testOurReplyThreadsUnderTheMastodonPost(): void {
		$theirs = $this->mastodon->publish($this->unique(), ['visibility' => 'public']);
		$ourCopy = $this->here->awaitResolvedStatus((string)$theirs['uri']);
		$this->assertNotNull($ourCopy, 'this side could not fetch the Mastodon post');

		$reply = $this->here->publish($this->unique('reply'), ['visibility' => 'public', 'in_reply_to_id' => $ourCopy['id']]);
		$this->drainQueue();

		$threaded = $this->mastodon->await(function () use ($theirs, $reply): ?array {
			$context = $this->mastodon->get('/api/v1/statuses/' . $theirs['id'] . '/context');

			return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], (string)$reply['uri']);
		});
		$this->assertNotNull($threaded, 'the reply is not in the Mastodon thread it answers');
		$this->assertSame((string)$theirs['id'], (string)($threaded['in_reply_to_id'] ?? ''));
	}

	/** A favourite, a boost and the undoing of both, as Mastodon counts them. */
	public function testOurFavouriteAndBoostAreCountedThereAndCanBeUndone(): void {
		$theirs = $this->mastodon->publish($this->unique(), ['visibility' => 'public']);
		$ourCopy = $this->here->awaitResolvedStatus((string)$theirs['uri']);
		$this->assertNotNull($ourCopy, 'this side could not fetch the Mastodon post');
		$id = (string)$ourCopy['id'];
		$counts = fn (string $field, int $wanted): ?array => $this->mastodon->awaitStatusThat(
			(string)$theirs['id'],
			static fn (array $s): bool => (int)($s[$field] ?? -1) === $wanted
		);

		$this->here->post('/api/v1/statuses/' . $id . '/favourite');
		$this->drainQueue();
		$this->assertNotNull($counts('favourites_count', 1), 'the favourite was not counted on Mastodon');

		$this->here->post('/api/v1/statuses/' . $id . '/reblog');
		$this->drainQueue();
		$this->assertNotNull($counts('reblogs_count', 1), 'the boost was not counted on Mastodon');

		$this->here->post('/api/v1/statuses/' . $id . '/unreblog');
		$this->drainQueue();
		$this->assertNotNull($counts('reblogs_count', 0), 'the boost was undone here and still counts on Mastodon');

		$this->here->post('/api/v1/statuses/' . $id . '/unfavourite');
		$this->drainQueue();
		$this->assertNotNull($counts('favourites_count', 0), 'the favourite was undone here and still counts on Mastodon');
	}

	/** Followers-only is read by a follower there and not by anybody else there. */
	public function testAFollowersOnlyPostReachesOnlyFollowers(): void {
		$stranger = $this->other('MASTODON_TOKEN_STRANGER', 'stranger');
		$adminThereForStranger = $stranger->resolveAccount($this->localHandle(self::USER));

		$ours = $this->here->publish($this->unique(), ['visibility' => 'private']);
		$this->drainQueue();

		$status = $this->mastodon->awaitStatus($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($status, 'the followers-only post never reached a follower on Mastodon');
		$this->assertSame('private', $status['visibility'] ?? '');

		$this->assertNull(
			ClientApi::findByUri($stranger->statusesOf($adminThereForStranger), (string)$ours['uri']),
			'a Mastodon account that follows nobody here can read a followers-only post'
		);
		$this->assertNull(
			$stranger->resolveStatus((string)$ours['uri']),
			'a Mastodon account that follows nobody here can fetch a followers-only post by its address'
		);
	}

	/** A direct message reaches the account it names and nobody else. */
	public function testADirectMessageReachesOnlyItsAddressee(): void {
		$stranger = $this->other('MASTODON_TOKEN_STRANGER', 'stranger');
		$adminThereForStranger = $stranger->resolveAccount($this->localHandle(self::USER));

		$ours = $this->here->publish('@' . $this->mastodon->handle() . ' ' . $this->unique(), ['visibility' => 'direct']);
		$this->drainQueue();

		$notification = $this->mastodon->awaitNotification(
			'mention',
			static fn (array $n): bool => ($n['status']['uri'] ?? '') === $ours['uri']
		);
		$this->assertNotNull($notification, 'the direct message never reached the account it was written to');
		$this->assertSame('direct', $notification['status']['visibility'] ?? '');

		$this->assertNull(
			ClientApi::findByUri($stranger->statusesOf($adminThereForStranger), (string)$ours['uri']),
			'a Mastodon account the message was not written to can read it'
		);
		$this->assertNull(
			$stranger->resolveStatus((string)$ours['uri']),
			'a Mastodon account the message was not written to can fetch it by its address'
		);
	}

	/** A hashtag and a mention reach Mastodon as a tag and a mention, not as words. */
	public function testHashtagsAndMentionsArriveAsLinks(): void {
		$tag = 'interop' . bin2hex(random_bytes(3));
		$ours = $this->here->publish(
			$this->unique() . ' #' . $tag . ' @' . $this->mastodon->handle(),
			['visibility' => 'public']
		);
		$this->drainQueue();

		$status = $this->mastodon->awaitStatus($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($status, 'the post never reached Mastodon');

		$this->assertContains(
			$tag,
			array_map(static fn (array $t): string => strtolower((string)$t['name']), $status['tags'] ?? []),
			'Mastodon did not take the hashtag as a tag'
		);
		$this->assertContains(
			strtolower($this->mastodon->ownId()),
			array_map(static fn (array $m): string => strtolower((string)$m['id']), $status['mentions'] ?? []),
			'Mastodon did not take the mention as a mention'
		);

		$content = (string)($status['content'] ?? '');
		$this->assertMatchesRegularExpression('~<a\b[^>]*>(?:(?!</a>).)*' . $tag . '~is', $content, 'the hashtag is not a link');
		$this->assertMatchesRegularExpression('~<a\b[^>]*>(?:(?!</a>).)*interop~is', $content, 'the mention is not a link');
	}

	/** A name, a bio and profile fields edited here are what Mastodon shows. */
	public function testAProfileEditReachesMastodon(): void {
		$profile = Here::forUser(self::PROFILE);
		$profileThere = $this->mastodon->resolveAccount($this->localHandle(self::PROFILE));
		$this->mastodon->follow($profileThere);
		$this->drainQueue();
		$this->assertTrue(
			$this->mastodon->awaitRelationship($profileThere, 'following', true),
			'Mastodon never managed to follow ' . $this->localHandle(self::PROFILE)
		);

		$name = 'Profile ' . bin2hex(random_bytes(3));
		$bio = 'Bio ' . bin2hex(random_bytes(3));
		$field = 'Value ' . bin2hex(random_bytes(3));
		$profile->patch('/api/v1/accounts/update_credentials', [
			'display_name' => $name,
			'note' => $bio,
			'fields_attributes' => [['name' => 'Website', 'value' => $field]],
		]);
		$this->drainQueue();

		$there = $this->mastodon->await(function () use ($profileThere, $name, $bio, $field): ?array {
			$account = $this->mastodon->account($profileThere);
			$fields = array_map(static fn (array $f): string => strip_tags((string)$f['value']), $account['fields'] ?? []);

			return (($account['display_name'] ?? '') === $name
				&& str_contains((string)($account['note'] ?? ''), $bio)
				&& in_array($field, $fields, true)) ? $account : null;
		});
		$account = $this->mastodon->account($profileThere);
		$this->assertNotNull(
			$there,
			'Mastodon does not show the edited profile: ' . json_encode([
				'display_name' => $account['display_name'] ?? null,
				'note' => $account['note'] ?? null,
				'fields' => $account['fields'] ?? null,
			])
		);

		// a new picture is a new address, which is what makes Mastodon fetch it
		$before = (string)($account['avatar'] ?? '');
		$picture = (string)tempnam(sys_get_temp_dir(), 'interop-avatar-');
		$image = imagecreatetruecolor(64, 64);
		imagefill($image, 0, 0, (int)imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
		imagepng($image, $picture);
		Server::get(AccountService::class)->changingProfile(self::PROFILE, static function () use ($picture): void {
			Server::get(AvatarService::class)->setFromFile(self::PROFILE, $picture);
		});
		@unlink($picture);
		$this->drainQueue();
		$this->assertNotNull(
			$this->mastodon->await(fn (): ?bool => (string)($this->mastodon->account($profileThere)['avatar'] ?? '') !== $before ? true : null),
			'Mastodon still shows the avatar from before: ' . $before
		);
	}

	/** An account deleted here is gone from Mastodon as well. */
	public function testADeletedAccountIsGoneThere(): void {
		$goneThere = $this->mastodon->resolveAccount($this->localHandle(self::GONER));
		$this->mastodon->follow($goneThere);
		$this->drainQueue();
		$this->assertTrue(
			$this->mastodon->awaitRelationship($goneThere, 'following', true),
			'Mastodon never managed to follow ' . $this->localHandle(self::GONER)
		);

		Server::get(AccountService::class)->deleteOwnAccount(self::GONER, self::GONER);
		$this->drainQueue();

		$this->assertTrue(
			$this->mastodon->await(function () use ($goneThere): ?bool {
				$account = $this->mastodon->getOrNull('/api/v1/accounts/' . $goneThere);

				return ($account === null || ($account['suspended'] ?? false) === true) ? true : null;
			}) === true,
			'Mastodon still shows an account that was deleted here'
		);
	}

	// --- the harness ------------------------------------------------------

	/** Another Mastodon account the workflow made a token for. */
	private function other(string $tokenVariable, string $username): Mastodon {
		$other = Mastodon::fromEnvironment($tokenVariable, $username);
		if ($other === null) {
			$this->markTestSkipped('no token for Mastodon\'s ' . $username . ': set ' . $tokenVariable);
		}

		return $other;
	}

	/**
	 * Makes sure `$follower` does not follow `$followedId`, as seen from both
	 * ends, so a test of following starts from nothing however often it runs.
	 */
	private function endFollow(ClientApi $follower, string $followedId, ClientApi $followed, string $followerId): void {
		$relationship = $follower->relationship($followedId);
		if (!(bool)$relationship['following'] && !(bool)$relationship['requested']) {
			return;
		}

		$follower->unfollow($followedId);
		$this->drainQueue();
		$this->assertTrue(
			$followed->awaitRelationship($followerId, 'followed_by', false),
			'the follow of an earlier run could not be ended first'
		);
	}
}
