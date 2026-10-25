<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Db\RecommendationFeedbackRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationContentCluster;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class RecommendationFeedbackServiceTest extends TestCase {
	private RecommendationFeedbackRequest $request;
	private RecommendationPreferenceService $preferences;
	private StreamRequest $streams;
	private ITimeFactory $clock;
	private RecommendationTopicCatalog $catalog;

	protected function setUp(): void {
		$this->request = $this->createMock(RecommendationFeedbackRequest::class);
		$this->preferences = $this->createStub(RecommendationPreferenceService::class);
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->clock = $this->createStub(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(2_000_000_000);
		$this->catalog = new RecommendationTopicCatalog();
	}

	private function service(): RecommendationFeedbackService {
		return new RecommendationFeedbackService(
			$this->request,
			$this->preferences,
			$this->catalog,
			new RecommendationFeatureExtractor($this->catalog),
			$this->streams,
			$this->clock,
		);
	}

	private function viewer(): Person {
		return (new Person())->setId('https://social.example/users/alice')->setUserId('alice')->setLocal(true);
	}

	private function applePost(): Note {
		return (new Note())->setNid('123')->setAttributedTo('https://remote.example/users/bob')
			->setId('https://remote.example/posts/123')->setContent('<p>Apple released a new iPhone</p>');
	}

	public function testTopicSelectionFeedbackIsRejected(): void {
		$this->request->expects($this->never())->method('record');
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->expectException(InvalidArgumentException::class);
		$this->service()->record($this->viewer(), '792b7610-e146-422c-9c87-d703412e9874', '123', 'MORE_TOPIC');
	}

	public function testAuthorFeedbackHashesThePostAuthorInsteadOfTrustingClientInput(): void {
		$this->request->method('byEvent')->willReturn(null);
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->applePost()]);
		$this->request->expects($this->once())->method('record')->with(
			'alice', $this->anything(), $this->anything(), 'author', hash('sha256', 'https://remote.example/users/bob'), 'MORE_AUTHOR', 2_000_000_000
		)->willReturn(['record' => ['feedback_id' => '0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072', 'event_id' => '792b7610-e146-422c-9c87-d703412e9874',
			'scope' => 'author', 'scope_key' => hash('sha256', 'https://remote.example/users/bob'), 'operation' => 'MORE_AUTHOR', 'undo_until' => 2_000_000_600],
			'feedback_version' => 1, 'duplicate' => false]);

		$result = $this->service()->record($this->viewer(), '792b7610-e146-422c-9c87-d703412e9874', '123', 'MORE_AUTHOR');

		$this->assertSame('author', $result['applied_scope']['type']);
		$this->assertSame(hash('sha256', 'https://remote.example/users/bob'), $result['applied_scope']['key']);
	}

	public function testSimilarityFeedbackStoresAClusterOfNonsensitiveTopicsAndFormat(): void {
		$this->request->method('byEvent')->willReturn(null);
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->applePost()]);
		$key = RecommendationContentCluster::key(['technology', 'tech.apple'], 'text', $this->catalog);
		$this->request->expects($this->once())->method('record')->with(
			'alice', $this->anything(), $this->anything(), 'similarity', $key, 'MORE_SIMILAR', 2_000_000_000
		)->willReturn([
			'record' => ['feedback_id' => '0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072', 'event_id' => '792b7610-e146-422c-9c87-d703412e9874',
				'scope' => 'similarity', 'scope_key' => $key, 'operation' => 'MORE_SIMILAR', 'undo_until' => 2_000_000_600],
			'feedback_version' => 1, 'duplicate' => false,
		]);

		$result = $this->service()->record($this->viewer(), '792b7610-e146-422c-9c87-d703412e9874', '123', 'MORE_SIMILAR');

		$this->assertSame('similarity', $result['applied_scope']['type']);
		$this->assertSame($key, $result['applied_scope']['key']);
	}

	public function testSimilarityFeedbackIsRejectedWhenOnlySensitiveTopicsMatch(): void {
		$this->request->method('byEvent')->willReturn(null);
		$post = $this->applePost()->setContent('<p>International politics</p>');
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);
		$this->request->expects($this->never())->method('record');
		$this->expectException(InvalidArgumentException::class);

		$this->service()->record($this->viewer(), '792b7610-e146-422c-9c87-d703412e9874', '123', 'MORE_SIMILAR');
	}

	public function testFeedbackContextExposesAvailabilityAndFormatWithoutTopicLabels(): void {
		$post = $this->applePost()->setContent('<p>Apple released a new iPhone. International politics and elections.</p>');
		$this->request->expects($this->never())->method('byEvent');
		$this->streams->expects($this->once())->method('setViewer')->with($this->isInstanceOf(Person::class));
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);

		$context = $this->service()->context($this->viewer(), '123');

		$this->assertSame(['similarity_available' => true, 'format' => 'text'], $context);
	}

	public function testFeedbackContextDoesNotExposeUnreadablePosts(): void {
		$this->request->expects($this->never())->method('byEvent');
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn([]);
		$this->expectException(ItemNotFoundException::class);
		$this->service()->context($this->viewer(), '123');
	}

	public function testFeedbackContextDoesNotExposeOwnPosts(): void {
		$this->request->expects($this->never())->method('byEvent');
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['124'])->willReturn([
			'124' => (new Note())->setNid('124')->setAttributedTo('https://social.example/users/alice'),
		]);
		$this->expectException(ItemNotFoundException::class);
		$this->service()->context($this->viewer(), '124');
	}

	public function testCannotApplyFeedbackToAnUnreadablePostOrAFalseSensitiveTopicLabel(): void {
		$this->request->expects($this->never())->method('record');
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn([]);
		$this->expectException(ItemNotFoundException::class);
		$this->service()->record($this->viewer(), '792b7610-e146-422c-9c87-d703412e9874', '123', 'HIDE_POST');
	}

	public function testExportContainsOnlyCurrentOwnerFeedbackFields(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->request->expects($this->once())->method('current')->with('alice')->willReturn([
			"topic\0tech.apple" => [
				'feedback_id' => '0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072',
				'event_id' => '792b7610-e146-422c-9c87-d703412e9874',
				'scope' => 'topic', 'scope_key' => 'tech.apple', 'operation' => 'MORE_TOPIC',
				'created_at' => 2_000_000_000, 'undo_until' => 2_000_000_600, 'active' => 1,
			],
		]);

		$this->assertSame([], $this->service()->export('alice'), 'legacy topic feedback is inert and omitted');
	}
}
