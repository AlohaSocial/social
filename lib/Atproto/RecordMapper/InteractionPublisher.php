<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Identity\AtprotoDid;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\Repository;

/** Session-owned public interactions. Actor identifiers from request bodies are never trusted. */
class InteractionPublisher {
	public function __construct(
		private readonly IdentityService $identities,
		private readonly Repository $repositories,
	) {
	}
	public function publish(string $userId, string $kind, string $target, ?string $cid = null, bool $remove = false): void {
		$actor = $this->identities->actorIdForUser($userId) ?? throw new \InvalidArgumentException('No local Social actor');
		$identity = $this->identities->createIdentity($actor);
		if ($identity['state'] !== IdentityService::STATE_ACTIVE) {
			throw new \RuntimeException('Identity registration is pending');
		}
		if (!in_array($kind, ['follow', 'like', 'repost'], true)) {
			throw new \InvalidArgumentException('Unsupported interaction');
		}
		if ($kind === 'follow') {
			if (AtprotoDid::parse($target) === null && !preg_match('/^did:web:[a-zA-Z0-9.:%_-]+$/D', $target)) {
				throw new \InvalidArgumentException('Invalid subject DID');
			}
			$collection = 'app.bsky.graph.follow';
			$subject = $target;
		} else {
			if (!preg_match('#^at://did:(?:plc:[a-z2-7]{24}|web:[a-zA-Z0-9.:%_-]+)/app\.bsky\.feed\.post/[a-zA-Z0-9._~:-]{1,255}$#D', $target)) {
				throw new \InvalidArgumentException('Invalid post URI');
			}
			if (!$remove) {
				Cid::decode($cid ?? '');
			}
			$collection = 'app.bsky.feed.' . $kind;
			$subject = ['uri' => $target, 'cid' => $cid];
		}
		$this->repositories->transaction(function () use ($identity, $actor, $collection, $subject, $target, $remove) {
			$matches = [];
			foreach ($this->repositories->getRecords($identity['did'], $collection) as $rkey => $record) {
				$value = $record->getValue();
				$current = is_array($value['subject']) ? $value['subject']['uri'] : $value['subject'];
				if ($current === $target) {
					$matches[] = $rkey;
				}
			}
			if (!$remove && $matches !== []) {
				return;
			}
			if ($remove && $matches === []) {
				return;
			}
			foreach ($matches as $rkey) {
				$this->repositories->deleteRecord($identity['did'], $collection, $rkey);
			}
			if (!$remove) {
				$this->repositories->createRecord($identity['did'], $collection, Tid::next(), ['$type' => $collection, 'subject' => $subject, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z')]);
			}
			$this->repositories->commit($identity['did'], $this->identities->getSigningKey($actor));
		});
	}
}
