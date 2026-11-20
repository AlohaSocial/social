<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * An account's public posts as an RSS 2.0 feed, as PeerTube and Mastodon
 * publish (`/@user.rss`).
 *
 * Readable signed out, so the caller passes posts read with no viewer, and
 * this class refuses anything that is not public a second time: a leaked
 * followers-only post in a feed reader cannot be taken back.
 */
class FeedService {
	/** How many posts a feed carries. PeerTube's own is twenty. */
	public const LIMIT = 20;

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/**
	 * @param Stream[] $posts
	 */
	public function forAccount(Person $actor, array $posts): string {
		$title = $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername();
		$link = $actor->getId();

		$items = '';
		foreach ($posts as $post) {
			// the second refusal of non-public posts (see the class comment)
			if (!$post->isPublic()) {
				continue;
			}

			$items .= $this->item($post, $actor);
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" '
			. 'xmlns:media="http://search.yahoo.com/mrss/">' . "\n"
			. '<channel>' . "\n"
			. '<title>' . $this->escape($title) . '</title>' . "\n"
			. '<link>' . $this->escape($link) . '</link>' . "\n"
			. '<description>' . $this->escape($this->descriptionOf($actor)) . '</description>' . "\n"
			. '<language>' . $this->escape($actor->getLanguage() !== '' ? $actor->getLanguage() : 'en') . '</language>' . "\n"
			. '<atom:link href="' . $this->escape($link . '.rss') . '" rel="self" type="application/rss+xml"/>' . "\n"
			. $items
			. '</channel>' . "\n"
			. '</rss>' . "\n";
	}

	/**
	 * One post as an item. A video carries an `enclosure`, which podcast
	 * clients play.
	 */
	private function item(Stream $post, Person $actor): string {
		$title = $this->titleOf($post, $actor);
		$link = $post->getId();

		$item = '<item>' . "\n"
			. '<title>' . $this->escape($title) . '</title>' . "\n"
			. '<link>' . $this->escape($link) . '</link>' . "\n"
			. '<guid isPermaLink="true">' . $this->escape($link) . '</guid>' . "\n"
			. '<pubDate>' . gmdate('D, d M Y H:i:s O', $post->getPublishedTime()) . '</pubDate>' . "\n"
			. '<description>' . $this->escape($post->getContent()) . '</description>' . "\n";

		foreach ($post->getAttachments() as $attachment) {
			$url = (string)$attachment->getUrl();
			if ($url === '' || $attachment->getType() !== 'video') {
				continue;
			}

			// one enclosure per item is what RSS allows and what a podcast
			// client reads; a post with several videos is not a video post
			$item .= '<enclosure url="' . $this->escape($url) . '" type="'
				. $this->escape($attachment->getMediaType() !== '' ? $attachment->getMediaType() : 'video/mp4')
				. '" length="' . $attachment->getSizeBytes() . '"/>' . "\n";
			break;
		}

		return $item . '</item>' . "\n";
	}

	/**
	 * What to call a post in a list of titles: a video's name, or else the
	 * post's first line.
	 */
	private function titleOf(Stream $post, Person $actor): string {
		$video = $post->getVideoMeta();
		if (($video['title'] ?? '') !== '') {
			return (string)$video['title'];
		}

		$text = trim(html_entity_decode(
			strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $post->getContent())),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		));
		$first = trim((string)strtok($text, "\n"));

		return ($first !== '') ? mb_substr($first, 0, 120) : $actor->getPreferredUsername();
	}

	private function descriptionOf(Person $actor): string {
		$bio = trim(strip_tags($actor->getDescription()));

		return ($bio !== '') ? $bio : $actor->getPreferredUsername() . ' on ' . $this->host();
	}

	private function host(): string {
		try {
			return $this->configService->getCloudHost();
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
