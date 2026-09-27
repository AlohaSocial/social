<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\External\ExternalScope;
use OCA\Social\External\ExternalUserBackend;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCSController;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Log\LoggerInterface;

/**
 * Keeps self-registered external users inside the Social app.
 *
 * Registered as a global middleware, so it sees the controllers of every app
 * and of core, and checks `ExternalScope` before any of them runs. Hiding a
 * menu entry is not a boundary; this is. A page is answered with a redirect
 * to Social, anything else with 403. Personal settings are allowed for the
 * sections in `ExternalScope::SETTINGS_SECTIONS`, and the navigation of that
 * page lists only those.
 *
 * Core's profile page is sent to the Social profile for everybody when the
 * profile is an external user's: an external user has no Nextcloud profile to
 * show, and a link to one from inside Social should still land somewhere.
 *
 * WebDAV is not a controller; `ExternalDavGuard` covers it.
 */
class ExternalScopeMiddleware extends Middleware {
	public function __construct(
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private ISettingsManager $settingsManager,
		private IInitialStateService $initialStateService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		$class = $controller::class;
		$viewer = $this->userSession->getUser();
		$external = ExternalUserBackend::isExternal($viewer);

		if (ExternalScope::isProfilePage($class)) {
			$target = (string)$this->request->getParam('targetUserId', '');
			if ($external || ExternalUserBackend::isExternal($this->userManager->get($target))) {
				throw new ExternalScopeException(
					$this->urlGenerator->linkToRoute('social.ActivityPub.actorAlias', ['username' => $target])
				);
			}

			return;
		}

		if (!$external) {
			return;
		}

		$verdict = ExternalScope::verdict($class, $methodName);
		if ($verdict === ExternalScope::ALLOW) {
			return;
		}
		if ($verdict === ExternalScope::SETTINGS) {
			if (ExternalScope::allowsSettingsSection((string)$this->request->getParam('section', ''))) {
				return;
			}

			throw new ExternalScopeException(
				$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => ExternalScope::SETTINGS_FALLBACK])
			);
		}

		$this->logger->debug('an external user was kept inside Social', [
			'controller' => $class, 'method' => $methodName,
		]);

		throw new ExternalScopeException($this->urlGenerator->linkToRoute('social.Navigation.navigate'));
	}

	#[\Override]
	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!($exception instanceof ExternalScopeException)) {
			throw $exception;
		}

		if ($controller instanceof OCSController) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		}
		if ($this->request->getMethod() === 'GET' && $this->wantsPage()) {
			return new RedirectResponse($exception->getRedirect());
		}

		return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
	}

	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($response instanceof TemplateResponse
			&& ExternalScope::verdict($controller::class, $methodName) === ExternalScope::SETTINGS
			&& ExternalUserBackend::isExternal($this->userSession->getUser())) {
			$this->provideSettingsSections((string)$this->request->getParam('section', ''));
		}

		return $response;
	}

	/**
	 * The personal settings navigation, down to the sections an external
	 * user may open.
	 *
	 * The page provided its own list when the controller ran; this one is
	 * provided after it, under the same key, and is the one the page reads.
	 * The shape is the one `CommonSettingsTrait::formatSections()` writes.
	 */
	private function provideSettingsSections(string $current): void {
		$sections = [];
		foreach ($this->settingsManager->getPersonalSections() as $prioritized) {
			foreach ($prioritized as $section) {
				$id = $section->getID();
				if (!ExternalScope::allowsSettingsSection($id) || $this->settingsManager->getPersonalSettings($id) === []) {
					continue;
				}

				$sections[] = [
					'id' => $id,
					'name' => $section->getName(),
					'active' => $id === $current,
					'icon' => $section->getIcon(),
				];
			}
		}

		/** @psalm-suppress DeprecatedInterface the key belongs to the settings app, not to this one */
		$this->initialStateService->provideInitialState('settings', 'sections', [
			'personal' => $sections,
			'admin' => [],
		]);
	}

	/** Whether the request is a browser asking for a page rather than for data. */
	private function wantsPage(): bool {
		$accept = $this->request->getHeader('Accept');

		return $accept === '' || str_contains($accept, 'text/html');
	}
}
