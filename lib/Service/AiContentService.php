<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;

/**
 * Whether a post says it was made with AI, and whether a reader wants such
 * posts hidden.
 *
 * Three things label a post, and all three are things the post *carries*: a
 * hashtag from a known set, the author's own mark — which on the wire is the
 * same hashtag, since the composer adds `#AIgenerated` — and a picture whose
 * metadata states machine-generation provenance (IPTC's `DigitalSourceType`,
 * or the same term inside a C2PA manifest). Nothing here looks at the pixels
 * or the prose: a post that is generated and says nothing about it is not
 * recognised, and a post that is honest about it is. That is the whole of what
 * the switch can promise, and the documentation says so in as many words.
 *
 * The switch is the reader's, stored per user, and off until they turn it on.
 * When it is on the labelled posts are dropped in `FilterService::apply()`,
 * which is the one place every timeline, thread, profile and notification
 * page passes through on its way to a client; a post hidden there is hidden
 * in every list and the API alike. Independently of the switch, every status
 * and attachment states `ai_generated`, so a client can draw a quiet label
 * for readers who chose to keep seeing them.
 */
class AiContentService {
	/** The per-user switch: `'1'` hides, anything else shows. */
	public const USER_KEY = 'hide_ai_content';

	/**
	 * The hashtags that label a post, lower-case and without their `#`.
	 *
	 * Generic words first, then the tools people name. An administrator can
	 * add to the list with the `ai_tags` app value; nothing here can take one
	 * of these away, because a reader who turned the switch on is relying on
	 * `#aigenerated` meaning what it says.
	 */
	public const DEFAULT_TAGS = [
		'ai',
		'aiart',
		'aigenerated',
		'aigeneratedart',
		'aiimage',
		'aiphoto',
		'aivideo',
		'genai',
		'generativeai',
		'madewithai',
		'midjourney',
		'stablediffusion',
		'dalle',
		'dalle3',
		'sora',
		'fluxai',
		'comfyui',
	];

	/** @var string[]|null the merged tag set, read once per request */
	private ?array $tags = null;

	/** @var array<string, bool> user id => the switch, read once per request */
	private array $hides = [];

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/** Whether this reader wants labelled posts kept out of what they are shown. */
	public function hides(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$this->hides[$userId] ??= $this->configService->getUserValue(self::USER_KEY, $userId) === '1';

		return $this->hides[$userId];
	}

	public function setHides(string $userId, bool $hide): void {
		$this->configService->setValueForUser($userId, self::USER_KEY, $hide ? '1' : '0');
		$this->hides[$userId] = $hide;
	}

	/** The switch as the client API answers it. */
	public function export(string $userId): array {
		return ['hide' => $this->hides($userId)];
	}

	/**
	 * The hashtags that label a post: the defaults and whatever the
	 * administrator added, every one normalised the way a stored hashtag is.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		if ($this->tags !== null) {
			return $this->tags;
		}

		$tags = self::DEFAULT_TAGS;
		foreach (explode(',', (string)$this->configService->getAppValue(ConfigService::SOCIAL_AI_TAGS)) as $tag) {
			$tag = self::normalise($tag);
			if ($tag !== '' && !in_array($tag, $tags, true)) {
				$tags[] = $tag;
			}
		}

		return $this->tags = $tags;
	}

	/**
	 * A hashtag as it is compared: trimmed, without its `#`, lower-cased. The
	 * same normalisation the followed-tags table applies, so `#AIgenerated`,
	 * `aigenerated` and `AIGENERATED` are one tag here as they are there.
	 */
	public static function normalise(string $tag): string {
		return FollowedTagsRequest::normalise($tag);
	}

	/**
	 * Whether any of these hashtags is one of the labelling tags.
	 *
	 * @param array<mixed> $hashtags as the post carries them, in any case and
	 *                               with or without `#`
	 * @param string[] $tags the normalised set to compare against
	 */
	public static function anyLabelled(array $hashtags, array $tags): bool {
		foreach ($hashtags as $hashtag) {
			if (is_array($hashtag)) {
				$hashtag = $hashtag['name'] ?? '';
			}
			if (!is_string($hashtag)) {
				continue;
			}

			if (in_array(self::normalise($hashtag), $tags, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a status entity, as a client would receive it, is labelled: by a
	 * hashtag, by a labelled attachment, or — for a boost — by the post it
	 * boosts, since a boost carries no tags or pictures of its own.
	 *
	 * @param array $status a status entity
	 */
	public function isLabelled(array $status): bool {
		if (($status['ai_generated'] ?? false) === true) {
			return true;
		}

		if (self::anyLabelled((array)($status['tags'] ?? []), $this->tags())) {
			return true;
		}

		foreach ((array)($status['media_attachments'] ?? []) as $attachment) {
			if (self::attachmentLabelled($attachment)) {
				return true;
			}
		}

		$reblog = $status['reblog'] ?? null;

		return is_array($reblog) && $this->isLabelled($reblog);
	}

	/**
	 * The same question of the model, for the export that builds the entity.
	 *
	 * A boost is asked about the post it boosts as well as itself, so the
	 * `ai_generated` on the wrapper agrees with the one on its `reblog`.
	 */
	public function labelsPost(Stream $post): bool {
		return self::labels($post, $this->tags());
	}

	/**
	 * `labelsPost()` with an explicit tag set, for a model exported where no
	 * container can hand out the service and the defaults are all there is.
	 *
	 * @param string[] $tags
	 */
	public static function labels(Stream $post, array $tags): bool {
		if (self::anyLabelled($post->getHashtags(), $tags)) {
			return true;
		}

		foreach ($post->getAttachments() as $attachment) {
			if (self::attachmentLabelled($attachment)) {
				return true;
			}
		}

		$object = $post->getObject();

		return $object instanceof Stream && self::labels($object, $tags);
	}

	/** Whether one attachment, however it is carried, states provenance. */
	private static function attachmentLabelled(mixed $attachment): bool {
		if (is_array($attachment)) {
			return ($attachment['ai_generated'] ?? false) === true;
		}

		if ($attachment instanceof Document || $attachment instanceof MediaAttachment) {
			return $attachment->isAiGenerated();
		}

		return false;
	}
}
