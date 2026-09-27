<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Client\FilterStatus;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Controller;
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
 * Keyword filters, as Mastodon's v2 filter API.
 *
 * A filter is what an account mutes a word or a phrase with: a title, the
 * contexts it applies in, an expiry, an action — `warn`, which leaves the
 * status in place for the client to blur, or `hide`, which takes it out of the
 * timeline — and the keywords that match a status. This is the editing half;
 * what a filter *does* is `FilterService`'s, and is applied wherever statuses
 * are handed to a client.
 *
 * The v1 routes (`/api/v1/filters`) are deprecated in Mastodon but are served
 * here, over the same filters: a v1 filter is a v2 *keyword*, carrying its
 * parent's contexts, expiry and action. This docblock said they were not
 * served long after `indexV1()` and the rest below were written. The web
 * client (`src/components/FiltersSettings.vue`) uses v2 only — a filter of
 * three words is three ids in v1 and one in v2, and an editor that mixed the
 * two would delete the wrong row.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController` and
 * `TagController`, and for the same reason: a Mastodon client authenticates
 * with a bearer token and has no Nextcloud session or CSRF token to present,
 * so `#[NoAdminRequired]` would refuse every real caller before the handler
 * ran. Every route here requires a viewer itself — no token, no session, 401 —
 * and every read and write is scoped to that viewer in SQL, so one account can
 * neither see nor edit another's filters.
 */
class FilterController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private ClientService $clientService,
		private FiltersRequest $filtersRequest,
		private TimelineRevisionService $timelineRevisionService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 * Every filter of the viewer, newest first.
	 *
	 * Not paged, and Mastodon does not page it either: an account has a
	 * handful of filters, and a client needs all of them to decide what to
	 * blur.
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
	 * Mastodon's **v1** filters, over the v2 ones.
	 *
	 * v1 has no notion of a filter with several keywords: each filter *is* a
	 * phrase. So a v1 filter here is a v2 keyword, carrying its parent's
	 * contexts and expiry — which is the mapping Mastodon itself serves for
	 * clients that have not moved, and why the ids in the two APIs are
	 * different things.
	 *
	 * Absent, these routes 404'd, and a client that has not moved to v2 reads a
	 * 404 as "this server has no filters at all" rather than "none configured".
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
	 * Creates a v1 filter: a v2 filter whose title is the phrase, holding that
	 * one keyword.
	 *
	 * `irreversible` is Mastodon's older name for what v2 calls
	 * `filter_action: hide` — the filtered status is dropped rather than
	 * blurred — so it maps onto the action rather than being stored twice.
	 *
	 * @param array<mixed> $context
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/filters')]
	public function createV1(
		string $phrase = '',
		array $context = [],
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
	 * Changes a v1 filter. What is not named is left alone, as everywhere else
	 * here — a client that sends only `phrase` must not thereby clear the
	 * contexts or the expiry.
	 *
	 * @param array<mixed>|null $context
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/filters/{id}', requirements: ['id' => '\\d+'])]
	public function updateV1(
		int $id,
		?string $phrase = null,
		?array $context = null,
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
	 * Removes a v1 filter: the keyword, and the filter with it when that was
	 * its last one — a v2 filter with no keywords matches nothing, and leaving
	 * one behind would show up in the v2 list as an empty filter the user never
	 * made.
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
	 * Creates a filter, with the keywords it was given.
	 *
	 * `title` and at least one known `context` are required, as they are on
	 * Mastodon; `filter_action` defaults to `warn`, and an absent `expires_in`
	 * means a filter that never expires.
	 *
	 * @param array<mixed> $context
	 * @param array<mixed> $keywords_attributes
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/filters')]
	public function create(
		string $title = '',
		array $context = [],
		string $filter_action = '',
		mixed $expires_in = null,
		array $keywords_attributes = [],
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
	 * Changes a filter. What is not named is left as it is — a client that
	 * sends only `title` must not thereby clear the contexts, the expiry or
	 * the keywords.
	 *
	 * `keywords_attributes` edits keywords in place, as Mastodon's does: an
	 * entry with an `id` changes that keyword, one with `_destroy` removes it,
	 * one without an id adds it, and a keyword nobody named is untouched.
	 *
	 * @param array<mixed>|null $context
	 * @param array<mixed>|null $keywords_attributes
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v2/filters/{id}', requirements: ['id' => '\\d+'])]
	public function update(
		int $id,
		?string $title = null,
		?array $context = null,
		?string $filter_action = null,
		?array $keywords_attributes = null,
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

			// read from the request rather than taken as an argument, because
			// only the raw parameter distinguishes "not sent", which leaves the
			// expiry alone, from an explicit null, which is Mastodon's way of
			// saying the filter should stop expiring
			$expiresIn = $this->request->getParam('expires_in', false);
			if ($expiresIn !== false) {
				$filter->setExpiresAt($this->expiry($expiresIn));
			}

			// One change, however many rows it is. `applyKeywordAttributes()`
			// refuses a keyword that belongs to another filter, and can refuse
			// the fourth after writing the first three: without this the
			// request answered 404 with the title already changed, and a
			// client retrying what it was told had failed retried against
			// state that had partly moved.
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
	 * Adds one post to a filter: a client's "filter this post".
	 *
	 * The post is named by the id the client API hands out. It is not looked
	 * up: a filter is the reader's own list and may name a post this server
	 * has since deleted or has never held — the entry simply never matches
	 * anything. A lookup here would refuse to filter a post the reader can see
	 * on their own screen in a thread whose rows arrived from somewhere else.
	 *
	 * Adding the same post twice makes a second entry, as it does on Mastodon:
	 * each has its own id, and deleting one leaves the other.
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
	 * @param array<mixed> $keywordAttributes
	 *
	 * @throws Exception
	 */
	private function applyKeywordAttributes(Filter $filter, array $keywordAttributes): void {
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
	 * @param array<mixed> $raw
	 *
	 * @return array<array<string, mixed>>
	 */
	private function keywordAttributes(array $raw): array {
		$entries = [];
		foreach ($raw as $entry) {
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
	private function contexts(array $raw): array {
		$contexts = Filter::normaliseContexts($raw);
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

		// a filter that expires now, or expired before it was made, is one
		// that never expires — which is what Mastodon stores for a blank
		// expires_in, and the only reading that is not a filter dead on arrival
		return ($seconds <= 0) ? 0 : time() + $seconds;
	}

	/** A flag as any client spells one: true, "true", 1, "1", "on". */
	private function flag(mixed $raw): bool {
		return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
	}

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` uses, because the
	 * same clients call both.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 *
	 * @throws ClientNotFoundException there is nobody to answer for
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes = ['read']): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId);
			// Every route here that is not a read changes which posts this
			// account is shown, and its timelines' ETag is built from ids that
			// none of it moves — see TimelineRevisionService. It is recorded
			// here rather than beside each of the eleven writes below: one
			// place that cannot be forgotten, at the cost of also moving the
			// number when a write is attempted and refused, which spends one
			// revalidation and is the harmless direction to be wrong in.
			if ($this->request->getMethod() !== 'GET') {
				$this->timelineRevisionService->bumpForActor($this->viewer->getId());
			}
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[FilterController] no usable credentials', [
				'exception' => $e->getMessage(),
			]);

			throw new ClientNotFoundException('the access_token was revoked');
		}
	}

	/**
	 * @param string[] $scopes
	 *
	 * @throws ClientNotFoundException
	 * @throws InsufficientScopeException
	 */
	private function currentSession(array $scopes): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope($scopes);

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new ClientNotFoundException('userId not defined');
	}

	/**
	 * A scope is satisfied by itself or by any of its granular variants
	 * ('read' by 'read:filters').
	 *
	 * @param string[] $accepted
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		foreach ($accepted as $scope) {
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client?->getAuthScopes() ?? [] as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		throw new InsufficientScopeException(
			'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
		);
	}

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it. An unrecognised failure is a bug
	 * on this side, so it answers 500 and its message is not sent on — these
	 * are `#[PublicPage]` routes, and echoing getMessage() publishes whatever
	 * the failure happened to name.
	 */
	private function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		if ($e instanceof ClientNotFoundException) {
			$message = trim($e->getMessage());

			return new DataResponse(
				['error' => ($message === '') ? 'the access_token is invalid' : $message],
				Http::STATUS_UNAUTHORIZED,
				['WWW-Authenticate' => 'Bearer error="invalid_token"']
			);
		}

		if ($e instanceof ItemNotFoundException) {
			// what the viewer does not own does not exist as far as this API is
			// concerned: a 403 would tell them somebody else's filter is there
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof InvalidResourceException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[FilterController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
