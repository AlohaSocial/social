<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\ApproveReply;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use Psr\Log\LoggerInterface;

/**
 * FEP-5624 `ApproveReply`, as PeerTube ≥ 6.2 sends it when a moderated video
 * approves one of our replies; it only moves the state of our stored reply.
 * Accepted only from the author of the post replied to, on the server that
 * holds it.
 */
class ApproveReplyInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException the approval does not come from the author
	 *                                of the post the reply answers, on the server
	 *                                that holds it
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item instanceof ApproveReply) {
			return;
		}

		$replyId = $item->getObjectId();
		if ($replyId === '') {
			return;
		}

		try {
			$reply = $this->streamRequest->getStreamById($replyId);
		} catch (StreamNotFoundException $e) {
			// an approval about something we never wrote
			return;
		}

		if (!$reply->isLocal() || $reply->getInReplyTo() === '') {
			return;
		}

		// the approval has to come from the server that holds the post being
		// replied to: anybody else saying so would be a stranger approving a
		// reply on somebody else's post
		$item->checkOrigin($reply->getInReplyTo());

		try {
			$parent = $this->streamRequest->getStreamById($reply->getInReplyTo());
		} catch (StreamNotFoundException $e) {
			// nobody here can be told to be the author of a post that is not here
			return;
		}

		if (!in_array($item->getActorId(), self::authorsOf($parent), true)) {
			throw new InvalidOriginException('a reply is approved by the author of the post it answers');
		}

		$reply->setReplyState(Stream::REPLY_APPROVED);
		$this->streamRequest->updateDetails($reply, Details::REPLY_STATE);

		$this->logger->info('a reply of ours was approved', [
			'reply' => $replyId, 'inReplyTo' => $reply->getInReplyTo(),
		]);
	}

	/**
	 * Who wrote the post: the author it is stored under, and every actor its
	 * own document names in `attributedTo`. A PeerTube video names its channel
	 * and the account behind it, and PeerTube approves as the account.
	 *
	 * @return list<string>
	 */
	private static function authorsOf(Stream $post): array {
		$authors = [$post->getAttributedTo()];

		$source = json_decode($post->getSource(), true);
		$named = is_array($source) ? ($source['attributedTo'] ?? []) : [];
		foreach (is_array($named) ? $named : [$named] as $entry) {
			$id = is_array($entry) ? ($entry['id'] ?? '') : $entry;
			if (is_string($id) && $id !== '') {
				$authors[] = $id;
			}
		}

		return array_values(array_filter($authors, static fn (string $id): bool => $id !== ''));
	}
}
