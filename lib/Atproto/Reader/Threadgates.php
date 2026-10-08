<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may reply in a Bluesky thread (`app.bsky.feed.threadgate`): the gate
 * the thread's root carries, its rules checked for the account replying
 * from here. A reply the gate does not let through is hidden by every
 * AppView, so it is refused here, with the reason, rather than sent to
 * where nobody will see it. A thread nobody may reply to is marked when its
 * root is read (`PostMapper`), so the reply is not even offered.
 */
class Threadgates {
	private const GATE = 'app.bsky.feed.threadgate';
	/** how many members of a list are looked through */
	private const LIST_PAGES = 10;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private IdentityService $identities,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Why the account may not reply to the post, or '' when it may — also
	 * when the post is not a Bluesky one, or the gate cannot be read.
	 */
	public function refusal(Person $replier, Stream $parent): string {
		if (!$this->config->isEnabled() || !BlueskyIds::isPostId($parent->getId())) {
			return '';
		}
		$details = $parent->getDetails(PostMapper::DETAIL);
		$parentUri = is_string($details['uri'] ?? null) ? $details['uri'] : '';
		$rootUri = is_string($details['reply_root']['uri'] ?? null) && $details['reply_root']['uri'] !== '' ? $details['reply_root']['uri'] : $parentUri;
		$replier = $this->identities->forActor($replier, false)?->did ?? '';
		if ($rootUri === '' || $replier === '') {
			return '';
		}
		try {
			$root = $this->appView->query('app.bsky.feed.getPosts', ['uris' => [$rootUri]])['posts'][0] ?? null;
			$allow = is_array($root) ? ($root['threadgate']['record']['allow'] ?? null) : null;
			if (!is_array($allow) || ($root['author']['did'] ?? '') === $replier) {
				return '';
			}
			foreach ($allow as $rule) {
				if (is_array($rule) && $this->lets($rule, $root, $replier)) {
					return '';
				}
			}
		} catch (Throwable $e) {
			$this->logger->info('Thread gate not read; the reply goes out', ['root' => $rootUri, 'exception' => $e]);

			return '';
		}

		return $allow === []
			? 'The author of this thread on Bluesky allows no replies'
			: 'The author of this thread on Bluesky allows replies only from ' . self::describe($allow);
	}

	/**
	 * Whether one rule of a gate lets the replier through.
	 */
	private function lets(array $rule, array $root, string $replier): bool {
		$author = (string)($root['author']['did'] ?? '');

		return match ((string)($rule['$type'] ?? '')) {
			self::GATE . '#mentionRule' => in_array($replier, self::mentioned($root['record']['facets'] ?? []), true),
			self::GATE . '#followerRule' => $this->relationship($author, $replier, 'followedBy'),
			self::GATE . '#followingRule' => $this->relationship($author, $replier, 'following'),
			self::GATE . '#listRule' => $this->onList((string)($rule['list'] ?? ''), $replier),
			default => false,
		};
	}

	/**
	 * Whether the author follows the replier (`following`) or is followed by
	 * them (`followedBy`).
	 */
	private function relationship(string $author, string $replier, string $side): bool {
		$answer = $this->appView->query('app.bsky.graph.getRelationships', ['actor' => $author, 'others' => [$replier]]);
		foreach (is_array($answer['relationships'] ?? null) ? $answer['relationships'] : [] as $relationship) {
			if (is_array($relationship) && ($relationship['did'] ?? '') === $replier) {
				return is_string($relationship[$side] ?? null) && $relationship[$side] !== '';
			}
		}

		return false;
	}

	private function onList(string $list, string $replier): bool {
		$cursor = '';
		for ($page = 0; $page < self::LIST_PAGES && $list !== ''; $page++) {
			$answer = $this->appView->query('app.bsky.graph.getList', ['list' => $list, 'limit' => 100] + ($cursor !== '' ? ['cursor' => $cursor] : []));
			foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
				if (is_array($item) && ($item['subject']['did'] ?? '') === $replier) {
					return true;
				}
			}
			$cursor = is_string($answer['cursor'] ?? null) ? $answer['cursor'] : '';
			if ($cursor === '') {
				break;
			}
		}

		return false;
	}

	/**
	 * @return list<string> the DIDs a record's facets mention
	 */
	private static function mentioned(mixed $facets): array {
		$dids = [];
		foreach (is_array($facets) ? $facets : [] as $facet) {
			foreach (is_array($facet['features'] ?? null) ? $facet['features'] : [] as $feature) {
				if (($feature['$type'] ?? '') === 'app.bsky.richtext.facet#mention' && is_string($feature['did'] ?? null)) {
					$dids[] = $feature['did'];
				}
			}
		}

		return $dids;
	}

	private static function describe(array $allow): string {
		$who = [];
		foreach ($allow as $rule) {
			$who[] = match (is_array($rule) ? (string)($rule['$type'] ?? '') : '') {
				self::GATE . '#mentionRule' => 'the accounts it mentions',
				self::GATE . '#followerRule' => 'its author\'s followers',
				self::GATE . '#followingRule' => 'the accounts its author follows',
				self::GATE . '#listRule' => 'the members of a list',
				default => 'some accounts',
			};
		}

		return implode(' or ', array_unique($who));
	}
}
