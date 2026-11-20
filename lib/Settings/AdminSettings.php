<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Settings;

use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\Report;
use OCA\Social\Service\AboutService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\BackgroundHealthService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ExternalAdminState;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\MediaPurgeService;
use OCA\Social\Service\MediaUsageService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\SectionsService;
use OCA\Social\Service\SensitiveMediaService;
use OCA\Social\Service\ServerSettingsService;
use OCA\Social\Service\VideoQuotaService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Moderation panel: open reports (resolved ones a click away), posts waiting
 * for review, outbound federation state, the Fediverse access list the
 * `occ social:fediverse` command manages, announcements, and for an
 * administrator proper the Server card.
 *
 * One Vue application (`src/adminSettings.js`); render-time data travels as
 * initial state. Announcements are read from `/admin/announcements`, the
 * route the section writes through.
 *
 * Delegated settings, so an administrator can hand moderation to a group
 * under Administration privileges; `AuthorizedAdminSetting` and
 * `IManager::getAllowedAdminSettings()` let that group act without reaching
 * anything else. The Server card is administration, not moderation, and is
 * not rendered for a delegate.
 */
class AdminSettings implements IDelegatedSettings {
	public function __construct(
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
		private PostReviewService $postReviewService,
		private StreamRequest $streamRequest,
		private MediaUsageService $mediaUsageService,
		private MediaPurgeService $mediaPurgeService,
		private VideoQuotaService $videoQuotaService,
		private SensitiveMediaService $sensitiveMediaService,
		private FederationHealthService $federationHealthService,
		private BackgroundHealthService $backgroundHealthService,
		private IL10N $l10n,
		private ServerSettingsService $serverSettingsService,
		private SectionsService $sectionsService,
		private InterestService $interestService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IInitialState $initialState,
		private ExternalAdminState $externalAdminState,
		private NotificationPolicyService $notificationPolicyService,
		private AtprotoStatusService $atprotoStatus,
		private RecommendationPreferenceService $recommendationPreferences,
		private AboutService $aboutService,
		private AdminApiService $adminApiService,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		// the framework chunk first, then the page's own entry, which is not
		// self-contained
		Util::addScript('social', 'social-framework');
		Util::addScript('social', 'social-adminSettings');

		$open = $this->reportService->page(false, 1);
		$decisions = $this->currentDecisions();

		// render-time data as initial state; everything after is read from
		// the routes the page writes through
		$this->initialState->provideInitialState('adminSettings', [
			// the first page of open reports, in the shape
			// `ModerationController::reports()` answers in
			'reports' => array_map(
				static fn (Report $report): array => $report->moderationRow($decisions),
				$open['reports']
			),
			'openReports' => $open['total'],
			'resolvedReports' => $this->reportService->countResolved(),
			'reportsPerPage' => $open['perPage'],
			'server' => $this->isAdministrator() ? $this->serverSettingsService->current() : null,
			// what the app offers everybody, and the groups to choose from
			// (with names, for the picker); administrators only, like the
			// Server card
			'sections' => $this->isAdministrator() ? $this->sectionsService->current() : null,
			'groups' => $this->isAdministrator() ? $this->availableGroups() : null,
			// Bluesky: the switch, the relays and whether the instance is a
			// working PDS, for the administrator proper
			'bluesky' => $this->isAdministrator() ? $this->atprotoStatus->current() : null,
			// For you, for the same administrator proper
			'interests' => $this->isAdministrator() ? $this->interestAdminSettings() : null,
			'accessType' => $this->fediverseService->getAccessType(),
			'accessList' => $this->fediverseService->getListedAddresses(),
			// on a block list, each entry with its tier and its reasons
			'accessBlocks' => ($this->fediverseService->getAccessType() === 'all_but')
				? $this->adminApiService->domainBlocks()
				: [],
			'retentionDays' => (int)$this->configService->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS),
			'federation' => $this->federationHealthService->summary(),
			// whether background jobs are running at all, since a stopped cron
			// shows only as posts that never arrive
			'background' => $this->backgroundHealthService->summary(),
			// what this instance's own people have been doing: the two numbers
			// an administrator asks for first, and the page had neither
			'activity' => $this->activity(),
			// what is on disk, as the cron last measured it: adding it up is a
			// `stat` per stored file and has no business in a page load
			'storage' => $this->mediaUsageService->lastMeasured(),
			// the two limits the daily purge of other servers' media applies,
			// and what it last removed
			'mediaCache' => [
				'days' => $this->mediaPurgeService->cacheDays(),
				'sizeMb' => intdiv($this->mediaPurgeService->cacheBytes(), 1024 * 1024),
				'lastPurge' => $this->mediaPurgeService->lastCachePurge(),
			],
			// who is holding the video, live: one grouped query over a column
			// the rows carry
			'videoStorage' => $this->isAdministrator() ? [
				'quota' => $this->videoQuotaService->quota(),
				'accounts' => $this->videoQuotaService->byAccount(),
				'renditions' => $this->videoQuotaService->renditionBytes(),
				'store' => $this->videoQuotaService->storage(),
			] : null,
			// the first page of the review queue, in the shape
			// `ModerationController::review()` answers in
			'review' => $this->postReviewService->pending(),
			'reviewTotal' => $this->postReviewService->countPending(),
			'reviewFirstPost' => $this->postReviewService->reviewsFirstPost(),
			'autospam' => $this->postReviewService->autospam(),
			'reviewVideos' => $this->postReviewService->reviewsVideos(),
			// what this instance does with a post somebody marked sensitive,
			// for readers who have not chosen for themselves
			'nsfwPolicy' => $this->sensitiveMediaService->instancePolicy(),
			// self-registered external users: who may have an account on
			// this server is a decision for an administrator proper
			'external' => $this->isAdministrator() ? $this->externalAdminState->current() : null,
			// what a new account's notification policy starts as: a decision
			// about the instance, for an administrator proper
			'notificationPolicyDefaults' => $this->isAdministrator()
				? $this->notificationPolicyService->defaults()->getDecisions() : null,
			// what the "ready to be public" setup check would ask for, for
			// the administrator proper who can set it
			'publicBasics' => $this->isAdministrator() ? $this->publicBasics() : null,
			// what the About this server card shows from Theming, which it
			// links to rather than edits
			'about' => array_intersect_key(
				$this->aboutService->about(),
				array_flip(['name', 'privacy_policy_url', 'legal_notice_url'])
			),
		]);

		return new TemplateResponse('social', 'settings/admin');
	}

	/**
	 * Posts written here in the last day and the last week, and how many
	 * accounts wrote them; local posts only.
	 *
	 * @return array{day: array{posts: int, authors: int}, week: array{posts: int, authors: int}}
	 */
	private function activity(): array {
		$now = time();

		return [
			'day' => $this->streamRequest->localActivitySince($now - 86400),
			'week' => $this->streamRequest->localActivitySince($now - 7 * 86400),
		];
	}

	/**
	 * What a server that takes sign-ups or federates is missing of its
	 * contact, rules, privacy policy and legal notice, as `PublicReady` reads
	 * it; null on a server that does neither, which that check passes.
	 *
	 * @return array{missing: list<string>, signUp: bool}|null
	 */
	private function publicBasics(): ?array {
		$signUp = $this->aboutService->isOpenForSignUp();
		if (!$signUp && !$this->aboutService->isFederating()) {
			return null;
		}

		return ['missing' => $this->aboutService->missingBasics(), 'signUp' => $signUp];
	}

	/** @return array<string, mixed> */
	private function interestAdminSettings(): array {
		$recommendations = $this->recommendationPreferences->adminSettings();
		return $this->interestService->adminSettings() + [
			'recommendationsEnabled' => $recommendations['enabled'] ?? false,
			'remoteDiscoveryPaused' => $recommendations['remoteDiscoveryPaused'] ?? false,
			'learningPaused' => $recommendations['learningPaused'] ?? false,
			'forceLegacyFeed' => $recommendations['forceLegacyFeed'] ?? false,
		];
	}

	/**
	 * What stands against each account, keyed by actor id, so the reports
	 * table can show its own state rather than only what was complained about.
	 *
	 * @return array<string, string>
	 */
	private function currentDecisions(): array {
		$decisions = [];
		foreach ($this->moderationService->decisions() as $decision) {
			$decisions[$decision->getActorId()] = $decision->getLevel();
		}

		return $decisions;
	}

	/**
	 * Whether the viewer administers the server, as opposed to holding the
	 * Social section by delegation.
	 */
	private function isAdministrator(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	/**
	 * The groups an administrator may turn into Social lists: every group, by
	 * id and display name, unpaged; very large instances can use
	 * `occ config:app:set social group_lists`.
	 *
	 * @return array<array{id: string, name: string}>
	 */
	private function availableGroups(): array {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = ['id' => $group->getGID(), 'name' => $group->getDisplayName()];
		}

		return $groups;
	}

	#[\Override]
	public function getSection(): string {
		return AdminSection::SECTION_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}

	/**
	 * The name this section is offered under in Administration privileges.
	 * The section has one panel, so it names what is being handed over.
	 */
	#[\Override]
	public function getName(): ?string {
		return $this->l10n->t('Moderation');
	}

	/**
	 * No app config is reachable through core's settings API: the panel
	 * writes through `ModerationController` (guarded by
	 * `AuthorizedAdminSetting`) and the Server card through
	 * `ServerSettingsController` (administrators only).
	 */
	#[\Override]
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
