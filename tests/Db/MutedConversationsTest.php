<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequestBuilder;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `muted` on a status is the viewer's mute of the thread's root, attached
 * to every post of a page as it is read, through a per-request memo of the
 * viewer's muted roots that every mute write has to drop.
 *
 * The statements need a real database and are exercised by the integration
 * suite (`MutedConversationFlagTest`); what is covered here is the marking
 * and the memo.
 */
#[AllowMockObjectsWithoutExpectations]
class MutedConversationsTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	protected function tearDown(): void {
		StreamRequestBuilder::forgetMutedRoots();
		parent::tearDown();
	}

	/** @return array<string, array<string, true>> */
	private function memo(): array {
		return (new ReflectionProperty(StreamRequestBuilder::class, 'mutedRoots'))->getValue();
	}

	private function fillMemo(): void {
		(new ReflectionProperty(StreamRequestBuilder::class, 'mutedRoots'))
			->setValue(null, [self::VIEWER => ['https://a/1' => true]]);
	}

	/**
	 * @param array<string, true> $muted the viewer's muted roots
	 * @param array<string, string> $roots post id => its root
	 */
	private function builder(string $viewerId, array $muted, array $roots): StreamRequestBuilder {
		$builder = $this->getMockBuilder(StreamRequestBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['getViewerId', 'mutedRootsOf', 'rootsOf'])
			->getMock();
		$builder->method('getViewerId')->willReturn($viewerId);
		$builder->method('mutedRootsOf')->willReturn($muted);
		$builder->method('rootsOf')->willReturnCallback(
			static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): string => $roots[$id] ?? $id, $ids))
		);

		return $builder;
	}

	/** @param Stream[] $streams */
	private function mark(StreamRequestBuilder $builder, array $streams): void {
		$method = new \ReflectionMethod(StreamRequestBuilder::class, 'markMutedConversations');
		$method->invoke($builder, $streams);
	}

	private function note(string $id): Note {
		$note = new Note();
		$note->setId($id);

		return $note;
	}

	public function testTheStatusEntityCarriesTheMute(): void {
		$this->assertFalse((new Note())->exportAsLocal()['muted'], 'nobody muted it');
		$this->assertTrue((new Note())->setMutedConversation(true)->exportAsLocal()['muted']);
	}

	public function testEveryPostOfAMutedThreadIsMarkedAndNothingElse(): void {
		$root = $this->note('https://a/1');
		$reply = $this->note('https://a/2');
		$deeper = $this->note('https://a/3');
		$elsewhere = $this->note('https://b/1');

		$this->mark(
			$this->builder(self::VIEWER, ['https://a/1' => true], ['https://a/2' => 'https://a/1', 'https://a/3' => 'https://a/1']),
			[$root, $reply, $deeper, $elsewhere]
		);

		$this->assertTrue($root->isMutedConversation());
		$this->assertTrue($reply->isMutedConversation());
		$this->assertTrue($deeper->isMutedConversation());
		$this->assertFalse($elsewhere->isMutedConversation());
	}

	public function testANotificationIsMarkedThroughThePostItIsAbout(): void {
		$post = $this->note('https://a/2');
		$notification = $this->note('https://cloud.example/notifications/9');
		$notification->setObject($post);

		$this->mark($this->builder(self::VIEWER, ['https://a/1' => true], ['https://a/2' => 'https://a/1']), [$notification]);

		$this->assertTrue($post->isMutedConversation());
	}

	public function testNothingIsWalkedForAViewerWhoMutedNothingOrForNobody(): void {
		foreach ([[self::VIEWER, []], ['', ['https://a/1' => true]]] as [$viewer, $muted]) {
			$builder = $this->getMockBuilder(StreamRequestBuilder::class)
				->disableOriginalConstructor()
				->onlyMethods(['getViewerId', 'mutedRootsOf', 'rootsOf'])
				->getMock();
			$builder->method('getViewerId')->willReturn($viewer);
			$builder->method('mutedRootsOf')->willReturn($muted);
			$builder->expects($this->never())->method('rootsOf');

			$post = $this->note('https://a/1');
			$this->mark($builder, [$post]);
			$this->assertFalse($post->isMutedConversation());
		}
	}

	/**
	 * Muting a thread that already has a state row is an update, which
	 * returns before the insert; the memo has to be gone either way, or the
	 * mute route answers with the post read through the stale memo.
	 */
	public function testMutingDropsTheMemoOnTheUpdatePath(): void {
		$request = $this->getMockBuilder(ConversationsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getMarkers', 'updateMuted'])
			->getMock();
		$request->method('getMarkers')->willReturn(['https://a/1' => ['rootId' => 'https://a/1', 'readNid' => 0, 'hiddenNid' => 0]]);
		$request->expects($this->once())->method('updateMuted')->with(self::VIEWER, 'https://a/1', true);

		$this->fillMemo();
		$request->setMuted(self::VIEWER, 'https://a/1', true);

		$this->assertSame([], $this->memo());
	}

	public function testForgettingEmptiesTheMemoForEveryViewer(): void {
		$this->fillMemo();

		StreamRequestBuilder::forgetMutedRoots();

		$this->assertSame([], $this->memo());
	}
}
