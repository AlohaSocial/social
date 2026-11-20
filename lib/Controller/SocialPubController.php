<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The browser half of the `/@{username}` routes, which
 * `ActivityPubController` hands a request asking for HTML. A reader with a
 * session gets the app, whose router has a view for each of these paths;
 * anybody else gets the public page.
 */
class SocialPubController extends Controller {
	use TNCDataResponse;

	private ?string $userId = null;
	private IL10N $l10n;
	private NavigationController $navigationController;
	private AccountService $accountService;
	private StreamService $streamService;
	private IInitialState $initialState;

	public function __construct(
		?string $userId,
		IInitialState $initialState,
		IRequest $request,
		IL10N $l10n,
		NavigationController $navigationController,
		private CacheActorService $cacheActorService,
		AccountService $accountService,
		StreamService $streamService,
		private ConfigService $configService,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userId = $userId;
		$this->initialState = $initialState;
		$this->l10n = $l10n;
		$this->navigationController = $navigationController;
		$this->accountService = $accountService;
		$this->streamService = $streamService;
	}

	/**
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	private function renderPage(string $username): Response {
		try {
			// only what this instance already knows: an anonymous page request is no
			// reason to WebFinger whatever name is in the address
			$actor = $this->cacheActorService->getFromAccount($username, false);
		} catch (CacheActorDoesNotExistException|ActorDoesNotExistException $e) {
			// A remote profile need not be cached yet: the client resolves a
			// well-formed federated handle through the rate-limited account-info API,
			// for guests and signed-in readers alike; nothing is WebFingered here.
			if ($this->isFederatedHandle($username)) {
				return ($this->userId === null)
					? $this->publicPage($username)
					: $this->navigationController->navigate();
			}

			// both: a name with no local actor throws ActorDoesNotExistException from
			// getFromLocalAccount(), and only one that reached the remote cache throws
			// the other
			return $this->notFound(
				$this->l10n->t('Account not found'),
				$this->l10n->t('There is no account named %s on this server.', [$username])
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}

		if ($this->userId !== null) {
			return $this->navigationController->navigate();
		}

		$displayName = $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername();
		return $this->publicPage($displayName);
	}

	private function publicPage(string $displayName): Response {
		$this->initialState->provideInitialState('serverData', [
			'public' => true,
			'cloudAddress' => $this->configService->getCloudUrl(),
		]);
		$page = new PublicTemplateResponse(Application::APP_ID, 'main', [
			'application' => $displayName . ' - Aloha Social',
		]);
		$page->setHeaderTitle(Application::APP_NAME_DISPLAY);

		return $page;
	}

	/**
	 * Whether this names an account on another server well enough to try. The
	 * host needs a dot and no port, so this route cannot be used to probe
	 * internal hosts like `localhost` or `10.0.0.5:8080`.
	 */
	private function isFederatedHandle(string $username): bool {
		$handle = ltrim($username, '@');

		return preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9][A-Za-z0-9-]*(?:\.[A-Za-z0-9-]+)+$/D', $handle) === 1;
	}

	/**
	 * An account's profile, for a browser.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function actor(string $username): Response {
		return $this->renderPage($username);
	}

	/**
	 * An account's followers, for a browser.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function followers(string $username): Response {
		return $this->renderPage($username);
	}

	/**
	 * An account's collections, for a browser. Not an ActivityPub URL, since
	 * collections are local; this exists so reloading or bookmarking the
	 * client-side route is not a 404. A visitor gets the public page.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/collections')]
	public function collections(string $username): Response {
		return $this->renderPage($username);
	}

	/**
	 * The accounts an account follows, for a browser.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function following(string $username): Response {
		return $this->renderPage($username);
	}

	/**
	 * A post, for a browser, rendered into the page in the client format so it
	 * shows before the app asks for anything. A reader with a session gets the
	 * app around it; anybody else the public page.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function displayPost(string $username, string $token): Response {
		// whoever is reading, so a post narrower than public resolves for the
		// people it was addressed to rather than only for the whole internet
		try {
			$viewer = $this->accountService->getCurrentViewer();
			$this->streamService->setViewer($viewer);
		} catch (AccountDoesNotExistException $e) {
		}

		$post = $this->resolvePost($username, $token);
		if ($post === null) {
			return $this->notFound(
				$this->l10n->t('Post not found'),
				$this->l10n->t('This post does not exist on this server. It may have been deleted, or the link may be wrong.')
			);
		}

		$post->setCompleteDetails(true);
		// the app reads a status the way its client API serves one; the
		// ActivityPub shape has no `account`, and its `id` is an address
		$post->setExportFormat(ACore::FORMAT_LOCAL);

		if ($this->userId !== null) {
			$page = $this->navigationController->navigate();
			$this->initialState->provideInitialState('item', $post);
			$this->addOembedDiscovery($page, $post);

			return $page;
		}

		$this->initialState->provideInitialState('item', $post);
		$this->initialState->provideInitialState('serverData', [
			'public' => true,
			'cloudAddress' => $this->configService->getCloudUrl(),
			'firstrun' => false,
			'setup' => false,
		]);

		// A TemplateResponse is private by default, which would redirect a visitor
		// to /login: serve the anonymous timeline's public app shell instead.
		$page = new PublicTemplateResponse(Application::APP_ID, 'main');
		$page->setHeaderTitle(Application::APP_NAME_DISPLAY);
		$this->addOembedDiscovery($page, $post);

		return $page;
	}

	/** Advertise oEmbed only where the requested post is already public. */
	private function addOembedDiscovery(Response $response, Stream $post): void {
		if (!$post->isLocal() || $post->getVisibility() !== Stream::TYPE_PUBLIC) {
			return;
		}

		$endpoint = $this->configService->getSocialUrl() . 'api/oembed?url=' . rawurlencode($post->getId());
		$response->addHeader('Link', '<' . $endpoint . '>; rel="alternate"; type="application/json+oembed"');
	}

	/** A minimal, public-only document that can be framed by an external site. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/embed')]
	public function embedPost(string $username, string $token): Response {
		try {
			$post = $this->streamService->getStreamById($this->configService->getSocialUrl() . '@' . $username . '/' . $token);
		} catch (StreamNotFoundException $e) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}

		if (!$post->isLocal() || $post->getVisibility() !== Stream::TYPE_PUBLIC) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$post->setCompleteDetails(true);

		$author = $this->cacheActorService->getFromId($post->getAttributedTo());
		$authorName = htmlspecialchars($author->getDisplayName() !== '' ? $author->getDisplayName() : $author->getPreferredUsername(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$authorHandle = htmlspecialchars('@' . $author->getPreferredUsername(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$authorUrl = htmlspecialchars($author->getId(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$postUrl = htmlspecialchars($post->getId(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$avatarUrl = $this->embedMediaUrl($author->getAvatar());
		$avatar = $avatarUrl === null ? '' : '<img class="avatar" src="' . htmlspecialchars($avatarUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="">';
		$publishedTime = $post->getPublishedTime();
		$published = gmdate(DATE_ATOM, $publishedTime);
		$publishedLabel = gmdate('Y-m-d', $publishedTime);
		$body = HtmlSanitizer::sanitize($post->getContent(), $post->getId());
		$body .= $this->embedMedia($post);
		if ($post->isSensitive()) {
			$warning = htmlspecialchars($post->getSpoilerText() ?: $this->l10n->t('Content warning'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$body = '<details><summary>' . $warning . '</summary>' . $body . '</details>';
		}

		$style = <<<'CSS'
			body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:16px;color:#222;background:#fff}
			article{box-sizing:border-box;width:100%;max-width:550px;margin:auto;overflow-wrap:anywhere}
			.author{display:flex;align-items:center;gap:10px;margin-bottom:12px}
			.avatar{width:40px;height:40px;object-fit:cover;border-radius:50%}
			.author-name{display:block;font-weight:600}
			.handle,time,footer{font-size:.9em;color:#555}
			a{color:#176b39}
			img,video,audio{max-width:100%;height:auto}
			footer{margin-top:1rem}
			@media(prefers-color-scheme:dark){body{color:#eee;background:#181818}.handle,time,footer{color:#bbb}a{color:#8fd6a9}}
			CSS;
		$document = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
			. $authorName . ' on Aloha Social</title><style>' . $style . '</style></head><body><article><header class="author">'
			. $avatar . '<a href="' . $authorUrl . '" rel="author"><span class="author-name">' . $authorName
			. '</span><span class="handle">' . $authorHandle . '</span></a></header><time datetime="' . $published . '">'
			. htmlspecialchars($publishedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</time><main>' . $body
			. '</main><footer><a href="' . $postUrl . '">'
			. htmlspecialchars($this->l10n->t('Open post'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</a></footer></article></body></html>';
		$response = new DataDisplayResponse($document, Http::STATUS_OK, [
			'Content-Type' => 'text/html; charset=utf-8',
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'ALLOWALL',
			'Cache-Control' => 'no-store',
		]);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameAncestorDomain('*');
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/** Media is loaded only from this instance, avoiding third-party tracking pixels in embeds. */
	private function embedMedia(Stream $post): string {
		$media = '';
		foreach ($post->getAttachments() as $attachment) {
			$url = $this->embedMediaUrl($attachment->getUrl());
			if ($url === null) {
				continue;
			}
			$src = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$description = htmlspecialchars($attachment->getDescription(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			if (str_starts_with($attachment->getType(), 'video') || str_starts_with($attachment->getType(), 'gifv')) {
				$media .= '<video controls preload="metadata" src="' . $src . '"></video>';
			} elseif ($attachment->getType() === 'audio') {
				$media .= '<audio controls preload="metadata" src="' . $src . '"></audio>';
			} elseif ($attachment->getType() === 'image') {
				$media .= '<img loading="lazy" src="' . $src . '" alt="' . $description . '">';
			}
		}

		return $media;
	}

	/** Accept attachment and avatar URLs only from this instance. */
	private function embedMediaUrl(?string $url): ?string {
		if ($url === null) {
			return null;
		}
		$parsed = parse_url($url);
		$localUrl = parse_url($this->configService->getSocialUrl());
		if (!is_array($parsed) || !is_array($localUrl) || !isset($parsed['scheme'], $parsed['host'])
			|| !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
			|| strtolower($parsed['host']) !== strtolower($localUrl['host'] ?? '')
			|| ($parsed['port'] ?? null) !== ($localUrl['port'] ?? null)) {
			return null;
		}

		return $url;
	}

	/**
	 * The post an address names: by the token in the post's own address first,
	 * then by the numeric id the app links with. Only for the browser page: an
	 * ActivityPub request asks by address, and answering another identifier
	 * there would invent a second canonical id.
	 *
	 * @throws SocialAppConfigException
	 */
	private function resolvePost(string $username, string $token): ?Stream {
		$postId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;
		try {
			return $this->streamService->getStreamById($postId, true);
		} catch (StreamNotFoundException $e) {
		}

		if (!ctype_digit($token)) {
			return null;
		}

		try {
			return $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($token));
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * What an address that names nothing gets: a 404, with the app for a reader
	 * with a session (whose views say what is missing) and a small guest page for
	 * anybody else.
	 */
	private function notFound(string $title, string $message): Response {
		if ($this->userId !== null) {
			$page = $this->navigationController->navigate();
			$page->setStatus(Http::STATUS_NOT_FOUND);

			return $page;
		}

		return new TemplateResponse(
			Application::APP_ID,
			'notfound',
			['title' => $title, 'message' => $message],
			TemplateResponse::RENDER_AS_GUEST,
			Http::STATUS_NOT_FOUND
		);
	}
}
