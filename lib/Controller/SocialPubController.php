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
 * The browser half of the `/@{username}` routes.
 *
 * `ActivityPubController` owns those urls and answers ActivityPub clients
 * itself; a request whose `Accept` header asks for HTML is handed here. Who is
 * asking decides what they get: a reader with a session gets the app, the same
 * page `NavigationController::navigate()` serves at `/`, since the client-side
 * router has a view for every one of these paths — a profile, its followers, a
 * post. Everybody else gets the public page, which has no navigation and no
 * reply box. This used to serve the public page to everybody, so a link to a
 * post or a profile landed a logged-in reader on a page with a "Get your own
 * free account" banner and a Follow button that started the remote-follow flow
 * for an account they could have followed with one click.
 *
 * @package OCA\Social\Controller
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
			// what this instance already knows, and nothing it would have to go
			// and ask for: an anonymous page request is not a reason to
			// webfinger whatever name is in the address. That lookup is also
			// where an unknown name used to blow up — a name without a host is
			// not a valid account to resolve, and the exception saying so was
			// a 500 rather than the 404 it meant
			$actor = $this->cacheActorService->getFromAccount($username, false);
		} catch (CacheActorDoesNotExistException|ActorDoesNotExistException $e) {
			// A remote profile need not already be in this instance's actor
			// cache. Let the client resolve a well-formed federated handle
			// through the rate-limited account-info API; nothing is
			// WebFingered as part of this HTML request, whoever is asking.
			//
			// Whoever is asking: a guest got the page and somebody signed in
			// got a 404 for the same handle, which is the wrong way round
			// twice over — a reader with an account is the one who can follow
			// what they find there.
			if ($this->isFederatedHandle($username)) {
				return ($this->userId === null)
					? $this->publicPage($username)
					: $this->navigationController->navigate();
			}

			// both, because which one comes back depends on how far the lookup
			// got: a name this server has no local actor for throws
			// ActorDoesNotExistException from inside getFromLocalAccount(),
			// and only a name that got as far as the remote cache throws the
			// other. Catching one of the two left the bare local name — the
			// common case, a mistyped handle — as a 500.
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
		$page->setHeaderTitle($this->l10n->t('Aloha Social'));

		return $page;
	}

	/**
	 * Whether this names an account on another server well enough to try.
	 *
	 * The host needs a dot and may not carry a port. A fediverse handle never
	 * has one — WebFinger is asked of the host — and accepting them turned
	 * this route into a way for anybody at all to have the server render a
	 * page for `someone@10.0.0.5:8080`, one host and port at a time. A host
	 * with no dot at all (`localhost`, an intranet name) went the same way.
	 */
	private function isFederatedHandle(string $username): bool {
		$handle = ltrim($username, '@');

		return preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9][A-Za-z0-9-]*(?:\.[A-Za-z0-9-]+)+$/D', $handle) === 1;
	}

	/**
	 * Return webpage content for human navigation.
	 * Should return information about a Social account, based on username.
	 *
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
	 * Return webpage content for human navigation.
	 * Should return followers of a Social account, based on username.
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
	 * An account's collections, for a browser.
	 *
	 * Not an ActivityPub URL — a collection is local and federates nothing —
	 * so unlike the followers page there is no JSON to answer first: the
	 * client-side router owns the path, and this exists so that reloading or
	 * bookmarking it is not a 404. A visitor gets the public page, like the
	 * profile itself.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/collections')]
	public function collections(string $username): Response {
		return $this->renderPage($username);
	}

	/**
	 * Somebody's page of work, for a browser.
	 *
	 * Public, and the point of the feature: a portfolio is what a photographer
	 * links from a CV, and a link that asks the reader to sign in first is not
	 * that. The client-side router owns the path; this exists so that a link
	 * to it is not a 404 when it is opened cold.
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/portfolio')]
	public function portfolio(string $username): Response {
		return $this->renderPage($username);
	}

	/**
	 * Return webpage content for human navigation.
	 * Should return following of a Social account, based on username.
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
	 * A post, for a browser.
	 *
	 * The post is rendered into the page so that it shows before the app has
	 * asked for anything, in the client format the app reads everywhere else.
	 * A reader with a session gets the app around it; anybody else gets the
	 * public page.
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

		// A TemplateResponse is private by default: Nextcloud redirects a
		// visitor to /login while the page is loading, even though the public
		// post was already resolved above. Serve the same app shell as the
		// anonymous timeline route so a cold link and an in-app navigation have
		// the same public access rules.
		$page = new PublicTemplateResponse(Application::APP_ID, 'main');
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
	 * The post an address names: by the token in the post's own address
	 * first, then by the numeric id the app itself links with.
	 *
	 * The app writes its links to a post with the id its client API uses,
	 * which is not the token in the post's ActivityPub address. Opening one in
	 * a new tab, reloading it, or following one somebody sent found nothing
	 * here and the page said the post did not exist. Only for the browser
	 * page: an ActivityPub request asks for a post by its address, and
	 * answering a different identifier there would invent a second canonical
	 * id for every post.
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
	 * What an address that names nothing gets: a 404, and a page that says so.
	 *
	 * A reader with a session gets the app, whose views say "User not found"
	 * and "This post is not available" themselves once they have asked; the
	 * status code is still a 404 so that a browser, a crawler or a link
	 * checker is told the truth. Everybody else gets a small guest page, since
	 * the public page had nothing to show and nowhere to go.
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
