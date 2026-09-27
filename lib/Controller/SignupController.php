<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Service\ExternalSignupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The registration page of self-registered external users.
 *
 * Public, and only for somebody who is not logged in. The form posts with
 * the CSRF token the guest page carries. `ExternalSignupService` decides
 * everything; this answers in the shapes the page reads.
 */
class SignupController extends Controller {
	public function __construct(
		IRequest $request,
		private ExternalSignupService $signupService,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The form, or why there is none.
	 *
	 * @param string $invite the token of an invitation link
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/signup')]
	public function page(string $invite = ''): Response {
		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('social.Navigation.navigate'));
		}

		$this->initialState->provideInitialState('signup', $this->signupService->pageState($invite));

		return new TemplateResponse(Application::APP_ID, 'signup', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * A registration.
	 *
	 * `website` is a field no person sees and a form-filling bot does; see
	 * `ExternalSignupService::submit()`.
	 */
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/signup')]
	public function submit(
		string $handle = '',
		string $email = '',
		string $password = '',
		bool $rules = false,
		bool $age = false,
		string $invite = '',
		string $website = '',
	): DataResponse {
		if ($this->userSession->isLoggedIn()) {
			return new DataResponse(['message' => 'You are already logged in.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($this->signupService->submit(
				$handle,
				$email,
				$password,
				$rules,
				$age,
				$invite,
				$website,
				$this->request->getRemoteAddress(),
			));
		} catch (ExternalUserException $e) {
			return new DataResponse(
				['message' => $e->getMessage(), 'field' => $e->getField()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}
	}

	/**
	 * The link from the confirmation email: the page again, telling the
	 * person what happened.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[AnonRateLimit(limit: 20, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/signup/verify/{token}')]
	public function verify(string $token): Response {
		$state = $this->signupService->pageState();
		try {
			$state['result'] = $this->signupService->verify($token);
		} catch (ExternalUserException $e) {
			$state['result'] = ['state' => 'error', 'message' => $e->getMessage()];
		}

		$this->initialState->provideInitialState('signup', $state);

		return new TemplateResponse(Application::APP_ID, 'signup', [], TemplateResponse::RENDER_AS_GUEST);
	}
}
