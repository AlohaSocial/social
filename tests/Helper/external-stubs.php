<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Classes of the dav app, of Sabre and of a proposed server event that the
 * external-user code refers to and the standalone suite does not have: just
 * enough of each to construct and inspect them. Declared only where the real
 * ones are absent.
 */

namespace Sabre\DAV {
	if (!class_exists(Server::class, false)) {
		class Server {
			/** @var array<string, list<array{callable, int}>> */
			public array $listeners = [];

			public function on(string $eventName, callable $callBack, int $priority = 100): void {
				$this->listeners[$eventName][] = [$callBack, $priority];
			}
		}
	}

	if (!class_exists(Exception::class, false)) {
		class Exception extends \Exception {
		}
	}
}

namespace Sabre\DAV\Exception {
	if (!class_exists(Forbidden::class, false)) {
		class Forbidden extends \Sabre\DAV\Exception {
		}
	}
}

namespace OCA\DAV\Events {
	use OCP\EventDispatcher\Event;

	if (!class_exists(SabrePluginAddEvent::class, false)) {
		class SabrePluginAddEvent extends Event {
			public function __construct(
				private \Sabre\DAV\Server $server,
			) {
				parent::__construct();
			}

			public function getServer(): \Sabre\DAV\Server {
				return $this->server;
			}
		}
	}

	if (!class_exists(CardCreatedEvent::class, false)) {
		class CardCreatedEvent extends Event {
			public function __construct(
				private int $addressBookId,
				private array $addressBookData,
				private array $shares,
				private array $cardData,
			) {
				parent::__construct();
			}

			public function getAddressBookId(): int {
				return $this->addressBookId;
			}

			public function getAddressBookData(): array {
				return $this->addressBookData;
			}

			public function getShares(): array {
				return $this->shares;
			}

			public function getCardData(): array {
				return $this->cardData;
			}
		}
	}

	if (!class_exists(CardUpdatedEvent::class, false)) {
		class CardUpdatedEvent extends CardCreatedEvent {
		}
	}
}

namespace OCA\DAV\CardDAV {
	if (!class_exists(SyncService::class, false)) {
		class SyncService {
			/** @var list<mixed> */
			public array $deleted = [];
			/** @var list<\OCP\IUser> */
			public array $updated = [];

			public function updateUser(\OCP\IUser $user): void {
				$this->updated[] = $user;
			}

			public function deleteUser($userOrCardId): void {
				$this->deleted[] = $userOrCardId;
			}
		}
	}
}

namespace OCP\Navigation\Events {
	use OCP\EventDispatcher\Event;

	if (!class_exists(NavigationEntriesFilterEvent::class)) {
		class NavigationEntriesFilterEvent extends Event {
			public function __construct(
				private array $entries,
				private string $type = 'link',
			) {
				parent::__construct();
			}

			public function getEntries(): array {
				return $this->entries;
			}

			public function setEntries(array $entries): void {
				$this->entries = $entries;
			}

			public function getType(): string {
				return $this->type;
			}
		}
	}
}

namespace {
	// what `OCP\Util::addStyle()` hands a stylesheet to, recorded rather than
	// rendered, so a listener that adds one can be asked what it added
	if (!class_exists('OC_Util', false)) {
		class OC_Util {
			/** @var list<string> app/file */
			public static array $styles = [];

			public static function addStyle($application, $file = null, $prepend = false): void {
				self::$styles[] = $application . '/' . $file;
			}
		}
	}
}
