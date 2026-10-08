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
 * Moderation existed here before this controller did — the admin panel silences
 * and suspends accounts, resolves reports and keeps the instance access list —
 * but every one of those was a `Moderation#*` route needing a Nextcloud session
 * *and* a CSRF token, which no API client has and none can obtain. A moderator
 * could act from a browser and from nowhere else.
 *
 * ### Who may reach any of this
 *
 * A **Nextcloud administrator**, and nothing else. Every route begins with
 * `initAdmin()`, which resolves the Nextcloud user behind the request — the
 * token's user, or the session's — and then asks `IGroupManager::isAdmin()`
 * about *that user*. Anyone else is a 403, whatever they present.
 *
 * An OAuth scope is not, and cannot be, the check. This app's client
 * registration stores whatever scope string a client asks for: `admin:write`
 * on a token records that some client asked for it during an authorisation,
 * never that the user behind it may moderate anything. A scope check alone
 * would therefore have made every account on the instance an administrator of
 * it. The scope is still required on a bearer token, as Mastodon requires it —
 * it is what keeps an ordinary client's `read` token from reaching the admin
 * API on an administrator's behalf — but it is checked *after* the group, and
 * it can only ever narrow what an administrator may do.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like every other client-API
 * controller here: a bearer token carries no session and no CSRF token, so
 * `#[AdminRequired]` — which is what `ModerationController` relies on — would
 * refuse every real caller before the handler ran. Nothing is public in fact;
 * the administrator check simply happens in the handler rather than in the
 * middleware, and it happens on every single route.
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
	 * A page of accounts, newest first.
	 *
	 * `email` and `ip` are accepted and match nothing: this instance holds
	 * neither for a fediverse account, so a page filtered by one is empty
	 * rather than unfiltered — a filter that was ignored would have shown a
	 * moderator the whole instance as the answer to a question about one
	 * account.
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

			// clamped here as well as in the service: the cursor is offered
			// only when the page came back full, and a client asking for more
			// than the instance will build would never be offered one
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
	 * The moderator's decision about an account.
	 *
	 * Answers `{}`, as Mastodon does. `report_id` resolves that report at the
	 * same time — a decision taken from a report is the report handled, and
	 * making the client send a second call for it leaves the two able to
	 * disagree.
	 *
	 * A report about some other account is a 404 with nothing done, as on
	 * Mastodon, which looks it up among the target's own reports: otherwise
	 * any open report could be closed as a side effect of an unrelated
	 * decision.
	 *
	 * @param string $type `silence`, `suspend` or `none`
	 * @param string $text the moderator's note, kept as the comment on the
	 *                     decision
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `{id}` accepts slashes here and on account(): a suspended account, whose
	// cached actor the suspension purged, is named by its actor id and no
	// longer by a numeric one. That makes account()'s `/api/v1/admin/accounts/{id}`
	// match this url too, and only the verb keeps them apart — so every action
	// route under `/api/v1/admin/accounts/{id}/` has to stay a POST.
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
	 * Mastodon's "re-enable a disabled login".
	 *
	 * Nothing here can disable one — a fediverse account has no login of its
	 * own, and the Nextcloud account behind a local one is enabled where
	 * Nextcloud keeps it — so this answers with the account and changes
	 * nothing. It exists because a moderation client calls it unconditionally
	 * when clearing a strike, and a 404 there reads as "no such account".
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
	 * Lifts "everything this account posts is sensitive".
	 *
	 * The counterpart of `action` with `sensitive`, which this app has had
	 * since the tier between doing nothing and silencing was added. Without
	 * this route an admin client could apply it and not take it off.
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
	 * Deletes what an account posted here, without deleting the account.
	 *
	 * Mastodon's `DELETE /admin/accounts/{id}`, which removes the *data* — it
	 * does not remove the person's login, and here it could not: an account on
	 * this server is a Nextcloud account and the server owns it. What this
	 * does is the suspension's destructive half: the posts go, the cached
	 * actor goes, and a local account's deletion is federated as a `Delete`.
	 *
	 * Irreversible, which is why it is a separate verb from `action` rather
	 * than a severity inside it.
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
	 * The account a write is about, once the caller is known to be allowed to
	 * act on it.
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
	 * A page of reports, newest first.
	 *
	 * `resolved` follows Mastodon: absent means unresolved only, which is the
	 * queue a moderator opens the panel to work through.
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
	 * Changes what a report says it is about.
	 *
	 * A moderator reading a report often finds it filed under the wrong
	 * category — "spam" for something that is harassment — and Mastodon lets
	 * them correct it before deciding. Without this the category a reporter
	 * chose was the category for ever.
	 *
	 * `rule_ids` is accepted and ignored, as it is on the reporting side: a
	 * report here carries a category and never a rule id, and the instance's
	 * rules are prose rather than numbered rows to point at. Ignoring it is
	 * the same answer `POST /api/v1/reports` gives, which is the one thing
	 * worse than refusing it would be — two halves of one API disagreeing.
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
	 * Adds a domain block at one of the two tiers this instance has.
	 *
	 * `suspend` (the default) puts the domain on the deny list, which refuses
	 * everything from it; `silence` keeps its accounts readable to whoever
	 * follows them and takes them out of the public timelines. Mastodon's
	 * `noop` has no list to go on here and is refused with a 422 rather than
	 * quietly applied as something else.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/domain_blocks')]
	public function domainBlockCreate(string $domain = '', string $severity = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->blockDomain($domain, $severity), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Moves a block between the two tiers: a change of severity is a move
	 * from one list to the other, the same severity again a no-op.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlockUpdate(string $id, string $severity = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->updateDomainBlock($id, $severity), Http::STATUS_OK);
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
	 * The instances this server will talk to at all.
	 *
	 * Mastodon's other half of `domain_blocks`, and it means something only
	 * when `access_type` is `none_but` — allowlist federation, which this app
	 * has had all along with no API over it. An admin client could read the
	 * deny list and not this one, so on an allowlisted instance it showed an
	 * empty screen and no way to tell why.
	 *
	 * The ids are derived from the domain rather than stored: the access list
	 * is a config array with no ids of its own, and giving it a table so a
	 * client could address a row by number would be storing something for the
	 * client's benefit alone. Every route here takes the domain itself too.
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
	 * The addresses this instance answers nothing from.
	 *
	 * Mastodon's list, with one severity: `no_access`. The other two police a
	 * sign-up this instance has not — an account here is a Nextcloud account,
	 * and the server decides who gets one.
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
	 * Refuses an address or a range everything this app serves.
	 *
	 * `expires_in` is Mastodon's: seconds from now, or absent for a block that
	 * does not lift itself. A severity this instance cannot honour is a
	 * **422** rather than a row nothing will ever read — an admin told their
	 * rule was stored would believe sign-ups from that range were being turned
	 * away.
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
					$ip, $severity, $comment, $expires_in > 0 ? time() + $expires_in : 0
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
	 * Changes one. The address is not among what can change: a block on a
	 * different range is a different block, and Mastodon's own PUT keeps it.
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
					$expires_in > 0 ? time() + $expires_in : 0
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
	 * The email domains this instance does not give fediverse accounts to.
	 *
	 * Mastodon refuses a sign-up at one of these. There is no sign-up here, so
	 * what it refuses is the decision this app does make: whether a Nextcloud
	 * account gets a fediverse identity — the same question one step later.
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
	 * The same three trend readers the public routes use, behind the admin
	 * gate a moderation client expects them at.
	 *
	 * On Mastodon these carry a moderator's extra field — whether the trend is
	 * allowed or pending review — and this instance reviews nothing: a trend
	 * here is what the counts say. So they answer exactly what
	 * `/api/v1/trends/*` answers, which is the honest thing to do with a route
	 * whose only difference is a review queue that does not exist. They exist
	 * because a moderation client asks for them by this path and a 404 reads
	 * as "this server has no trends".
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
	 * Keeps something out of what is trending, or lets it back in.
	 *
	 * Six routes because Mastodon has six — approve and reject, for each of
	 * tags, links and statuses — and one implementation, because what differs
	 * between them is the word naming the kind.
	 *
	 * **Rejecting hides; approving grants nothing.** Trending here shows
	 * everything nobody has objected to, which is what it did before this
	 * existed, so an approval only records that a moderator has looked. The
	 * other arrangement — nothing trends until approved — would have emptied
	 * every instance's Explore page on upgrade.
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
	 * The hashtags a moderator has decided about.
	 *
	 * Mastodon's `/admin/tags`, narrowed to the one thing this app stores
	 * about a tag: whether it may trend. Its `usable` and `listable` are about
	 * a tag row that can be disabled for *posting* and for *search*, which
	 * this app has no equivalent of — a hashtag here is written by whoever
	 * types it and exists because a post carries it.
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
	 * One hashtag's decision, and `PUT` to change it.
	 *
	 * `trendable` is the one field that means anything here; `usable` and
	 * `listable` are accepted and ignored, for the same reason `rule_ids` is
	 * on a report — a client sending all three should not have the one that
	 * works refused along with the two that do not.
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
	 * One number a day over a window, for each key asked for.
	 *
	 * Mastodon's `Admin::Measure`. A key this instance cannot answer is a
	 * **422** naming the ones it can, rather than a row of zeroes: answering
	 * 0 to "how many accounts signed up through an invite" reads as "none
	 * did", which is a different claim from "this instance has no invites".
	 *
	 * @param string[] $keys
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/measures')]
	public function measures(
		array $keys = [],
		string $start_at = '',
		string $end_at = '',
		string $instance = '',
		string $id = '',
	): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->measures(
					$keys, $this->timestamp($start_at), $this->timestamp($end_at), $instance, $id
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
	 * @param string[] $keys
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/dimensions')]
	public function dimensions(
		array $keys = [],
		string $start_at = '',
		string $end_at = '',
		int $limit = 10,
		string $id = '',
	): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->dimensions(
					$keys, $this->timestamp($start_at), $this->timestamp($end_at), $limit, $id
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
	 *
	 * Monthly, whatever `frequency` asks for: a cohort is a thing you read
	 * over months, and a daily one on an instance with a handful of sign-ups a
	 * month is a table of zeroes.
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
	 * A moment as a client writes one: an ISO date, or seconds since the
	 * epoch, or nothing.
	 *
	 * Zero for anything unreadable, which the service refuses by name — a
	 * window silently rounded to "the epoch until now" is a query nobody asked
	 * for over every row there is.
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
	 * A page with the `Link` header masto.js reads its cursor from — without
	 * it a client shows the first page and stops.
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
