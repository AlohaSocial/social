<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Search;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Reference\PostReferenceProvider;
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
	 * The unique id of the provider.
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
		return Application::APP_NAME_DISPLAY;
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

		// Each source is asked for one entry beyond this page, so the merged list
		// is a faithful prefix and the leftover says whether there is a next page.
		$reach = $offset + $limit + 1;
		$result = array_merge(
			// cache-only, and nothing queued: Nextcloud asks every provider
			// for every search in every app, so what is typed there (an
			// e-mail address, a pasted link) is never sent to another server
			$this->convertAccounts($this->searchService->searchUri($search, false, false)),
			$this->convertAccounts($this->searchService->searchAccounts($search, $reach, '', false, false)),
			$this->convertHashtags($this->searchService->searchHashtags($search, $reach)),
			$this->convertStreams($this->searchService->searchStreamContent($search, $reach))
		);

		$page = array_slice($result, $offset, $limit);
		if (count($result) > $offset + $limit) {
			return SearchResult::paginated(Application::APP_NAME_DISPLAY, $page, $offset + $limit);
		}

		return SearchResult::complete(Application::APP_NAME_DISPLAY, $page);
	}

	/**
	 * Hands the viewer to every service this search asks, or each would answer
	 * only what is public.
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
			// behind a content warning, the warning stands in for the text
			$excerpt = PostReferenceProvider::warningOf($stream, $this->l10n, 120)
				?? PostReferenceProvider::excerpt($stream->getContent(), 120);

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
