<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

/**
 * An emoji sent back to whoever posted a short (a story in the code).
 *
 * @see StoryInteraction for the shape and why it is Pixelfed's
 */
class StoryReaction extends StoryInteraction {
	public const TYPE = 'Story:Reaction';
}
