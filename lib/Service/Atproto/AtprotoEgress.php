<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Service\ConfigService;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a local public post into the Bluesky repository its author linked.
 *
 * This is deliberately a post mirror, not an ActivityPub delivery: the PDS
 * receives a protocol-native `app.bsky.feed.post` record using the linked
 * account's app-password session. ActivityPub delivery has already happened
 * before this service is called and cannot be held up by a PDS failure.
 */
class AtprotoEgress {
	/** The low ten bits that make two writes in the same microsecond distinct. */
	private int $clock = 0;

	public function __construct(
		private AtprotoClient $client,
		private AtprotoRequest $atprotoRequest,
		private ActorsRequest $actorsRequest,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Writes a local, public post once. A returned mapping makes retries
	 * idempotent: if a listener is delivered twice, no duplicate Bluesky post
	 * is made.
	 *
	 * @throws AtprotoException for the listener to record and contain
	 */
	public function publish(Stream $post): void {
		if (!$this->shouldMirror($post)) {
			return;
		}

		if ($this->atprotoRequest->getLinkByLocalId($post->getId()) !== null) {
			return;
		}

		$account = $this->accountFor($post);
		if ($account === null) {
			return;
		}

		// PDS listRecords pages are ordered by record key. A timestamp ID keeps
		// newest writes together, which is what lets the ingress stop at the
		// first already-known page instead of repeatedly scanning a repository.
		$rkey = $this->tid();
		$record = [
			'$type' => 'app.bsky.feed.post',
			'text' => $this->text($post->getContent()),
			'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(1, $post->getPublishedTime())),
		];
		$parent = null;
		if ($post->getInReplyTo() !== '') {
			$parent = $this->atprotoRequest->getLinkByLocalId($post->getInReplyTo());
		}
		if ($parent !== null && $parent->getCollection() === AtprotoIngress::COLLECTION) {
			// A reply mirrored from an ActivityPub-only thread has no AT root;
			// the closest mapped parent is a valid root and keeps the reply in
			// the conversation readers can actually dereference.
			$record['reply'] = [
				'root' => ['uri' => $parent->getAtUri(), 'cid' => $parent->getCid()],
				'parent' => ['uri' => $parent->getAtUri(), 'cid' => $parent->getCid()],
			];
		}

		$answer = $this->client->authedPost('com.atproto.repo.putRecord', [
			'repo' => $account->getDid(),
			'collection' => AtprotoIngress::COLLECTION,
			'rkey' => $rkey,
			'record' => $record,
		], $account, $account->getPds());
		$uri = (string)($answer['uri'] ?? '');
		$cid = (string)($answer['cid'] ?? '');
		if (!str_starts_with($uri, 'at://' . $account->getDid() . '/')) {
			throw new AtprotoException('the PDS accepted a post without its AT URI', 0);
		}

		$this->atprotoRequest->saveLink(
			(new AtprotoLink())
				->setLocalId($post->getId())
				->setAtUri($uri)
				->setCid($cid)
				->setDid($account->getDid())
				->setCollection(AtprotoIngress::COLLECTION)
				->setRkey($rkey)
				->setHandle($account->getHandle())
		);
	}

	/** Removes the mirrored record after its local post was deleted. */
	public function delete(Stream $post): void {
		$link = $this->atprotoRequest->getLinkByLocalId($post->getId());
		if ($link === null) {
			return;
		}
		$account = $this->accountFor($post);
		if ($account === null || $link->getDid() !== $account->getDid()) {
			return;
		}

		$this->client->authedPost('com.atproto.repo.deleteRecord', [
			'repo' => $account->getDid(),
			'collection' => $link->getCollection(),
			'rkey' => $link->getRkey(),
		], $account, $account->getPds());
		$this->atprotoRequest->deleteLinkByLocalId($post->getId());
	}

	private function shouldMirror(Stream $post): bool {
		return $this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_ENABLED) === '1'
			&& $this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_EGRESS) === '1'
			&& $post->getAttributedTo() !== ''
			&& $post->getVisibility() === Stream::TYPE_PUBLIC;
	}

	/** The Nextcloud user behind the local ActivityPub actor's id. */
	private function accountFor(Stream $post): ?\OCA\Social\Model\Atproto\AtprotoAccount {
		try {
			$userId = $this->actorsRequest->getFromId($post->getAttributedTo())->getUserId();
		} catch (ActorDoesNotExistException $e) {
			$this->logger->warning('could not find the local author for an AT-Proto mirror', [
				'author' => $post->getAttributedTo(), 'exception' => $e,
			]);

			return null;
		}

		return $userId === '' ? null : $this->atprotoRequest->getAccount($userId);
	}

	/** Bluesky accepts text, not the ActivityPub HTML held in a local row. */
	private function text(string $html): string {
		$text = trim(html_entity_decode(strip_tags(preg_replace('/<br\\s*\\/?\\s*>/i', chr(10), $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		// The network's 300-grapheme maximum is part of its record contract;
		// cutting here is safer than an opaque PDS validation failure.
		if (function_exists('grapheme_substr') && grapheme_strlen($text) > 300) {
			return grapheme_substr($text, 0, 299) . '…';
		}

		return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
	}

	/** A 13-character AT Protocol timestamp record key (TID). */
	private function tid(): string {
		$alphabet = '234567abcdefghijklmnopqrstuvwxyz';
		$now = new \DateTimeImmutable('now');
		$value = ((int)$now->format('U') * 1_000_000) + (int)$now->format('u');
		$key = '';
		for ($position = 0; $position < 11; $position++) {
			$key = $alphabet[$value % 32] . $key;
			$value = intdiv($value, 32);
		}

		$clock = $this->clock++ % 1024;

		return $key . $alphabet[intdiv($clock, 32)] . $alphabet[$clock % 32];
	}
}
