<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;
use OCP\ILogger;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

class PlcClient {
	private const MAX_RETRIES = 5;
	private const BASE_DELAY_MS = 1000;
	private const MAX_DELAY_MS = 30000;
	
	public function __construct(
		private readonly IConfig $config,
		private readonly ILogger $logger
	) {}
	
	public function submitOperation(string $did, array $operation): bool {
		return $this->retryWithBackoff(
			fn() => $this->doSubmitOperation($did, $operation),
			"submit PLC operation for $did (type: {$operation['type'] ?? 'unknown'})"
		);
	}
	
	public function submitOperationWithRetry(string $did, array $operation, int $maxRetries = self::MAX_RETRIES): array {
		$lastError = null;
		
		for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
			$result = $this->doSubmitOperation($did, $operation);
			if ($result['success']) {
				return ['success' => true, 'attempt' => $attempt + 1];
			}
			
			$lastError = $result['error'] ?? 'Unknown error';
			
			// Don't retry on client errors (4xx) except 429
			if (isset($result['status']) && $result['status'] >= 400 && $result['status'] < 500 && $result['status'] !== 429) {
				break;
			}
			
			if ($attempt < $maxRetries) {
				$delay = min(
					self::BASE_DELAY_MS * (2 ** $attempt) + random_int(0, 1000),
					self::MAX_DELAY_MS
				);
				$this->logger->warning('PLC operation failed, retrying', [
					'did' => $did,
					'attempt' => $attempt + 1,
					'maxRetries' => $maxRetries,
					'delayMs' => $delay,
					'error' => $lastError
				]);
				usleep($delay * 1000);
			}
		}
		
		return ['success' => false, 'error' => $lastError];
	}
	
	private function doSubmitOperation(string $did, array $operation): array {
		$plcDirectory = $this->config->getAppValue('social', 'atproto_plc_directory', 'https://plc.directory');
		$url = $plcDirectory . '/' . $did;
		
		$httpClient = new HttpClient([
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept' => 'application/json'
			]
		]);
		
		try {
			$response = $httpClient->post($url, [
				'json' => $operation
			]);
			
			$statusCode = $response->getStatusCode();
			if ($statusCode === 200 || $statusCode === 201) {
				return ['success' => true];
			}
			
			return [
				'success' => false,
				'status' => $statusCode,
				'error' => "PLC directory returned status $statusCode"
			];
		} catch (RequestException $e) {
			$status = $e->getResponse()?->getStatusCode();
			return [
				'success' => false,
				'status' => $status,
				'error' => $e->getMessage()
			];
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}
	
	public function resolveDid(string $did): ?array {
		return $this->retryWithBackoff(
			fn() => $this->doResolveDid($did),
			"resolve DID $did"
		);
	}
	
	private function doResolveDid(string $did): ?array {
		$plcDirectory = $this->config->getAppValue('social', 'atproto_plc_directory', 'https://plc.directory');
		$url = $plcDirectory . '/' . $did;
		
		$httpClient = new HttpClient(['timeout' => 10]);
		
		try {
			$response = $httpClient->get($url);
			if ($response->getStatusCode() === 200) {
				return json_decode($response->getBody()->getContents(), true);
			}
		} catch (\Throwable $e) {
			$this->logger->error('PLC DID resolution failed', [
				'did' => $did,
				'error' => $e->getMessage()
			]);
		}
		
		return null;
	}
	
	public function getOperationLog(string $did): array {
		return $this->retryWithBackoff(
			fn() => $this->doGetOperationLog($did),
			"get PLC operation log for $did"
		) ?? [];
	}
	
	private function doGetOperationLog(string $did): ?array {
		$plcDirectory = $this->config->getAppValue('social', 'atproto_plc_directory', 'https://plc.directory');
		$url = $plcDirectory . '/' . $did . '/log/audit';
		
		$httpClient = new HttpClient(['timeout' => 10]);
		
		try {
			$response = $httpClient->get($url);
			if ($response->getStatusCode() === 200) {
				return json_decode($response->getBody()->getContents(), true) ?? [];
			}
		} catch (\Throwable $e) {
			$this->logger->error('PLC operation log fetch failed', [
				'did' => $did,
				'error' => $e->getMessage()
			]);
		}
		
		return null;
	}
	
	/**
	 * Execute a callable with exponential backoff retry logic
	 * 
	 * @template T
	 * @param callable(): T $callable
	 * @param string $description
	 * @return T|null
	 */
	private function retryWithBackoff(callable $callable, string $description): mixed {
		$lastError = null;
		
		for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
			try {
				return $callable();
			} catch (\Throwable $e) {
				$lastError = $e;
				
				if ($attempt < self::MAX_RETRIES) {
					$delay = min(
						self::BASE_DELAY_MS * (2 ** $attempt) + random_int(0, 1000),
						self::MAX_DELAY_MS
					);
					$this->logger->warning("$description failed, retrying", [
						'attempt' => $attempt + 1,
						'maxRetries' => self::MAX_RETRIES,
						'delayMs' => $delay,
						'error' => $e->getMessage()
					]);
					usleep($delay * 1000);
				}
			}
		}
		
		$this->logger->error("$description failed after all retries", [
			'maxRetries' => self::MAX_RETRIES,
			'error' => $lastError?->getMessage()
		]);
		
		return null;
	}
}