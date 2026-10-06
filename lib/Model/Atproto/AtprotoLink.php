<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Atproto;

use JsonSerializable;

/**
 * One AT-Proto record copied across, as held in `social_atproto_link`.
 *
 * The two id schemes do not meet: AT-Proto names a record by `at://` URI and
 * an ActivityPub instance can only store something whose id it can also pass
 * an origin check against — `parse_url('at://…')` has no host, so such an id
 * can never be accepted (see `ACore::checkOrigin`). Every record therefore
 * keeps its own `https://<cloud>/ap/bluesky/<did>/<collection>/<rkey>` as the
 * id it is stored and addressed under, and this row is where the `at://` URI,
 * the collection and the key live for the way back: an edit has to name the
 * record it edits, a delete the one it deletes, and both need the `cid` the
 * repository addresses a record with alongside its key.
 */
class AtprotoLink implements JsonSerializable {
	private int $nid = 0;
	private string $localId = '';
	private string $localIdPrim = '';
	private string $atUri = '';
	private string $cid = '';
	private string $did = '';
	private string $collection = '';
	private string $rkey = '';
	private string $handle = '';
	private ?string $creation = null;

	public function getNid(): int {
		return $this->nid;
	}

	public function setNid(int $nid): AtprotoLink {
		$this->nid = $nid;

		return $this;
	}

	public function getLocalId(): string {
		return $this->localId;
	}

	public function setLocalId(string $localId): AtprotoLink {
		$this->localId = $localId;

		return $this;
	}

	public function getLocalIdPrim(): string {
		return $this->localIdPrim;
	}

	public function setLocalIdPrim(string $localIdPrim): AtprotoLink {
		$this->localIdPrim = $localIdPrim;

		return $this;
	}

	public function getAtUri(): string {
		return $this->atUri;
	}

	public function setAtUri(string $atUri): AtprotoLink {
		$this->atUri = $atUri;

		return $this;
	}

	public function getCid(): string {
		return $this->cid;
	}

	public function setCid(string $cid): AtprotoLink {
		$this->cid = $cid;

		return $this;
	}

	public function getDid(): string {
		return $this->did;
	}

	public function setDid(string $did): AtprotoLink {
		$this->did = $did;

		return $this;
	}

	public function getCollection(): string {
		return $this->collection;
	}

	public function setCollection(string $collection): AtprotoLink {
		$this->collection = $collection;

		return $this;
	}

	public function getRkey(): string {
		return $this->rkey;
	}

	public function setRkey(string $rkey): AtprotoLink {
		$this->rkey = $rkey;

		return $this;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	public function setHandle(string $handle): AtprotoLink {
		$this->handle = $handle;

		return $this;
	}

	public function getCreation(): ?string {
		return $this->creation;
	}

	public function setCreation(?string $creation): AtprotoLink {
		$this->creation = $creation;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'local_id' => $this->localId,
			'at_uri' => $this->atUri,
			'cid' => $this->cid,
			'did' => $this->did,
			'collection' => $this->collection,
			'rkey' => $this->rkey,
			'handle' => $this->handle,
		];
	}
}
