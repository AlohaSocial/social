<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\WellKnown;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\Http\WellKnown\IResponse;

final class XrdResponse implements IResponse {
	private int $httpCode;

	/** @var mixed[] */
	private array $links = [];

	public function __construct(int $httpCode = Http::STATUS_OK) {
		$this->httpCode = $httpCode;
	}

	/**
	 * Add a link
	 *
	 * @param string $rel
	 * @param string $template
	 *
	 * @return XrdResponse
	 */
	public function addLink(string $rel, string $template): self {
		$this->links[] = [
			'rel' => $rel,
			'template' => $template
		];

		return $this;
	}

	#[\Override]
	public function toHttpResponse(): Response {
		$data = [];
		$data[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$data[] = '<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">';

		foreach ($this->links as $link) {
			$data[] = '  <Link rel="' . $link['rel'] . '"  template="' . $link['template'] . '"/>';
		}

		$data[] = '</XRD>';

		$response = new TextPlainResponse(implode("\n", $data) . "\n", $this->httpCode);
		$response->addHeader('Content-Type', 'application/xrd+xml'); // overwrite default header

		return $response;
	}
}
