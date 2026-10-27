<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\ReplyRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ReplyRuleService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class ReplyRequestInterface extends AbstractActivityPubInterface {
	public function __construct(
		private StreamRequest $streams,
		private ReplyRuleService $replyRules,
		private CacheActorService $actors,
		private ActivityService $activities,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item instanceof ReplyRequest) {
			return;
		}

		$replyId = $item->getInstrument();
		$targetId = $item->getObjectId();
		$reply = $item->getInstrumentObject();
		$actor = $item->getActorId();
		if ($replyId === '' || $targetId === '' || $actor === '') {
			return;
		}

		if ($reply === []) {
			// A URI-only instrument cannot be checked against its author and
			// inReplyTo fields. Resolve no arbitrary remote URL here: if this is
			// a request for one of our posts, explicitly reject it so the sender
			// does not wait forever for a decision that will never arrive.
			$item->checkOrigin($replyId);
			$item->checkOrigin($actor);
			try {
				$target = $this->streams->getStreamById($targetId);
			} catch (StreamNotFoundException) {
				return;
			}
			$recipients = array_merge($item->getToAll(), $item->getCcArray(), $item->getBccArray());
			if ($target->isLocal() && in_array($target->getAttributedTo(), $recipients, true)) {
				$this->answer($item, $target, false, '');
			}

			return;
		}

		$item->checkOrigin($replyId);
		$replyAuthor = $this->id($reply['attributedTo'] ?? '');
		if (($reply['id'] ?? '') !== $replyId
			|| $replyAuthor === ''
			|| $replyAuthor !== $actor
			|| $this->id($reply['inReplyTo'] ?? '') !== $targetId
			|| !in_array(($reply['type'] ?? ''), ['Note', 'Article', 'Page'], true)) {
			$this->logger->notice('GoToSocial ReplyRequest did not contain a matching reply', [
				'activity' => $item->getId(),
				'reply' => $replyId,
				'target' => $targetId,
			]);

			return;
		}
		$item->checkActor($actor, $replyAuthor);

		try {
			$target = $this->streams->getStreamById($targetId);
		} catch (StreamNotFoundException $e) {
			return;
		}
		$recipients = array_merge($item->getToAll(), $item->getCcArray(), $item->getBccArray());
		if (!$target->isLocal() || !in_array($target->getAttributedTo(), $recipients, true)) {
			return;
		}
		// The authorization URL is intentionally public and serves no post
		// content. Do not issue a grant for a private target that its later
		// anonymous verifier must refuse to disclose.
		if (!$target->isPublic()
			&& !in_array($target->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true)) {
			$this->answer($item, $target, false, '');

			return;
		}

		if ($this->replyRules->refusal($target, $actor) !== '') {
			$this->answer($item, $target, false, '');

			return;
		}

		$secret = $this->config->getSystemValueString('secret', '');
		if ($secret === '') {
			$this->logger->error('Cannot authorize a GoToSocial reply without the instance secret', ['target' => $targetId]);

			return;
		}
		$stamp = self::authorizationStamp($replyId, $targetId, $secret);
		$authorization = $targetId . '/reply_authorizations/' . $stamp;
		$this->answer($item, $target, true, $authorization);
	}

	private function id(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_array($value)) {
			$id = $value['id'] ?? '';
			if (is_string($id)) {
				return $id;
			}
			if (array_is_list($value)) {
				foreach ($value as $entry) {
					$id = $this->id($entry);
					if ($id !== '') {
						return $id;
					}
				}
			}
		}
		return '';
	}

	public static function authorizationStamp(string $replyId, string $targetId, string $secret): string {
		$encoded = rtrim(strtr(base64_encode($replyId), '+/', '-_'), '=');
		$signature = hash_hmac('sha256', $targetId . "\n" . $replyId, $secret);

		return $encoded . '.' . $signature;
	}

	public static function replyFromStamp(string $stamp, string $targetId, string $secret): string {
		[$encoded, $signature] = array_pad(explode('.', $stamp, 2), 2, '');
		$replyId = base64_decode(strtr($encoded, '-_', '+/'), true);
		if (!is_string($replyId) || $replyId === '') {
			return '';
		}
		if (rtrim(strtr(base64_encode($replyId), '+/', '-_'), '=') !== $encoded
			|| !hash_equals(hash_hmac('sha256', $targetId . "\n" . $replyId, $secret), $signature)
			|| !(str_starts_with($replyId, 'https://') || str_starts_with($replyId, 'http://'))) {
			return '';
		}

		return $replyId;
	}

	private function answer(ReplyRequest $request, Stream $target, bool $accept, string $authorization): void {
		try {
			$actor = $this->actors->getFromId($request->getActorId());
		} catch (Throwable $e) {
			$this->logger->notice('Cannot answer a GoToSocial ReplyRequest: requester is unknown', [
				'activity' => $request->getId(),
				'actor' => $request->getActorId(),
				'exception' => $e,
			]);

			return;
		}

		$inbox = $actor->getSharedInbox() !== '' ? $actor->getSharedInbox() : $actor->getInbox();
		if ($inbox === '') {
			return;
		}

		$answer = $accept ? new Accept() : new Reject();
		$answer->generateUniqueIdFromActor($target->getAttributedTo(), $accept ? 'accept/reply-requests' : 'reject/reply-requests');
		$answer->setActorId($target->getAttributedTo());
		$answer->setObject($request);
		$answer->setToArray([$request->getActorId()]);
		if ($accept) {
			$answer->addEntry('result', $authorization);
		}
		$answer->addInstancePath(new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP));
		try {
			$this->activities->request($answer);
		} catch (Throwable $e) {
			$this->logger->error('Could not send the GoToSocial ReplyRequest answer', [
				'activity' => $request->getId(),
				'exception' => $e,
			]);
		}
	}
}
