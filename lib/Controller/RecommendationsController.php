<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\IRecommendationSignalEvents;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationTimelineService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/** Automatic recommendations and separately consented activity learning. */
class RecommendationsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private RecommendationPreferenceService $recommendationService,
		private RecommendationFeedbackService $feedbackService,
		private IRecommendationSignalEvents $signalService,
		private RecommendationTimelineService $timelineService,
		private FilterService $filterService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/preferences')]
	public function preferences(): DataResponse {
		return $this->answer(['read:accounts'], fn (): array => $this->learningState($this->recommendationService->get($this->viewer()->getUserId())));
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/recommendations/learning')]
	public function learning(string $mode = '', int $if_revision = -1): DataResponse {
		return $this->answer(['write:accounts'], function () use ($if_revision, $mode): DataResponse|array {
			if ($if_revision < 0) {
				throw new InvalidArgumentException('if_revision is required');
			}
			$state = $this->recommendationService->setLearningMode($this->viewer()->getUserId(), $if_revision, $mode);
			if ($state === null) {
				return new DataResponse(['error' => 'revision-conflict', 'current' => $this->learningState($this->recommendationService->get($this->viewer()->getUserId()))], Http::STATUS_CONFLICT);
			}
			return $this->learningState($state);
		});
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/recommendations/signals')]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	public function signals(array|string $events = []): DataResponse {
		return $this->answer(['write:statuses'], function () use ($events): array {
			$decoded = is_string($events) ? json_decode($events, true) : $events;
			return $this->signalService->recordEvents($this->viewer(), is_array($decoded) ? $decoded : []);
		});
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/recommendations/reset')]
	public function reset(int $if_revision = -1, string $scope = 'all'): DataResponse {
		return $this->answer(['write:accounts'], function () use ($if_revision, $scope): DataResponse|array {
			if ($if_revision < 0) {
				throw new InvalidArgumentException('if_revision is required');
			}
			$state = match ($scope) {
				'learning' => $this->recommendationService->resetLearning($this->viewer()->getUserId(), $if_revision),
				'all' => $this->recommendationService->reset($this->viewer()->getUserId(), $if_revision),
				default => throw new InvalidArgumentException('scope must be learning or all'),
			};
			if ($state === null) {
				return new DataResponse(['error' => 'revision-conflict', 'current' => $this->learningState($this->recommendationService->get($this->viewer()->getUserId()))], Http::STATUS_CONFLICT);
			}

			return $this->learningState($state);
		});
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/export')]
	public function export(): DataResponse {
		return $this->answer(['read:accounts'], fn (): array => [
			'schema_version' => 1,
			'exported_at' => time(),
			'preferences' => $this->learningState($this->recommendationService->get($this->viewer()->getUserId())),
			'feedback' => $this->feedbackService->export($this->viewer()->getUserId()),
		]);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/context/{postId}')]
	public function feedbackContext(string $postId): DataResponse {
		return $this->answer(['read:statuses'], fn (): array
			=> $this->feedbackService->context($this->viewer(), $postId)
		);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/recommendations/feedback')]
	public function feedback(string $event_id = '', string $post_id = '', string $kind = ''): DataResponse {
		return $this->answer(['write:statuses'], fn (): array
			=> $this->feedbackService->record($this->viewer(), $event_id, $post_id, $kind)
		);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/recommendations/feedback/{feedbackId}')]
	public function undoFeedback(string $feedbackId): DataResponse {
		return $this->answer(['write:statuses'], fn (): array
			=> $this->feedbackService->undo($this->viewer(), $feedbackId)
		);
	}

	/** The web For You feed, with an envelope for its versioned session cursor. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/timeline')]
	public function timeline(int $limit = 20, string $cursor = '', string $media = ''): DataResponse {
		return $this->answer(['read:statuses'], function () use ($limit, $cursor, $media): array {
			$viewer = $this->viewer();
			$limit = max(1, min(RecommendationTimelineService::MAX_PAGE_SIZE, $limit));
			$page = $this->timelineService->page($viewer, $limit, $cursor, $media);
			$result = $page;
			$items = [];

			// The legacy timeline applies hide filters after ranking. Apply the
			// same public-context rules here, and advance the immutable snapshot
			// while a hidden page leaves room for more visible results.
			for ($scan = 0; $scan < 5; $scan++) {
				$items = array_merge($items, $this->filterService->apply($page['items'], Filter::CONTEXT_PUBLIC, $viewer));
				if (count($items) >= $limit || $page['next_cursor'] === null) {
					break;
				}
				$page = $this->timelineService->page($viewer, $limit - count($items), $page['next_cursor'], $media);
				$result['next_cursor'] = $page['next_cursor'];
				$result['cursor_expired'] = $result['cursor_expired'] || $page['cursor_expired'];
			}

			$result['items'] = array_slice($items, 0, $limit);
			$result['coverage']['returned'] = count($result['items']);
			if ($result['mode'] === 'RANKED' && count($result['items']) < min($limit, 15)) {
				$result['coverage']['state'] = 'UNDERFILLED';
			}
			return $result;
		});
	}

	/** @param string[] $scopes @param callable(): mixed $callback */
	private function answer(array $scopes, callable $callback): DataResponse {
		try {
			$this->initViewer($scopes);
			if (!$this->recommendationService->isEnabled()) {
				throw new ItemNotFoundException('Recommendations are disabled');
			}
			$result = $callback();
			return $result instanceof DataResponse ? $result : new DataResponse($result);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** @param array<string, mixed> $state @return array{revision: int, generation: int, learning_mode: string, learning: bool, learning_active: bool} */
	private function learningState(array $state): array {
		$mode = (string)($state['learning_mode'] ?? 'off');
		return [
			'revision' => (int)($state['revision'] ?? 0),
			'generation' => (int)($state['generation'] ?? 0),
			'learning_mode' => $mode,
			'learning' => array_key_exists('learning', $state) ? (bool)$state['learning'] : $mode === 'on',
			'learning_active' => $mode === 'on'
				&& $this->recommendationService->isEnabled()
				&& !$this->recommendationService->learningPaused(),
		];
	}
}
