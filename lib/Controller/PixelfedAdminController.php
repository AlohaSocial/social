<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\PixelfedAdminService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pixelfed's administration API, `/api/admin/*`, for its app's admin screens.
 *
 * Behind the same gate as Mastodon's admin API and answered by the same
 * service, so who may moderate and what a moderation does are decided in
 * one place. See `PixelfedAdminService` for what each screen is shown.
 */
class PixelfedAdminController extends AdminApiControllerBase {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AdminApiService $adminApiService,
		ClientService $clientService,
		private PixelfedAdminService $pixelfedAdminService,
	) {
		parent::__construct($request, $userSession, $logger, $adminApiService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/stats')]
	public function stats(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->stats(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/config')]
	public function config(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->config(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/config/update')]
	public function configUpdate(string $key = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->pixelfedAdminService->updateConfig($key);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/users/list')]
	public function users(string $q = '', string $sort = 'desc'): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->users($q, $sort), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/users/get')]
	public function user(string $user_id = ''): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->user($user_id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/users/action')]
	public function userAction(string $id = '', string $action = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->pixelfedAdminService->userAction($id, $action, $this->userId), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/mod-reports/list')]
	public function modReports(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->modReports(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/mod-reports/handle')]
	public function modReportHandle(int $id = 0, string $action = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->pixelfedAdminService->handleModReport($id, $action, $this->userId), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/autospam/list')]
	public function autospam(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->autospam(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @param int $id the held post
	 * @param string $action `approve` (Pixelfed's "not spam") or `delete`
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/autospam/handle')]
	public function autospamHandle(int $id = 0, string $action = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->pixelfedAdminService->handleAutospam($id, $action), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/instances/list')]
	public function instances(string $q = '', string $sort = 'desc', string $sort_by = 'id', string $filter = 'all'): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->instances($q, $sort, $sort_by, $filter), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/instances/get')]
	public function instance(string $id = ''): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->pixelfedAdminService->instance($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/instances/moderate')]
	public function instanceModerate(string $id = '', string $key = '', string $value = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$on = in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);

			return new DataResponse($this->pixelfedAdminService->moderateInstance($id, $key, $on), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
