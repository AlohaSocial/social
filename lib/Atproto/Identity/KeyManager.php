<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;
use OCP\ILogger;
use ParagonIE\ECC\ECCFactory;
use ParagonIE\ConstantTime\Base58;
use ParagonIE\ConstantTime\Hex;

class KeyManager {
	public function __construct(
		private readonly IConfig $config,
		private readonly ILogger $logger
	) {}
	
	public function generateSigningKey(): array {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$privateKey = $ecc->generatePrivateKey();
		$publicKey = $ecc->derivePublicKey($privateKey);
		
		// Encode as did:key:z... (multibase base58btc)
		$publicKeyBytes = $publicKey->getPublicKey(); // 33 bytes compressed (0x02/0x03 + x)
		$multicodecPrefix = hex2bin('e7'); // 0xe7 = p256k1 pubkey
		$keyData = $multicodecPrefix . $publicKeyBytes;
		$multibase = 'z' . Base58::encode($keyData);
		$didKey = 'did:key:' . $multibase;
		
		return [
			'private' => $privateKey->toString(),
			'public' => $publicKey->getPublicKey(),
			'multibase' => $multibase,
			'didKey' => $didKey,
			'kid' => '#atproto'
		];
	}
	
	public function generateRotationKey(): array {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$privateKey = $ecc->generatePrivateKey();
		$publicKey = $ecc->derivePublicKey($privateKey);
		
		$publicKeyBytes = $publicKey->getPublicKey();
		$multicodecPrefix = hex2bin('e7');
		$keyData = $multicodecPrefix . $publicKeyBytes;
		$multibase = 'z' . Base58::encode($keyData);
		$didKey = 'did:key:' . $multibase;
		
		return [
			'private' => $privateKey->toString(),
			'public' => $publicKey->getPublicKey(),
			'multibase' => $multibase,
			'didKey' => $didKey
		];
	}
	
	public function generateRecoveryPhrase(): string {
		// Generate 32 bytes of entropy for BIP-39
		$entropy = random_bytes(32);
		// This is simplified - real implementation would use BIP-39 wordlist
		return Base58::encode($entropy);
	}
	
	public function sealPrivateKey(string $privateKey): string {
		$instanceSecret = $this->config->getSystemValue('secret', '');
		if (empty($instanceSecret)) {
			throw new \RuntimeException('Instance secret not configured');
		}
		
		$key = hash('sha256', $instanceSecret . '_atproto_key_seal', true);
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$ciphertext = \Sodium::crypto_aead_xchacha20poly1305_ietf_encrypt(
			$privateKey,
			'',
			$nonce,
			$key
		);
		
		return $nonce . $ciphertext;
	}
	
	public function unsealPrivateKey(string $sealedKey): string {
		$instanceSecret = $this->config->getSystemValue('secret', '');
		if (empty($instanceSecret)) {
			throw new \RuntimeException('Instance secret not configured');
		}
		
		$key = hash('sha256', $instanceSecret . '_atproto_key_seal', true);
		$nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		$nonce = substr($sealedKey, 0, $nonceLen);
		$ciphertext = substr($sealedKey, $nonceLen);
		
		$plaintext = \Sodium::crypto_aead_xchacha20poly1305_ietf_decrypt(
			$ciphertext,
			'',
			$nonce,
			$key
		);
		
		if ($plaintext === false) {
			throw new \RuntimeException('Failed to unseal private key');
		}
		
		return $plaintext;
	}
	
	public function sign(string $data, string $privateKey): string {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$key = $ecc->loadPrivateKey($privateKey);
		$signature = $key->sign($data);
		
		// Ensure low-S normalization per RFC 6979
		return $this->normalizeSignature($signature);
	}
	
	public function verify(string $data, string $signature, string $publicKey): bool {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$key = $ecc->loadPublicKey($publicKey);
		return $key->verify($data, $signature);
	}
	
	private function normalizeSignature(string $signature): string {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$n = $ecc->getN();
		$r = Hex::decode(substr($signature, 0, 64));
		$s = Hex::decode(substr($signature, 64, 64));
		
		if (gmp_cmp($s, gmp_div_q($n, 2)) > 0) {
			$s = gmp_sub($n, $s);
			return Hex::encodeUpper($r) . Hex::encodeUpper($s);
		}
		
		return $signature;
	}
	
	/**
	 * Get JWT signing key derived from instance secret
	 */
	public function getJwtSigningKey(): string {
		$instanceSecret = $this->config->getSystemValue('secret', '');
		if (empty($instanceSecret)) {
			throw new \RuntimeException('Instance secret not configured');
		}
		return hash('sha256', $instanceSecret . '_atproto_jwt_hmac', true);
	}
}