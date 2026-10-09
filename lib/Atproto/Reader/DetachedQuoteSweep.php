<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Notices a quote from here that its quoted author detached on Bluesky. The
 * fediverse says so (a `Reject` of the quote request); Bluesky does not —
 * the author lists the quote in their post's postgate — so the posts quoting
 * a Bluesky post of the last month are checked a page at a time
 * (`Postgates::detached()`), and a detached one is withdrawn here, as one
 * taken back on the fediverse is. The place it got to is kept, and it
 * starts over when it reaches the end.
 */
class DetachedQuoteSweep {
	public const WINDOW = 30 * 86400;
	public const PAGE = 10;

	public function __construct(
		private StreamRequest $streams,
		private Postgates $postgates,
		private PostRefs $refs,
		private ConfigService $configService,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return int how many quotes were found detached
	 */
	public function run(): int {
		$after = (int)$this->configService->getAppValue(ConfigService::ATPROTO_DETACH_CURSOR);
		$quoting = $this->streams->getLocalQuotesOfBluesky($this->time->getTime() - self::WINDOW, $after, self::PAGE);
		if ($quoting === []) {
			$this->configService->setAppValue(ConfigService::ATPROTO_DETACH_CURSOR, '0');

			return 0;
		}
		$detached = 0;
		foreach ($quoting as $post) {
			if ($post->getQuoteState() !== Stream::QUOTE_REVOKED && $this->isDetached($post)) {
				$post->setQuoteState(Stream::QUOTE_REVOKED);
				$this->streams->updateDetails($post);
				$detached++;
			}
		}
		$this->configService->setAppValue(ConfigService::ATPROTO_DETACH_CURSOR, (string)max(array_map(static fn (Stream $p): int => (int)$p->getNid(), $quoting)));

		return $detached;
	}

	private function isDetached(Stream $post): bool {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		if ($uri === '') {
			return false;
		}
		try {
			$quoted = $this->streams->getStreamById($post->getQuote());
		} catch (StreamNotFoundException) {
			return false;
		}

		return $this->postgates->detached($quoted, $uri);
	}
}
