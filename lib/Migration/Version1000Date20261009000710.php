<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_followed_tag.filled`: when the posts with a followed hashtag were
 * last read from beyond this server (`Service\Discovery\FollowedTagsFill`),
 * as a Unix time, 0 for never. Indexed with the tag, which is how the
 * background job picks the tags read longest ago and how it stamps one.
 */
class Version1000Date20261009000710 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_followed_tag')) {
			return null;
		}
		$table = $schema->getTable('social_followed_tag');
		if ($table->hasColumn('filled')) {
			return null;
		}
		$table->addColumn('filled', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addIndex(['hashtag', 'filled'], 'social_ft_hf');

		return $schema;
	}
}
