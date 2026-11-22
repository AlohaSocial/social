<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Client\FilterStatus;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AiContentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\CountsService;
use OCA\Social\Service\FeedDisplayService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Keyword filters, as Mastodon's v2 filter API; what a filter does is
 * `FilterService`'s.
 *
 * The deprecated v1 routes are served over the same filters, a v1 filter
 * being a v2 keyword. The web client (`src/components/FiltersSettings.vue`)
 * uses v2 only, since a filter of three words is three ids in v1.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]` for bearer-token clients; every
 * route requires a viewer itself and scopes all reads and writes to that
 * viewer in SQL.
 */
class FilterController extends ClientApiController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private FiltersRequest $filtersRequest,
		private TimelineRevisionService $timelineRevisionService,
		private AiContentService $aiContentService,
		private CountsService $countsService,
		private FeedDisplayService $feedDisplayService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * Whether the viewer hides posts made with AI: `{"hide": bool}`. Applied
	 * with the other filters in `FilterService::apply()`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/ai_content')]
	public function aiContent(): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			return new DataResponse(
				$this->aiContentService->export($this->viewer->getUserId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Turns the switch, and answers with it. `hide` is required and has to be
	 * a boolean in one of the spellings a client uses; anything else is a 422,
	 * because a request that is unclear about a switch must not flip it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/social/ai_content')]
	public function aiContentUpdate(mixed $hide = null): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);

			$this->aiContentService->setHides($this->viewer->getUserId(), $this->requiredFlag($hide, 'hide'));

			return new DataResponse(
				$this->aiContentService->export($this->viewer->getUserId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Whether the reader is shown counts of likes, dislikes, boosts and
	 * followers, applied to every answer by `HideCountsMiddleware`. Hidden
	 * unless turned off.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/counts')]
	public function counts(): DataResponse {
		try {
			$this->initViewer(['read:accounts', 'read']);

			return new DataResponse($this->countsService->export($this->viewer->getUserId()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Turns the switch, and answers with it. `hide` is required, as for the AI switch. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/social/counts')]
	public function countsUpdate(mixed $hide = null): DataResponse {
		try {
			$this->initViewer(['write:accounts', 'write']);
			$this->countsService->setHides($this->viewer->getUserId(), $this->requiredFlag($hide, 'hide'));

			return new DataResponse($this->countsService->export($this->viewer->getUserId()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Whether replies are left out of the reader's feeds, and the thread order. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/feed_preferences')]
	public function feedPreferences(): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			return new DataResponse($this->feedDisplayService->export($this->viewer->getUserId()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Every filter of the viewer, newest first. Not paged, as on Mastodon.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/filters')]
	public function index(): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			$filters = [];
			foreach ($this->filtersRequest->getByActor($this->viewer->getId()) as $filter) {
				$filters[] = $filter->jsonSerialize();
			}

			return new DataResponse($filters, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's v1 filters, over the v2 ones: v1 has one phrase per filter,
	 * so a v1 filter is a v2 keyword with its parent's contexts and expiry, as
	 * Mastodon maps them. Clients still on v1 read a 404 as "no filters at all".
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/filters')]
	public function indexV1(): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			$filters = [];
			foreach ($this->filtersRequest->getByActor($this->viewer->getId()) as $filter) {
				foreach ($filter->getKeywords() as $keyword) {
					$filters[] = self::asV1($filter, $keyword);
				}
			}

			return new DataResponse($filters, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One v1 filter, which is one keyword of one of the viewer's filters. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/filters/{id}', requirements: ['id' => '\\d+'])]
	public function getV1(int $id): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);
			[$filter, $keyword] = $this->keywordOfViewer($id);

			return new DataResponse(self::asV1($filter, $keyword), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Creates a v1 filter: a v2 filter titled with the phrase, holding that
	 * one keyword. `irreversible` maps to `filter_action: hide`.
	 *
	 * @param array<mixed>|string $context
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/filters')]
	public function createV1(
		string $phrase = '',
		array|string $context = [],
		mixed $irreversible = false,
		mixed $whole_word = false,
		mixed $expires_in = null,
	): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);

			$filter = (new Filter())
				->setActorId($this->viewer->getId())
				->setTitle($this->keyword($phrase))
				->setContexts($this->contexts($context))
				->setAction($this->flag($irreversible) ? Filter::ACTION_HIDE : Filter::ACTION_WARN)
				->setExpiresAt($this->expiry($expires_in));
			$filter->addKeyword(
				(new FilterKeyword())
					->setKeyword($this->keyword($phrase))
					->setWholeWord($this->flag($whole_word))
			);
			$this->filtersRequest->transactional(fn (): int => $this->filtersRequest->save($filter));

			$keywords = $filter->getKeywords();

			return new DataResponse(self::asV1($filter, $keywords[0]), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes a v1 filter; what is not sent is left alone.
	 *
	 * @param array<mixed>|string|null $context
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/filters/{id}', requirements: ['id' => '\\d+'])]
	public function updateV1(
		int $id,
		?string $phrase = null,
		array|string|null $context = null,
		mixed $irreversible = null,
		mixed $whole_word = null,
		mixed $expires_in = null,
	): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			[$filter, $keyword] = $this->keywordOfViewer($id);

			if ($phrase !== null) {
				$keyword->setKeyword($this->keyword($phrase));
				$filter->setTitle($this->keyword($phrase));
			}
			if ($whole_word !== null) {
				$keyword->setWholeWord($this->flag($whole_word));
			}
			if ($context !== null) {
				$filter->setContexts($this->contexts($context));
			}
			if ($irreversible !== null) {
				$filter->setAction($this->flag($irreversible) ? Filter::ACTION_HIDE : Filter::ACTION_WARN);
			}
			if ($expires_in !== null) {
				$filter->setExpiresAt($this->expiry($expires_in));
			}

			// the filter row and its keyword are one change: a phrase writes
			// both, and half of it is a filter whose title and keyword disagree
			$this->filtersRequest->transactional(function () use ($filter, $keyword): void {
				$this->filtersRequest->update($filter);
				$this->filtersRequest->updateKeyword($keyword);
			});

			return new DataResponse(self::asV1($filter, $keyword), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Removes a v1 filter: the keyword, and its filter when that was the last
	 * keyword, as an empty v2 filter would match nothing.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/filters/{id}', requirements: ['id' => '\\d+'])]
	public function deleteV1(int $id): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			[$filter, $keyword] = $this->keywordOfViewer($id);

			$this->filtersRequest->transactional(function () use ($filter, $keyword): void {
				$this->filtersRequest->deleteKeyword($keyword->getId(), $this->viewer->getId());
				if (count($filter->getKeywords()) <= 1) {
					$this->filtersRequest->delete($filter->getId(), $this->viewer->getId());
				}
			});

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The v1 entity: the keyword's id and phrase, with its parent's contexts,
	 * expiry and action.
	 *
	 * @return array<string, mixed>
	 */
	private static function asV1(Filter $filter, FilterKeyword $keyword): array {
		return [
			'id' => (string)$keyword->getId(),
			'phrase' => $keyword->getKeyword(),
			'context' => $filter->getContexts(),
			'whole_word' => $keyword->isWholeWord(),
			'expires_at' => $filter->jsonSerialize()['expires_at'],
			'irreversible' => $filter->getAction() === Filter::ACTION_HIDE,
		];
	}

	/**
	 * The keyword behind a v1 id, and the filter holding it — both resolved
	 * against the viewer, so somebody else's is a 404 rather than a 403.
	 *
	 * @return array{Filter, FilterKeyword}
	 * @throws ItemNotFoundException
	 */
	private function keywordOfViewer(int $keywordId): array {
		foreach ($this->filtersRequest->getByActor($this->viewer->getId()) as $filter) {
			foreach ($filter->getKeywords() as $keyword) {
				if ($keyword->getId() === $keywordId) {
					return [$filter, $keyword];
				}
			}
		}

		throw new ItemNotFoundException('no such filter');
	}

	/** One filter of the viewer. Somebody else's is a 404, not a 403. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/filters/{id}', requirements: ['id' => '\\d+'])]
	public function get(int $id): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			return new DataResponse($this->filter($id)->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Creates a filter with its keywords. `title` and one known `context` are
	 * required; `filter_action` defaults to `warn`, and no `expires_in` means
	 * it never expires.
	 *
	 * @param array<mixed>|string $context
	 * @param array<mixed>|string $keywords_attributes
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/filters')]
	public function create(
		string $title = '',
		array|string $context = [],
		string $filter_action = '',
		mixed $expires_in = null,
		array|string $keywords_attributes = [],
	): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);

			$filter = new Filter();
			$filter->setActorId($this->viewer->getId())
				->setTitle($this->title($title))
				->setContexts($this->contexts($context))
				->setAction(($filter_action === '') ? Filter::ACTION_WARN : $this->action($filter_action))
				->setExpiresAt($this->expiry($expires_in));

			foreach ($this->keywordAttributes($keywords_attributes) as $attributes) {
				if ($this->flag($attributes['_destroy'] ?? false)) {
					continue;
				}

				$filter->addKeyword(
					(new FilterKeyword())
						->setKeyword($this->keyword((string)($attributes['keyword'] ?? '')))
						->setWholeWord($this->flag($attributes['whole_word'] ?? false))
				);
			}

			$this->filtersRequest->transactional(fn (): int => $this->filtersRequest->save($filter));

			return new DataResponse($filter->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes a filter; what is not sent is left alone. `keywords_attributes`
	 * edits keywords in place as Mastodon's does: with `id` it changes one,
	 * with `_destroy` removes it, without an id adds one.
	 *
	 * @param array<mixed>|string|null $context
	 * @param array<mixed>|string|null $keywords_attributes
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v2/filters/{id}', requirements: ['id' => '\\d+'])]
	public function update(
		int $id,
		?string $title = null,
		array|string|null $context = null,
		?string $filter_action = null,
		array|string|null $keywords_attributes = null,
	): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			$filter = $this->filter($id);

			if ($title !== null) {
				$filter->setTitle($this->title($title));
			}
			if ($context !== null) {
				$filter->setContexts($this->contexts($context));
			}
			if ($filter_action !== null && $filter_action !== '') {
				$filter->setAction($this->action($filter_action));
			}

			// read from the request because only the raw parameter tells "not
			// sent" (leave the expiry) from null (stop expiring)
			$expiresIn = $this->request->getParam('expires_in', false);
			if ($expiresIn !== false) {
				$filter->setExpiresAt($this->expiry($expiresIn));
			}

			// One transaction: `applyKeywordAttributes()` can refuse a keyword
			// after writing others, and a refused request must change nothing.
			$this->filtersRequest->transactional(function () use ($filter, $keywords_attributes): void {
				$this->filtersRequest->update($filter);

				if ($keywords_attributes !== null) {
					$this->applyKeywordAttributes($filter, $keywords_attributes);
				}
			});

			return new DataResponse($this->filter($id)->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Deletes the filter and its keywords. Mastodon answers an empty object,
	 * and a client reads that as "gone".
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v2/filters/{id}', requirements: ['id' => '\\d+'])]
	public function delete(int $id): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			// so that somebody else's filter is a 404 rather than a silent no-op
			$this->filter($id);
			$this->filtersRequest->delete($id, $this->viewer->getId());

			return new DataResponse(new stdClass(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The keywords of one of the viewer's filters. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/filters/{id}/keywords', requirements: ['id' => '\\d+'])]
	public function keywords(int $id): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			$keywords = [];
			foreach ($this->filter($id)->getKeywords() as $keyword) {
				$keywords[] = $keyword->jsonSerialize();
			}

			return new DataResponse($keywords, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Adds one keyword to one of the viewer's filters. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/filters/{id}/keywords', requirements: ['id' => '\\d+'])]
	public function addKeyword(int $id, string $keyword = '', mixed $whole_word = false): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			$filter = $this->filter($id);

			$entry = (new FilterKeyword())
				->setFilterId($filter->getId())
				->setKeyword($this->keyword($keyword))
				->setWholeWord($this->flag($whole_word));
			$this->filtersRequest->saveKeyword($entry);

			return new DataResponse($entry->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One keyword, of one of the viewer's filters. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/filters/keywords/{id}', requirements: ['id' => '\\d+'])]
	public function getKeyword(int $id): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);
			$keyword = $this->filtersRequest->getKeywordById($id, $this->viewer->getId());

			return new DataResponse($keyword->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Changes one keyword. What is not named is left as it is. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v2/filters/keywords/{id}', requirements: ['id' => '\\d+'])]
	public function updateKeyword(int $id, ?string $keyword = null, mixed $whole_word = null): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			$entry = $this->filtersRequest->getKeywordById($id, $this->viewer->getId());

			if ($keyword !== null) {
				$entry->setKeyword($this->keyword($keyword));
			}
			if ($whole_word !== null) {
				$entry->setWholeWord($this->flag($whole_word));
			}

			$this->filtersRequest->updateKeyword($entry);

			return new DataResponse($entry->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Removes one keyword; the filter itself stays. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v2/filters/keywords/{id}', requirements: ['id' => '\\d+'])]
	public function deleteKeyword(int $id): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			// read first, so that a keyword the viewer does not own is this
			// route's 404 rather than a delete that silently matched no row
			$keyword = $this->filtersRequest->getKeywordById($id, $this->viewer->getId());
			$this->filtersRequest->deleteKeyword($keyword->getId(), $this->viewer->getId());

			return new DataResponse(new stdClass(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The posts one of the viewer's filters covers by name. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/filters/{id}/statuses', requirements: ['id' => '\\d+'])]
	public function statuses(int $id): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			$statuses = [];
			foreach ($this->filter($id)->getStatuses() as $status) {
				$statuses[] = $status->jsonSerialize();
			}

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Adds one post to a filter: a client's "filter this post". The post id
	 * is not looked up, as it may name a post this server never held; adding
	 * it twice makes two entries, as on Mastodon.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/filters/{id}/statuses', requirements: ['id' => '\\d+'])]
	public function addStatus(int $id, string $status_id = ''): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			$filter = $this->filter($id);

			// kept as the string it was sent as: a nid does not fit a PHP int
			// everywhere, and a cast would name a different post
			$statusId = trim($status_id);
			if (!ctype_digit($statusId) || Nid::compare($statusId, '0') < 1) {
				throw new InvalidResourceException('status_id is required');
			}

			$entry = (new FilterStatus())
				->setFilterId($filter->getId())
				->setStatusId($statusId);
			$this->filtersRequest->saveStatus($entry);

			return new DataResponse($entry->jsonSerialize(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One entry, of one of the viewer's filters. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/filters/statuses/{id}', requirements: ['id' => '\\d+'])]
	public function getStatus(int $id): DataResponse {
		try {
			$this->initViewer(['read:filters', 'read']);

			return new DataResponse(
				$this->filtersRequest->getStatusById($id, $this->viewer->getId())->jsonSerialize(),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Stops a filter covering that post; the filter itself stays. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v2/filters/statuses/{id}', requirements: ['id' => '\\d+'])]
	public function deleteStatus(int $id): DataResponse {
		try {
			$this->initViewer(['write:filters', 'write']);
			$this->filtersRequest->deleteStatus($id, $this->viewer->getId());

			return new DataResponse(new stdClass(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @throws ItemNotFoundException the viewer has no such filter, which is
	 *                               also the answer for somebody else's
	 */
	private function filter(int $id): Filter {
		return $this->filtersRequest->getById($id, $this->viewer->getId());
	}

	/**
	 * @param array<mixed>|string $keywordAttributes
	 *
	 * @throws Exception
	 */
	private function applyKeywordAttributes(Filter $filter, array|string $keywordAttributes): void {
		$actorId = $this->viewer->getId();

		foreach ($this->keywordAttributes($keywordAttributes) as $attributes) {
			$keywordId = (int)($attributes['id'] ?? 0);
			$destroy = $this->flag($attributes['_destroy'] ?? false);

			if ($keywordId === 0) {
				if ($destroy) {
					continue;
				}

				$this->filtersRequest->saveKeyword(
					(new FilterKeyword())
						->setFilterId($filter->getId())
						->setKeyword($this->keyword((string)($attributes['keyword'] ?? '')))
						->setWholeWord($this->flag($attributes['whole_word'] ?? false))
				);

				continue;
			}

			// reading it back is the ownership check: a keyword of somebody
			// else's filter, or of another filter of the viewer's, is not
			// editable through this filter
			$entry = $this->filtersRequest->getKeywordById($keywordId, $actorId);
			if ($entry->getFilterId() !== $filter->getId()) {
				throw new ItemNotFoundException('filter keyword not found');
			}

			if ($destroy) {
				$this->filtersRequest->deleteKeyword($keywordId, $actorId);

				continue;
			}

			if (isset($attributes['keyword'])) {
				$entry->setKeyword($this->keyword((string)$attributes['keyword']));
			}
			if (array_key_exists('whole_word', $attributes)) {
				$entry->setWholeWord($this->flag($attributes['whole_word']));
			}

			$this->filtersRequest->updateKeyword($entry);
		}
	}

	/**
	 * `keywords_attributes` as a list of entries, however it arrived: JSON
	 * sends a list, form encoding sends `keywords_attributes[0][keyword]`,
	 * which PHP hands over as an array keyed by the index.
	 *
	 * @param array<mixed>|string $raw
	 *
	 * @return array<array<string, mixed>>
	 */
	private function keywordAttributes(array|string $raw): array {
		$entries = [];
		foreach (is_array($raw) ? $raw : [] as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @throws InvalidResourceException
	 */
	private function title(string $raw): string {
		$title = Filter::normaliseTitle($raw);
		if ($title === '') {
			throw new InvalidResourceException('title is required');
		}

		return $title;
	}

	/**
	 * @param array<mixed> $raw
	 *
	 * @return string[]
	 *
	 * @throws InvalidResourceException
	 */
	private function contexts(array|string $raw): array {
		$contexts = Filter::normaliseContexts(self::listParam($raw));
		if ($contexts === []) {
			throw new InvalidResourceException(
				'context must name at least one of ' . implode(', ', Filter::CONTEXTS)
			);
		}

		return $contexts;
	}

	/**
	 * @throws InvalidResourceException
	 */
	private function action(string $raw): string {
		$action = Filter::normaliseAction($raw);
		if ($action === '') {
			throw new InvalidResourceException(
				'filter_action must be one of ' . implode(', ', Filter::ACTIONS)
			);
		}

		return $action;
	}

	/**
	 * @throws InvalidResourceException
	 */
	private function keyword(string $raw): string {
		$keyword = FilterKeyword::normalise($raw);
		if ($keyword === '') {
			// an empty keyword is not an empty filter: it would match every
			// status there is
			throw new InvalidResourceException('keyword is required');
		}

		return $keyword;
	}

	/**
	 * When the filter stops applying, as a timestamp; 0 for never.
	 *
	 * @throws InvalidResourceException
	 */
	private function expiry(mixed $raw): int {
		if ($raw === null || $raw === '' || $raw === false) {
			return 0;
		}

		if (!is_numeric($raw)) {
			throw new InvalidResourceException('expires_in must be a number of seconds');
		}

		$seconds = (int)$raw;

		// an expiry in the past means never expiring, as Mastodon stores a
		// blank expires_in
		return ($seconds <= 0) ? 0 : time() + $seconds;
	}

	/** A flag as any client spells one: true, "true", 1, "1", "on". */
	private function flag(mixed $raw): bool {
		return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
	}

	/**
	 * A required flag: `flag()` reads anything unrecognised as `false`, which
	 * is wrong for a switch being set.
	 *
	 * @throws InvalidResourceException
	 */
	private function requiredFlag(mixed $raw, string $name): bool {
		$flag = (is_scalar($raw) && $raw !== '')
			? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
		if ($flag === null) {
			throw new InvalidResourceException($name . ' must be true or false');
		}

		return $flag;
	}

	/**
	 * Every write here changes which posts the account is shown, which the
	 * timelines' ETag cannot see (TimelineRevisionService), so the revision is
	 * bumped for every non-GET request in this one place, refused ones included.
	 */
	#[\Override]
	protected function prepareViewer(Person $viewer): Person {
		if ($this->request->getMethod() !== 'GET') {
			$this->timelineRevisionService->bumpForActor($viewer->getId());
		}

		return $viewer;
	}
}
