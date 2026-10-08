<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Moderation\BlocklistManager;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3b against Bluesky's own software: a report about a Bluesky post
 * reaches the development moderation service (Ozone), made by this server
 * and not by the reporter; and an account the administrator blocks is gone
 * from here, its feed no longer read.
 */
class AtprotoModerationTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;
	private string $moderation = '';

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('md');
		$config = Server::get(ConfigService::class);
		$this->moderation = (string)$config->getAppValue(ConfigService::ATPROTO_MODERATION_DID);
		if ($network->ozoneDid !== '') {
			$config->setAppValue(ConfigService::ATPROTO_MODERATION_DID, $network->ozoneDid);
		}
	}

	protected function tearDown(): void {
		if ($this->moderation !== '') {
			Server::get(ConfigService::class)->setAppValue(ConfigService::ATPROTO_MODERATION_DID, $this->moderation);
		}
	}

	public function testAReportReachesBlueskysModerationAndABlockEndsTheAccountHere(): void {
		$bobDid = $this->network->createUser('mod' . bin2hex(random_bytes(3)));
		$bobId = $this->network->await(function (): ?string {
			try {
				return $this->alice->resolve($this->network->userHandle());
			} catch (\Throwable) {
				return null;
			}
		});
		$this->assertNotNull($bobId);
		$this->alice->follow($bobId);
		$words = 'Report me ' . bin2hex(random_bytes(4));
		$post = $this->network->postText($words);
		$status = $this->network->await(fn (): ?array => $this->homeStatus($bobDid, $words));
		$this->assertNotNull($status);

		// a report with `forward` goes to the moderation service
		if ($this->network->ozoneDid !== '') {
			$this->alice->post('/api/v1/reports', [
				'account_id' => $bobId,
				'status_ids' => [(string)$status['id']],
				'comment' => 'interop ' . $words,
				'category' => 'spam',
				'forward' => true,
			]);
			$event = $this->network->await(function () use ($post): ?array {
				foreach ($this->network->moderationReports() as $event) {
					if (($event['subject']['uri'] ?? '') === $post['uri']) {
						return $event;
					}
				}

				return null;
			});
			$this->assertNotNull($event, 'the moderation service has the report');
			$this->assertSame('did:web:nextcloud.test', $event['createdBy'] ?? '', 'made by this server, not by the reporter');
			$this->assertStringContainsString($words, (string)($event['event']['comment'] ?? ''));
		}

		// the administrator blocks the account: it is purged and not read again
		$this->assertSame(1, Server::get(BlocklistManager::class)->block($bobDid, 'interop'));
		$this->assertNull(Server::get(AtprotoWatchRequest::class)->getByDid($bobDid), 'the watch is gone');
		$this->assertNull($this->alice->status((string)$status['id']), 'the post is gone here');
		Server::get(BlocklistManager::class)->unblock($bobDid);
	}

	private function homeStatus(string $bobDid, string $words): ?array {
		$watch = Server::get(AtprotoWatchRequest::class)->getByDid($bobDid);
		if ($watch !== null) {
			Server::get(FeedPoller::class)->pollWatch($watch);
		}
		foreach ($this->alice->get('/api/v1/timelines/home', ['limit' => '40']) as $s) {
			if (is_array($s) && str_contains((string)($s['content'] ?? ''), $words)) {
				return $s;
			}
		}

		return null;
	}
}
