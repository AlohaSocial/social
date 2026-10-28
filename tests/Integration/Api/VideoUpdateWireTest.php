<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\StatusApiController;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCP\Server;

class VideoUpdateWireTest extends ApiTestCase {
	public function testAnApiVideoEditQueuesTheEditedActivityPubNoteAfterDatabaseReload(): void {
		$status = $this->publish('alice', ['status' => 'original video caption']);
		$streams = Server::get(StreamRequest::class);
		$post = $streams->getStreamById($status->uri);
		$media = (new MediaAttachment())->setId('video-wire-fixture')->setType('video')
			->setUrl('https://media.example/video.mp4')->setMediaType('video/mp4');
		$post->setAttachments([$media]);
		$post->addInstancePath(new InstancePath('https://pixelfed.example/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW));
		$streams->update($post, true);
		$config = Server::get(ConfigService::class);
		$before = $config->getAppValue(ConfigService::SOCIAL_PUBLISH_VIDEO);
		$config->setAppValue(ConfigService::SOCIAL_PUBLISH_VIDEO, '0');
		$activities = Server::get(ActivityService::class);
		$activities->beginDeferredDeliveries();
		try {
			$edited = $this->call(StatusApiController::class, 'statusUpdate', ['nid' => $status->id],
				['status' => 'edited video caption'], 'alice', 'PUT', services: [ActivityService::class => $activities]);
			$this->assertSame(200, $edited->status, json_encode($edited->body) ?: '');
			$rows = Server::get(RequestQueueRequest::class)->getByObject(md5($status->uri));
			$this->assertCount(1, $rows);
			$wire = json_decode($rows[0]->getActivity(), true, flags: JSON_THROW_ON_ERROR);
			$this->assertSame('Update', $wire['type']);
			$this->assertSame('Note', $wire['object']['type']);
			$this->assertSame($status->uri, $wire['object']['id']);
			$this->assertStringContainsString('edited video caption', $wire['object']['content']);
			$this->assertNotEmpty($wire['object']['updated']);
			$this->assertSame('video/mp4', $wire['object']['attachment'][0]['mediaType']);
			$this->assertSame('https://media.example/video.mp4', $wire['object']['attachment'][0]['url']);
			$this->assertSame($wire['actor'], $wire['object']['attributedTo']);
		} finally {
			$activities->discardDeferredDeliveries();
			$config->setAppValue(ConfigService::SOCIAL_PUBLISH_VIDEO, $before);
		}
	}
}
