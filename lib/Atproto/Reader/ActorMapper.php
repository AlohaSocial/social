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
	/** Bluesky's own account (bsky.app), the verifier every Bluesky app trusts */
	public const BLUESKY_DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** the moderation labels that mark an account rather than a post */
	private const LIMITING_LABELS = ['!hide', '!takedown'];

	/**
	 * Bluesky's pronouns and website as the profile rows the rest of the
	 * network writes them in (`PropertyValue`), so they show where any
	 * other account's do.
	 *
	 * @return list<array{type: string, name: string, value: string}>
	 */
	private static function profileRows(array $profile): array {
		$rows = [];
		$pronouns = trim((string)($profile['pronouns'] ?? ''));
		if ($pronouns !== '') {
			$rows[] = ['type' => 'PropertyValue', 'name' => 'Pronouns', 'value' => htmlspecialchars(mb_substr($pronouns, 0, 200), ENT_QUOTES | ENT_HTML5)];
		}
		$website = trim((string)($profile['website'] ?? ''));
		if (preg_match('~^https?://\S+$~i', $website) === 1 && filter_var($website, FILTER_VALIDATE_URL) !== false) {
			$escaped = htmlspecialchars($website, ENT_QUOTES | ENT_HTML5);
			$rows[] = ['type' => 'PropertyValue', 'name' => 'Website', 'value' => '<a href="' . $escaped . '" target="_blank" rel="nofollow noopener noreferrer me">' . $escaped . '</a>'];
		}

		return $rows;
	}

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
			'attachment' => self::profileRows($profile),
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
		] + self::verification($profile['verification'] ?? null));

		return $person;
	}

	/**
	 * Whether Bluesky shows the account as verified — by a trusted verifier,
	 * as the AppView judged it — who verified it, and whether it verifies
	 * others itself.
	 *
	 * @return array{verified: bool, verified_by: list<string>, verified_by_bluesky: bool, trusted_verifier: bool}
	 */
	public static function verification(mixed $state): array {
		$state = is_array($state) ? $state : [];
		$issuers = [];
		foreach (is_array($state['verifications'] ?? null) ? $state['verifications'] : [] as $verification) {
			if (is_array($verification) && ($verification['isValid'] ?? false) === true && is_string($verification['issuer'] ?? null)) {
				$issuers[] = $verification['issuer'];
			}
		}
		$verified = ($state['verifiedStatus'] ?? '') === 'valid';

		return [
			'verified' => $verified,
			'verified_by' => $verified ? array_values(array_unique($issuers)) : [],
			'verified_by_bluesky' => $verified && in_array(self::BLUESKY_DID, $issuers, true),
			'trusted_verifier' => ($state['trustedVerifierStatus'] ?? '') === 'valid',
		];
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
