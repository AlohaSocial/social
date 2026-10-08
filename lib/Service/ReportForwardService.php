<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Moderation\BlueskyReporter;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\Report;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use Psr\Log\LoggerInterface;

/**
 * Passing a local report on to the instance the reported account is on.
 *
 * `POST /api/v1/reports` takes `forward`, and a user who ticks it is asking
 * for the one thing this instance cannot do for them: act on an account it
 * does not host. Without this, ticking the box did nothing at all and the
 * report stopped at our own moderators — the remote instance, the only one
 * that can suspend the account, was never told.
 *
 * **Who signs it.** The instance, never the reporter. The `Flag` names this
 * server's own actor and is signed with its key, so the receiving moderators
 * see "cloud.example reported this" and not the account that filed it. That is
 * Mastodon's behaviour and the reason for it is the whole point: the person
 * is reporting an account on that very instance, whose admins may be hostile
 * to them, and a report that carried their handle would hand those admins a
 * name to retaliate against. The comment is passed on as written — the user is
 * told the report is being forwarded, and can keep it to our moderators by not
 * ticking the box.
 *
 * **Delivery.** Sent inline rather than through `social_request_queue`, and
 * this is the one thing here that is a compromise: a queued delivery is signed
 * by `HttpSignatureService::signDelivery()`, which resolves the signing key
 * from `oc_social_actor` by the queue row's author — and the instance actor is
 * deliberately not a row there (see `InstanceActorService`). So the one
 * activity that must be signed as the server is the one the queue cannot sign,
 * and it goes out inline with `HttpSignatureService::signAsInstance()`. One
 * report is one POST with a short timeout, it never blocks storing the report,
 * and a failure costs the forward and nothing else.
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
	 * Whether the report can be forwarded at all: it has to be one of ours,
	 * about an account somewhere else, that publishes an inbox.
	 *
	 * A report that arrived as a `Flag` is not ours to pass on — forwarding it
	 * again would put this instance's name on somebody else's complaint, and
	 * two instances doing that to each other is a loop.
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
	 * identifies them.
	 *
	 * Addressed `to` the reported account, the way Mastodon addresses one, so
	 * an instance that routes by audience delivers it to the right moderators.
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
	 * The reported posts, by the addresses the receiving server knows them by.
	 *
	 * A client reports a post by the id **this** API gave it — a snowflake nid
	 * — and those ids were being put into the `Flag` as they arrived. No other
	 * server has ever seen them, so every forwarded report named one account
	 * the receiving moderators could find and a list of numbers they could not:
	 * a report about an account read as a complaint with no evidence attached,
	 * and a report about a **video**, where the video is the entire complaint,
	 * carried nothing at all. PeerTube resolves an abuse by the video's own
	 * address and would have found nothing every time.
	 *
	 * A local post is left out rather than translated. The statuses in a report
	 * about a remote account are that account's posts; one of ours in the list
	 * is a reply somebody picked up by mistake, and naming it would be telling
	 * another instance's moderators about a post of our own.
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
