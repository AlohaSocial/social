<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\WellKnown;

use OCA\Social\AppInfo\Application;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\InstanceActorService;
use OCP\AppFramework\Http;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\IRequest;

class WebfingerHandler implements IHandler {
	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private CacheActorService $cacheActorService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private InstanceActorService $instanceActorService,
	) {
	}

	/**
	 * @see https://docs.joinmastodon.org/spec/webfinger/
	 *
	 * @param string $service
	 * @param IRequestContext $context
	 * @param IResponse|null $previousResponse
	 *
	 * @return IResponse|null
	 */
	#[\Override]
	public function handle(
		string $service,
		IRequestContext $context,
		?IResponse $previousResponse,
	): ?IResponse {
		try {
			$this->fediverseService->jailed();
		} catch (UnauthorizedFediverseException $e) {
			return $previousResponse;
		}

		$response = null;
		switch (strtolower($service)) {
			case 'webfinger':
				$response = $this->handleWebfinger($context, $previousResponse);
				break;

			case 'nodeinfo':
				$response = $this->handleNodeInfo();
				break;

			case 'host-meta':
				$response = $this->handleHostMeta();
				break;
		}

		if ($response !== null) {
			return $response;
		}

		return $previousResponse;
	}

	/**
	 * handle request on /.well-known/webfinger
	 *
	 * @param IRequestContext $context
	 *
	 * @return IResponse|null
	 */
	public function handleWebfinger(IRequestContext $context, ?IResponse $previousResponse): ?IResponse {
		$subject = $this->getSubjectFromRequest($context->getHttpRequest());
		if ($subject === '') {
			// RFC 7033, section 4.2: the resource parameter is required
			return new JrdResponse('', Http::STATUS_BAD_REQUEST);
		}

		$subjectAcct = $subject;
		if (str_starts_with($subject, 'acct:')) {
			$subject = substr($subject, 5);
		}

		if ($subject === Application::APP_SUBJECT) {
			if ($previousResponse !== null && method_exists($previousResponse, 'addLink')) {
				$previousResponse->addLink(
					Application::APP_REL,
					'application/json',
					$this->configService->getSocialUrl(),
					[],
					[
						'app' => Application::APP_ID,
						'name' => Application::APP_NAME,
						'version' => $this->configService->getAppValue('installed_version'),
					]
				);
			}

			return $previousResponse;
		}

		$instanceActor = $this->instanceActorResponse($subject);
		if ($instanceActor !== null) {
			return $instanceActor;
		}

		$actor = null;
		try {
			$actor = $this->cacheActorService->getFromLocalAccount($subject);
		} catch (ActorDoesNotExistException|SocialAppConfigException $e) {
			return null;
		} catch (CacheActorDoesNotExistException $e) {
		}

		if ($actor === null) {
			try {
				$actor = $this->cacheActorsRequest->getFromId($subject);
			} catch (CacheActorDoesNotExistException $e) {
			}
		}

		if ($actor === null || !$actor->isLocal()) {
			return new JrdResponse('', Http::STATUS_NOT_FOUND);
		}

		// ActivityPub profile. The links have to name the actor exactly as its own
		// document does, so they come from the stored id rather than from the host
		// this request happened to arrive under: on an instance reachable under two
		// trusted domains, the request-derived href handed a remote server an actor
		// id that disagreed with the document it then fetched.
		$href = $actor->getId();
		if ($href === '') {
			return new JrdResponse('', Http::STATUS_NOT_FOUND);
		}

		// The subject is the account's own handle, never the query echoed
		// back: `acct:@maya@host`, `acct:Maya@host` and a bare `maya@host`
		// all find this account, and a consumer that splits the answer on
		// `@` read the first of them as a user with no name.
		try {
			$address = $this->configService->getSocialAddress();
			if ($address !== '' && $actor->getPreferredUsername() !== '') {
				$subjectAcct = 'acct:' . $actor->getPreferredUsername() . '@' . $address;
			}
		} catch (SocialAppConfigException $e) {
		}

		$response = new JrdResponse($subjectAcct);
		$response->addAlias($href);
		$response->addLink('self', 'application/activity+json', $href);

		// The profile a remote reader is sent to has to be the *social* one:
		// `rel=profile-page` is what a peer follows when somebody clicks the
		// handle, and the Nextcloud user page has none of the account's posts
		// on it — it is the profile of a Nextcloud user, not of a fediverse
		// actor. The Nextcloud page stays an alias, which is what an alias is
		// for: another name this account answers to.
		$socialProfileUrl = $this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername();
		$nextcloudProfileUrl = $this->configService->getCloudUrl() . '/u/' . $actor->getPreferredUsername();
		$response->addAlias($nextcloudProfileUrl);
		$response->addLink('http://webfinger.net/rel/profile-page', 'text/html', $socialProfileUrl);

		// Ostatus subscribe url
		$subscribe = $this->configService->getSocialUrl() . 'ostatus/follow/?uri={uri}';
		$response->addLink(
			'http://ostatus.org/schema/1.0/subscribe',
			'',
			'',
			null,
			null,
			['template' => $subscribe]
		);

		return $response;
	}

	/**
	 * The instance's own `Application` actor, answered for
	 * `acct:<host>@<host>` — the handle Mastodon gives its own instance actor
	 * and the one a peer holding a `keyId` from here will look up when it wants
	 * to know what the signer is.
	 *
	 * It is answered before any local account is looked up, so a Nextcloud user
	 * whose id happens to equal the instance host cannot take the name the
	 * server signs under. Mastodon reserves the handle the same way.
	 *
	 * @return IResponse|null null when the subject is somebody else's
	 */
	private function instanceActorResponse(string $subject): ?IResponse {
		try {
			$address = $this->configService->getSocialAddress();
			$id = $this->instanceActorService->getId();
		} catch (SocialAppConfigException $e) {
			return null;
		}

		if ($address === '' || strtolower($subject) !== strtolower($address . '@' . $address)) {
			return null;
		}

		$response = new JrdResponse('acct:' . $address . '@' . $address);
		$response->addAlias($id);
		$response->addLink('self', 'application/activity+json', $id);

		return $response;
	}

	/**
	 * handle request on /.well-known/nodeinfo
	 * returns Json
	 *
	 * @return IResponse|null
	 */
	private function handleNodeInfo(): ?IResponse {
		$response = new JrdResponse();

		// Every schema the controller serves, oldest first. Oldest first on
		// purpose: a consumer that reads this properly takes the highest
		// version it understands, and one that naively takes the first link
		// gets 2.0, which is what it got before 2.1 was reachable. Nothing
		// that reads this today is given something new to cope with.
		foreach (OAuthController::NODEINFO_SCHEMAS as $schema) {
			$response->addLink(
				'http://nodeinfo.diaspora.software/ns/schema/' . $schema,
				null,
				$this->configService->getSocialUrl() . '.well-known/nodeinfo/' . $schema
			);
		}

		return $response;
	}

	/**
	 * handle request on /.well-known/host-meta
	 * returns xml/xrd
	 *
	 * @return IResponse|null
	 */
	private function handleHostMeta(): ?IResponse {
		$response = new XrdResponse();
		try {
			$url = $this->configService->getCloudUrl(true) . '/.well-known/webfinger?resource={uri}';
		} catch (SocialAppConfigException $e) {
			return null;
		}

		$response->addLink('lrdd', $url);

		return $response;
	}

	private function getSubjectFromRequest(IRequest $request): string {
		$subject = $request->getParam('resource') ?? '';
		if ($subject !== '') {
			return $subject;
		}

		// work around to extract resource:
		// on some setup (i.e. tests) the data are not available from IRequest
		parse_str((string)parse_url($request->getRequestUri(), PHP_URL_QUERY), $query);

		return $query['resource'] ?? '';
	}
}
