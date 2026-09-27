<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\External\ExternalGroupBackend;
use OCP\IConfig;

/**
 * Whether two-factor authentication is required of external users.
 *
 * Nothing of its own: core's mandatory two-factor setting, read and written
 * for the externals' group, so the switch on the Social page and the one in
 * Administration → Security always show the same thing. Core's rule is that
 * a list of enforced groups limits enforcement to those groups, and without
 * one enforcement covers everybody but the excluded groups. Both shapes are
 * kept: turning the switch off never turns enforcement on for everybody,
 * which is what emptying the list of enforced groups would do.
 */
class ExternalTwoFactorService {
	private const ENFORCED = 'twofactor_enforced';
	private const GROUPS = 'twofactor_enforced_groups';
	private const EXCLUDED = 'twofactor_enforced_excluded_groups';

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return array{enforced: bool, everybody: bool}
	 */
	public function state(): array {
		[$enforced, $groups, $excluded] = $this->read();
		if (!$enforced) {
			return ['enforced' => false, 'everybody' => false];
		}
		if ($groups !== []) {
			return ['enforced' => in_array(ExternalGroupBackend::GROUP_ID, $groups, true), 'everybody' => false];
		}

		return ['enforced' => !in_array(ExternalGroupBackend::GROUP_ID, $excluded, true), 'everybody' => true];
	}

	/**
	 * @return array{enforced: bool, everybody: bool} the state afterwards
	 */
	public function set(bool $enforce): array {
		[$enforced, $groups, $excluded] = $this->read();
		$group = ExternalGroupBackend::GROUP_ID;

		if ($enforce) {
			if (!$enforced) {
				$enforced = true;
				$groups = [$group];
			} elseif ($groups !== []) {
				$groups[] = $group;
			} else {
				$excluded = array_diff($excluded, [$group]);
			}
		} elseif ($enforced) {
			if ($groups !== []) {
				$groups = array_diff($groups, [$group]);
				if ($groups === []) {
					$enforced = false;
				}
			} else {
				$excluded[] = $group;
			}
		}

		$this->config->setSystemValue(self::ENFORCED, $enforced ? 'true' : 'false');
		$this->config->setSystemValue(self::GROUPS, array_values(array_unique($groups)));
		$this->config->setSystemValue(self::EXCLUDED, array_values(array_unique($excluded)));

		return $this->state();
	}

	/**
	 * @return array{0: bool, 1: list<string>, 2: list<string>}
	 */
	private function read(): array {
		$groups = $this->config->getSystemValue(self::GROUPS, []);
		$excluded = $this->config->getSystemValue(self::EXCLUDED, []);

		return [
			$this->config->getSystemValue(self::ENFORCED, 'false') === 'true',
			is_array($groups) ? array_values(array_map('strval', $groups)) : [],
			is_array($excluded) ? array_values(array_map('strval', $excluded)) : [],
		];
	}
}
