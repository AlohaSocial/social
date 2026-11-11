<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\PostService;
use Psr\Container\ContainerInterface;

/**
 * After a Bluesky account moved away, the person's account here says where
 * it went — one public post, or a profile field — through the ordinary post
 * and profile paths, so it is theirs to edit or delete afterwards. The
 * text is a short English template, as the switch wizard's announcement
 * is.
 */
class MoveAnnouncement {
	public const POST = 'post';
	public const PROFILE = 'profile';
	public const NONE = 'none';
	public const KINDS = [self::POST, self::PROFILE, self::NONE];
	/** the name of the profile field */
	public const FIELD = 'Also at';
	/** how many fields a profile holds (`Person::setFields()`) */
	private const MAX_FIELDS = 4;

	public function __construct(
		private AccountService $accountService,
		private ContainerInterface $container,
	) {
	}

	/**
	 * @throws MoveErrorException when it is not one of KINDS
	 */
	public static function assertKind(string $kind): void {
		if (!in_array($kind, self::KINDS, true)) {
			throw new MoveErrorException('announce is one of ' . implode(', ', self::KINDS), 'announce-invalid');
		}
	}

	/** The account's address on Bluesky, as anybody opens it. */
	public static function url(string $handle): string {
		return 'https://bsky.app/profile/' . $handle;
	}

	/** The text of the post. */
	public static function text(string $oldHandle, string $newHandle): string {
		return sprintf('My account %s now lives at %s: %s', $oldHandle, $newHandle, self::url($newHandle));
	}

	/**
	 * Says where the account went, as the person chose.
	 *
	 * @throws MoveErrorException profile-fields-full when the profile has no free field
	 * @throws \Throwable when the post or the profile cannot be written
	 */
	public function announce(string $userId, string $kind, string $oldHandle, string $newHandle): void {
		if ($kind === self::POST) {
			$post = new Post($this->accountService->getActorFromUserId($userId));
			$post->setContent(self::text($oldHandle, $newHandle));
			$post->setType(Stream::TYPE_PUBLIC);
			// this server's own template, not the person's words
			$post->setReview(Post::REVIEW_EXEMPT);
			$this->postService()->createPost($post);
		} elseif ($kind === self::PROFILE) {
			$this->accountService->setFields($userId, $this->withField($userId, self::url($newHandle)));
		}
	}

	/**
	 * The profile's fields with this one put in: in place of one of the
	 * same name, else added.
	 *
	 * @return list<array{name: string, value: string}>
	 * @throws MoveErrorException
	 */
	private function withField(string $userId, string $value): array {
		$fields = [];
		$placed = false;
		foreach ($this->accountService->getActorFromUserId($userId)->getFields() as $field) {
			$name = (string)($field['name'] ?? '');
			if ($name === self::FIELD) {
				$fields[] = ['name' => $name, 'value' => $value];
				$placed = true;
				continue;
			}
			$fields[] = ['name' => $name, 'value' => (string)($field['value'] ?? '')];
		}
		if (!$placed) {
			if (count($fields) >= self::MAX_FIELDS) {
				throw new MoveErrorException('The profile has no free field', 'profile-fields-full');
			}
			$fields[] = ['name' => self::FIELD, 'value' => $value];
		}

		return $fields;
	}

	/** Resolved when needed: posting reaches most of the app. */
	private function postService(): PostService {
		return $this->container->get(PostService::class);
	}
}
