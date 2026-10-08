<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Moderation;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Report;
use OCA\Social\Service\CurlService;
use OCA\Social\Tools\Nid;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A report about a Bluesky account or post, passed on to Bluesky's
 * moderation service (§12.3) when the person asks for it — the `forward`
 * of a Mastodon report. Like the Fediverse forward it is made by this
 * instance, never by the reporter: the token is signed by the instance's
 * own `did:web` with its service key, so the moderators there see this
 * server and the comment, and no name.
 */
class BlueskyReporter {
	private const METHOD = 'com.atproto.moderation.createReport';
	private const TIMEOUT = 5;
	private const REASONS = [
		Report::CATEGORY_SPAM => 'com.atproto.moderation.defs#reasonSpam',
		Report::CATEGORY_LEGAL => 'com.atproto.moderation.defs#reasonViolation',
		Report::CATEGORY_VIOLATION => 'com.atproto.moderation.defs#reasonViolation',
		Report::CATEGORY_OTHER => 'com.atproto.moderation.defs#reasonOther',
	];

	public function __construct(
		private AtprotoConfig $config,
		private InstanceKeyService $instanceKeys,
		private ServiceAuth $serviceAuth,
		private PlcClient $plc,
		private PostRefs $refs,
		private StreamRequest $streams,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	public function canReport(Report $report, Person $target): bool {
		return $this->config->isEnabled() && $report->isLocal() && BlueskyIds::isActorId($target->getId());
	}

	/**
	 * @return string the moderation service's id of the report, '' when it was not taken
	 */
	public function report(Report $report, Person $target): string {
		if (!$this->canReport($report, $target)) {
			return '';
		}
		$moderation = $this->config->moderationDid();
		try {
			$endpoint = $this->endpointOf($moderation);
			if ($endpoint === '') {
				$this->logger->warning('Bluesky moderation service names no endpoint', ['did' => $moderation]);

				return '';
			}
			$token = $this->serviceAuth->token($this->instanceKeys->serviceKey(), $this->config->serviceDid(), $moderation, self::METHOD);
			$status = 0;
			$answer = $this->curlService->doRequest('post', $endpoint . '/xrpc/' . self::METHOD, [
				'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
				'body' => (string)json_encode($this->body($report, $target), JSON_UNESCAPED_SLASHES),
				'timeout' => self::TIMEOUT,
				'json_headers' => false,
				'accept_errors' => true,
				'allow_local_address' => !str_starts_with($endpoint, 'https://'),
			], $contentType, $status);
		} catch (Throwable $e) {
			$this->logger->warning('Report not passed on to Bluesky', ['report' => $report->getId(), 'exception' => $e]);

			return '';
		}
		$decoded = json_decode($answer, true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$this->logger->warning('Bluesky moderation refused a report (' . (int)$status . '): ' . substr(trim($answer), 0, 300), ['report' => $report->getId(), 'status' => $status]);

			return '';
		}
		$id = (string)($decoded['id'] ?? '');
		$this->logger->info('Report passed on to Bluesky', ['report' => $report->getId(), 'bluesky_report' => $id]);

		return $id === '' ? 'accepted' : $id;
	}

	/**
	 * The report as `createReport` takes it: the first reported post that
	 * is on Bluesky as the subject, else the account.
	 */
	public function body(Report $report, Person $target): array {
		$subject = ['$type' => 'com.atproto.admin.defs#repoRef', 'did' => BlueskyIds::didOf($target->getId())];
		foreach ($report->getStatusIds() as $statusId) {
			$ref = $this->refs->strongRef($this->postIdOf((string)$statusId));
			if ($ref !== null) {
				$subject = ['$type' => 'com.atproto.repo.strongRef'] + $ref;
				break;
			}
		}
		$body = [
			'reasonType' => self::REASONS[$report->getCategory()] ?? self::REASONS[Report::CATEGORY_OTHER],
			'subject' => $subject,
		];
		$comment = trim($report->getComment());
		if ($comment !== '') {
			$body['reason'] = self::clip($comment);
		}

		return $body;
	}

	/**
	 * A reported status by the id a client knows it by — its number — or by
	 * its address, as a report that came from elsewhere names it.
	 */
	private function postIdOf(string $statusId): string {
		if (!ctype_digit($statusId)) {
			return $statusId;
		}
		try {
			return $this->streams->getStreamByNid(Nid::fromStorage($statusId))->getId();
		} catch (Throwable) {
			return '';
		}
	}

	private function endpointOf(string $did): string {
		$document = $this->plc->document($did) ?? [];
		foreach (is_array($document['service'] ?? null) ? $document['service'] : [] as $service) {
			if (is_array($service) && ($service['id'] ?? '') === '#atproto_labeler') {
				return rtrim((string)($service['serviceEndpoint'] ?? ''), '/');
			}
		}

		return '';
	}

	/** The lexicon's limit: 2,000 graphemes, 20,000 bytes. */
	private static function clip(string $text): string {
		if (Lexicon::graphemes($text) > 2000 && preg_match_all('/\X/u', $text, $graphemes) > 0) {
			$text = implode('', array_slice($graphemes[0], 0, 2000));
		}

		return strlen($text) > 20000 ? mb_strcut($text, 0, 20000) : $text;
	}
}
