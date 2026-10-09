<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\IL10N;

/**
 * What the scopes a Bluesky app asks for mean, in words, for the consent
 * page: one line each, a permission set by its own title and detail with
 * what it holds beneath, and whether it lets the app act rather than only
 * read. A scope this server does not know is shown as it was asked for.
 */
class PermissionDescriber {
	public function __construct(
		private IL10N $l10n,
		private AtprotoConfig $config,
		private PermissionSets $sets,
	) {
	}

	/**
	 * @param string[] $scopes
	 * @return list<array{scope: string, label: string, detail: string, writes: bool, items: list<string>}>
	 */
	public function describe(array $scopes, string $language): array {
		$lines = [];
		foreach ($scopes as $scope) {
			$lines[] = ['scope' => $scope] + $this->line($scope, $language);
		}

		return $lines;
	}

	/**
	 * @return array{label: string, detail: string, writes: bool, items: list<string>}
	 */
	private function line(string $scope, string $language): array {
		$plain = static fn (string $label, bool $writes = false): array => ['label' => $label, 'detail' => '', 'writes' => $writes, 'items' => []];
		switch ($scope) {
			case Permissions::ATPROTO:
				return $plain($this->l10n->t('Know which account you are'));
			case Permissions::GENERIC:
				return $plain($this->l10n->t('Post, like, follow, upload and read as you, everywhere on Bluesky'), true);
			case Permissions::EMAIL:
				return $plain($this->l10n->t('See your e-mail address'));
			case Permissions::CHAT:
				return $plain($this->l10n->t('Read and send your direct messages on Bluesky'), true);
		}
		$rule = Permissions::parse($scope);
		if ($rule === null) {
			return $plain($scope, true);
		}
		if ($rule['resource'] !== 'include') {
			return $plain($this->rule($rule), $this->writes($rule));
		}
		$set = $this->sets->resolve((string)$rule['nsid']);
		if ($set === null) {
			return $plain($this->l10n->t('The permissions named %s', [(string)$rule['nsid']]), true);
		}
		$rules = $this->sets->expand((string)$rule['nsid'], (string)$rule['aud']) ?? [];

		return [
			'label' => $set['title_lang'][$language] ?? $set['title'],
			'detail' => $set['detail_lang'][$language] ?? $set['detail'],
			'writes' => array_filter($rules, fn (array $r): bool => $this->writes($r)) !== [],
			'items' => array_map(fn (array $r): string => $this->rule($r), $rules),
		];
	}

	private function writes(array $rule): bool {
		return match ($rule['resource']) {
			'repo', 'blob', 'identity' => true,
			'account' => ($rule['action'][0] ?? 'read') === 'manage',
			default => in_array('*', $rule['lxm'] ?? [], true) || array_filter($rule['lxm'] ?? [], static fn (string $l): bool => preg_match('/\.(create|put|delete|update|upload|send|mute|unmute|register)[A-Z]/', $l) === 1) !== [],
		};
	}

	private function rule(array $rule): string {
		switch ($rule['resource']) {
			case 'repo':
				$what = implode(', ', array_map(fn (string $c): string => $this->collection($c), $rule['collection'] ?? []));
				$actions = $rule['action'] ?? [];
				if (count($actions) === 3) {
					return $this->l10n->t('Create, change and delete %s', [$what]);
				}
				$parts = [];
				if (in_array('create', $actions, true)) {
					$parts[] = $this->l10n->t('create');
				}
				if (in_array('update', $actions, true)) {
					$parts[] = $this->l10n->t('change');
				}
				if (in_array('delete', $actions, true)) {
					$parts[] = $this->l10n->t('delete');
				}

				return $this->l10n->t('%1$s: %2$s', [ucfirst(implode(', ', $parts)), $what]);
			case 'rpc':
				$service = $this->service((string)($rule['aud'] ?? ''));
				if (in_array('*', $rule['lxm'] ?? [], true)) {
					return $this->l10n->t('Use anything %s offers, as you', [$service]);
				}

				return $this->l10n->t('Use %1$s at %2$s, as you', [implode(', ', $rule['lxm'] ?? []), $service]);
			case 'blob':
				$types = array_map(fn (string $a): string => match ($a) {
					'*/*' => $this->l10n->t('any files'),
					'image/*' => $this->l10n->t('pictures'),
					'video/*' => $this->l10n->t('videos'),
					default => $a,
				}, $rule['accept'] ?? []);

				return $this->l10n->t('Upload %s', [implode(', ', $types)]);
			case 'account':
				if (($rule['attr'] ?? '') === 'email') {
					return ($rule['action'][0] ?? 'read') === 'manage' ? $this->l10n->t('See and change your e-mail address') : $this->l10n->t('See your e-mail address');
				}

				return ($rule['action'][0] ?? 'read') === 'manage' ? $this->l10n->t('Manage your account and repository') : $this->l10n->t('See the state of your account');
			case 'identity':
				return ($rule['attr'] ?? '') === 'handle' ? $this->l10n->t('Change your handle') : $this->l10n->t('Change your handle and your identity');
			default:
				return (string)($rule['resource'] ?? '');
		}
	}

	private function collection(string $nsid): string {
		return match ($nsid) {
			'*' => $this->l10n->t('records of any kind'),
			'app.bsky.feed.post' => $this->l10n->t('posts'),
			'app.bsky.feed.like' => $this->l10n->t('likes'),
			'app.bsky.feed.repost' => $this->l10n->t('reposts'),
			'app.bsky.graph.follow' => $this->l10n->t('follows'),
			'app.bsky.graph.block' => $this->l10n->t('blocks'),
			'app.bsky.graph.list' => $this->l10n->t('lists'),
			'app.bsky.graph.listitem' => $this->l10n->t('list members'),
			'app.bsky.actor.profile' => $this->l10n->t('your profile'),
			'app.bsky.feed.threadgate' => $this->l10n->t('who may reply'),
			'app.bsky.feed.postgate' => $this->l10n->t('who may quote'),
			default => $nsid,
		};
	}

	private function service(string $aud): string {
		if ($aud === '*') {
			return $this->l10n->t('any service');
		}

		return explode('#', $aud, 2)[0] === $this->config->appViewDid() ? $this->l10n->t('Bluesky') : $aud;
	}
}
