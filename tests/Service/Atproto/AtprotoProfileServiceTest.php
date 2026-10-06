<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Atproto;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\Atproto\AtprotoClient;
use OCA\Social\Service\Atproto\AtprotoEngagementService;
use OCA\Social\Service\Atproto\AtprotoIdentity;
use OCA\Social\Service\Atproto\AtprotoIngress;
use OCA\Social\Service\Atproto\AtprotoProfileService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AtprotoProfileServiceTest extends TestCase {
	private AtprotoClient|MockObject $client;
	private AtprotoIdentity|MockObject $identity;
	private AtprotoIngress|MockObject $ingress;
	private AtprotoEngagementService|MockObject $engagement;

	protected function setUp(): void {
		$this->client = $this->createMock(AtprotoClient::class);
		$this->identity = $this->createMock(AtprotoIdentity::class);
		$this->ingress = $this->createMock(AtprotoIngress::class);
		$this->engagement = $this->createMock(AtprotoEngagementService::class);
		$this->identity->method('resolve')->willReturn([
			'did' => 'did:plc:profile', 'handle' => 'alice.example', 'pds' => 'https://pds.example',
		]);
		$this->identity->method('actor')->willReturn(
			(new Person())->setId('https://cloud.example/apps/social/ap/bluesky/did:plc:profile')
		);
	}

	public function testAuthorFeedCursorIsPassedThroughAndReturned(): void {
		$calls = [];
		$this->client->expects($this->exactly(2))->method('get')->willReturnCallback(
			function (string $nsid, array $params) use (&$calls): array {
				$calls[] = [$nsid, $params];

				return count($calls) === 1
					? ['displayName' => 'Alice']
					: ['feed' => [], 'cursor' => 'next-page'];
			}
		);

		$service = new AtprotoProfileService($this->client, $this->identity, $this->ingress, $this->engagement);
		$data = $service->read('alice.example', 20, null, 'previous-page');

		$this->assertSame('app.bsky.actor.getProfile', $calls[0][0]);
		$this->assertSame('app.bsky.feed.getAuthorFeed', $calls[1][0]);
		$this->assertSame('previous-page', $calls[1][1]['cursor']);
		$this->assertSame('next-page', $data['nextCursor']);
		$this->assertSame('alice.example', $data['profile']['handle']);
	}

	public function testNativeThreadUsesThePostUriAndReturnsDirectReplies(): void {
		$this->identity->method('didOf')->willReturn('did:plc:profile');
		$this->identity->method('collectionOf')->willReturn('app.bsky.feed.post');
		$this->identity->method('rkeyOf')->willReturn('3replyroot');
		$this->client->expects($this->once())->method('get')->with(
			'app.bsky.feed.getPostThread',
			['uri' => 'at://did:plc:profile/app.bsky.feed.post/3replyroot', 'depth' => 1],
		)->willReturn(['thread' => ['replies' => []]]);

		$service = new AtprotoProfileService($this->client, $this->identity, $this->ingress, $this->engagement);

		$this->assertSame([], $service->thread('https://cloud.example/apps/social/ap/bluesky/did:plc:profile/app.bsky.feed.post/3replyroot'));
	}

	public function testAppViewModerationLabelsBecomeAContentWarning(): void {
		$this->identity->method('recordId')->willReturn('https://cloud.example/apps/social/ap/bluesky/did:plc:profile/app.bsky.feed.post/3label');
		$post = (new \OCA\Social\Model\ActivityPub\Stream())
			->setId('https://cloud.example/apps/social/ap/bluesky/did:plc:profile/app.bsky.feed.post/3label')
			->setContent('<p>hello</p>');
		$this->ingress->expects($this->once())->method('fetch')->willReturn($post);
		$calls = 0;
		$this->client->expects($this->exactly(2))->method('get')->willReturnCallback(
			function (string $nsid, array $params) use (&$calls): array {
				$calls++;

				return $calls === 1
					? ['displayName' => 'Alice']
					: ['feed' => [[
						'post' => [
							'uri' => 'at://did:plc:profile/app.bsky.feed.post/3label',
							'labels' => [['val' => 'graphic-media']],
						],
					]], 'cursor' => ''];
			}
		);

		$service = new AtprotoProfileService($this->client, $this->identity, $this->ingress, $this->engagement);
		$data = $service->read('alice.example');

		$this->assertTrue($data['statuses'][0]->isSensitive());
		$this->assertSame('Bluesky: graphic-media', $data['statuses'][0]->getSpoilerText());
	}
}
