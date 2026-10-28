<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\DocumentService;
use OCP\IDBConnection;
use Throwable;

/** Original upload bytes, independent of Social's publishing conversions. */
class BlobUploadService {
	public function __construct(
		private DocumentService $documents,
		private AtprotoBlobRequest $blobs,
		private AtprotoRepoRequest $repositories,
		private IDBConnection $connection,
	) {
	}

	public function store(Identity $identity, Person $owner, string $path): BlobRef {
		$size = filesize($path);
		$hash = hash_file('sha256', $path, true);
		if ($size === false || $hash === false) {
			throw new AtprotoException('The uploaded blob could not be read');
		}
		$cid = Cid::forRawDigest($hash);
		$known = $this->blobs->get($identity->did, $cid->toString());
		if ($known !== null) {
			return $known;
		}
		$document = $this->documents->storeAsIs($owner, $path, false);
		$blob = new BlobRef($identity->did, $cid, $document->getId(), $document->getMimeType(), $size);
		$this->connection->beginTransaction();
		try {
			if ($this->blobs->put($blob)) {
				$this->repositories->addBlobBytes($identity->did, $size);
			} else {
				$blob = $this->blobs->get($identity->did, $cid->toString())
					?? throw new AtprotoException('The blob registration is no longer available');
			}
			$this->connection->commit();
		} catch (Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}

		return $blob;
	}
}
