<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\NotificationPolicyService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may send a person direct messages, on Bluesky: the
 * `chat.bsky.actor.declaration` record in their repository (`self`), whose
 * `allowIncoming` the chat service goes by — `all`, `following` or `none`,
 * the same three answers as the setting here
 * (`NotificationPolicyService::directMessagesFrom()`). Written when the
 * setting changes; on the account's first read of its messages only when
 * the setting is narrower than Bluesky's own default (`following`), so a
 * person is never opened to strangers on Bluesky without choosing it. One
 * a Bluesky app writes through this server becomes the setting here, which
 * writes it.
 */
class ChatDeclaration {
	public const COLLECTION = 'chat.bsky.actor.declaration';

	public function __construct(
		private Publisher $publisher,
		private AccountService $accounts,
		private ActorsRequest $actors,
		private NotificationPolicyService $policies,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The record as the setting says it.
	 *
	 * @return array{"$type": string, allowIncoming: string}
	 */
	public static function record(string $from): array {
		return ['$type' => self::COLLECTION, 'allowIncoming' => $from];
	}

	/**
	 * The setting here just changed: written to the person's repository,
	 * for a person with a Bluesky identity.
	 */
	public function changed(string $userId, string $from): void {
		try {
			$this->publisher->writeSelfRecord($this->accounts->getActorFromUserId($userId), self::COLLECTION, self::record($from));
		} catch (Throwable $e) {
			$this->logger->info('Who may send direct messages not written to Bluesky', ['user' => $userId, 'exception' => $e]);
		}
	}

	/**
	 * Written when the repository has none and the setting here allows less
	 * than Bluesky's default does: nobody. A wider setting waits until the
	 * person sets it, as Bluesky's default only lets people they follow in.
	 */
	public function ensure(Identity $identity): void {
		try {
			$actor = $this->actors->getFromId($identity->actorId);
			if ($this->policies->directMessagesFrom($actor->getUserId()) === 'none' && !$this->publisher->hasSelfRecord($actor, self::COLLECTION)) {
				$this->publisher->writeSelfRecord($actor, self::COLLECTION, self::record('none'));
			}
		} catch (Throwable $e) {
			$this->logger->info('Who may send direct messages not written to Bluesky', ['did' => $identity->did, 'exception' => $e]);
		}
	}

	/**
	 * `putRecord` or `createRecord` of the declaration by a Bluesky app:
	 * the setting here, which writes the record.
	 *
	 * @throws XrpcException an rkey other than `self`, or an answer the setting does not have
	 */
	public function fromApp(ClientSession $session, string $rkey, array $record): void {
		$from = $record['allowIncoming'] ?? null;
		if ($rkey !== RecordMapper::PROFILE_RKEY || !is_string($from) || !in_array($from, NotificationPolicyService::DIRECT_FROM, true)) {
			throw XrpcException::invalidRequest('The declaration is kept under self, with allowIncoming all, following or none');
		}
		$before = $this->policies->directMessagesFrom($session->userId);
		$this->policies->saveDirectMessagesFrom($session->userId, $from);
		if ($before === $from) {
			// unchanged here, so nothing was written; the app still gets its record
			$this->changed($session->userId, $from);
		}
	}
}
