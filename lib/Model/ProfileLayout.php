<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use InvalidArgumentException;

/** Public presentation preferences, never an audience or permission filter. */
final class ProfileLayout {
	public const TABS = ['posts', 'photos', 'videos', 'tagged', 'collections'];
	public const DEFAULT = ['default_tab' => 'posts', 'tabs' => self::TABS];

	/** @return array{default_tab: string, tabs: list<string>} */
	public static function normalize(array $input, bool $strict = false): array {
		$default = $input['default_tab'] ?? 'posts';
		$tabs = $input['tabs'] ?? self::TABS;
		$valid = is_string($default) && in_array($default, ['posts', 'photos', 'videos'], true)
			&& is_array($tabs) && array_is_list($tabs) && count($tabs) <= count(self::TABS);
		if ($valid) {
			foreach ($tabs as $tab) {
				if (!is_string($tab) || !in_array($tab, self::TABS, true)) {
					$valid = false;
					break;
				}
			}
		}
		if (!$valid || !in_array('posts', $tabs, true) || !in_array($default, $tabs, true)) {
			if ($strict) {
				throw new InvalidArgumentException('Choose a visible default profile tab and keep Posts available');
			}

			return self::DEFAULT;
		}

		return ['default_tab' => $default, 'tabs' => array_values(array_intersect(self::TABS, $tabs))];
	}
}
