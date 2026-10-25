<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Owner-scoped HMAC keys for consented exposure to a classified topic. */
final class RecommendationTopicFingerprint {
	public static function key(string $topicId, string $secret, string $owner): ?string {
		if ($topicId === '' || $secret === '' || $owner === '') {
			return null;
		}

		return hash_hmac('sha256', $owner . "\0recommendation-topic-exposure-v1\0" . $topicId, $secret);
	}
}
