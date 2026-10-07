<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Exceptions\InvalidActionException;

/** A post's transport choice cannot widen its audience or silently select a disabled PDS. */
final class PublicationPolicy {
	public static function targets(string $target, string $visibility, bool $enabled): array {
		if ($target === '') {
			$target = $enabled && $visibility === 'public' ? 'both' : 'fediverse';
		}
		if (!in_array($target, ['fediverse', 'atproto', 'both'], true)) {
			throw new InvalidActionException('Unknown publication target');
		}
		$native = $target !== 'fediverse';
		if ($native && !$enabled) {
			throw new InvalidActionException('AT Protocol is disabled');
		}
		if ($native && $visibility !== 'public') {
			throw new InvalidActionException('Only public posts can be published through AT Protocol');
		}
		return ['fediverse' => $target !== 'atproto', 'atproto' => $native];
	}
}
