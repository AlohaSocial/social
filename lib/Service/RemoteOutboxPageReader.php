<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;

/** Reads and validates one page of a remote ActivityPub outbox. */
class RemoteOutboxPageReader {
	public function __construct(
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{items: array, pageUrl: string, nextUrl: string, success: bool}
	 */
	public function read(Person $actor, string $pageUrl = ''): array {
		$result = ['items' => [], 'pageUrl' => '', 'nextUrl' => '', 'success' => false];
		$outboxUrl = $actor->getOutbox();
		if ($actor->isLocal() || $outboxUrl === '') {
			return $result;
		}

		try {
			$pageUrl = $pageUrl !== '' ? $pageUrl : $outboxUrl;
			if (!$this->isOutboxPageUrl($pageUrl, $outboxUrl)) {
				return $result;
			}

			$this->logger->debug('[RemoteOutboxPageReader] Fetching outbox page', [
				'url' => $pageUrl,
				'actor' => $actor->getId(),
			]);
			$outboxData = $this->curlService->retrieveObject($pageUrl);
			$pageData = $outboxData;

			if (isset($outboxData['first'])) {
				$first = $outboxData['first'];
				if (is_array($first) && (array_key_exists('items', $first) || array_key_exists('orderedItems', $first)
					|| in_array($first['type'] ?? '', ['CollectionPage', 'OrderedCollectionPage'], true))) {
					$pageData = $first;
					$id = $this->collectionPageLink($first);
					if ($id !== '') {
						$pageUrl = $this->resolvePageUrl($id, $pageUrl);
						if (!$this->isOutboxPageUrl($pageUrl, $outboxUrl)) {
							return $result;
						}
					}
				} else {
					$firstPage = $this->resolvePageUrl($this->collectionPageLink($first), $pageUrl);
					if ($firstPage === '' || !$this->isOutboxPageUrl($firstPage, $outboxUrl)) {
						return $result;
					}
					$this->logger->debug('[RemoteOutboxPageReader] Following first page', ['url' => $firstPage]);
					$pageUrl = $firstPage;
					$pageData = $this->curlService->retrieveObject($pageUrl);
				}
			}

			$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
			if (!is_array($items)) {
				$items = [];
			}

			$nextUrl = $this->collectionPageLink($pageData['next'] ?? null);
			if ($nextUrl !== '') {
				$nextUrl = $this->resolvePageUrl($nextUrl, $pageUrl);
				if ($nextUrl === '' || $nextUrl === $pageUrl || !$this->isOutboxPageUrl($nextUrl, $outboxUrl)) {
					$this->logger->warning('[RemoteOutboxPageReader] Rejected an outbox page outside the actor server', [
						'actor' => $actor->getId(),
					]);
					$nextUrl = '';
				}
			}

			$result = ['items' => $items, 'pageUrl' => $pageUrl, 'nextUrl' => $nextUrl, 'success' => true];
		} catch (Exception $e) {
			$this->logger->warning('[RemoteOutboxPageReader] Failed to fetch outbox', [
				'actor' => $actor->getId(),
				'error' => $e->getMessage(),
			]);
		}

		return $result;
	}

	/**
	 * Resolve collection references on the owner's server, never an embedded
	 * boost's foreign object. The regular Announce cache worker fetches that
	 * object from its own origin. A shared budget bounds one background page.
	 *
	 * @return array{item: ?array, deferred: bool}
	 */
	public function resolveEntry(Person $actor, mixed $entry, int &$remaining): array {
		$result = $this->resolveReference($actor, $entry, $remaining);
		$entry = $result['item'];
		if ($entry === null) {
			return $result;
		}
		if (in_array($entry['type'] ?? '', ['Create', 'Update'], true)
			&& (is_string($entry['object'] ?? null)
				|| (is_array($entry['object'] ?? null) && !isset($entry['object']['type'])))) {
			$activityActor = $entry['actor'] ?? '';
			$activityActor = is_array($activityActor) ? ($activityActor['id'] ?? '') : $activityActor;
			if ($activityActor !== $actor->getId()) {
				return ['item' => null, 'deferred' => false];
			}
			$object = $this->resolveReference($actor, $entry['object'], $remaining);
			if ($object['item'] === null) {
				return $object;
			}
			// Only objects, never recursively nested activity wrappers.
			if (!in_array($object['item']['type'] ?? '', ['Note', 'Question', 'Article', 'Page', 'Audio', 'Video', 'Event'], true)) {
				return ['item' => null, 'deferred' => false];
			}
			$entry['object'] = $object['item'];
		}

		return ['item' => $entry, 'deferred' => false];
	}

	/** @return array{item: ?array, deferred: bool} */
	private function resolveReference(Person $actor, mixed $entry, int &$remaining): array {
		$isReference = is_string($entry) || (is_array($entry)
			&& (!isset($entry['type']) || $entry['type'] === 'Link'));
		if ($isReference) {
			$url = $this->collectionPageLink($entry);
			if ($url === '' || !$this->isOutboxPageUrl($url, $actor->getId())) {
				return ['item' => null, 'deferred' => false];
			}
			if ($remaining <= 0) {
				return ['item' => null, 'deferred' => true];
			}
			$remaining--;
			$entry = $this->curlService->retrieveObject($url, true, 3);
			if (($entry['id'] ?? '') !== $url) {
				return ['item' => null, 'deferred' => false];
			}
		}

		return ['item' => is_array($entry) ? $entry : null, 'deferred' => false];
	}

	private function collectionPageLink(mixed $link): string {
		if (is_string($link)) {
			return trim($link);
		}
		if (is_array($link)) {
			$id = $link['id'] ?? $link['@id'] ?? $link['href'] ?? '';
			return is_string($id) ? trim($id) : '';
		}

		return '';
	}

	private function resolvePageUrl(string $link, string $baseUrl): string {
		if ($link === '') {
			return '';
		}
		if (preg_match('#^https?://#i', $link) === 1) {
			return $link;
		}
		$base = parse_url($baseUrl);
		if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
		if (str_starts_with($link, '//')) {
			return $base['scheme'] . ':' . $link;
		}
		if (str_starts_with($link, '/')) {
			return $origin . $link;
		}
		if (str_starts_with($link, '?')) {
			return $origin . ($base['path'] ?? '/') . $link;
		}

		$path = $base['path'] ?? '/';
		$directory = substr($path, 0, strrpos($path, '/') + 1);
		return $origin . $directory . $link;
	}

	private function isOutboxPageUrl(string $url, string $outboxUrl): bool {
		$page = parse_url($url);
		$outbox = parse_url($outboxUrl);
		if (!is_array($page) || !is_array($outbox)) {
			return false;
		}
		$scheme = strtolower($outbox['scheme'] ?? '');
		$pageScheme = strtolower($page['scheme'] ?? '');
		$defaultPort = $scheme === 'https' ? 443 : 80;
		$pagePort = $page['port'] ?? ($pageScheme === 'https' ? 443 : 80);
		$outboxPort = $outbox['port'] ?? $defaultPort;

		return in_array($scheme, ['http', 'https'], true)
			&& !isset($page['user']) && !isset($page['pass']) && !isset($page['fragment'])
			&& $pageScheme === $scheme
			&& strtolower($page['host'] ?? '') === strtolower($outbox['host'] ?? '')
			&& $pagePort === $outboxPort;
	}
}
