<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;
use SpomkyLabs\Cbor\CborEncoder;
use ParagonIE\ConstantTime\Base32;

class AtprotoDid {
	private const PLC_DIRECTORY = 'https://plc.directory';
	
	public function __construct(
		private readonly IConfig $config
	) {}
	
	/**
	 * Generate a did:plc from a signed PLC operation
	 * Per spec: DID = "did:plc:" + base32(lowercase(SHA-256(signed_genesis_operation))[0:24])
	 */
	public static function generateFromSignedOperation(string $signedOperationCbor): string {
		$hash = hash('sha256', $signedOperationCbor, true);
		// Take first 24 bytes (192 bits) and encode as base32 lowercase
		$didSuffix = strtolower(Base32::encodeUpper(substr($hash, 0, 24)));
		return 'did:plc:' . $didSuffix;
	}
	
	/**
	 * Create a PLC genesis operation (unsigned)
	 * Per current PLC spec: type "plc_operation" with verificationMethods, rotationKeys, alsoKnownAs, services
	 */
	public static function createGenesisOperation(
		array $verificationMethods,
		array $rotationKeys,
		string $handle,
		string $pdsEndpoint
	): array {
		return [
			'type' => 'plc_operation',
			'verificationMethods' => $verificationMethods,
			'rotationKeys' => $rotationKeys,
			'alsoKnownAs' => ['at://' . $handle],
			'services' => [
				'#atproto_pds' => [
					'type' => 'AtprotoPersonalDataServer',
					'endpoint' => $pdsEndpoint
				]
			],
			'prev' => null
		];
	}
	
	/**
	 * Sign a PLC operation with the signing key
	 * Returns the signed operation CBOR
	 */
	public static function signPlcOperation(array $operation, string $signingPrivateKey): string {
		// Remove prev from operation for signing
		$toSign = $operation;
		unset($toSign['sig']);
		
		$encoded = CborEncoder::encode($toSign);
		
		// Sign with secp256k1 (using KeyManager would be better)
		// This is a placeholder - real implementation would use KeyManager
		$signature = self::signData($encoded, $signingPrivateKey);
		
		$operation['sig'] = $signature;
		return CborEncoder::encode($operation);
	}
	
	private static function signData(string $data, string $privateKey): string {
		// Would use ParagonIE\ECC for secp256k1 signing
		// Placeholder
		return str_repeat("\x00", 64);
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