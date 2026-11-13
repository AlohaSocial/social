<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Service\CurlService;

/**
 * A CurlService that answers from a script and records what was sent.
 */
final class FakeCurl extends CurlService {
	/** @var array<string, array{0: int, 1: string}> "METHOD url" => [status, body] */
	public array $answers = [];
	/** @var list<array{method: string, url: string, options: array<string, mixed>}> */
	public array $sent = [];

	public function __construct() {
	}

	public function answer(string $method, string $url, int $status, string|array $body): void {
		$this->answers[strtoupper($method) . ' ' . $url] = [$status, is_array($body) ? (string)json_encode($body) : $body];
	}

	#[\Override]
	public function doRequest(string $method, string $url, array $options = [], ?string &$contentType = null, ?int &$statusCode = null): string {
		$this->sent[] = ['method' => strtoupper($method), 'url' => $url, 'options' => $options];
		$answer = $this->answers[strtoupper($method) . ' ' . $url] ?? null;
		if ($answer === null) {
			throw new RequestNetworkException('unreachable: ' . $url);
		}
		$statusCode = $answer[0];
		$contentType = 'application/json';

		return $answer[1];
	}
}
