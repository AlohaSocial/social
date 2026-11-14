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
use OCA\Social\Service\SignupChallengeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The registration page of self-registered external users.
 *
 * Public, and only for somebody who is not logged in. The form posts with
 * the CSRF token the guest page carries. `ExternalSignupService` decides
 * everything; this answers in the shapes the page reads.
 *
 * The session remembers which registration the form made, so that the code
 * from its confirmation email is only ever tried against that one, and how
 * many wrong codes it was given.
 */
class SignupController extends Controller {
	public const SESSION_PENDING = 'social_signup_pending';
	public const SESSION_TRIES = 'social_signup_code_tries';

	public function __construct(
		IRequest $request,
		private ExternalSignupService $signupService,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private SignupChallengeService $challengeService,
		private ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The form, or why there is none.
	 *
	 * @param string $invite the token of an invitation link
	 * @param string $return the OAuth authorization request of the app that sent the person here; anything else is ignored, see `SignupReturn`
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/signup')]
	public function page(string $invite = '', string $return = ''): Response {
		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('social.Navigation.navigate'));
		}

		$this->initialState->provideInitialState('signup', $this->signupService->pageState($invite, $return));

		return $this->template();
	}

	/**
	 * A proof-of-work challenge for the form; see `SignupChallengeService`.
	 * Nothing is stored until an answer to it is accepted.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/signup/challenge')]
	public function challenge(): DataResponse {
		if ($this->userSession->isLoggedIn()) {
			return new DataResponse(['message' => 'You are already logged in.'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->challengeService->issue());
	}

	/**
	 * A registration.
	 *
	 * `website` is a field no person sees and a form-filling bot does;
	 * `challenge` and `nonce` are the proof of work. See
	 * `ExternalSignupService::submit()`.
	 */
	#[PublicPage]
	#[UseSession]
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
		bool $notice = false,
		string $noticeVersion = '',
		string $challenge = '',
		string $nonce = '',
		string $return = '',
	): DataResponse {
		if ($this->userSession->isLoggedIn()) {
			return new DataResponse(['message' => 'You are already logged in.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->signupService->submit(
				$handle,
				$email,
				$password,
				$rules,
				$age,
				$invite,
				$website,
				$this->request->getRemoteAddress(),
				$notice,
				$noticeVersion,
				$challenge,
				$nonce,
				$return,
			);
		} catch (ExternalUserException $e) {
			return new DataResponse(
				['message' => $e->getMessage(), 'field' => $e->getField()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		// every answer that says "check your email" offers the code, whether
		// or not a registration waits behind it, so the two look the same
		$this->session->set(self::SESSION_PENDING, $result['pending'] ?? 0);
		$this->session->set(self::SESSION_TRIES, 0);
		unset($result['pending']);
		$result['code'] = $result['state'] === ExternalSignupService::STATE_VERIFY && $this->signupService->confirmsByCode();

		return new DataResponse($result);
	}

	/**
	 * The code from the confirmation email, typed into the page that made
	 * the registration. Only that registration is tried, and only
	 * `ExternalSignupService::CODE_ATTEMPTS` wrong codes are taken; a page
	 * that made none is answered as a wrong code is.
	 */
	#[PublicPage]
	#[UseSession]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/signup/confirm')]
	public function confirm(string $code = ''): DataResponse {
		if ($this->userSession->isLoggedIn()) {
			return new DataResponse(['message' => 'You are already logged in.'], Http::STATUS_FORBIDDEN);
		}

		$tries = (int)$this->session->get(self::SESSION_TRIES);
		if ($tries >= ExternalSignupService::CODE_ATTEMPTS) {
			return new DataResponse(
				['message' => $this->signupService->codeLockedMessage(), 'field' => 'code-locked'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$result = $this->signupService->confirmCode((int)$this->session->get(self::SESSION_PENDING), $code);
		} catch (ExternalUserException $e) {
			$tries++;
			$this->session->set(self::SESSION_TRIES, $tries);
			$locked = $e->getField() === 'code-locked' || $tries >= ExternalSignupService::CODE_ATTEMPTS;

			return new DataResponse(
				[
					'message' => ($locked && $e->getField() !== 'code-locked') ? $this->signupService->codeLockedMessage() : $e->getMessage(),
					'field' => $locked ? 'code-locked' : $e->getField(),
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$this->session->remove(self::SESSION_PENDING);
		$this->session->remove(self::SESSION_TRIES);

		return new DataResponse($result);
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

		return $this->template();
	}

	/**
	 * The page, allowed to start the Web Worker that solves the challenge
	 * from this server's own scripts.
	 */
	private function template(): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'signup', [], TemplateResponse::RENDER_AS_GUEST);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedWorkerSrcDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
