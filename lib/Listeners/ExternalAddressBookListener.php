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
 * Keeps external users out of the system address book, which the contacts
 * menu, the share dialog and every CardDAV client read: their display name and
 * email cannot be made private, so the card is removed as soon as it is
 * written. It is recognised by its name, `Social:<uid>.vcf`.
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
