<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

/**
 * @deprecated
 * Trait TAsync
 *
 * @package OCA\Social\Tools\Traits
 */
trait TAsync {
	/**
	 * Hacky way to async the rest of the process without keeping client on hold.
	 *
	 * @param string $result
	 */
	public function async(string $result = ''): void {
		if (ob_get_contents() !== false) {
			ob_end_clean();
		}

		header('Connection: close');
		header('Content-Encoding: none');
		ignore_user_abort();
		ob_start();
		echo($result);
		$size = ob_get_length();
		header('Content-Length: ' . $size);
		ob_end_flush();
		flush();
	}
}
