<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Response\StreamedRemoteResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `/xrpc/<method>`: the AT Protocol surface of this instance as a PDS.
 *
 * Reached at the root through the web-server rules in contrib/webserver,
 * the way `/api/` is; the firehose (`subscribeRepos`) is a WebSocket the
 * daemon serves and the web server proxies to it. Every answer is JSON
 * with an `{error, message}` body on failure, or the bytes of a CAR file
 * or a blob, and carries the CORS header a web client needs.
 */
class AtprotoXrpcController extends Controller {
	public function __construct(
		IRequest $request,
		private XrpcService $xrpc,
		private ClientXrpc $client,
		private DpopNonce $dpopNonce,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /xrpc/{method}`: a query.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/{method}', requirements: ['method' => '[a-zA-Z0-9._-]+'])]
	public function query(string $method): Response {
		try {
			$answer = $this->client->query($method, $_SERVER['QUERY_STRING'] ?? '', $this->headers());

			return $this->answer($answer ?? $this->xrpc->query($method, $this->request->getParams()));
		} catch (XrpcException $e) {
			return $this->refuse($e);
		} catch (Throwable $e) {
			$this->logger->error('XRPC query failed', ['method' => $method, 'exception' => $e]);

			return $this->refuse(new XrpcException(500, 'InternalServerError', 'Internal server error'));
		}
	}

	/**
	 * `POST /xrpc/{method}`: a procedure.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/xrpc/{method}', requirements: ['method' => '[a-zA-Z0-9._-]+'], postfix: 'procedure')]
	public function procedure(string $method): Response {
		try {
			if ($method === ClientXrpc::UPLOAD) {
				return $this->upload();
			}
			$raw = file_get_contents('php://input', false, null, 0, ClientXrpc::MAX_BLOB + 1);
			$raw = $raw === false ? '' : $raw;
			$answer = $this->client->procedure($method, $raw, $this->headers(), $this->request->getRemoteAddress());
			if ($answer !== null) {
				return $this->answer($answer);
			}
			$body = json_decode($raw, true);

			return $this->answer($this->xrpc->procedure($method, is_array($body) ? $body : []));
		} catch (XrpcException $e) {
			return $this->refuse($e);
		} catch (Throwable $e) {
			$this->logger->error('XRPC procedure failed', ['method' => $method, 'exception' => $e]);

			return $this->refuse(new XrpcException(500, 'InternalServerError', 'Internal server error'));
		}
	}

	/**
	 * `com.atproto.repo.uploadBlob`: the body is copied to a file as it
	 * arrives, never held in memory, and refused past the largest blob a
	 * record may name.
	 *
	 * @throws XrpcException
	 */
	private function upload(): Response {
		$path = tempnam(sys_get_temp_dir(), 'social-atproto-upload-');
		if ($path === false) {
			throw new XrpcException(500, 'InternalServerError', 'No room for the upload');
		}
		try {
			$in = fopen('php://input', 'rb');
			$out = fopen($path, 'wb');
			if ($in === false || $out === false) {
				throw new XrpcException(500, 'InternalServerError', 'No room for the upload');
			}
			$copied = stream_copy_to_stream($in, $out, ClientXrpc::MAX_UPLOAD + 1);
			fclose($in);
			fclose($out);
			if ($copied === false || $copied > ClientXrpc::MAX_UPLOAD) {
				throw new XrpcException(413, 'BlobTooLarge', 'This file is too large');
			}

			return $this->answer($this->client->upload($path, $this->headers()));
		} finally {
			@unlink($path);
		}
	}

	/**
	 * `OPTIONS /xrpc/{method}`: the preflight a browser sends.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'OPTIONS', url: '/xrpc/{method}', requirements: ['method' => '[a-zA-Z0-9._-]+'], postfix: 'preflight')]
	public function preflight(string $method): Response {
		return $this->cors(new DataResponse(null, Http::STATUS_NO_CONTENT));
	}

	/**
	 * The headers a signed-in call and the AppView proxy read.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		$headers = [];
		foreach (['authorization', 'dpop', 'atproto-proxy', 'atproto-accept-labelers', 'accept-language', 'content-type'] as $name) {
			$value = $this->request->getHeader($name);
			if ($value !== '') {
				$headers[$name] = $value;
			}
		}

		return $headers;
	}

	private function answer(array|XrpcBytes $result): Response {
		if ($result instanceof XrpcBytes && is_resource($result->stream)) {
			$headers = ['Content-Type' => $result->contentType, 'X-Content-Type-Options' => 'nosniff'];
			if ($result->length >= 0) {
				$headers['Content-Length'] = (string)$result->length;
			}
			$response = new StreamedRemoteResponse($result->stream, $result->status, $headers);
			$response->cacheFor(60, false, true);

			return $this->cors($response);
		}
		if ($result instanceof XrpcBytes) {
			$response = new DataDisplayResponse($result->bytes, $result->status, ['Content-Type' => $result->contentType]);
			if ($result->status === Http::STATUS_OK) {
				$response->cacheFor(60, false, true);
			}

			return $this->cors($response);
		}

		return $this->cors(new DataResponse($result));
	}

	private function refuse(XrpcException $e): Response {
		$response = new DataResponse($e->toArray(), $e->status);
		foreach ($e->headers as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $this->cors($response);
	}

	/**
	 * The CORS headers a browser app needs, and the DPoP nonce on every
	 * answer to a request that carried a proof, so the app always has the
	 * current one.
	 */
	private function cors(Response $response): Response {
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, DPoP, atproto-accept-labelers, atproto-proxy');
		$response->addHeader('Access-Control-Expose-Headers', 'DPoP-Nonce, WWW-Authenticate');
		$response->addHeader('Access-Control-Max-Age', '600');
		if ($this->request->getHeader('DPoP') !== '' && !isset($response->getHeaders()['DPoP-Nonce'])) {
			$response->addHeader('DPoP-Nonce', $this->dpopNonce->current());
		}

		return $response;
	}
}
