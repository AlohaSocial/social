<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\WellKnown;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\WellKnown\GenericResponse;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;

/**
 * `/.well-known/aloha-directory`: the domain proof of an enrolment in the
 * Aloha Directory. While an enrolment waits for it, the directory fetches the
 * challenge and the id of the key that enrolled; otherwise there is nothing.
 */
class AlohaDirectoryHandler implements IHandler {
	public const SERVICE = 'aloha-directory';

	public function __construct(
		private DirectoryConfig $config,
	) {
	}

	#[\Override]
	public function handle(string $service, IRequestContext $context, ?IResponse $previousResponse): ?IResponse {
		if (strtolower($service) !== self::SERVICE) {
			return $previousResponse;
		}

		$challenge = $this->config->challenge();
		$key = $this->config->key(DirectoryConfig::PENDING_KEY);
		if ($challenge === '' || $key === null) {
			return $previousResponse;
		}

		$response = new JSONResponse(['challenge' => $challenge, 'key_id' => $key->keyId()]);
		$response->addHeader('Cache-Control', 'no-store');

		return new GenericResponse($response);
	}
}
