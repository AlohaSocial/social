<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use RuntimeException;

/**
 * A recovery key as twelve words.
 *
 * BIP-39: 128 bits of entropy and a four-bit checksum, in eleven-bit groups
 * indexing the English word list. The signing key is derived from the
 * entropy (SHA-256 of it), so the words are the key and the key is never
 * stored: what the person wrote down is the only copy.
 */
final class Mnemonic {
	private const WORDLIST = __DIR__ . '/../../../resources/atproto/bip39-english.txt';
	private const WORDS = 12;

	/** @var string[]|null */
	private static ?array $words = null;

	/**
	 * @return array{0: string, 1: PrivateKey} the phrase and the key it is
	 */
	public static function generate(): array {
		$entropy = random_bytes(16);

		return [self::encode($entropy), self::keyFromEntropy($entropy)];
	}

	/**
	 * The key a phrase is, for the person who kept it.
	 *
	 * @throws InvalidArgumentException when the words are not a phrase
	 */
	public static function keyFromPhrase(string $phrase): PrivateKey {
		return self::keyFromEntropy(self::decode($phrase));
	}

	public static function encode(string $entropy): string {
		if (strlen($entropy) !== 16) {
			throw new InvalidArgumentException('Twelve words encode sixteen bytes');
		}
		$checksum = ord(hash('sha256', $entropy, true)[0]) >> 4;
		$bits = '';
		foreach (str_split($entropy) as $byte) {
			$bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
		}
		$bits .= str_pad(decbin($checksum), 4, '0', STR_PAD_LEFT);
		$words = self::words();
		$out = [];
		for ($i = 0; $i < self::WORDS; $i++) {
			$out[] = $words[bindec(substr($bits, $i * 11, 11))];
		}

		return implode(' ', $out);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public static function decode(string $phrase): string {
		$given = preg_split('/\s+/', strtolower(trim($phrase))) ?: [];
		if (count($given) !== self::WORDS) {
			throw new InvalidArgumentException('A recovery phrase is twelve words');
		}
		$index = array_flip(self::words());
		$bits = '';
		foreach ($given as $word) {
			if (!isset($index[$word])) {
				throw new InvalidArgumentException('Not a word of the list: ' . $word);
			}
			$bits .= str_pad(decbin($index[$word]), 11, '0', STR_PAD_LEFT);
		}
		$entropy = '';
		for ($i = 0; $i < 16; $i++) {
			$entropy .= chr(bindec(substr($bits, $i * 8, 8)));
		}
		if ((ord(hash('sha256', $entropy, true)[0]) >> 4) !== bindec(substr($bits, 128, 4))) {
			throw new InvalidArgumentException('The phrase does not check');
		}

		return $entropy;
	}

	private static function keyFromEntropy(string $entropy): PrivateKey {
		return new PrivateKey(Curve::K256, hash('sha256', 'social.atproto.recovery.v1' . $entropy, true));
	}

	/**
	 * @return string[]
	 */
	private static function words(): array {
		if (self::$words === null) {
			$content = file_get_contents(self::WORDLIST);
			$words = $content === false ? [] : (preg_split('/\r?\n/', trim($content)) ?: []);
			if (count($words) !== 2048) {
				throw new RuntimeException('The word list is not the 2048 words of BIP-39');
			}
			self::$words = $words;
		}

		return self::$words;
	}
}
