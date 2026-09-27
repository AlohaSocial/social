<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What every client-API controller needs before it can answer anything: who is
 * asking, whether their token is allowed to ask, and what to say when something
 * goes wrong.
 *
 * Every client-API controller extends it except the ones built on
 * `MastodonApiController` and `LocalController`, which grew their own session
 * handling before it existed and still answer in their own shapes. A controller that needs more than a
 * viewer overrides `prepareViewer()`, and one that maps an exception of its
 * own overrides `error()` and hands everything else to it.
 *
 * The rules it encodes are the ones the copies already agreed on:
 *
 *  - A bearer token identifies the caller. Failing that, a Nextcloud session
 *    that passes its CSRF check does -- which is what lets the app's own
 *    frontend call these routes without minting a token for itself.
 *  - A granular scope is satisfied by itself or by the broad scope containing
 *    it (`read:lists` by `read:lists` or by `read`), and by nothing else. Not
 *    by another granular variant of the same parent: a token granted
 *    `read:statuses` has not been granted the caller's collections.
 *  - "Not there", "somebody else's" and "no such account" are one answer, so
 *    that none of them can be told apart from the others by someone probing.
 */
abstract class ClientApiController extends Controller {
	protected string $bearer = '';
	protected ?SocialClient $client = null;
	protected ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		protected IUserSession $userSession,
		protected LoggerInterface $logger,
		protected AccountService $accountService,
		protected ClientService $clientService,
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
	 * Who is asking, held to the scopes the route needs.
	 *
	 * With `$required` false a caller without usable credentials is answered
	 * as nobody and the viewer stays null; a token that is valid but lacks
	 * the scope is still refused, since it said who it was.
	 *
	 * @param string[] $scopes
	 *
	 * @throws ClientNotFoundException
	 * @throws InsufficientScopeException
	 */
	protected function initViewer(array $scopes, bool $required = true): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->prepareViewer($this->accountService->getActorFromUserId($userId));
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[' . static::class . '] no usable credentials', [
				'exception' => $e->getMessage(),
			]);

			if ($required) {
				throw new ClientNotFoundException('the access_token was revoked');
			}
		}
	}

	/**
	 * What a controller does with the viewer once it is known, before the
	 * route runs. A failure here counts as the credentials failing.
	 */
	protected function prepareViewer(Person $viewer): Person {
		return $viewer;
	}

	/** The viewer, for a route that has already called `initViewer()`. */
	protected function viewer(): Person {
		if ($this->viewer === null) {
			throw new ClientNotFoundException('userId not defined');
		}

		return $this->viewer;
	}

	/**
	 * The viewer when there is one and null when there is not, for a route that
	 * answers anonymous callers but shows more to a signed-in one.
	 */
	protected function optionalViewer(array $scopes): ?Person {
		try {
			$this->initViewer($scopes);

			return $this->viewer;
		} catch (Throwable $e) {
			return null;
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
	 * @param string[] $accepted none means any valid token will do, as on
	 *                           routes Mastodon guards with a user and no scope
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		if ($accepted === []) {
			return;
		}

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
	 * This request's own URL with the cursor replaced, so every other filter
	 * the client sent survives into the next page of a `Link` header.
	 *
	 * @param array<string, string> $cursor
	 */
	protected function pageUrl(array $cursor): string {
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

	protected function error(Throwable $e): DataResponse {
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

		if ($e instanceof ItemNotFoundException || $e instanceof CacheActorDoesNotExistException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof InvalidResourceException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[' . static::class . '] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
