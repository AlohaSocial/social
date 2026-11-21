<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Moderation\BlueskyReporter;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\Report;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use Psr\Log\LoggerInterface;

/**
 * Passes a local report on to the instance the reported account is on, when
 * the reporter ticks `forward`.
 *
 * Signed by this instance's actor, never the reporter, as Mastodon does, so
 * the remote admins see the server, not a name to retaliate against; the
 * comment is passed on as written. Sent inline with
 * `HttpSignatureService::signAsInstance()` rather than through
 * `social_request_queue`, whose signing looks the key up in `oc_social_actor`,
 * where the instance actor is not (see `InstanceActorService`). A failure
 * costs the forward and nothing else.
 */
class ReportForwardService {
	/**
	 * The report is already stored and the user is waiting on the response, so
	 * an unreachable instance has to give up quickly. Matches the timeout
	 * `ActivityService` gives its own inline delivery.
	 */
	private const TIMEOUT = 3;

	public function __construct(
		private InstanceActorService $instanceActorService,
		private HttpSignatureService $httpSignatureService,
		private CurlService $curlService,
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
		private ?BlueskyReporter $bluesky = null,
	) {
	}

	/**
	 * Whether the report can be forwarded at all: one of ours, about an account
	 * somewhere else that publishes an inbox. A report that arrived as a `Flag`
	 * is not ours to pass on, and forwarding it could loop.
	 */
	public function canForward(Report $report, Person $target): bool {
		if ($this->bluesky !== null && BlueskyIds::isActorId($target->getId())) {
			return $this->bluesky->canReport($report, $target);
		}

		return $report->isLocal()
			&& !$target->isLocal()
			&& $this->inboxOf($target) !== '';
	}

	/**
	 * Delivers the anonymised `Flag`.
	 *
	 * @return bool whether the remote instance accepted it — which is what the
	 *              report's `forwarded` reports, so it must not be optimistic
	 */
	public function forward(Report $report, Person $target): bool {
		if (!$this->canForward($report, $target)) {
			return false;
		}
		if ($this->bluesky !== null && BlueskyIds::isActorId($target->getId())) {
			// Bluesky's moderation service, not an inbox: see BlueskyReporter
			return $this->bluesky->report($report, $target) !== '';
		}

		$actor = $this->instanceActorService->getSigningActor();
		if ($actor === null) {
			// this instance cannot sign as itself, and signing as the reporter
			// is the one thing forwarding must never do
			$this->logger->warning('cannot forward a report: no instance actor to sign it', [
				'report' => $report->getId(),
			]);

			return false;
		}

		$inbox = $this->inboxOf($target);
		$body = json_encode($this->flag($report, $target, $actor), JSON_UNESCAPED_SLASHES);

		try {
			$this->curlService->retrieveJson('post', $inbox, [
				'headers' => $this->httpSignatureService->signAsInstance($inbox, (string)$body),
				'body' => (string)$body,
				'timeout' => self::TIMEOUT,
			]);
		} catch (RequestResultNotJsonException $e) {
			// an inbox answers 202 with an empty body, which is a success and
			// not a document — the delivery queue reads it the same way
		} catch (\Exception $e) {
			$this->logger->warning('could not forward a report', [
				'report' => $report->getId(), 'inbox' => $inbox, 'exception' => $e,
			]);

			return false;
		}

		$this->logger->info('report forwarded', ['report' => $report->getId(), 'inbox' => $inbox]);

		return true;
	}

	/**
	 * The activity: this instance's actor flagging the reported account and
	 * the reported statuses, with the reporter's comment and nothing that
	 * identifies them, addressed `to` the reported account as Mastodon does.
	 */
	private function flag(Report $report, Person $target, InstanceActor $actor): Flag {
		$flag = new Flag();
		$flag->setId($actor->getId() . '#reports/' . $report->getId())
			->setActorId($actor->getId())
			->setTo($target->getId());
		$flag->setObjectIds(array_merge([$target->getId()], $this->addressesOf($report)))
			->setContent($report->getComment());

		return $flag;
	}

	/**
	 * The reported posts by their ActivityPub ids, as the receiving server
	 * knows them, not this API's nids. A local post is left out: it is not
	 * evidence about the remote account and not another instance's business.
	 *
	 * @return string[]
	 */
	private function addressesOf(Report $report): array {
		$addresses = [];

		foreach ($report->getStatusIds() as $statusId) {
			if (str_starts_with($statusId, 'http://') || str_starts_with($statusId, 'https://')) {
				// already an address: a report that arrived as a `Flag` names
				// them this way, and so does anything that knew better
				$addresses[] = $statusId;
				continue;
			}

			if (!ctype_digit($statusId)) {
				continue;
			}

			try {
				$stream = $this->streamRequest->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($statusId));
			} catch (\Exception $e) {
				// a post this instance no longer holds; the account is still
				// named, which is the part the report is about
				continue;
			}

			if ($stream->isLocal() || $stream->getId() === '') {
				continue;
			}

			$addresses[] = $stream->getId();
		}

		return array_values(array_unique($addresses));
	}

	/**
	 * A personal inbox first: a `Flag` is about one account, and an instance
	 * that publishes both routes it to that account's moderators either way.
	 */
	private function inboxOf(Person $target): string {
		$inbox = $target->getInbox();

		return ($inbox !== '') ? $inbox : $target->getSharedInbox();
	}
}
