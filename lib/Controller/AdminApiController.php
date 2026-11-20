<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\TrendReviewRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ModerationNotAllowedException;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Service\AccessBlockService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MetricsService;
use OCA\Social\Service\TrendReviewService;
use OCA\Social\Service\TrendService;
use OCP\AppFramework\Controller;
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
 * Mastodon's admin API: `/api/v1/admin/*`.
 *
 * Only a Nextcloud administrator may reach it: every route begins with
 * `initAdmin()`, which asks `IGroupManager::isAdmin()` about the user behind
 * the token or session. An OAuth scope cannot be the check, since a client
 * may register any scope string; the scope is still required on a bearer
 * token, as Mastodon requires, and can only narrow what an administrator may
 * do.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]` like every other client-API
 * controller, since `#[AdminRequired]` would refuse a bearer token; the
 * administrator check happens in the handler on every route.
 *
 * Entities are documented in `AdminAccount`, `AdminReport` and
 * `AdminDomainBlock`; each emits every key Mastodon documents, with the empty
 * value of its type wherever this app has nothing behind one.
 */
class AdminApiController extends AdminApiControllerBase {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AdminApiService $adminApiService,
		private AccessBlockService $accessBlockService,
		private MetricsService $metricsService,
		private HashtagService $hashtagService,
		private TrendService $trendService,
		private TrendReviewService $trendReviewService,
		ClientService $clientService,
	) {
		parent::__construct($request, $userSession, $logger, $adminApiService, $clientService);
	}

	/**
	 * A page of accounts, newest first. `email` and `ip` are accepted and
	 * match nothing, since this instance holds neither for a fediverse
	 * account; the page is empty rather than unfiltered.
	 *
	 * @param string $origin `local`, `remote`, or empty for both
	 * @param string $status `active`, `silenced`, `suspended`; `pending` and
	 *                       `disabled` are states this app has not and answer
	 *                       with nothing
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/accounts')]
	// the path a current admin client calls; same page, same filters
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/admin/accounts', postfix: 'v2')]
	public function accounts(
		string $origin = '',
		string $status = '',
		string $username = '',
		string $display_name = '',
		string $by_domain = '',
		string $email = '',
		string $ip = '',
		int $limit = AdminApiService::LIMIT,
		int $max_id = 0,
		int $min_id = 0,
	): DataResponse {
		try {
			$this->initAdmin();

			if (trim($email) !== '' || trim($ip) !== '') {
				return new DataResponse([], Http::STATUS_OK);
			}

			// clamped here too: the cursor is only offered when the page came
			// back full
			$limit = $this->limit($limit);
			$page = $this->adminApiService->accountPage(
				$this->origin($origin), $username, $display_name, $by_domain,
				$status, $limit, $max_id, $min_id
			);

			return $this->paged($page['accounts'], $limit, $page['cursors']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One account, by numeric id, actor id or handle. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/accounts/{id}', requirements: ['id' => '.+'])]
	public function account(string $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->account($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The moderator's decision about an account. Answers `{}`, as Mastodon
	 * does. `report_id` resolves that report at the same time; a report about
	 * another account is a 404 with nothing done.
	 *
	 * @param string $type `silence`, `suspend` or `none`
	 * @param string $text the moderator's note, kept as the comment on the
	 *                     decision
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `{id}` accepts slashes here and on account(), since a suspended account
	// is named by its actor id; account()'s route then matches this url too,
	// so every action route under `/api/v1/admin/accounts/{id}/` must stay a POST.
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/action', requirements: ['id' => '.+'])]
	public function accountAction(
		string $id,
		string $type = '',
		string $text = '',
		int $report_id = 0,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			$account = $this->actionable($id);
			if ($report_id > 0) {
				$this->adminApiService->assertReportConcerns($report_id, $account->getActorId());
			}

			$this->adminApiService->act($account, $type, $text, $report_id);

			if ($report_id > 0) {
				$this->adminApiService->resolveReport($report_id, $this->userId);
			}

			return new DataResponse((object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's "re-enable a disabled login". Nothing here can disable one,
	 * so this answers with the account and changes nothing; moderation clients
	 * call it when clearing a strike.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/enable', requirements: ['id' => '.+'])]
	public function accountEnable(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->account($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/unsilence', requirements: ['id' => '.+'])]
	public function accountUnsilence(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->unsilence($this->actionable($id)), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/unsuspend', requirements: ['id' => '.+'])]
	public function accountUnsuspend(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->unsuspend($this->actionable($id)), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Lifts "everything this account posts is sensitive", the counterpart of
	 * `action` with `sensitive`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/unsensitive', requirements: ['id' => '.+'])]
	public function accountUnsensitive(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->unsensitive($this->actionable($id)),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Deletes what an account posted here, without deleting the account
	 * (Mastodon's `DELETE /admin/accounts/{id}`; the Nextcloud account is the
	 * server's). The suspension's destructive half: posts and cached actor go,
	 * and a local account's deletion is federated. Irreversible, hence a
	 * separate verb.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/admin/accounts/{id}', requirements: ['id' => '.+'])]
	public function accountDelete(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->purge($this->actionable($id)), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The account a write is about, once the caller may act on it.
	 *
	 * @throws ItemNotFoundException
	 * @throws ModerationNotAllowedException
	 */
	private function actionable(string $id): AdminAccount {
		$account = $this->adminApiService->account($id);
		$this->adminApiService->assertMayActOn($account->getActorId(), $this->userId);

		return $account;
	}

	/**
	 * A page of reports, newest first. As in Mastodon, an absent `resolved`
	 * means unresolved only.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/reports')]
	public function reports(
		string $resolved = '',
		string $account_id = '',
		string $target_account_id = '',
		int $limit = AdminApiService::LIMIT,
		int $max_id = 0,
		int $min_id = 0,
	): DataResponse {
		try {
			$this->initAdmin();

			$limit = $this->limit($limit);
			$reports = $this->adminApiService->reports(
				$this->bool($resolved) ?? false,
				$account_id,
				$target_account_id,
				$limit,
				$max_id,
				$min_id
			);

			return $this->paged($reports, $limit, array_map(
				static fn ($report): int => $report->getId(), $reports
			));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/reports/{id}')]
	public function report(int $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->report($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes a report's category. `rule_ids` is accepted and ignored: the
	 * rules a report names are kept as they read when it was filed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/reports/{id}')]
	public function reportUpdate(int $id, string $category = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->recategoriseReport($id, $category), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/resolve')]
	public function reportResolve(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->resolveReport($id, $this->userId), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/reopen')]
	public function reportReopen(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->reopenReport($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/assign_to_self')]
	public function reportAssignToSelf(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->assignReport($id, $this->userId), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/unassign')]
	public function reportUnassign(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->assignReport($id, null), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The instance-wide access list, as domain blocks. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/domain_blocks')]
	public function domainBlocks(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->domainBlocks(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlock(string $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->domainBlock($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Adds a domain block at one of the two tiers this instance has: `suspend`
	 * (the default) refuses everything from the domain; `silence` keeps its
	 * accounts readable to followers and out of public timelines. Mastodon's
	 * `noop` is refused with a 422. `private_comment` is for moderators,
	 * `public_comment` for the published list; an absent one is left as is.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/domain_blocks')]
	public function domainBlockCreate(
		string $domain = '',
		string $severity = '',
		?string $private_comment = null,
		?string $public_comment = null,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->blockDomain($domain, $severity, $private_comment, $public_comment),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Moves a block between the two tiers (the same severity is a no-op) and
	 * rewrites whichever of the two reasons is sent.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlockUpdate(
		string $id,
		string $severity = '',
		?string $private_comment = null,
		?string $public_comment = null,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->updateDomainBlock($id, $severity, $private_comment, $public_comment),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Lifts a block and answers with the entry that was lifted. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlockRemove(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->unblockDomain($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// The allowlist, which only means anything in allowlist federation

	/**
	 * The instances this server will talk to at all: Mastodon's allowlist,
	 * meaningful only when `access_type` is `none_but`. The ids are derived
	 * from the domain, since the access list is a config array with no ids;
	 * every route here also takes the domain itself.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/domain_allows')]
	public function domainAllows(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->domainAllows(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/domain_allows/{id}')]
	public function domainAllow(string $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->domainAllow($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/domain_allows')]
	public function domainAllowCreate(string $domain = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->allowDomain($domain), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/admin/domain_allows/{id}')]
	public function domainAllowRemove(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->disallowDomain($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// IP blocks and email-domain blocks

	/**
	 * The addresses this instance answers nothing from: Mastodon's list, with
	 * one severity, `no_access`, since there is no sign-up here to police.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/ip_blocks')]
	public function ipBlocks(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->accessBlockService->ipBlocks(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/ip_blocks/{id}', requirements: ['id' => '\\d+'])]
	public function ipBlock(int $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->accessBlockService->ipBlock($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Refuses an address or a range everything this app serves. `expires_in`
	 * is seconds from now, or absent for a permanent block. A severity this
	 * instance cannot honour is a 422, as is a range wider than a /16 (IPv4)
	 * or /32 (IPv6), or one holding the caller's own address.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/ip_blocks')]
	public function ipBlockCreate(
		string $ip = '',
		string $severity = AccessBlock::SEVERITY_NO_ACCESS,
		string $comment = '',
		int $expires_in = 0,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->accessBlockService->blockIp(
					$ip, $severity, $comment, $expires_in > 0 ? time() + $expires_in : 0,
					$this->request->getRemoteAddress()
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes one. The address cannot change: a different range is a
	 * different block, as in Mastodon.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/ip_blocks/{id}', requirements: ['id' => '\\d+'])]
	public function ipBlockUpdate(
		int $id,
		string $severity = AccessBlock::SEVERITY_NO_ACCESS,
		string $comment = '',
		int $expires_in = 0,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$block = $this->accessBlockService->ipBlock($id);

			return new DataResponse(
				$this->accessBlockService->blockIp(
					$block->getValue(), $severity, $comment,
					$expires_in > 0 ? time() + $expires_in : 0,
					$this->request->getRemoteAddress()
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/admin/ip_blocks/{id}', requirements: ['id' => '\\d+'])]
	public function ipBlockRemove(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->accessBlockService->unblockIp($id);

			return new DataResponse((object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The email domains this instance does not give fediverse accounts to:
	 * where Mastodon refuses a sign-up, this refuses a Nextcloud account a
	 * fediverse identity.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/email_domain_blocks')]
	public function emailDomainBlocks(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->accessBlockService->emailDomainBlocks(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/email_domain_blocks/{id}', requirements: ['id' => '\\d+'])]
	public function emailDomainBlock(int $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->accessBlockService->emailDomainBlock($id), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/email_domain_blocks')]
	public function emailDomainBlockCreate(string $domain = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->accessBlockService->blockEmailDomain($domain), Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/admin/email_domain_blocks/{id}', requirements: ['id' => '\\d+'])]
	public function emailDomainBlockRemove(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->accessBlockService->unblockEmailDomain($id);

			return new DataResponse((object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// trends, as a moderation client asks for them

	/**
	 * The same three trend readers the public routes use, at the admin path
	 * moderation clients expect. This instance has no review queue, so they
	 * answer exactly what `/api/v1/trends/*` answers.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/trends/tags')]
	public function trendTags(int $limit = 10): DataResponse {
		try {
			$this->initAdmin();

			$tags = [];
			foreach ($this->hashtagService->getTrending(max(1, min(100, $limit))) as $hashtag) {
				$tags[] = $this->hashtagService->tagEntity($hashtag['hashtag']);
			}

			return new DataResponse($tags, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/trends/statuses')]
	public function trendStatuses(int $limit = 10, int $offset = 0): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->trendService->trendingStatuses(
					HashtagService::PERIOD_DEFAULT, max(1, min(100, $limit)), max(0, $offset)
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/trends/links')]
	public function trendLinks(int $limit = 10, int $offset = 0): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->trendService->trendingLinks(
					HashtagService::PERIOD_DEFAULT, max(1, min(100, $limit)), max(0, $offset)
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Keeps something out of what is trending, or lets it back in: six routes
	 * as in Mastodon (approve and reject for tags, links and statuses), one
	 * implementation. Rejecting hides; approving only records that a moderator
	 * looked, since everything not objected to trends.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/trends/tags/{id}/approve', requirements: ['id' => '.+'])]
	public function trendTagApprove(string $id): DataResponse {
		return $this->decideTrend(TrendReviewRequest::KIND_TAG, $id, true);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/trends/tags/{id}/reject', requirements: ['id' => '.+'])]
	public function trendTagReject(string $id): DataResponse {
		return $this->decideTrend(TrendReviewRequest::KIND_TAG, $id, false);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/trends/links/{id}/approve', requirements: ['id' => '.+'])]
	public function trendLinkApprove(string $id): DataResponse {
		return $this->decideTrend(TrendReviewRequest::KIND_LINK, $id, true);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/trends/links/{id}/reject', requirements: ['id' => '.+'])]
	public function trendLinkReject(string $id): DataResponse {
		return $this->decideTrend(TrendReviewRequest::KIND_LINK, $id, false);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/trends/statuses/{id}/approve', requirements: ['id' => '.+'])]
	public function trendStatusApprove(string $id): DataResponse {
		return $this->decideTrend(TrendReviewRequest::KIND_STATUS, $id, true);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/trends/statuses/{id}/reject', requirements: ['id' => '.+'])]
	public function trendStatusReject(string $id): DataResponse {
		return $this->decideTrend(TrendReviewRequest::KIND_STATUS, $id, false);
	}

	/**
	 * The hashtags a moderator has decided about: Mastodon's `/admin/tags`,
	 * narrowed to whether a tag may trend. This app has no equivalent of
	 * `usable` and `listable`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/tags')]
	public function adminTags(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->trendReviewService->decisions(TrendReviewRequest::KIND_TAG), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One hashtag's decision, and `PUT` to change it. Only `trendable` means
	 * anything; `usable` and `listable` are accepted and ignored.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/tags/{id}', requirements: ['id' => '.+'])]
	public function adminTag(string $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->trendReviewService->tagEntity($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/tags/{id}', requirements: ['id' => '.+'])]
	public function adminTagUpdate(string $id, bool $trendable = true): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->trendReviewService->decide(
				TrendReviewRequest::KIND_TAG, $id, $trendable, $this->userId
			);

			return new DataResponse($this->trendReviewService->tagEntity($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The one implementation behind the six approve/reject routes. */
	private function decideTrend(string $kind, string $ref, bool $approved): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->trendReviewService->decide($kind, $ref, $approved, $this->userId);

			return new DataResponse([
				'kind' => $kind,
				'ref' => $ref,
				'approved' => $approved,
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// metrics

	/**
	 * One number a day over a window, for each key asked for (Mastodon's
	 * `Admin::Measure`). A key this instance cannot answer is a 422 naming
	 * the ones it can, rather than a misleading zero.
	 *
	 * @param string[]|string $keys
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/measures')]
	public function measures(
		array|string $keys = [],
		string $start_at = '',
		string $end_at = '',
		string $instance = '',
		string $id = '',
	): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->measures(
					self::listParam($keys), $this->timestamp($start_at), $this->timestamp($end_at), $instance, $id
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The ranked list behind one number, for each key asked for.
	 *
	 * @param string[]|string $keys
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/dimensions')]
	public function dimensions(
		array|string $keys = [],
		string $start_at = '',
		string $end_at = '',
		int $limit = 10,
		string $id = '',
	): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->dimensions(
					self::listParam($keys), $this->timestamp($start_at), $this->timestamp($end_at), $limit, $id
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How much of each month's new local accounts is still posting later.
	 * Monthly whatever `frequency` asks for, since daily cohorts on a small
	 * instance are all zeroes.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/retention')]
	public function retention(string $start_at = '', string $end_at = ''): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->retention(
					$this->timestamp($start_at), $this->timestamp($end_at)
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A moment as a client writes one: an ISO date, seconds since the epoch,
	 * or nothing. Zero for anything unreadable, which the service refuses.
	 */
	private function timestamp(string $written): int {
		$written = trim($written);
		if ($written === '') {
			return 0;
		}

		if (ctype_digit($written)) {
			return (int)$written;
		}

		return max(0, (int)strtotime($written));
	}

	/**
	 * Mastodon's `origin`: true for local accounts, false for remote, null for
	 * both.
	 *
	 * @throws InvalidResourceException anything else, rather than silently
	 *                                  listing everything
	 */
	private function origin(string $origin): ?bool {
		switch (trim($origin)) {
			case '':
				return null;
			case 'local':
				return true;
			case 'remote':
				return false;
			default:
				throw new InvalidResourceException("'" . $origin . "' is not a valid origin");
		}
	}

	/** What the instance will actually build, which is what a page is. */
	private function limit(int $limit): int {
		return max(1, min(AdminApiService::MAX_LIMIT, $limit));
	}

	/** A tri-state query parameter: absent is null, not false. */
	private function bool(string $value): ?bool {
		$value = strtolower(trim($value));
		if ($value === '') {
			return null;
		}

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * A page with the `Link` header masto.js reads its cursor from.
	 *
	 * @param array<int|string> $ids the cursor ids of the page, in its order; empty when
	 *                               the page has no cursor at all, which is what the
	 *                               `silenced` and `suspended` account lists are
	 */
	private function paged(array $items, int $limit, array $ids): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);
		if ($ids === []) {
			return $response;
		}

		$links = [];
		if (count($ids) >= $limit) {
			// a page shorter than the limit is the last one
			$links[] = '<' . $this->pageUrl(['max_id' => (string)min($ids)]) . '>; rel="next"';
		}
		$links[] = '<' . $this->pageUrl(['min_id' => (string)max($ids)]) . '>; rel="prev"';

		$response->addHeader('Link', implode(', ', $links));

		return $response;
	}

	/**
	 * This request's own URL with the cursor replaced, so every filter the
	 * client sent survives into the next page.
	 */
	private function pageUrl(array $cursor): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['_route']);

		return $path . '?' . http_build_query(array_merge($query, $cursor));
	}
}
