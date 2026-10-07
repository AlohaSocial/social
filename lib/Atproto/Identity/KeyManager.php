<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use Mdanter\Ecc\Crypto\Key\PublicKey;
use Mdanter\Ecc\Crypto\Signature\Signature;
use Mdanter\Ecc\Crypto\Signature\Signer;
use Mdanter\Ecc\Curves\SecureCurveFactory;
use Mdanter\Ecc\Math\GmpMath;
use Mdanter\Ecc\Random\HmacRandomNumberGenerator;
use Mdanter\Ecc\Serializer\Point\CompressedPointSerializer;
use Mdanter\Ecc\Serializer\Signature\IEEEP1363Serializer;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class KeyManager {
	public function __construct(
		private readonly ?IConfig $config,
		private readonly ?LoggerInterface $logger,
		private readonly ?PrivateKeyCipher $cipher = null,
	) {
	}
	public function generateSigningKey(): array {
		$generator = SecureCurveFactory::getGeneratorByName('secp256k1');
		$key = $generator->createPrivateKey();
		$public = hex2bin((new CompressedPointSerializer(new GmpMath()))->serialize($key->getPublicKey()->getPoint()));
		$multibase = 'z' . self::base58("\xe7\x01" . $public);
		return ['private' => str_pad(gmp_strval($key->getSecret(), 16), 64, '0', STR_PAD_LEFT),
			'public' => $public, 'multibase' => $multibase, 'didKey' => 'did:key:' . $multibase, 'kid' => '#atproto'];
	}
	public function generateRotationKey(): array {
		return $this->generateSigningKey();
	}
	public function sealPrivateKey(#[\SensitiveParameter] string $key): string {
		if ($this->cipher === null) {
			throw new \RuntimeException('Key cipher unavailable');
		}
		return $this->cipher->seal($key);
	}
	public function unsealPrivateKey(#[\SensitiveParameter] string $key): string {
		if ($this->cipher === null) {
			throw new \RuntimeException('Key cipher unavailable');
		}
		$value = $this->cipher->open($key);
		if ($value === '') {
			throw new \RuntimeException('Cannot decrypt AT Protocol key');
		}
		return $value;
	}
	public function sign(string $data, #[\SensitiveParameter] string $privateKey): string {
		if (!preg_match('/^[a-f0-9]{64}$/D', $privateKey)) {
			throw new \InvalidArgumentException('Invalid signing key');
		}
		$generator = SecureCurveFactory::getGeneratorByName('secp256k1');
		$math = new GmpMath();
		$key = $generator->getPrivateKeyFrom(gmp_init($privateKey, 16));
		$hash = gmp_init(hash('sha256', $data), 16);
		$rng = new HmacRandomNumberGenerator($math, $key, $hash, 'sha256');
		$sig = (new Signer($math))->sign($key, $hash, $rng->generate($generator->getOrder()));
		$s = $sig->getS();
		$order = $generator->getOrder();
		if (gmp_cmp($s, gmp_div_q($order, 2)) > 0) {
			$s = gmp_sub($order, $s);
		}
		return (new IEEEP1363Serializer())->serialize(new Signature($sig->getR(), $s), 256);
	}
	public function verify(string $data, string $signature, string $publicKey): bool {
		try {
			if (strlen($signature) !== 64) {
				return false;
			}
			$generator = SecureCurveFactory::getGeneratorByName('secp256k1');
			$math = new GmpMath();
			if (str_starts_with($publicKey, 'did:key:z')) {
				$publicKey = substr($publicKey, 8);
			}
			if (str_starts_with($publicKey, 'z')) {
				$bytes = self::unbase58(substr($publicKey, 1));
				if (substr($bytes, 0, 2) !== "\xe7\x01") {
					return false;
				}
				$publicKey = substr($bytes, 2);
			}
			if (strlen($publicKey) !== 33 || !in_array(ord($publicKey[0]), [2, 3], true)) {
				return false;
			}
			$point = (new CompressedPointSerializer($math))->unserialize($generator->getCurve(), bin2hex($publicKey));
			$key = new PublicKey($math, $generator, $point);
			$sig = (new IEEEP1363Serializer())->parse($signature);
			if (gmp_cmp($sig->getS(), gmp_div_q($generator->getOrder(), 2)) > 0) {
				return false;
			}
			return (new Signer($math))->verify($key, $sig, gmp_init(hash('sha256', $data), 16));
		} catch (\Throwable) {
			return false;
		}
	}
	public static function base58(string $bytes): string {
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		$n = gmp_init(bin2hex($bytes) ?: '0', 16);
		$out = '';
		while (gmp_cmp($n, 0) > 0) {
			[$n, $r] = gmp_div_qr($n, 58);
			$out = $alphabet[gmp_intval($r)] . $out;
		}
		return str_repeat('1', strlen($bytes) - strlen(ltrim($bytes, "\0"))) . $out;
	}
	public static function unbase58(string $value): string {
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		$n = gmp_init(0);
		foreach (str_split($value) as $char) {
			$i = strpos($alphabet, $char);
			if ($i === false) {
				throw new \InvalidArgumentException('Invalid base58');
			}
			$n = gmp_add(gmp_mul($n, 58), $i);
		}
		$hex = gmp_strval($n, 16);
		$bytes = gmp_cmp($n, 0) === 0 ? '' : hex2bin(str_pad($hex, (int)(ceil(strlen($hex) / 2) * 2), '0', STR_PAD_LEFT));
		return str_repeat("\0", strlen($value) - strlen(ltrim($value, '1'))) . $bytes;
	}
	public function getJwtSigningKey(): string {
		$secret = $this->config?->getSystemValue('secret', '');
		if (!is_string($secret) || $secret === '') {
			throw new \RuntimeException('Instance secret unavailable');
		}
		return hash_hmac('sha256', 'social.atproto.sessions.v1', $secret, true);
	}
}
