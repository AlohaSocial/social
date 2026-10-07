<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;
use OCP\ILogger;
use ParagonIE\ECC\ECCFactory;
use ParagonIE\ConstantTime\Base32;
use ParagonIE\ConstantTime\Hex;

class KeyManager {
	public function __construct(
		private readonly IConfig $config,
		private readonly ILogger $logger
	) {}
	
	public function generateSigningKey(): array {
		// Generate secp256k1 key pair
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$privateKey = $ecc->generatePrivateKey();
		$publicKey = $ecc->derivePublicKey($privateKey);
		
		// Encode as multibase multicodec (0xe7 for secp256k1)
		$publicKeyBytes = $publicKey->getPublicKey();
		$multicodecPrefix = hex2bin('e7');
		$encoded = $multicodecPrefix . $publicKeyBytes;
		$multibase = 'z' . Base32::encodeUpper($encoded);
		
		return [
			'private' => $privateKey->toString(),
			'public' => $publicKey->getPublicKey(),
			'multibase' => $multibase,
			'kid' => '#atproto'
		];
	}
	
	public function generateRotationKey(): array {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$privateKey = $ecc->generatePrivateKey();
		$publicKey = $ecc->derivePublicKey($privateKey);
		
		$publicKeyBytes = $publicKey->getPublicKey();
		$multicodecPrefix = hex2bin('e7');
		$encoded = $multicodecPrefix . $publicKeyBytes;
		$multibase = 'z' . Base32::encodeUpper($encoded);
		
		return [
			'private' => $privateKey->toString(),
			'public' => $publicKey->getPublicKey(),
			'multibase' => $multibase
		];
	}
	
	public function generateRecoveryPhrase(): string {
		// Generate 32 bytes of entropy for BIP-39
		$entropy = random_bytes(32);
		// This is simplified - real implementation would use BIP-39 wordlist
		return Base32::encodeUpper($entropy);
	}
	
	public function sealPrivateKey(string $privateKey): string {
		// Use instance secret to seal (similar to PrivateKeyCipher)
		$instanceSecret = $this->config->getSystemValue('secret', '');
		if (empty($instanceSecret)) {
			throw new \RuntimeException('Instance secret not configured');
		}
		
		$key = \Sodium::crypto_generichash($instanceSecret, '', 32);
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
		
		$key = \Sodium::crypto_generichash($instanceSecret, '', 32);
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
		
		// Ensure low-S normalization
		return $this->normalizeSignature($signature);
	}
	
	public function verify(string $data, string $signature, string $publicKey): bool {
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$key = $ecc->loadPublicKey($publicKey);
		return $key->verify($data, $signature);
	}
	
	private function normalizeSignature(string $signature): string {
		// Ensure low-S per RFC 6979
		$ecc = ECCFactory::getInstance()->getECC('secp256k1');
		$n = $ecc->getN();
		$r = Hex::decode(substr($signature, 0, 64));
		$s = Hex::decode(substr($signature, 64, 64));
		
		// Check if S > n/2
		if (gmp_cmp($s, gmp_div_q($n, 2)) > 0) {
			$s = gmp_sub($n, $s);
			return Hex::encodeUpper($r) . Hex::encodeUpper($s);
		}
		
		return $signature;
	}
}