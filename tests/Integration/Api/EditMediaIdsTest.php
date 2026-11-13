<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\StatusApiController;

/**
 * Editing a post with `media_ids` (`PUT /api/v1/statuses/{id}`) keeps the
 * attachments it names as a new post carries them: with their id, type and
 * alt text, in the edit's answer and on every read after it, so that the next
 * edit can name them again.
 */
class EditMediaIdsTest extends ApiTestCase {
	private function upload(string $as, string $description): \stdClass {
		$file = tempnam(sys_get_temp_dir(), 'apiedit');
		$image = imagecreatetruecolor(40, 20);
		imagefilledrectangle($image, 0, 0, 39, 19, (int)imagecolorallocate($image, 200, 60, 30));
		imagepng($image, $file);

		$upload = $this->call(MediaApiController::class, 'mediaNew', [], [
			'description' => $description,
		], $as, 'POST', '/apps/social/api/v1/media', [
			'file' => ['name' => 'square.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
		]);
		@unlink($file);
		$this->assertSame(200, $upload->status, json_encode($upload->body) ?: '');

		return $upload->body;
	}

	private function edit(string $statusId, string $text, array $mediaIds): ApiResponse {
		return $this->call(StatusApiController::class, 'statusUpdate', ['nid' => $statusId], [
			'status' => $text,
			'media_ids' => $mediaIds,
		], 'alice', 'PUT');
	}

	/** @param list<string> $ids */
	private function assertAttachments(array $ids, \stdClass $status, string $when): void {
		$this->assertSame($ids, array_map(static fn (\stdClass $a): string => $a->id, $status->media_attachments), $when);
		foreach ($status->media_attachments as $attachment) {
			$this->assertSame('image', $attachment->type, $when);
			$this->assertNotNull($attachment->url, $when);
		}
		$this->assertEntity('Status', $status, $when);
	}

	public function testAnEditKeepsTheAttachmentsItNames(): void {
		$media = $this->upload('alice', 'a red square');
		$status = $this->publish('alice', ['status' => 'probe', 'media_ids' => [$media->id], 'visibility' => 'direct']);
		$this->assertAttachments([$media->id], $status, 'the new post');

		$edited = $this->edit($status->id, 'probe edited', [$media->id]);
		$this->assertSame(200, $edited->status, json_encode($edited->body) ?: '');
		$this->assertAttachments([$media->id], $edited->body, 'the answer to the edit');
		$this->assertSame('a red square', $edited->body->media_attachments[0]->description);

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $status->id], [], 'alice');
		$this->assertSame(200, $read->status, json_encode($read->body) ?: '');
		$this->assertAttachments([$media->id], $read->body, 'a read after the edit');

		// the ids the post now shows are what the next edit names
		$again = $this->edit($status->id, 'probe edited twice', array_map(
			static fn (\stdClass $a): string => $a->id, $read->body->media_attachments
		));
		$this->assertSame(200, $again->status, json_encode($again->body) ?: '');
		$this->assertAttachments([$media->id], $again->body, 'a second edit');
	}

	public function testAnEditCanAddAndReorderAttachments(): void {
		$first = $this->upload('alice', 'first');
		$second = $this->upload('alice', 'second');
		$status = $this->publish('alice', ['status' => 'one picture', 'media_ids' => [$first->id], 'visibility' => 'direct']);

		$edited = $this->edit($status->id, 'two pictures', [$second->id, $first->id]);
		$this->assertSame(200, $edited->status, json_encode($edited->body) ?: '');
		$this->assertAttachments([$second->id, $first->id], $edited->body, 'the answer to the edit');
	}
}
