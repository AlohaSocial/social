<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Move\MoveAnnouncement;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\PostService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** What the account here says once its Bluesky account moved away. */
#[AllowMockObjectsWithoutExpectations]
class MoveAnnouncementTest extends TestCase {
	private AccountService&MockObject $accounts;
	private PostService&MockObject $posts;
	private Person $actor;
	private MoveAnnouncement $announcement;

	protected function setUp(): void {
		$this->actor = (new Person())->setUserId('alice');
		$this->accounts = $this->createMock(AccountService::class);
		$this->accounts->method('getActorFromUserId')->willReturnCallback(fn (): Person => $this->actor);
		$this->posts = $this->createMock(PostService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(PostService::class)->willReturn($this->posts);
		$this->announcement = new MoveAnnouncement($this->accounts, $container);
	}

	public function testAPublicPostSaysWhereTheAccountWentThroughTheOrdinaryPath(): void {
		$this->posts->expects($this->once())->method('createPost')->with($this->callback(function (Post $post): bool {
			$this->assertSame('My account alice.social.test now lives at alice.pds.example.com: https://bsky.app/profile/alice.pds.example.com', $post->getContent());
			$this->assertSame('public', $post->getType());
			$this->assertSame($this->actor, $post->getActor());
			$this->assertSame(Post::REVIEW_EXEMPT, $post->getReview(), 'this server\'s template');

			return true;
		}));
		$this->accounts->expects($this->never())->method('setFields');

		$this->announcement->announce('alice', MoveAnnouncement::POST, 'alice.social.test', 'alice.pds.example.com');
	}

	public function testAProfileFieldIsAddedOrReplaced(): void {
		$this->actor->setFields([['name' => 'Website', 'value' => 'https://alice.example']]);
		$this->accounts->expects($this->exactly(2))->method('setFields')->willReturnCallback(function (string $userId, array $fields): void {
			$this->assertSame([['name' => 'Website', 'value' => 'https://alice.example'], ['name' => 'Also at', 'value' => 'https://bsky.app/profile/alice.pds.example.com']], $fields);
			$this->actor->setFields([['name' => 'Website', 'value' => 'https://alice.example'], ['name' => 'Also at', 'value' => 'https://bsky.app/profile/old.example']]);
		});
		$this->posts->expects($this->never())->method('createPost');

		$this->announcement->announce('alice', MoveAnnouncement::PROFILE, 'alice.social.test', 'alice.pds.example.com');
		$this->announcement->announce('alice', MoveAnnouncement::PROFILE, 'alice.social.test', 'alice.pds.example.com');
	}

	public function testAFullProfileIsLeftAsItIs(): void {
		$this->actor->setFields(array_map(static fn (int $i): array => ['name' => 'F' . $i, 'value' => 'v'], [1, 2, 3, 4]));
		$this->accounts->expects($this->never())->method('setFields');

		try {
			$this->announcement->announce('alice', MoveAnnouncement::PROFILE, 'alice.social.test', 'alice.pds.example.com');
			$this->fail('a fifth field');
		} catch (MoveErrorException $e) {
			$this->assertSame('profile-fields-full', $e->errorCode);
		}
	}

	public function testOnlyTheKnownKindsAreTaken(): void {
		MoveAnnouncement::assertKind('none');
		$this->expectException(MoveErrorException::class);
		MoveAnnouncement::assertKind('tweet');
	}
}
