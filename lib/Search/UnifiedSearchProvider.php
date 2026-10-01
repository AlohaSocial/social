<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Search;

use Exception;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

/**
 * Class UnifiedSearchProvider
 *
 * @package OCA\Social\Search
 */
class UnifiedSearchProvider implements IProvider {
	public const PROVIDER_ID = 'social';
	public const ORDER = 12;

	use TArrayTools;

	private ?Person $viewer = null;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private StreamService $streamService,
		private StreamRequest $streamRequest,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
		private AccountService $accountService,
		private SearchService $searchService,
	) {
	}

	/**
	 * return unique id of the provider
	 */
	#[\Override]
	public function getId(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * @return string
	 */
	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social');
	}

	/**
	 * @param string $route
	 * @param array $routeParameters
	 *
	 * @return int
	 */
	#[\Override]
	public function getOrder(string $route, array $routeParameters): int {
		return self::ORDER;
	}

	/**
	 * @param IUser $user
	 * @param ISearchQuery $query
	 *
	 * @return SearchResult
	 * @throws AccountDoesNotExistException
	 */
	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$this->initViewer($user);
		$search = trim($query->getTerm());

		$limit = max(1, $query->getLimit());
		$offset = max(0, (int)($query->getCursor() ?? 0));

		// Each source is asked for one entry beyond where this page ends: that
		// makes the merged list a faithful prefix as far as the page reaches, and
		// the leftover is what says whether there is a next page at all. Advertising
		// a cursor without it — which is what used to happen — made "load more"
		// answer with the same first page for ever.
		$reach = $offset + $limit + 1;
		$result = array_merge(
			$this->convertAccounts($this->searchService->searchUri($search)),
			$this->convertAccounts($this->searchService->searchAccounts($search, $reach)),
			$this->convertHashtags($this->searchService->searchHashtags($search, $reach)),
			$this->convertStreams($this->searchService->searchStreamContent($search, $reach))
		);

		$page = array_slice($result, $offset, $limit);
		if (count($result) > $offset + $limit) {
			return SearchResult::paginated($this->l10n->t('Aloha Social'), $page, $offset + $limit);
		}

		return SearchResult::complete($this->l10n->t('Aloha Social'), $page);
	}

	/**
	 * Tells everything this search will ask whose search it is.
	 *
	 * Each of these holds the viewer itself, so the viewer has to be handed to
	 * each of them: a service that was not told sees the search as a stranger's
	 * and answers only what is public.
	 *
	 * @param IUser $user who is searching
	 * @param bool $exception whether a user with no Social account throws
	 *
	 * @throws AccountDoesNotExistException
	 */
	private function initViewer(IUser $user, bool $exception = false) {
		try {
			$this->viewer = $this->accountService->getActorFromUserId($user->getUID());

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);
			$this->streamRequest->setViewer($this->viewer);
		} catch (Exception $e) {
			if ($exception) {
				throw new AccountDoesNotExistException(
					'unable to initViewer - ' . get_class($e) . ' - ' . $e->getMessage()
				);
			}
		}
	}

	/**
	 * @param Person[] $accounts
	 *
	 * @return UnifiedSearchResult[]
	 */
	private function convertAccounts(array $accounts): array {
		$result = [];
		foreach ($accounts as $account) {
			$icon = ($account->hasIcon()) ? $account->getIcon()
				->getUrl() : '';
			$result[] = new UnifiedSearchResult(
				$icon,
				$account->getPreferredUsername(),
				'@' . $account->getAccount(),
				$this->urlGenerator->linkToRoute('social.ActivityPub.actorAlias', ['username' => $account->getAccount()]),
				$icon
			);
		}

		return $result;
	}

	/**
	 * @param \OCA\Social\Model\ActivityPub\Stream[] $streams
	 *
	 * @return UnifiedSearchResult[]
	 */
	private function convertStreams(array $streams): array {
		$result = [];
		foreach ($streams as $stream) {
			$excerpt = html_entity_decode(strip_tags($stream->getContent()), ENT_QUOTES | ENT_HTML5);
			$excerpt = trim((string)preg_replace('/\s+/', ' ', $excerpt));
			if (mb_strlen($excerpt) > 120) {
				$excerpt = mb_substr($excerpt, 0, 119) . '…';
			}

			$author = '';
			$link = $stream->getId();
			if ($stream->hasActor()) {
				$actor = $stream->getActor();
				$author = '@' . $actor->getAccount();
				if ($stream->isLocal()) {
					$link = $this->urlGenerator->linkToRouteAbsolute(
						'social.ActivityPub.displayPost',
						['username' => $actor->getPreferredUsername(), 'token' => (string)$stream->getNid()]
					);
				}
			}

			$result[] = new UnifiedSearchResult(
				'',
				$excerpt,
				$author,
				$link,
				''
			);
		}

		return $result;
	}

	/**
	 * @param array $hashtags
	 *
	 * @return UnifiedSearchResult[]
	 */
	private function convertHashtags(array $hashtags): array {
		$result = [];
		foreach ($hashtags as $hashtag) {
			$tag = $this->get('hashtag', $hashtag, '');
			$posts = $this->getInt('10d', $this->getArray('trend', $hashtag, []), 0);
			$result[] = new UnifiedSearchResult(
				'',
				$this->l10n->n('%n post related to \'%s\'', '%n posts related to \'%s\'', $posts, [$tag]),
				'#' . $tag,
				$this->urlGenerator->linkToRouteAbsolute(
					'social.Navigation.timeline', ['path' => 'tags/' . $tag]
				),
				''
			);
		}

		return $result;
	}
}
