<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\NotificationPolicyService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who may reach a local account, and muting a conversation, against what a
 * real Mastodon sends.
 *
 * Each test has an account of its own, made the way the app makes one, so
 * it starts with the calm policy and follows nobody: Mastodon's `interop`
 * account is a stranger to it and, being made by the workflow minutes ago, a
 * new account too.
 *
 * "Raised nothing" is read off the notifications app's own table, which is
 * where the bell, the phone apps and the mail all read from; the workflow
 * installs that app for this.
 */
class MastodonNotificationPolicyTest extends TestCase {
	use LocalSide;

	private Mastodon $mastodon;
	private Here $here;
	private string $userId = '';
	/** our id for Mastodon's `interop` */
	private string $interopHere = '';

	protected function setUp(): void {
		$mastodon = Mastodon::fromEnvironment();
		if ($mastodon === null) {
			$this->markTestSkipped('no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN');
		}
		$this->mastodon = $mastodon;

		$this->userId = 'calm' . bin2hex(random_bytes(4));
		$user = Server::get(IUserManager::class)->createUser($this->userId, 'Interop-' . bin2hex(random_bytes(12)) . '!');
		if ($user === false) {
			throw new RuntimeException('could not create the user ' . $this->userId);
		}
		Server::get(AccountService::class)->getActorFromUserId($this->userId, true);
	}

	/** A stranger's mention waits in the requests and rings nothing; accepting lets the next one ring. */
	public function testAStrangersMentionWaitsUntilAccepted(): void {
		$this->assertSame(
			NotificationPolicy::CALM,
			Server::get(NotificationPolicyService::class)->of($this->userId)->getDecisions(),
			'a new account did not start calm'
		);
		$this->here = Here::forUser($this->userId, true);
		$this->interopHere = $this->here->resolveAccount($this->mastodon->handle());

		$first = $this->mastodon->publish($this->localHandle($this->userId) . ' ' . $this->unique(), ['visibility' => 'public']);

		$this->assertNotNull($this->awaitRequestFrom($this->interopHere), 'the mention never reached the requests');
		$this->assertNull($this->listed((string)$first['uri']), 'a held mention is on the notification list');
		$this->assertSame(0, $this->raised(), 'a held mention raised a Nextcloud notification');

		$this->here->post('/api/v1/notifications/requests/' . $this->interopHere . '/accept');

		$this->assertNotNull(
			$this->here->await(fn (): ?array => $this->listed((string)$first['uri'])),
			'accepting did not release the held mention into the list'
		);
		$this->assertSame(0, $this->raised(), 'releasing the held mention raised a notification');
		$allowed = array_map(
			static fn (array $account): string => (string)($account['id'] ?? ''),
			array_filter($this->here->get('/api/v1/social/notifications/allowed'), 'is_array')
		);
		$this->assertContains($this->interopHere, $allowed, 'the accepted sender is not always allowed');

		$second = $this->mastodon->publish($this->localHandle($this->userId) . ' ' . $this->unique(), ['visibility' => 'public']);

		$this->assertNotNull(
			$this->here->awaitNotification('mention', static fn (array $n): bool => ($n['status']['uri'] ?? '') === $second['uri']),
			'the next mention from the accepted sender is not listed'
		);
		$this->assertNotNull(
			$this->here->await(fn (): ?bool => ($this->raised() >= 1) ? true : null),
			'the next mention from the accepted sender raised no notification'
		);
	}

	/** A reply in a muted conversation is stored and raises nothing; unmuting lists it again. */
	public function testAMutedConversationRingsNothing(): void {
		$this->here = Here::forUser($this->userId);

		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->mastodon->awaitResolvedStatus((string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'Mastodon could not fetch the post written here');

		$first = $this->replyFromMastodon((string)$theirCopy['id']);
		$this->assertNotNull(
			$this->here->awaitNotification('mention', static fn (array $n): bool => ($n['status']['uri'] ?? '') === $first['uri']),
			'a reply from Mastodon raised no notification before the mute'
		);
		$before = (int)$this->here->await(fn (): ?int => ($this->raised() >= 1) ? $this->raised() : null);
		$this->assertGreaterThanOrEqual(1, $before, 'the reply before the mute rang nothing');

		$muted = $this->here->post('/api/v1/statuses/' . $ours['id'] . '/mute');
		$this->assertTrue((bool)($muted['muted'] ?? false), 'the status does not say its conversation is muted');

		$second = $this->replyFromMastodon((string)$theirCopy['id']);
		$this->assertNotNull(
			$this->here->await(function () use ($ours, $second): ?array {
				$context = $this->here->get('/api/v1/statuses/' . $ours['id'] . '/context');

				return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], (string)$second['uri']);
			}),
			'the reply in the muted conversation never arrived'
		);
		$this->assertNull($this->listed((string)$second['uri']), 'a reply in a muted conversation is on the notification list');
		$this->assertSame($before, $this->raised(), 'a reply in a muted conversation raised a notification');

		$unmuted = $this->here->post('/api/v1/statuses/' . $ours['id'] . '/unmute');
		$this->assertFalse((bool)($unmuted['muted'] ?? true), 'the status still says its conversation is muted');
		$this->assertNotNull(
			$this->here->await(fn (): ?array => $this->listed((string)$second['uri'])),
			'unmuting did not list what arrived meanwhile'
		);
		$this->assertSame($before, $this->raised(), 'unmuting raised a notification for what arrived meanwhile');
	}

	/** @return array<string, mixed> the reply as Mastodon wrote it */
	private function replyFromMastodon(string $inReplyTo): array {
		return $this->mastodon->publish($this->localHandle($this->userId) . ' ' . $this->unique('reply'), [
			'visibility' => 'public',
			'in_reply_to_id' => $inReplyTo,
		]);
	}

	/** @return array<string, mixed>|null the request row of that sender, once there is one */
	private function awaitRequestFrom(string $accountId): ?array {
		return $this->here->await(function () use ($accountId): ?array {
			foreach ($this->here->get('/api/v1/notifications/requests') as $request) {
				if (is_array($request) && (string)($request['account']['id'] ?? '') === $accountId) {
					return $request;
				}
			}

			return null;
		});
	}

	/** @return array<string, mixed>|null the listed notification about that post */
	private function listed(string $uri): ?array {
		foreach ($this->here->notifications() as $notification) {
			if (($notification['status']['uri'] ?? '') === $uri) {
				return $notification;
			}
		}

		return null;
	}

	/** How many Nextcloud notifications this app has raised for the test's user. */
	private function raised(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'raised'))
			->from('notifications')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)))
			->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('social')))
			->andWhere($qb->expr()->neq('subject', $qb->createNamedParameter('digest')));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['raised'] ?? 0);
	}
}
