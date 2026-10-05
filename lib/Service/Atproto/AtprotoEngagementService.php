<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoAccount;

/** Native likes for Bluesky records, kept separate from ActivityPub Like. */
class AtprotoEngagementService {
	private const COLLECTION = 'app.bsky.feed.like';
	private const FOLLOW_COLLECTION = 'app.bsky.graph.follow';

	public function __construct(
		private AtprotoClient $client,
		private AtprotoRequest $atprotoRequest,
	) {
	}

	/** @throws AtprotoException */
	public function setLiked(string $userId, Stream $post, bool $liked): void {
		$this->setRecordFlag($userId, $post, self::COLLECTION, $liked);
	}

	public function hasRecord(Stream $post): bool {
		return $this->atprotoRequest->getLinkByLocalId($post->getId()) !== null;
	}

	/** @throws AtprotoException */
	public function setReposted(string $userId, Stream $post, bool $reposted): void {
		$this->setRecordFlag($userId, $post, 'app.bsky.feed.repost', $reposted);
	}

	/** Write or remove the linked account's native follow record. */
	/** @throws AtprotoException */
	public function setFollowing(string $userId, string $did, bool $following): void {
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null) {
			throw new AtprotoException('link a Bluesky account before following profiles', 401);
		}
		$did = trim($did);
		if (!str_starts_with($did, 'did:')) {
			throw new AtprotoException('the Bluesky profile did is invalid', 422);
		}

		$existing = $this->records($account, self::FOLLOW_COLLECTION, $did);
		if ($following) {
			if ($existing !== []) {
				return;
			}
			$this->client->authedPost('com.atproto.repo.createRecord', [
				'repo' => $account->getDid(),
				'collection' => self::FOLLOW_COLLECTION,
				'record' => [
					'$type' => self::FOLLOW_COLLECTION,
					'subject' => $did,
					'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
				],
			], $account, $account->getPds());
			return;
		}

		foreach ($existing as $record) {
			$rkey = (string)($record['rkey'] ?? '');
			if ($rkey !== '') {
				$this->client->authedPost('com.atproto.repo.deleteRecord', [
					'repo' => $account->getDid(),
					'collection' => self::FOLLOW_COLLECTION,
					'rkey' => $rkey,
				], $account, $account->getPds());
			}
		}
	}

	/** @throws AtprotoException */
	public function isFollowing(string $userId, string $did): bool {
		$account = $this->atprotoRequest->getAccount($userId);
		return $account !== null && $did !== ''
			&& $this->records($account, self::FOLLOW_COLLECTION, $did) !== [];
	}

	/** @throws AtprotoException */
	private function setRecordFlag(string $userId, Stream $post, string $collection, bool $enabled): void {
		$link = $this->atprotoRequest->getLinkByLocalId($post->getId());
		if ($link === null || $link->getAtUri() === '' || $link->getCid() === '') {
			throw new AtprotoException('this post has no AT Protocol record', 422);
		}
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null) {
			throw new AtprotoException('link a Bluesky account before liking Bluesky posts', 401);
		}

		$existing = $this->records($account, $collection, $link->getAtUri());
		if ($enabled) {
			if ($existing !== []) {
				return;
			}
			$this->client->authedPost('com.atproto.repo.createRecord', [
				'repo' => $account->getDid(),
				'collection' => $collection,
				'record' => [
					'$type' => $collection,
					'subject' => ['uri' => $link->getAtUri(), 'cid' => $link->getCid()],
					'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
				],
			], $account, $account->getPds());
			return;
		}

		foreach ($existing as $record) {
			$rkey = (string)($record['rkey'] ?? '');
			if ($rkey === '') {
				continue;
			}
			$this->client->authedPost('com.atproto.repo.deleteRecord', [
				'repo' => $account->getDid(),
				'collection' => $collection,
				'rkey' => $rkey,
			], $account, $account->getPds());
		}
	}

	/** @return list<array<string, mixed>> */
	private function records(AtprotoAccount $account, string $collection, string $uri): array {
		$answer = $this->client->authedGet('com.atproto.repo.listRecords', [
			'repo' => $account->getDid(),
			'collection' => $collection,
			'limit' => 100,
		], $account, $account->getPds());
		$records = [];
		foreach ((array)($answer['records'] ?? []) as $record) {
			if (!is_array($record)) {
				continue;
			}
			$value = is_array($record['value'] ?? null) ? $record['value'] : [];
			$subject = $value['subject'] ?? '';
			$subjectUri = is_array($subject) ? (string)($subject['uri'] ?? '') : (string)$subject;
			if ($subjectUri === $uri) {
				$recordUri = (string)($record['uri'] ?? '');
				$parts = explode('/', trim(substr($recordUri, 5), '/'));
				$records[] = [
					'rkey' => (string)end($parts),
				];
			}
		}

		return $records;
	}
}
