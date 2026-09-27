<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\HostMetaException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Test;
use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Model\SimpleDataStore;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class TestService
 *
 * @package OCA\Social\Service
 */
class TestService {
	use TArrayTools;

	public function __construct(
		private CurlService $curlService,
	) {
	}

	public function testWebfinger(SimpleDataStore $tests) {
		$account = ltrim($tests->g('account'), '@');

		if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
			throw new InvalidResourceException('account format is not valid');
		}

		[$username, $host] = explode('@', $account);
		if ($username === null || $host === null) {
			throw new InvalidResourceException('account format should be valid');
		}

		$testHostMeta = new Test('host-meta', Test::SEVERITY_OPTIONAL);
		$protocols = ['https', 'http'];
		try {
			$path = $this->curlService->hostMeta($host, $protocols);
			$testHostMeta->s('host', $host);
			$testHostMeta->s('path', $path);
			$testHostMeta->sArray('protocol', $protocols);
			$testHostMeta->setSuccess(true);
		} catch (HostMetaException $e) {
			$testHostMeta->addMessage($e->getMessage());
			$path = '/.well-known/webfinger';
		}
		$tests->aObj('tests', $testHostMeta);

		$urls = array_map(
			static fn (string $protocol): string => $protocol . '://' . $host . $path
				. '?' . http_build_query(['resource' => 'acct:' . $account]),
			$protocols
		);

		$testWebfinger = new Test('webfinger', Test::SEVERITY_MANDATORY);
		$testWebfinger->sArray('request', $urls);
		$result = [];
		try {
			$result = $this->curlService->retrieveJsonFromFirstReachable($urls, ['json_headers' => false]);
			$testWebfinger->sArray('result', $result);
			$testWebfinger->setSuccess(true);
		} catch (Exception $e) {
			$testWebfinger->addMessage(get_class($e));
			$testWebfinger->addMessage($e->getMessage());
		}
		$tests->aObj('tests', $testWebfinger);

		$testActorLink = new Test('actor-link', Test::SEVERITY_MANDATORY);
		$link = [];
		try {
			$links = $this->getArray('links', $result);
			$link = $this->extractArray('rel', 'self', $links);
			$testActorLink->aArray('link', $link);
			$testActorLink->setSuccess(true);
		} catch (ArrayNotFoundException $e) {
			$testActorLink->addMessage(get_class($e));
			$testActorLink->addMessage('cannot find actor-link in links');
			$testActorLink->aArray('links', $links);
		}

		$tests->aObj('tests', $testActorLink);

		$id = $this->get('href', $link, '');

		$testActorData = new Test('actor-data', Test::SEVERITY_MANDATORY);
		$testActorData->a('id', $id);
		$data = [];
		try {
			$data = $this->curlService->retrieveObject($id);
			$testActorData->setSuccess(true);
			$testActorData->sArray('data', $data);
		} catch (Exception $e) {
			$testActorData->addMessage(get_class($e));
			$testActorData->addMessage($e->getMessage());
		}

		$tests->aObj('tests', $testActorData);

		$testActor = new Test('actor', Test::SEVERITY_MANDATORY);
		try {
			/** @var Person $actor */
			$actor = AP::instance()->getItemFromData($data);
			if (!AP::instance()->isActor($actor)) {
				throw new ItemUnknownException('Actor is not an Actor');
			}

			if (strtolower($actor->getId()) !== strtolower($id)) {
				throw new InvalidOriginException(
					'CurlService::retrieveAccount - id: ' . $id . ' - actorId: ' . $actor->getId()
				);
			}

			$testActor->setSuccess(true);
			$testActor->sObj('actor', $actor);
		} catch (Exception $e) {
			$testActor->addMessage(get_class($e));
			$testActor->addMessage($e->getMessage());
		}

		$tests->aObj('tests', $testActor);
	}
}
