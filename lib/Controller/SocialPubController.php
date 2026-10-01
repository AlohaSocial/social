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
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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

			return $page;
		}

		$this->initialState->provideInitialState('item', $post);
		$this->initialState->provideInitialState('serverData', [
			'public' => true,
			'firstrun' => false,
			'setup' => false,
		]);

		// A TemplateResponse is private by default: Nextcloud redirects a
		// visitor to /login while the page is loading, even though the public
		// post was already resolved above. Serve the same app shell as the
		// anonymous timeline route so a cold link and an in-app navigation have
		// the same public access rules.
		return new PublicTemplateResponse(Application::APP_ID, 'main');
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
