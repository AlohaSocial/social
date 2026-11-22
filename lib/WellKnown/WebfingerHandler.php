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

		// ActivityPub profile. The links name the actor exactly as its own
		// document does, from the stored id rather than the request's host,
		// which may be another trusted domain.
		$href = $actor->getId();
		if ($href === '') {
			return new JrdResponse('', Http::STATUS_NOT_FOUND);
		}

		// The subject is the account's own handle, never the query echoed
		// back (`acct:@maya@host` would confuse consumers splitting on `@`).
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

		// `rel=profile-page` is the Social profile, which a peer opens when
		// somebody clicks the handle; the Nextcloud user page stays an alias.
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
		$this->addFollowIntent($response);

		return $response;
	}

	/** FEP-3b86 advertises the existing confirmation flow to other services. */
	private function addFollowIntent(JrdResponse $response): void {
		$response->addLink('https://w3id.org/fep/3b86/Follow', '', '', null, null, [
			'template' => $this->configService->getSocialUrl() . 'ostatus/follow/?uri={object}',
		]);
	}

	/**
	 * The instance's own `Application` actor, answered for
	 * `acct:<host>@<host>`, Mastodon's instance actor handle, which peers look
	 * up for a `keyId` from here. Answered before any local account, so a
	 * user named like the host cannot take it.
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
		$this->addFollowIntent($response);

		return $response;
	}

	/**
	 * handle request on /.well-known/nodeinfo
	 * returns Json
	 *
	 * @return IResponse|null
	 */
	private function handleNodeInfo(): ?IResponse {
		// read by browser clients of any origin, as the documents it links to
		$response = new JrdResponse();
		$response->addHeader('Access-Control-Allow-Origin', '*');

		// Every schema served, oldest first: proper consumers take the highest
		// they understand, naive ones the first, which is 2.0.
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
