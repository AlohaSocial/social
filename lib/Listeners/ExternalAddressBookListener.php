<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\DAV\CardDAV\SyncService;
use OCA\DAV\Events\CardCreatedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\Social\External\ExternalUserBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps external users out of the system address book.
 *
 * Nextcloud writes a card there for every enabled user, and the contacts
 * menu, the share dialog's email and federated suggestions and every CardDAV
 * client read it. An external user's display name and email cannot be made
 * private, so the card is removed the moment it is written instead. A card's
 * name carries the user backend's (`Social:<uid>.vcf`), which is how it is
 * recognised without a lookup.
 *
 * @template-implements IEventListener<CardCreatedEvent|CardUpdatedEvent>
 */
class ExternalAddressBookListener implements IEventListener {
	public const CARD_PREFIX = ExternalUserBackend::BACKEND_NAME . ':';

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof CardCreatedEvent) && !($event instanceof CardUpdatedEvent)) {
			return;
		}

		$book = $event->getAddressBookData();
		$uri = (string)($event->getCardData()['uri'] ?? '');
		if (($book['principaluri'] ?? '') !== 'principals/system/system'
			|| ($book['uri'] ?? '') !== 'system'
			|| !str_starts_with($uri, self::CARD_PREFIX)) {
			return;
		}

		try {
			Server::get(SyncService::class)->deleteUser($uri);
		} catch (Throwable $e) {
			$this->logger->warning('could not keep an external user out of the system address book', [
				'card' => $uri, 'exception' => $e,
			]);
		}
	}
}
