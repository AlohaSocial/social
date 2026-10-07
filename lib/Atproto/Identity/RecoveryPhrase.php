<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

/** A 256-bit signing scalar requires 24 BIP-39 words (12 words carry 128 bits). */
final class RecoveryPhrase {
	public static function encode(string $privateKey): string {
		if (!preg_match('/^[a-f0-9]{64}$/D', $privateKey)) {
			throw new \InvalidArgumentException('Invalid recovery key');
		}
		$entropy = hex2bin($privateKey);
		$bits = '';
		foreach (str_split($entropy . hash('sha256', $entropy, true)[0]) as $byte) {
			$bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
		}
		$words = file(__DIR__ . '/../../../resources/atproto/bip39-english.txt', FILE_IGNORE_NEW_LINES);
		if (count($words) !== 2048) {
			throw new \RuntimeException('Recovery wordlist unavailable');
		}
		$out = [];
		for ($i = 0; $i < 264; $i += 11) {
			$out[] = $words[bindec(substr($bits, $i, 11))];
		}
		return implode(' ', $out);
	}
}
