<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;

/**
 * A Bluesky profile, as the AppView describes it, as the cached actor this
 * app keeps for any remote account. The profile is first shaped as the
 * actor document a Fediverse server would serve, so the one import path
 * reads it — icon, banner, bio, name — and then given what no actor
 * document carries: the bare handle as its account, the counts the
 * AppView already knows, and the DID, PDS and labels in `details.atproto`.
 */
class ActorMapper {
	public const DETAIL = Details::ATPROTO;

	/** the moderation labels that mark an account rather than a post */
	private const LIMITING_LABELS = ['!hide', '!takedown'];

	/**
	 * @param array $profile an `app.bsky.actor.defs#profileViewDetailed`
	 * @param string $pds the PDS endpoint the DID document names, '' when unknown
	 */
	public function person(array $profile, string $pds = ''): Person {
		$did = (string)($profile['did'] ?? '');
		$handle = strtolower((string)($profile['handle'] ?? ''));
		$description = (string)($profile['description'] ?? '');
		$data = [
			'id' => BlueskyIds::actorId($did),
			'type' => Person::TYPE,
			'preferredUsername' => $handle,
			'name' => (string)($profile['displayName'] ?? ''),
			'summary' => $description === '' ? '' : '<p>' . nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_HTML5), false) . '</p>',
			'url' => BlueskyIds::profileUrl($handle !== '' ? $handle : $did),
			'followers' => BlueskyIds::followersId($did),
		];
		foreach (['avatar' => 'icon', 'banner' => 'image'] as $field => $key) {
			$url = (string)($profile[$field] ?? '');
			if ($url !== '') {
				$data[$key] = ['type' => 'Image', 'mediaType' => self::mediaTypeOf($url), 'url' => $url];
			}
		}

		/** @var Person $person */
		$person = AP::instance()->getItemFromData($data);
		$person->setAccount($handle);
		$person->setLocal(false);
		$created = strtotime((string)($profile['createdAt'] ?? ''));
		if ($created !== false && $created > 0) {
			$person->setCreation($created);
		}
		$person->setDetailArray(Details::COUNT, [
			'followers' => (int)($profile['followersCount'] ?? 0),
			'following' => (int)($profile['followsCount'] ?? 0),
			'post' => (int)($profile['postsCount'] ?? 0),
		]);
		$labels = self::labelValues($profile['labels'] ?? []);
		$person->setDetailArray(self::DETAIL, [
			'did' => $did,
			'handle' => $handle,
			'pds' => $pds,
			'labels' => $labels,
			'limited' => array_intersect($labels, self::LIMITING_LABELS) !== [],
			'indexed_at' => (string)($profile['indexedAt'] ?? ''),
		]);
		$person->setDetailArray(Details::BLUESKY, [
			'handle' => $handle,
			'did' => $did,
			'url' => BlueskyIds::profileUrl($handle !== '' ? $handle : $did),
			'native' => true,
		]);

		return $person;
	}

	/**
	 * @return string[] the label values, self-labels and service labels alike
	 */
	public static function labelValues(mixed $labels): array {
		$values = [];
		foreach (is_array($labels) ? $labels : [] as $label) {
			if (is_array($label) && is_string($label['val'] ?? null) && $label['val'] !== '' && !in_array($label['val'], $values, true)) {
				$values[] = $label['val'];
			}
		}

		return $values;
	}

	/** The CDN names the format at the end of the path: `…@jpeg`, `…@png`. */
	public static function mediaTypeOf(string $url): string {
		$at = strrpos($url, '@');
		$format = $at === false ? '' : strtolower(substr($url, $at + 1));

		return match ($format) {
			'png' => 'image/png',
			'webp' => 'image/webp',
			'gif' => 'image/gif',
			default => 'image/jpeg',
		};
	}
}
