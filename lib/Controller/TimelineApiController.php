<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\NotificationInboxService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Service\WatchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The streams a reader reads: the timelines, a hashtag, their favourites and
 * bookmarks, the videos they are in the middle of, the v1 notifications, and
 * the markers that remember how far they got.
 */
class TimelineApiController extends MastodonApiController {
	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private MarkerService $markerService,
		private StreamRequest $streamRequest,
		private FilterService $filterService,
		private NotificationPolicyService $notificationPolicyService,
		private WatchService $watchService,
		private TimelineRevisionService $timelineRevisionService,
		private NotificationInboxService $notificationInboxService,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 *
	 * @param string $timeline
	 * @param bool $local
	 * @param int $limit
	 * @param int|string $max_id
	 * @param int|string $min_id
	 * @param int|string $since_id
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/{timeline}/')]
	public function timelines(
		string $timeline,
		bool $local = false,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		bool $only_media = false,
		bool $only_video = false,
		bool $only_news = false,
	): Response {
		$this->logger->debug('[' . static::class . '] timelines called', [
			'timeline' => $timeline,
			'local' => $local,
			'limit' => $limit,
			'max_id' => $max_id,
			'min_id' => $min_id,
			'since_id' => $since_id
		]);
		try {
			// Mastodon's public timeline is readable without a token, and a
			// client asks for it before it has one — to show the reader what is
			// here before they log in. Every other timeline is about somebody,
			// so it still needs a viewer. A token that *was* presented still has
			// to be a good one: a client whose token has been revoked has to
			// learn that, not quietly get the anonymous view instead.
			$this->initViewer(
				$this->bearer !== '' || strtolower($timeline) !== ProbeOptions::PUBLIC
			);
			$this->logger->debug('[' . static::class . '] Viewer initialized', [
				'viewerId' => $this->viewer?->getId()
			]);

			if (!in_array(
				strtolower($timeline),
				[
					ProbeOptions::HOME,
					ProbeOptions::ACCOUNT,
					ProbeOptions::PUBLIC,
					ProbeOptions::DIRECT,
					ProbeOptions::FAVOURITES
				], true
			)) {
				$this->logger->error('[' . static::class . '] Unknown timeline requested', [
					'timeline' => $timeline
				]);
				throw new UnknownProbeException('unknown timeline');
			}

			// A client polls this every thirty seconds and the answer is
			// almost always the one it already holds. The newest id the viewer
			// can see changes exactly when that stops being true, and costs one
			// index-only probe — see `notModified()`. Only the head of the home
			// timeline is worth tagging: a page reached with `max_id` is
			// historical and a client asks for it once.
			if ($timeline === ProbeOptions::HOME && $max_id === '0' && $min_id === '0') {
				// the newest id says whether anything arrived; the revision
				// says whether the reader has changed what they are shown —
				// a follow, a block, a mute, a filter, a followed hashtag —
				// none of which moves an id. See TimelineRevisionService.
				$notModified = $this->notModified(
					'h' . (($this->viewer === null) ? '0' : $this->streamRequest->newestHomeNid($this->viewer))
					. '-' . $limit
					. '-' . $this->timelineRevisionService->of($this->currentSession())
				);
				if ($notModified !== null) {
					return $notModified;
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe($timeline)
				->setLocal($local)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setOnlyNews($only_news);

			$posts = $this->streamService->getTimeline($options);
			$this->logger->debug('[' . static::class . '] Timeline retrieved', [
				'timeline' => $timeline,
				'postsCount' => count($posts)
			]);

			// the unfiltered page is what says whether a further page exists: a
			// page shortened by a `hide` filter says nothing about what is older
			return $this->tagged($this->paged(
				$this->filterService->apply($posts, $this->filterContext($timeline), $this->viewer),
				$options->getLimit(),
				$posts,
				$this->streamService->lastTimelineRowCount()
			));
		} catch (Throwable $e) {
			$this->logger->error('[' . static::class . '] Timeline request failed', [
				'timeline' => $timeline,
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/tag/{hashtag}')]
	public function tag(
		string $hashtag,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		bool $local = false,
		bool $only_media = false,
		bool $only_video = false,
		bool $only_news = false,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe('hashtag')
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setLocal($local)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setOnlyNews($only_news)
				->setArgument($hashtag);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged(
				$this->filterService->apply($posts, Filter::CONTEXT_PUBLIC, $this->viewer),
				$options->getLimit(),
				$posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int $limit
	 * @param int|string $max_id
	 * @param int|string $min_id
	 * @param int|string $since_id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/favourites/')]
	public function favourites(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FAVOURITES)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged(
				$this->filterService->apply($posts, '', $this->viewer), $options->getLimit(), $posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/bookmarks')]
	public function bookmarks(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::BOOKMARKS)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			// not a filter context in Mastodon either: the statuses carry an
			// empty `filtered`, because its absence is read as an answer
			return $this->paged(
				$this->filterService->apply($posts, '', $this->viewer), $options->getLimit(), $posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How far through each timeline the reader has got.
	 *
	 * @param array $timeline the timelines asked about; all of them when empty
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/markers')]
	public function markersGet(array $timeline = []): DataResponse {
		try {
			$this->initViewer(true);

			// an object, never a list: a fresh account has no markers at all,
			// and `[]` is not something a client can read `home.last_read_id`
			// out of
			return new DataResponse(
				(object)$this->markerService->get($this->currentSession(), $timeline),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Moves one or more markers forward.
	 *
	 * The body is Mastodon's: `{"notifications": {"last_read_id": "42"}}`, or
	 * the same thing form-encoded as `notifications[last_read_id]=42`.
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/markers')]
	public function markersSet(): DataResponse {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();

			$input = $this->convertInput(file_get_contents('php://input'));
			$updated = [];
			foreach (MarkerService::TIMELINES as $timeline) {
				$lastReadId = $input[$timeline]['last_read_id'] ?? null;
				if ($lastReadId === null || $lastReadId === '') {
					continue;
				}

				$updated[$timeline] = $this->markerService->set($userId, $timeline, (string)$lastReadId);
			}

			return new DataResponse((object)$updated, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications')]
	public function notifications(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		array $types = [],
		array $exclude_types = [],
		string $accountId = '',
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::NOTIFICATIONS)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setTypes($types)
				->setExcludeTypes($exclude_types)
				->setAccountId($accountId);

			// A notification whose sub-type Mastodon has no name for would
			// serialise as `"type": ""`, which a client with a closed enum
			// cannot decode — and one undecodable entry loses the whole page.
			// It is dropped instead: there is nothing a client could show for it.
			$page = $this->streamService->getTimeline($options);
			$posts = array_values(
				array_filter(
					$page,
					static fn (Stream $post): bool
						=> Stream::notificationTypeOfSubType($post->getSubType()) !== ''
				)
			);

			// paged() is told what the query returned, not what survived the
			// filter: a page shortened here says nothing about whether older
			// notifications exist, and a client that pages on the `Link` header
			// stopped there with the rest of the list still in the database.
			// and then what the notification policy holds back — Mastodon
			// 4.3's `filter` and `drop`, which are about the *sender* rather
			// than about the notification. An account that has not touched the
			// policy pays nothing for this: partition() answers at once when
			// every one of the five questions is `accept`.
			return $this->paged(
				$this->filterService->applyToNotifications(
					$this->notificationPolicyService->partition($this->viewer, $posts)['shown'],
					$this->viewer
				),
				$options->getLimit(),
				$page
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How many notifications have arrived since the reader last looked.
	 *
	 * The sidebar badge asks for this; a client that keeps markers gets the
	 * same answer from the same place. Only what the list shows counts: not
	 * what the notification policy holds, nor a muted thread's. Notifications
	 * released by accepting a request count even behind the marker, until the
	 * reader next sets it (`NotificationInboxService::unreadCount()`).
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/unread_count')]
	public function notificationsUnreadCount(): Response {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();
			$marker = $this->markerService->lastReadId($userId, 'notifications');

			// the count changes when a notification arrives, the marker
			// moves, the reader decides about a sender (the revision), or the
			// policy, what was released behind the marker or the muted
			// threads change (the inbox state); all of them are in the tag
			$newest = $this->streamRequest->newestNidFor(
				[md5($this->viewer->getId())], 'notif'
			);
			$notModified = $this->notModified(
				$newest . '-' . $marker . '-' . $this->timelineRevisionService->of($userId)
				. '-' . $this->notificationInboxService->unreadState($this->viewer, $userId)
			);
			if ($notModified !== null) {
				return $notModified;
			}

			return $this->tagged(new DataResponse([
				'count' => $this->notificationInboxService->unreadCount($this->viewer, $userId),
			], Http::STATUS_OK));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// --- where somebody stopped watching ----------------------------------

	/**
	 * The videos the reader was in the middle of, newest first.
	 *
	 * Neither the ones they barely started nor the ones they finished: a row
	 * that offers back a video somebody watched to the end is a row nobody
	 * presses twice.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/videos/continue')]
	public function videosContinue(int $limit = 20): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse(
				$this->watchService->unfinished($this->viewer, $limit), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
