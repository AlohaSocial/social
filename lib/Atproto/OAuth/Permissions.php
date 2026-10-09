<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use OCA\Social\Atproto\Protocol\Syntax;

/**
 * What an app was allowed to do, by AT Protocol's permission scopes
 * (atproto.com/specs/permission): the transitional scopes, which allow
 * everything an app password does, and the granular ones — `repo:` records
 * of a collection, `rpc:` methods at a service, `blob:` uploads of a type,
 * `account:` and `identity:` details — and the permission sets an
 * `include:` names, expanded beforehand (`PermissionSets`).
 */
final class Permissions {
	public const ATPROTO = 'atproto';
	public const GENERIC = 'transition:generic';
	public const EMAIL = 'transition:email';
	/** the direct messages (`chat.bsky.*`), which `transition:generic` does not reach */
	public const CHAT = 'transition:chat.bsky';
	public const REPO_ACTIONS = ['create', 'update', 'delete'];
	/** the resources a scope string may name */
	private const RESOURCES = ['repo', 'rpc', 'blob', 'account', 'identity', 'include'];

	/**
	 * @param string[] $scopes the scope strings as granted
	 * @param list<array{resource: string, collection?: list<string>, action?: list<string>, lxm?: list<string>, aud?: string, accept?: list<string>, attr?: string}> $rules each granular scope, a set's included
	 */
	private function __construct(
		public readonly array $scopes,
		private array $rules,
	) {
	}

	/**
	 * @param string[] $scopes
	 * @param list<array> $included the rules of the permission sets the scopes include
	 */
	public static function of(array $scopes, array $included = []): self {
		$rules = [];
		foreach ($scopes as $scope) {
			$rule = self::parse($scope);
			if ($rule !== null && $rule['resource'] !== 'include') {
				$rules[] = $rule;
			}
		}

		return new self(array_values($scopes), array_merge($rules, $included));
	}

	/**
	 * One granular scope string, or null when it is not one: unknown, or
	 * written wrong.
	 *
	 * @return array{resource: string, collection?: list<string>, action?: list<string>, lxm?: list<string>, aud?: string, accept?: list<string>, attr?: string, nsid?: string}|null
	 */
	public static function parse(string $scope): ?array {
		if (preg_match('/^([a-z]+)(?::([^?]*))?(?:\?(.*))?$/', $scope, $m) !== 1 || !in_array($m[1], self::RESOURCES, true)) {
			return null;
		}
		$resource = $m[1];
		$positional = isset($m[2]) && $m[2] !== '' ? rawurldecode($m[2]) : null;
		$params = self::params($m[3] ?? '');

		switch ($resource) {
			case 'repo':
				$collections = $positional !== null ? [$positional] : ($params['collection'] ?? []);
				$actions = $params['action'] ?? self::REPO_ACTIONS;
				if ($collections === [] || array_diff($actions, self::REPO_ACTIONS) !== []
					|| array_filter($collections, static fn (string $c): bool => $c !== '*' && !Syntax::isNsid($c)) !== []) {
					return null;
				}

				return ['resource' => 'repo', 'collection' => array_values(array_unique($collections)), 'action' => array_values(array_unique($actions))];
			case 'rpc':
				$methods = $positional !== null ? [$positional] : ($params['lxm'] ?? []);
				$aud = $params['aud'][0] ?? '';
				if ($methods === [] || $aud === '' || (in_array('*', $methods, true) && $aud === '*')
					|| array_filter($methods, static fn (string $l): bool => $l !== '*' && !Syntax::isNsid($l)) !== []) {
					return null;
				}

				return ['resource' => 'rpc', 'lxm' => array_values(array_unique($methods)), 'aud' => $aud];
			case 'blob':
				$accept = $positional !== null ? [$positional] : ($params['accept'] ?? []);
				if ($accept === [] || array_filter($accept, static fn (string $a): bool => preg_match('#^(\*/\*|[a-z0-9.+-]+/(\*|[a-z0-9.+-]+))$#', $a) !== 1) !== []) {
					return null;
				}

				return ['resource' => 'blob', 'accept' => array_values(array_unique($accept))];
			case 'account':
				$attr = $positional ?? ($params['attr'][0] ?? '');
				$action = $params['action'][0] ?? 'read';
				if (!in_array($attr, ['email', 'repo'], true) || !in_array($action, ['read', 'manage'], true)) {
					return null;
				}

				return ['resource' => 'account', 'attr' => $attr, 'action' => [$action]];
			case 'identity':
				$attr = $positional ?? ($params['attr'][0] ?? '');

				return in_array($attr, ['handle', '*'], true) ? ['resource' => 'identity', 'attr' => $attr] : null;
			default:
				$nsid = $positional ?? '';
				if (!Syntax::isNsid($nsid)) {
					return null;
				}

				return ['resource' => 'include', 'nsid' => $nsid, 'aud' => $params['aud'][0] ?? ''];
		}
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function params(string $query): array {
		$params = [];
		foreach ($query === '' ? [] : explode('&', $query) as $pair) {
			[$name, $value] = explode('=', $pair, 2) + [1 => ''];
			$params[rawurldecode($name)][] = rawurldecode($value);
		}

		return $params;
	}

	public function isGeneric(): bool {
		return in_array(self::GENERIC, $this->scopes, true);
	}

	/**
	 * Whether a record of the collection may be created, updated or deleted.
	 */
	public function mayWrite(string $collection, string $action): bool {
		if ($this->isGeneric()) {
			return true;
		}
		foreach ($this->rules as $rule) {
			if ($rule['resource'] === 'repo' && in_array($action, $rule['action'] ?? [], true)
				&& (in_array('*', $rule['collection'] ?? [], true) || in_array($collection, $rule['collection'] ?? [], true))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a method may be called at a service — `did#service`, or a bare
	 * DID, which any of its services matches.
	 */
	public function mayCall(string $lxm, string $aud): bool {
		if (str_starts_with($lxm, 'chat.bsky.') ? in_array(self::CHAT, $this->scopes, true) : $this->isGeneric()) {
			return true;
		}
		foreach ($this->rules as $rule) {
			if ($rule['resource'] !== 'rpc' || !(in_array('*', $rule['lxm'] ?? [], true) || in_array($lxm, $rule['lxm'] ?? [], true))) {
				continue;
			}
			$granted = (string)($rule['aud'] ?? '');
			if ($granted === '*' || $granted === $aud || (!str_contains($aud, '#') && explode('#', $granted, 2)[0] === $aud)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a file of the type may be uploaded.
	 */
	public function mayUpload(string $mime): bool {
		if ($this->isGeneric()) {
			return true;
		}
		$mime = strtolower(trim(explode(';', $mime, 2)[0]));
		foreach ($this->rules as $rule) {
			foreach ($rule['resource'] === 'blob' ? ($rule['accept'] ?? []) : [] as $accept) {
				if ($accept === '*/*' || $accept === $mime || (str_ends_with($accept, '/*') && str_starts_with($mime, substr($accept, 0, -1)))) {
					return true;
				}
			}
		}

		return false;
	}

	/** Whether the account's e-mail address may be read. */
	public function mayReadEmail(): bool {
		if (in_array(self::EMAIL, $this->scopes, true)) {
			return true;
		}
		foreach ($this->rules as $rule) {
			if ($rule['resource'] === 'account' && ($rule['attr'] ?? '') === 'email') {
				return true;
			}
		}

		return false;
	}
}
