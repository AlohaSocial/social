<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Reference;

use Exception;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCP\Collaboration\Reference\IPublicReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Unfurls a link to a post or a profile of this app wherever Nextcloud renders
 * references: a Talk message, a Text document, a Deck card.
 *
 * Only what anyone could read is rendered — a public or unlisted post, a
 * profile — because a reference is resolved once and then served from a cache
 * shared by everyone who sees the link. A followers-only or direct post, or a
 * post this server does not hold, resolves to nothing and the link stays a
 * link. Nothing is fetched from another server on the way: a remote account
 * that is not cached here is not looked up either.
 */
class PostReferenceProvider implements IReferenceProvider, IPublicReferenceProvider {
	/** how much of a post the card shows */
	public const EXCERPT_LENGTH = 300;

	/** the digits an id tail has: `time()` and ten more, so it never fits an int */
	private const ID_TAIL_LENGTH = 20;

	public function __construct(
		private StreamService $streamService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function matchReference(string $referenceText): bool {
		return $this->parse($referenceText) !== null;
	}

	#[\Override]
	public function resolveReference(string $referenceText): ?IReference {
		$target = $this->parse($referenceText);
		if ($target === null) {
			return null;
		}

		try {
			if ($target['token'] === '') {
				return $this->account($referenceText, $target['account']);
			}

			return $this->post($referenceText, $target['account'], $target['token']);
		} catch (Exception $e) {
			// not found, not readable by everyone, or malformed: the link is
			// left as a link
			$this->logger->debug('reference not resolved', ['reference' => $referenceText, 'exception' => $e]);

			return null;
		}
	}

	#[\Override]
	public function resolveReferencePublic(string $referenceText, string $sharingToken): ?IReference {
		// what is rendered is public anyway, so a public share renders the same
		return $this->resolveReference($referenceText);
	}

	/**
	 * The prefix is the reference itself and the key is shared: one cached card
	 * per link, for everyone. That is right only because nothing rendered
	 * depends on who is looking — see `post()`.
	 */
	#[\Override]
	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	#[\Override]
	public function getCacheKey(string $referenceId): ?string {
		return null;
	}

	#[\Override]
	public function getCacheKeyPublic(string $referenceId, string $sharingToken): ?string {
		return null;
	}

	/**
	 * Splits a link into the account and, for a post, the token after it.
	 *
	 * Both of this app's page shapes are read: `/@alice/42` (the nid a client
	 * uses, which can be nineteen digits long) and `/@alice/17580000000012345678`
	 * (the tail of the post's ActivityPub id, which is what the logged-out page
	 * is addressed by, and which is twenty digits).
	 *
	 * @return array{account: string, token: string}|null
	 */
	private function parse(string $referenceText): ?array {
		$referenceText = trim($referenceText);
		foreach ($this->prefixes() as $prefix) {
			if (!str_starts_with($referenceText, $prefix)) {
				continue;
			}

			$path = substr($referenceText, strlen($prefix));
			if (preg_match('#^@([A-Za-z0-9_.\-]+(?:@[A-Za-z0-9.\-]+)?)(?:/([0-9]+))?/?(?:[?\#].*)?$#', $path, $match) !== 1) {
				return null;
			}

			return ['account' => $match[1], 'token' => $match[2] ?? ''];
		}

		return null;
	}

	/**
	 * This app's front page, with and without `index.php`: a link is pasted the
	 * way the browser showed it, and that depends on whether pretty URLs are on.
	 *
	 * @return string[]
	 */
	private function prefixes(): array {
		$base = rtrim($this->urlGenerator->linkToRouteAbsolute('social.Navigation.navigate'), '/') . '/';
		$other = str_contains($base, '/index.php/')
			? str_replace('/index.php/', '/', $base)
			: (string)preg_replace('#/apps/social/$#', '/index.php/apps/social/', $base);

		return array_values(array_unique([$base, $other]));
	}

	/**
	 * @throws Exception
	 */
	private function post(string $referenceText, string $username, string $token): ?IReference {
		$post = null;
		if (strlen($token) < self::ID_TAIL_LENGTH && (string)(int)$token === $token) {
			try {
				$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($token));
			} catch (StreamNotFoundException $e) {
				// not a nid this server knows; an id tail of that length is rare but not impossible
			}
		}
		if ($post === null) {
			$post = $this->streamService->getStreamById(
				$this->configService->getSocialUrl() . '@' . $username . '/' . $token, false
			);
		}

		if (!in_array($post->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true)) {
			// what one viewer may read is not what everyone may read, and the
			// card would be cached for everyone
			return null;
		}

		$author = $post->hasActor() ? $post->getActor() : $this->cacheActorService->getFromId($post->getAttributedTo());
		if ($author === null) {
			return null;
		}

		$reference = new Reference($referenceText);
		$reference->setAccessible(true);
		$reference->setTitle($this->l10n->t('%1$s (@%2$s) on Aloha Social', [$author->getDisplayName(), $author->getAccount()]));
		$reference->setDescription($this->textOf($post));
		$reference->setImageUrl($this->pictureOf($post, $author));
		$reference->setUrl($post->isLocal() && $post->getNid() > 0
			? $this->urlGenerator->linkToRouteAbsolute(
				'social.ActivityPub.displayPost',
				['username' => $author->getPreferredUsername(), 'token' => (string)$post->getNid()]
			)
			: $referenceText);

		return $reference;
	}

	/**
	 * @throws Exception
	 */
	private function account(string $referenceText, string $account): ?IReference {
		// never a fetch: an unfurl is not a reason to call another server
		$person = str_contains($account, '@')
			? $this->cacheActorService->getFromAccount($account, false)
			: $this->cacheActorService->getFromLocalAccount($account);

		$reference = new Reference($referenceText);
		$reference->setAccessible(true);
		$reference->setTitle($this->l10n->t('%1$s (@%2$s) on Aloha Social', [$person->getDisplayName(), $person->getAccount()]));
		$reference->setDescription(self::excerpt($person->getDescription()));
		$reference->setImageUrl($person->getAvatar() !== '' ? $person->getAvatar() : null);
		$reference->setUrl($this->urlGenerator->linkToRouteAbsolute(
			'social.ActivityPub.actorAlias', ['username' => $person->getAccount()]
		));

		return $reference;
	}

	/**
	 * The card's text: behind a content warning, the warning and nothing else.
	 */
	private function textOf(Stream $post): string {
		if ($post->getSummary() !== '' && ($post->isSensitive() || $post->getContent() === '')) {
			return $this->l10n->t('Content warning: %s', [self::excerpt($post->getSummary())]);
		}

		$text = self::excerpt($post->getContent());
		$attachments = count($post->getAttachments());
		if ($attachments > 0) {
			$note = $this->l10n->n('%n attachment', '%n attachments', $attachments);
			$text = ($text === '') ? $note : $text . ' — ' . $note;
		}

		return $text;
	}

	/**
	 * The first picture attached, else the author's portrait. Behind a content
	 * warning the picture is the portrait: the attachment is what is warned about.
	 */
	private function pictureOf(Stream $post, Person $author): ?string {
		if (!$post->isSensitive()) {
			foreach ($post->getAttachments() as $attachment) {
				if ($attachment->getType() !== 'image') {
					continue;
				}
				$url = ($attachment->getPreviewUrl() !== '') ? $attachment->getPreviewUrl() : (string)$attachment->getUrl();
				if ($url !== '') {
					return $url;
				}
			}
		}

		return ($author->getAvatar() !== '') ? $author->getAvatar() : null;
	}

	/**
	 * Plain text out of the HTML a post is stored as, cut to card length.
	 */
	public static function excerpt(string $html, int $length = self::EXCERPT_LENGTH): string {
		$text = html_entity_decode(strip_tags(preg_replace('#</p>\s*<p>|<br\s*/?>#i', ' ', $html) ?? $html), ENT_QUOTES | ENT_HTML5);
		$text = trim((string)preg_replace('/\s+/', ' ', $text));
		if (mb_strlen($text) > $length) {
			$text = rtrim(mb_substr($text, 0, $length - 1)) . '…';
		}

		return $text;
	}
}
