<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as HttpClient;

class PlcClient {
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger
	) {}
	
	public function submitOperation(string $did, array $operation): bool {
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
			return $statusCode === 200 || $statusCode === 201;
		} catch (\Throwable $e) {
			$this->logger->error('PLC operation failed', [
				'did' => $did,
				'operation' => $operation['type'] ?? 'unknown',
				'error' => $e->getMessage()
			]);
			return false;
		}
	}
	
	public function resolveDid(string $did): ?array {
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
		
		return [];
	}
}