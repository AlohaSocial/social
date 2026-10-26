<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\RecommendationBehavioralProfile;
use OCA\Social\Service\RecommendationExplanationService;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Service\RecommendationTimelineAssembler;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class RecommendationTimelineAssemblerTest extends TestCase {
	public function testUnclassifiedMediaCanEnterTheBoundedColdStartPool(): void {
		$catalog = new RecommendationTopicCatalog();
		$signals = new RecommendationSignalService(
			$this->createStub(RecommendationPreferenceService::class),
			$this->createStub(RecommendationSignalRequest::class),
			new RecommendationFeatureExtractor($catalog),
			$this->createStub(StreamRequest::class),
			$this->createStub(ITimeFactory::class),
			$this->createStub(IConfig::class),
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(LinkPreviewService::class),
		);
		$assembler = new RecommendationTimelineAssembler(
			$catalog,
			new RecommendationFeatureExtractor($catalog),
			new RecommendationExplanationService($catalog),
			new RecommendationBehavioralProfile($signals, $catalog),
		);
		$post = (new Note())->setAttachments([(new MediaAttachment())->setType('image')]);

		$candidate = $assembler->candidate($post, '3', [], '', 1, [], [
			'topics' => [], 'authors' => [], 'formats' => [],
		], [], [], []);

		self::assertNotNull($candidate);
		self::assertSame('image', $candidate['format']);
		self::assertSame([], $candidate['topic_ids']);

		$textPost = (new Note())->setContent('<p></p>');
		self::assertNull($assembler->candidate($textPost, '4', [], '', 1, [], [
			'topics' => [], 'authors' => [], 'formats' => [],
		], [], [], []));
	}

	public function testDiversityUsesSpecificTopicsInsteadOfTheirBroadParents(): void {
		$catalog = new RecommendationTopicCatalog();
		$signals = new RecommendationSignalService(
			$this->createStub(RecommendationPreferenceService::class),
			$this->createStub(RecommendationSignalRequest::class),
			new RecommendationFeatureExtractor($catalog),
			$this->createStub(StreamRequest::class),
			$this->createStub(ITimeFactory::class),
			$this->createStub(IConfig::class),
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(LinkPreviewService::class),
		);
		$assembler = new RecommendationTimelineAssembler(
			$catalog,
			new RecommendationFeatureExtractor($catalog),
			new RecommendationExplanationService($catalog),
			new RecommendationBehavioralProfile($signals, $catalog),
		);
		$post = (new Note())->setContent('<p>PHP backend development is changing.</p>');

		$candidate = $assembler->candidate($post, '1', [], '', 1, [], [
			'topics' => [], 'authors' => [], 'formats' => [],
		], [], [], []);

		self::assertNotNull($candidate);
		self::assertContains('software', $candidate['topic_ids']);
		self::assertContains('software.backend', $candidate['topic_ids']);
		self::assertSame(['software.backend'], $candidate['diversity_topics']);
	}

	public function testSecurityStoriesCarryBothTheirSpecificSecurityAndSoftwareTopics(): void {
		$catalog = new RecommendationTopicCatalog();
		$signals = new RecommendationSignalService(
			$this->createStub(RecommendationPreferenceService::class),
			$this->createStub(RecommendationSignalRequest::class),
			new RecommendationFeatureExtractor($catalog),
			$this->createStub(StreamRequest::class),
			$this->createStub(ITimeFactory::class),
			$this->createStub(IConfig::class),
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(LinkPreviewService::class),
		);
		$assembler = new RecommendationTimelineAssembler(
			$catalog,
			new RecommendationFeatureExtractor($catalog),
			new RecommendationExplanationService($catalog),
			new RecommendationBehavioralProfile($signals, $catalog),
		);
		$post = (new Note())->setContent('<p>PHP maintainers disclose a security vulnerability.</p>');

		$candidate = $assembler->candidate($post, '2', [], '', 1, [], [
			'topics' => [], 'authors' => [], 'formats' => [],
		], [], [], []);

		self::assertNotNull($candidate);
		self::assertContains('software.backend', $candidate['diversity_topics']);
		self::assertContains('tech.security', $candidate['diversity_topics']);
	}
}
