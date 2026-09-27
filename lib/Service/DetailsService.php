<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDetails;

/**
 * Class DetailsService
 *
 * @package OCA\Social\Service
 */
class DetailsService {
	public function __construct(
		private StreamService $streamService,
		private AccountService $accountService,
		private FollowService $followService,
	) {
	}

	/**
	 * @param Stream $stream
	 *
	 * @return StreamDetails
	 * @throws SocialAppConfigException
	 */
	public function generateDetailsFromStream(Stream $stream): StreamDetails {
		$this->streamService->detectType($stream);

		$details = new StreamDetails($stream);
		$this->setStreamViewers($details);

		if ($stream->getTimeline() === Stream::TYPE_PUBLIC) {
			if ($stream->isLocal()) {
				$details->setPublic(true);
			} else {
				$details->setFederated(true);
			}
		}

		return $details;
	}

	/**
	 * @param StreamDetails $details
	 *
	 * @throws SocialAppConfigException
	 */
	private function setStreamViewers(StreamDetails $details) {
		$stream = $details->getStream();

		foreach ($stream->getRecipients(true) as $recipient) {
			try {
				$actor = $this->accountService->getFromId($recipient);
				$details->addDirectViewer($actor);
			} catch (ActorDoesNotExistException $e) {
			}

			$followers = $this->followService->getFollowersFromFollowId($recipient);
			foreach ($followers as $follower) {
				if (!$follower->hasActor()) {
					continue;
				}

				$actor = $follower->getActor();
				$details->addHomeViewer($actor);
			}
		}
	}
}
