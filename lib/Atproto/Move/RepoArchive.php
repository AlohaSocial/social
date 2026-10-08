<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\MstReader;
use OCA\Social\Exceptions\AtprotoException;

/**
 * A repository as another PDS exports it (`getRepo`), checked before any of
 * it is kept: every block hashes to its CID, the commit is the account's and
 * signed with the key its DID document names, and every record the tree
 * names is in the file.
 */
final class RepoArchive {
	/**
	 * @return array{rev: string, records: array<string, string>} the revision and path => record bytes
	 * @throws AtprotoException when the archive is not the account's repository
	 */
	public static function read(string $car, string $did, PublicKey $signingKey): array {
		try {
			$decoded = Car::decode($car);
			$root = $decoded['roots'][0] ?? null;
			$commitBytes = $root === null ? null : ($decoded['blocks'][$root->toString()] ?? null);
			if ($commitBytes === null) {
				throw new InvalidArgumentException('The archive has no commit');
			}
			$commit = Commit::fromBytes($commitBytes);
			if ($commit->did !== $did) {
				throw new InvalidArgumentException('The archive is another account\'s');
			}
			if (!$commit->verify($signingKey)) {
				throw new InvalidArgumentException('The commit is not signed with the account\'s key');
			}
			$records = [];
			foreach (MstReader::leaves($commit->data, $decoded['blocks']) as $path => $cid) {
				$bytes = $decoded['blocks'][$cid->toString()] ?? null;
				if ($bytes === null) {
					throw new InvalidArgumentException('Record ' . $path . ' is missing from the archive');
				}
				$records[$path] = $bytes;
			}
		} catch (InvalidArgumentException $e) {
			throw new AtprotoException('The repository could not be read: ' . $e->getMessage(), 0, $e);
		}

		return ['rev' => $commit->rev, 'records' => $records];
	}
}
