<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\PeerTubeService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Whether replies to a video have to be approved — FEP-5624, which PeerTube
 * ≥ 6.2 moderates comments with.
 *
 * Without reading it, a reply written here to a moderated video looked posted,
 * sat in a queue on the other side, and either appeared a day later or never,
 * with nothing anywhere to say which.
 */
class PeerTubeCommentPolicyTest extends TestCase {
	private PeerTubeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PeerTubeService(
			$this->createStub(DocumentInterface::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	public function testAVideoThatSaysNothingModeratesNothing(): void {
		$this->assertFalse($this->service->repliesNeedApproval(['type' => 'Video']));
	}

	public function testCommentsPolicyThreeMeansApproval(): void {
		$this->assertTrue($this->service->repliesNeedApproval(['commentsPolicy' => 3]));
		$this->assertFalse($this->service->repliesNeedApproval(['commentsPolicy' => 1]));
		$this->assertFalse($this->service->repliesNeedApproval(['commentsPolicy' => 2]));
	}

	/** Newer PeerTube sends the policy as an object with a label beside it. */
	public function testThePolicyIsReadWhenItArrivesAsAnObject(): void {
		$this->assertTrue($this->service->repliesNeedApproval([
			'commentsPolicy' => ['id' => 3, 'label' => 'Requires approval'],
		]));
		$this->assertFalse($this->service->repliesNeedApproval([
			'commentsPolicy' => ['id' => 1, 'label' => 'Enabled'],
		]));
	}

	/**
	 * `canReply` is the FEP's own field: it names who may reply without being
	 * approved, so a video that states an audience which is not everybody is
	 * moderating the rest — and from here that is us.
	 */
	public function testCanReplyNamingThePublicCollectionIsNotModeration(): void {
		$this->assertFalse($this->service->repliesNeedApproval([
			'canReply' => ACore::CONTEXT_PUBLIC,
		]));
		$this->assertFalse($this->service->repliesNeedApproval([
			'canReply' => [['type' => 'Collection', 'id' => ACore::CONTEXT_PUBLIC]],
		]));
	}

	public function testCanReplyNamingSomebodyElseMeansApproval(): void {
		$this->assertTrue($this->service->repliesNeedApproval([
			'canReply' => ['https://peertube.example/video-channels/news/followers'],
		]));
	}

	/** The one PeerTube actually sends wins over the FEP's general field. */
	public function testThePolicyIsReadBeforeCanReply(): void {
		$this->assertFalse($this->service->repliesNeedApproval([
			'commentsPolicy' => 1,
			'canReply' => ['https://peertube.example/nobody'],
		]));
	}
}
