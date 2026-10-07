<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;

class AtprotoDid {
	private const PLC_DIRECTORY = 'https://plc.directory';
	
	public function __construct(
		private readonly IConfig $config
	) {}
	
	public static function generate(string $signingPublicKey, array $rotationKeys, string $handle, string $pdsEndpoint): string {
		// The DID is computed from the genesis operation
		// Genesis operation structure per atproto spec
		$genesisOp = [
			'type' => 'create',
			'signingKey' => $signingPublicKey,
			'rotationKeys' => $rotationKeys,
			'handle' => $handle,
			'service' => [
				'#atproto_pds' => [
					'type' => 'AtprotoPersonalDataServer',
					'endpoint' => $pdsEndpoint
				]
			],
			'prev' => null
		];
		
		// DID is hash of genesis operation
		$operationBytes = \Sodium::base642bin(
			\SpomkyLabs\Cbor\CborEncoder::encode($genesisOp),
			SODIUM_BASE64_VARIANT_ORIGINAL
		);
		$hash = \Sodium::crypto_generichash($operationBytes, '', 32);
		$didSuffix = \Sodium::bin2base32($hash);
		
		return 'did:plc:' . strtolower($didSuffix);
	}
	
	public static function parse(string $did): ?array {
		if (!preg_match('/^did:plc:([a-z2-7]{24})$/', $did, $matches)) {
			return null;
		}
		return [
			'method' => 'plc',
			'suffix' => $matches[1]
		];
	}
	
	public function getPlcDirectory(): string {
		return $this->config->getAppValue('social', 'atproto_plc_directory', self::PLC_DIRECTORY);
	}
	
	public function resolve(string $did): ?array {
		$url = $this->getPlcDirectory() . '/' . $did;
		$response = @file_get_contents($url);
		if ($response === false) {
			return null;
		}
		return json_decode($response, true);
	}
	
	public function submitOperation(string $did, array $operation): bool {
		$url = $this->getPlcDirectory() . '/' . $did;
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($operation),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Accept: application/json'
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		return $httpCode === 200 || $httpCode === 201;
	}
}