<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('l10n')
	->notPath('src')
	->notPath('vendor')
	->notPath('node_modules')
	->in(__DIR__);
// Beyond the shared Nextcloud standard: every file declares strict types, and
// in_array() and friends compare strictly, so a type mismatch is an error
// rather than a quiet coercion.
$config
	->setRiskyAllowed(true)
	->setRules(array_merge($config->getRules(), [
		'declare_strict_types' => true,
		'strict_param' => true,
	]));
return $config;
