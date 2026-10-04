<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidResourceException;
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

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/ostatus/follow/')]
	public function subscribe(string $uri): Response {
		try {
			try {
				$actor = $this->cacheActorService->getFromAccount($uri);
			} catch (InvalidResourceException $e) {
				$actor = $this->cacheActorService->getFromId($uri);
			}

			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Exception('Failed to retrieve current user');
			}

			$this->initialState->provideInitialState('serverData', [
				'account' => $actor->getAccount(),
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
	 * Resolves where a remote account's instance sends a follow request.
	 *
	 * Anyone may call this, and the webfinger lookup it makes goes to whatever
	 * host the caller names — so the limit has to be real: the annotation this
	 * replaces was a docblock, and docblock throttles are no longer read.
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

			// the template is the remote server's to write, and the browser is
			// sent to the result: only a web address is one to send it to
			if (preg_match('/^https?:\/\//i', $url) !== 1) {
				throw new RetrieveAccountFormatException();
			}

			return $this->success(['url' => $url]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}
}
