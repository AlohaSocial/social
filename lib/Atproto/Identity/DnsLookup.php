<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

/**
 * The TXT records of a name, as the system resolver answers them.
 */
class DnsLookup {
	/**
	 * @return string[] each record's text, its strings joined; empty when there is none
	 */
	public function txt(string $name): array {
		$records = @dns_get_record($name, DNS_TXT);
		if (!is_array($records)) {
			return [];
		}
		$texts = [];
		foreach ($records as $record) {
			if (isset($record['entries']) && is_array($record['entries'])) {
				$texts[] = implode('', array_map('strval', $record['entries']));
			} elseif (isset($record['txt'])) {
				$texts[] = (string)$record['txt'];
			}
		}

		return $texts;
	}
}
