<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GifService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TranslationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What this instance says about itself, answered without a token since
 * `/api/v1/instance` is every Mastodon client's first request: its rules,
 * blocked domains, policies, known servers, translation languages, oEmbed,
 * and the custom emoji and GIFs its posts may use.
 */
class InstanceApiController extends MastodonApiController {
	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private InstanceService $instanceService,
		private ExternalSignupService $externalSignupService,
		private ISession $session,
		private ConfigService $configService,
		private EmojiService $emojiService,
		private FediverseService $fediverseService,
		private GifService $gifService,
		private TranslationService $translationService,
		private AdminApiService $adminApiService,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 * Mastodon's V1::Instance entity — the first request every client makes.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/')]
	public function instance(): JSONResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);
		return Revalidation::byContent($this->request, new DataResponse($local, Http::STATUS_OK));
	}

	/**
	 * The instance's rules, from the `rules` app value; `[]` when none are set.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/rules')]
	public function instanceRules(): DataResponse {
		return new DataResponse(
			$this->instanceService->getLocal(Stream::FORMAT_LOCAL)->getRules(),
			Http::STATUS_OK
		);
	}

	/**
	 * The instances this one does not federate with, or keeps out of its public
	 * timelines, each `suspend` or `silence` with its public reason as `comment`.
	 *
	 * Answers `[]` unless the admin set `publish_blocks`, and always in
	 * allow-list mode, where the same column lists the instances it does talk to.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/domain_blocks')]
	public function instanceDomainBlocks(): DataResponse {
		if ($this->configService->getAppValue(ConfigService::SOCIAL_PUBLISH_BLOCKS) !== '1'
			|| $this->fediverseService->getAccessType() !== 'all_but') {
			return new DataResponse([], Http::STATUS_OK);
		}

		return new DataResponse(array_map(
			static fn (AdminDomainBlock $block): array => $block->published(),
			$this->adminApiService->domainBlocks()
		), Http::STATUS_OK);
	}

	/**
	 * The long form of what this instance is (Mastodon's `ExtendedDescription`),
	 * from the `extended_description` app value, falling back to the short
	 * description.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/extended_description')]
	public function instanceExtendedDescription(): DataResponse {
		$text = trim($this->configService->getAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION));
		$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse([
			'updated_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
			'content' => $text === '' ? $instance->getDescription() : $text,
		], Http::STATUS_OK);
	}

	/**
	 * The server's privacy policy: Nextcloud's own, from Theming. A server
	 * without one answers 404, as Mastodon does, rather than an empty document.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/privacy_policy')]
	public function instancePrivacyPolicy(): DataResponse {
		$policy = $this->instanceService->privacyPolicy();
		if ($policy === null) {
			return new DataResponse(
				['error' => 'this server has published no privacy policy'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse($policy, Http::STATUS_OK);
	}

	/** The server's terms of service — Nextcloud's legal notice. See above. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/terms_of_service')]
	public function instanceTermsOfService(): DataResponse {
		$terms = $this->instanceService->termsOfService();
		if ($terms === null) {
			return new DataResponse(
				['error' => 'this server has published no terms of service'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse($terms, Http::STATUS_OK);
	}

	/**
	 * oEmbed for a public post of this server's own, as Mastodon serves it.
	 * The rich response frames only the public-only embed page, never the full
	 * application shell.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/oembed')]
	public function oembed(string $url = '', string $format = 'json'): DataResponse {
		try {
			if ($format !== '' && strtolower($format) !== 'json') {
				// oEmbed says to answer 501 for an unsupported format.
				return new DataResponse(
					['error' => 'only the json format is served'], Http::STATUS_NOT_IMPLEMENTED
				);
			}

			$post = $this->streamService->getStreamById(trim($url));
			if (!$post->isLocal() || $post->getVisibility() !== Stream::TYPE_PUBLIC) {
				throw new StreamNotFoundException('Stream not found');
			}

			$author = $this->cacheActorService->getFromId($post->getAttributedTo());
			$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

			$embedUrl = $post->getId() . '/embed';
			$escapedEmbedUrl = htmlspecialchars($embedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html = '<iframe src="' . $escapedEmbedUrl . '" width="550" height="480" loading="lazy" frameborder="0" style="max-width:100%;border:0;border-radius:12px" title="Aloha Social post"></iframe>';

			return new DataResponse([
				'type' => 'rich',
				'version' => '1.0',
				'html' => $html,
				'width' => 550,
				'height' => 480,
				'author_name' => ($author->getDisplayName() !== '')
					? $author->getDisplayName() : $author->getPreferredUsername(),
				'author_url' => $author->getId(),
				'provider_name' => $instance->getTitle(),
				'provider_url' => $this->configService->getCloudUrl(),
				'cache_age' => 300,
				'url' => $post->getId(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Which languages this server can translate between, read from the
	 * translation provider. With no provider it answers an empty object,
	 * matching `translation.enabled: false` in `/api/v2/instance`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/translation_languages')]
	public function instanceTranslationLanguages(): DataResponse {
		try {
			return new DataResponse(
				(object)$this->translationService->languages(), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's V2::Instance entity. Newer clients ask for this one first and
	 * fall back to v1 on a 404; answering it saves them the round trip.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/instance')]
	public function instanceV2(): JSONResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);
		return Revalidation::byContent($this->request, new DataResponse($local->asV2(), Http::STATUS_OK));
	}

	/**
	 * The instances this one has heard of (Mastodon's `/api/v1/instance/peers`):
	 * hostnames of cached remote actors, by the same walk as the `domain_count`
	 * statistic. Public, as Mastodon's is.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/peers')]
	public function instancePeers(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getPeers(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's weekly activity series over twelve weeks. Only statuses are
	 * counted: accounts are Nextcloud users, so `registrations` and `logins` are
	 * always `0`.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/activity')]
	public function instanceActivity(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getWeeklyActivity(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The emoji this instance publishes; only the ones marked visible, as a
	 * picker reads this route.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/custom_emojis')]
	public function customEmojis(): JSONResponse {
		return Revalidation::byContent(
			$this->request, new DataResponse($this->emojiService->visible(), Http::STATUS_OK)
		);
	}

	/**
	 * The image behind a shortcode. Unauthenticated, like `mediaOpen()`: remote
	 * servers dereference it from an `Emoji` tag without a token, and an emoji is
	 * public by nature.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/emoji/{shortcode}')]
	public function emojiOpen(string $shortcode): Response {
		try {
			$emoji = $this->emojiService->byShortcode($shortcode);
			if ($emoji === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->emojiService->picture($shortcode),
				Http::STATUS_OK,
				['Content-Type' => $emoji->getMediaType()]
			);
			// Replacing an emoji is deliberate, so a day is cheap; shared caches may
			// keep it, as everybody gets the same bytes.
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The instance's GIF library, or the part of it that matches `q`, for the
	 * composer's picker, so a viewer is required. Paged with `limit` and
	 * `offset`: the animated emoji alone are 881 images.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/gifs')]
	public function gifs(string $q = '', int $limit = 24, int $offset = 0): DataResponse {
		try {
			$this->initViewer(true);

			$limit = max(1, min(200, $limit));
			$found = $this->gifService->search($q);

			return new DataResponse([
				'gifs' => array_slice($found, max(0, $offset), $limit),
				'total' => count($found),
				// said wherever the pack is shown, because CC BY asks for it
				'attribution' => $this->gifService->attribution(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The bytes behind a slug. Unauthenticated, like `emojiOpen()`: the same
	 * bytes for everybody, so the grid can be cached.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/gif/{slug}')]
	public function gifOpen(string $slug): Response {
		try {
			// Release the session lock: PHP serialises requests holding it, and the
			// picker's sixty thumbnails (some fetched from Google on a cold instance)
			// would otherwise load one at a time.
			$this->session->close();

			$gif = $this->gifService->bySlug($slug);
			if ($gif === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->gifService->file($slug),
				Http::STATUS_OK,
				['Content-Type' => $gif->getMediaType()]
			);
			// Replacing an image is deliberate, so a day is cheap; shared caches may
			// keep it, as everybody gets the same bytes.
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
