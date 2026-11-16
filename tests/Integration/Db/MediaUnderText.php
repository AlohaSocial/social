<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use OCP\Server;
use ReflectionProperty;

/**
 * Media posts at the ratio a real timeline has them: three photographs and
 * two videos, under sixty posts of text that are all newer.
 *
 * A narrowing applied after a page was chosen — the recipient-index fast path
 * reading the newest sixty rows and then throwing the text away — answers a
 * seeded timeline of five posts perfectly and a real one with nothing: the
 * pictures sit below the window it read. Five posts cannot reproduce that
 * trap; this seed is the smallest one that can.
 */
trait MediaUnderText {
	/** @var string[] */
	private array $mediaUnderText = [];

	/** @return array<string, array{bool}> */
	public static function homeFastPath(): array {
		return [
			'fast path on' => [true],
			'fast path off' => [false],
		];
	}

	/**
	 * @return array{photos: string[], videos: string[]} the media posts' ids,
	 *                                                   newest first
	 */
	private function seedMediaUnderText(string $base, Person $author): array {
		$published = time() - 100;
		$seeded = ['photos' => [], 'videos' => []];
		foreach ([['image', 'photos', 3], ['video', 'videos', 2]] as [$type, $kind, $count]) {
			for ($i = 0; $i < $count; $i++) {
				$media = new MediaAttachment();
				$media->setId($kind . $i);
				$media->setType($type);
				$media->setUrl($base . '/media/' . $kind . $i);
				$media->setPreviewUrl($base . '/media/' . $kind . $i);
				array_unshift($seeded[$kind], $this->postUnderText($base . '/ratio/' . $kind . '-' . $i, $author, ++$published, [$media]));
			}
		}
		for ($i = 0; $i < 60; $i++) {
			$this->postUnderText($base . '/ratio/text-' . $i, $author, ++$published, []);
		}

		return $seeded;
	}

	/** @param MediaAttachment[] $attachments */
	private function postUnderText(string $id, Person $author, int $published, array $attachments): string {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo($author->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author->getFollowers()]);
		$note->setContent('<p>' . basename($id) . '</p>');
		$note->setAttachments($attachments);
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		Server::get(StreamRequest::class)->save($note);
		$this->mediaUnderText[] = $id;

		return $id;
	}

	private function removeMediaUnderText(): void {
		$streamRequest = Server::get(StreamRequest::class);
		foreach ($this->mediaUnderText as $id) {
			$streamRequest->deleteById($id, Note::TYPE);
		}
		$this->mediaUnderText = [];
	}

	private function useHomeFastPath(bool $fast): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_DEST_NID_FILLED, $fast ? '1' : '0');
		// the request memoises the flag for the life of the instance
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))
			->setValue(Server::get(StreamRequest::class), null);
	}

	/**
	 * Reads a timeline the way a client pages it: `$limit` at a time, each
	 * page below the oldest id of the one before, until a page comes back
	 * empty. Only the posts under `$base` are kept, so whatever else the
	 * shared instance holds does not count.
	 *
	 * @param callable(ProbeOptions): void $narrow the probe and its narrowing
	 *
	 * @return list<list<string>> the ids of each page, the empty last one included
	 */
	private function pageThrough(string $base, int $limit, callable $narrow): array {
		$streamRequest = Server::get(StreamRequest::class);
		$pages = [];
		$maxId = 0;
		do {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)->setLimit($limit)->setMaxId($maxId);
			$narrow($options);
			$page = $streamRequest->getTimeline($options);

			$pages[] = array_values(array_filter(
				array_map(static fn (Stream $stream): string => $stream->getId(), $page),
				static fn (string $id): bool => str_starts_with($id, $base . '/ratio/')
			));
			if ($page !== []) {
				$maxId = min(array_map(static fn (Stream $stream): int|string => $stream->getNid(), $page));
			}
		} while ($page !== [] && count($pages) < 10);

		return $pages;
	}

	/**
	 * The narrowed timeline finds every media post below the text, on the
	 * first page of twenty and, read two at a time, across pages that follow
	 * on from each other without repeating or skipping one.
	 *
	 * @param string[] $expected newest first
	 * @param callable(ProbeOptions): void $narrow
	 */
	private function assertFoundUnderText(string $base, array $expected, callable $narrow, string $what): void {
		$this->assertSame(
			$expected, $this->pageThrough($base, 20, $narrow)[0],
			$what . ': a page of twenty finds every one of them under sixty newer posts of text'
		);

		$pages = $this->pageThrough($base, 2, $narrow);
		$this->assertSame(
			$expected, array_merge(...$pages),
			$what . ': paged two at a time with max_id, each page follows on from the last'
		);
		$this->assertSame([], end($pages), $what . ': and the walk ends on an empty page');
	}
}
