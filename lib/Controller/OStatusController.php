<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;

class OStatusController extends Controller {
	use TNCDataResponse;
	use TArrayTools;

	private CacheActorService $cacheActorService;
	private AccountService $accountService;
	private MiscService $miscService;
	private IUserSession $userSession;
	private IInitialState $initialState;

	public function __construct(
		IRequest $request,
		IInitialState $initialState,
		CacheActorService $cacheActorService,
		AccountService $accountService,
		private CurlService $curlService,
		MiscService $miscService,
		IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->initialState = $initialState;
		$this->cacheActorService = $cacheActorService;
		$this->accountService = $accountService;
		$this->miscService = $miscService;
		$this->userSession = $userSession;
	}

	/**
	 * The page another server sends somebody to, to follow an account from here:
	 * `uri` is the account, by address or actor URL. Only accounts held here are
	 * looked up; any other is passed to the page as `resolve`, which asks
	 * `resolve()` with the page's request token.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/ostatus/follow/')]
	public function subscribe(string $uri): Response {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Exception('Failed to retrieve current user');
			}

			try {
				$account = $this->cacheActorService->resolve(self::reference($uri))->getAccount();
				$resolve = '';
			} catch (Exception) {
				$account = '';
				$resolve = $uri;
			}

			$this->initialState->provideInitialState('serverData', [
				'account' => $account,
				'resolve' => $resolve,
				'currentUser' => [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				],
			]);
			return new TemplateResponse(
				'social', 'main', []
			);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * The account a follow link names, looked up on its own server where
	 * this one does not hold it: what `subscribe()` leaves to the page.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/ostatus/resolve')]
	public function resolve(string $uri): DataResponse {
		try {
			$actor = $this->cacheActorService->resolve(self::reference($uri), true);

			return $this->success(['account' => $actor->getAccount()]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/** An account as a follow link names it, without the `acct:` some servers put in front. */
	private static function reference(string $uri): string {
		$uri = trim($uri);

		return (stripos($uri, 'acct:') === 0) ? substr($uri, 5) : $uri;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/ostatus/followRemote/{local}')]
	public function followRemote(string $local): Response {
		try {
			$following = $this->accountService->getActor($local);

			$this->initialState->provideInitialState('serverData', [
				'local' => $local,
				'account' => $following->getAccount(),
			]);
			return new TemplateResponse(
				'social', 'main', [], 'guest'
			);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Resolves where a remote account's instance sends a follow request. Public
	 * and making a WebFinger lookup to a host the caller names, so it is rate
	 * limited.
	 */
	#[AnonRateLimit(limit: 10, period: 300)]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/ostatus/link/{local}/{account}')]
	public function getLink(string $local, string $account): Response {
		try {
			$following = $this->accountService->getActor($local);
			$result = $this->curlService->webfingerAccount($account);

			try {
				$link = $this->extractArray(
					'rel', 'http://ostatus.org/schema/1.0/subscribe',
					$this->getArray('links', $result)
				);
			} catch (ArrayNotFoundException $e) {
				throw new RetrieveAccountFormatException();
			}

			$template = $this->get('template', $link, '');
			$url = str_replace('{uri}', $following->getAccount(), $template);

			// The template is the remote server's: only redirect to a web address.
			if (preg_match('/^https?:\/\//i', $url) !== 1) {
				throw new RetrieveAccountFormatException();
			}

			return $this->success(['url' => $url]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}
}
