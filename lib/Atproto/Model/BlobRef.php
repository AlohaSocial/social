<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

use OCA\Social\Atproto\Protocol\Cid;

/**
 * A blob a repository holds: the stored document it is, by CID.
 */
final class BlobRef {
	public function __construct(
		public readonly string $did,
		public readonly Cid $cid,
		public readonly string $documentId,
		public readonly string $mime,
		public readonly int $size,
	) {
	}

	/** the reference a record carries */
	public function toRecordValue(): array {
		return ['$type' => 'blob', 'ref' => $this->cid, 'mimeType' => $this->mime, 'size' => $this->size];
	}
}
