<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use Exception;
use JsonSerializable;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class LinkedDataSignature
 *
 * @package OCA\Social\Model
 */
class LinkedDataSignature implements JsonSerializable {
	use TArrayTools;

	private string $type = '';
	private string $creator = '';
	private string $created = '';
	private string $nonce = '';
	private string $signatureValue = '';
	private string $privateKey = '';
	private string $publicKey = '';
	private array $object = [];

	public function getType(): string {
		return $this->type;
	}

	public function setType(string $type): LinkedDataSignature {
		$this->type = $type;

		return $this;
	}

	public function getCreator(): string {
		return $this->creator;
	}

	public function setCreator(string $creator): LinkedDataSignature {
		$this->creator = $creator;

		return $this;
	}

	public function getNonce(): string {
		return $this->nonce;
	}

	public function setNonce(string $nonce): LinkedDataSignature {
		$this->nonce = $nonce;

		return $this;
	}

	public function getCreated(): string {
		return $this->created;
	}

	public function setCreated(string $created): LinkedDataSignature {
		$this->created = $created;

		return $this;
	}

	public function getSignatureValue(): string {
		return $this->signatureValue;
	}

	public function setSignatureValue(string $signatureValue): LinkedDataSignature {
		$this->signatureValue = $signatureValue;

		return $this;
	}

	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	public function setPrivateKey(string $privateKey): self {
		$this->privateKey = $privateKey;

		return $this;
	}

	public function setPublicKey(string $publicKey): self {
		$this->publicKey = $publicKey;

		return $this;
	}

	public function getObject(): array {
		return $this->object;
	}

	public function setObject(array $object): self {
		$this->object = $object;

		return $this;
	}

	/**
	 * @throws LinkedDataSignatureMissingException
	 */
	public function sign() {
		$header = [
			'@context' => 'https://w3id.org/identity/v1',
			'creator' => $this->getCreator(),
			'created' => $this->getCreated()
		];

		$hash = $this->hashedCanonicalize($header) . $this->hashedCanonicalize($this->getObject());

		$algo = OPENSSL_ALGO_SHA256;
		if ($this->getType() === 'RsaSignature2017') {
			$algo = OPENSSL_ALGO_SHA256;
		}

		if (!openssl_sign($hash, $signed, $this->getPrivateKey(), $algo)) {
			throw new LinkedDataSignatureMissingException();
		}

		$this->setSignatureValue(base64_encode($signed));
	}

	/**
	 * The contexts a signed document may name: the two the vocabulary is
	 * read with. `https://w3id.org/identity/v1` is shipped for the signature
	 * header only; in a document it would remap `name`, `url` and `image`.
	 */
	public const DOCUMENT_CONTEXTS = [
		ACore::CONTEXT_ACTIVITYSTREAMS,
		ACore::CONTEXT_SECURITY,
	];

	/**
	 * Term definitions a document may declare inline: this app's own
	 * (ACore::CONTEXT_EXTENSIONS) and the ones Mastodon emits. Each only adds
	 * a term the named contexts leave undefined, so none can change what a
	 * key of the vocabulary expands to.
	 */
	private const MASTODON_TERMS = [
		'featuredTags' => ['@id' => 'toot:featuredTags', '@type' => '@id'],
		'memorial' => 'toot:memorial',
		'suspended' => 'toot:suspended',
		'attributionDomains' => ['@id' => 'toot:attributionDomains', '@type' => '@id'],
		'ostatus' => 'http://ostatus.org#',
		'atomUri' => 'ostatus:atomUri',
		'inReplyToAtomUri' => 'ostatus:inReplyToAtomUri',
		'conversation' => 'ostatus:conversation',
	];

	public function verify(): bool {
		// The signature covers the triples the document expands to, but the
		// handlers read its keys. A context that redefines or aliases a term
		// can make keys say something the triples do not, so only documents
		// read with known definitions are verified at all.
		if (!array_key_exists('@context', $this->getObject())
			|| !self::usesOnlyKnownContexts($this->getObject())) {
			return false;
		}

		$header = [
			'@context' => 'https://w3id.org/identity/v1',
			'nonce' => $this->getNonce(),
			'creator' => $this->getCreator(),
			'created' => $this->getCreated()
		];

		try {
			$hashHeader = $this->hashedCanonicalize($header, true);
			$hashObject = $this->hashedCanonicalize($this->getObject());
		} catch (Exception|\Error $e) {
			// Normalisation refuses contexts that are not shipped with the app
			// (SignatureService::documentLoader), so a document using one cannot be
			// verified — which is a false result, not an error: the caller falls back
			// to the HTTP-signature origin. The normaliser also fails with an
			// Error on JSON-LD it does not expect — a context that is a NUL, an
			// empty object where a type belongs — and that document is not
			// verified either.
			return false;
		}

		$algo = OPENSSL_ALGO_SHA256;
		if ($this->getType() === 'RsaSignature2017') {
			$algo = OPENSSL_ALGO_SHA256;
		}

		$signed = $this->getSignatureBytes();
		if ($signed !== null
			&& openssl_verify($hashHeader . $hashObject, $signed, $this->publicKey, $algo) === 1) {
			return true;
		}

		return false;
	}

	/**
	 * The signature bytes, or null unless the value is canonical, padded
	 * base64: `base64_decode()` also accepts the same bytes without padding,
	 * with whitespace or with stray trailing bits, and each spelling would
	 * otherwise pass as a signature not seen before.
	 */
	public function getSignatureBytes(): ?string {
		$signed = base64_decode($this->getSignatureValue(), true);
		if ($signed === false || $signed === '' || base64_encode($signed) !== $this->getSignatureValue()) {
			return null;
		}

		return $signed;
	}

	/**
	 * Whether every `@context` in the document, at any depth, is one of
	 * DOCUMENT_CONTEXTS or an inline object of known term definitions, and
	 * no other key is a JSON-LD keyword.
	 */
	public static function usesOnlyKnownContexts(array $document): bool {
		foreach ($document as $key => $value) {
			if ($key === '@context') {
				if (!self::isKnownContext($value)) {
					return false;
				}
				continue;
			}

			if (is_string($key) && str_starts_with($key, '@')) {
				return false;
			}

			if (is_array($value) && !self::usesOnlyKnownContexts($value)) {
				return false;
			}
		}

		return true;
	}

	private static function isKnownContext(mixed $context): bool {
		if (is_string($context)) {
			return in_array($context, self::DOCUMENT_CONTEXTS, true);
		}

		if (!is_array($context)) {
			return false;
		}

		if (array_is_list($context)) {
			foreach ($context as $entry) {
				if (is_array($entry) && array_is_list($entry)) {
					return false;
				}
				if (!self::isKnownContext($entry)) {
					return false;
				}
			}

			return true;
		}

		$known = ACore::CONTEXT_EXTENSIONS + self::MASTODON_TERMS;
		foreach ($context as $term => $definition) {
			if (!is_string($term) || !array_key_exists($term, $known)
				|| !self::sameDefinition($known[$term], $definition)) {
				return false;
			}
		}

		return true;
	}

	private static function sameDefinition(mixed $known, mixed $definition): bool {
		if (is_array($known) && is_array($definition)) {
			ksort($known);
			ksort($definition);
		}

		return $known === $definition;
	}

	private function hashedCanonicalize(array $data, bool $removeEmptyValue = false): string {
		if ($removeEmptyValue) {
			$this->cleanArray($data);
		}

		// a document JSON cannot hold — bytes that are not UTF-8, nesting past
		// the encoder's depth — throws here, and is not verified
		$object = json_decode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$res = jsonld_normalize(
			$object,
			[
				'algorithm' => 'URDNA2015',
				'format' => 'application/nquads',
				'documentLoader' => [SignatureService::class, 'documentLoader']
			]
		);

		return hash('sha256', $res);
	}

	/**
	 * @throws LinkedDataSignatureMissingException
	 */
	public function import(array $data): void {
		//		if (!in_array(ACore::CONTEXT_SECURITY, $this->getArray('@context', $data, []))) {
		//			throw new LinkedDataSignatureMissingException('no @context security entry');
		//		}

		$signature = $this->getArray('signature', $data, []);
		if ($signature === []) {
			throw new LinkedDataSignatureMissingException('missing signature');
		}

		$this->setType($this->get('type', $signature, ''));
		$this->setCreator($this->get('creator', $signature, ''));
		$this->setNonce($this->get('nonce', $signature, ''));
		$this->setCreated($this->get('created', $signature, ''));
		$this->setSignatureValue($this->get('signatureValue', $signature, ''));

		unset($data['signature']);

		$this->setObject($data);
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'type' => $this->getType(),
			'creator' => $this->getCreator(),
			'created' => $this->getCreated(),
			'signatureValue' => $this->getSignatureValue()
		];
	}
}
