<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;

/**
 * What a Bluesky reference to one of this instance's own repositories is
 * here: a reply to, quote of or like of `at://<local did>/app.bsky.feed.post/<rkey>`
 * means the local post that record was written for, and a mention of a
 * local DID means the local account. Anything else keeps its bsky.app id.
 */
class LocalRecordResolver {
	/** @var array<string, string> DID → local actor id, '' when not local */
	private array $actors = [];

	public function __construct(
		private AtprotoIdentityRequest $identities,
		private AtprotoRepoRequest $records,
	) {
	}

	/**
	 * The post id for an `at://` post URI: the local post when the DID is
	 * one of ours and the record is known, the bsky.app id otherwise, ''
	 * when it is no post URI at all.
	 */
	public function postId(string $uri): string {
		$parsed = Syntax::parseAtUri($uri);
		if ($parsed !== null && $parsed['collection'] === RecordMapper::POST && $parsed['rkey'] !== '' && $this->actorId($parsed['authority']) !== '') {
			$record = $this->records->getRecord($parsed['authority'], RecordMapper::POST, $parsed['rkey']);
			if ($record !== null && $record->localId !== '') {
				return $record->localId;
			}
		}

		return BlueskyIds::postIdOfUri($uri);
	}

	/**
	 * The actor id for a DID: the local account's when the DID is one of ours.
	 */
	public function actorId(string $did): string {
		if (!array_key_exists($did, $this->actors)) {
			try {
				$this->actors[$did] = Syntax::isDid($did) ? $this->identities->getByDid($did)->actorId : '';
			} catch (AtprotoIdentityNotFoundException) {
				$this->actors[$did] = '';
			}
		}

		return $this->actors[$did];
	}

	/** The actor id a mention of a DID points at: local when ours, bsky.app otherwise. */
	public function mentionTarget(string $did): string {
		$local = $this->actorId($did);

		return $local !== '' ? $local : BlueskyIds::actorId($did);
	}
}
