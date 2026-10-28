<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Lexicon;

use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Protocol\Tid;

/**
 * Validates records against the lexicon documents vendored under
 * lib/Atproto/lexicons, read at runtime. Everything this app writes to a
 * repository goes through here before it is signed, and everything that
 * arrives from Bluesky goes through here before it is stored.
 *
 * Values are the DAG-CBOR model (Bytes, Cid, arrays); a record that came as
 * JSON is converted with DagCbor::fromLexJson() first.
 */
class Lexicon {
	private const DIRECTORY = __DIR__ . '/../lexicons';

	/** a type and a subtype of at most 127 characters each (RFC 6838 §4.2), and the slash */
	private const MAX_MIME_TYPE = 255;
	private const MAX_OPAQUE_RECORD_BYTES = 1_000_000;
	private const MAX_OPAQUE_RECORD_VALUES = 20_000;
	private const MAX_OPAQUE_RECORD_DEPTH = 64;

	/** @var array<string, array|null> loaded documents by NSID */
	private array $documents = [];

	/**
	 * @param string[] $directories where the documents are, the app's own last
	 */
	public function __construct(
		private readonly array $directories = [self::DIRECTORY],
	) {
	}

	/**
	 * Checks a record against the lexicon its `$type` names.
	 *
	 * @param array<string, mixed> $record
	 * @throws LexiconException
	 */
	public function validateRecord(array $record, ?bool $validate = null): void {
		$type = $record['$type'] ?? null;
		if (!is_string($type) || !Syntax::isNsid($type)) {
			throw new LexiconException('A record needs a $type that is an NSID');
		}
		$document = $this->document($type);
		if ($document === null && $validate === true) {
			throw new LexiconException('No lexicon document for ' . $type);
		}
		if ($document === null || $validate === false) {
			$this->validateOpaqueRecord($record);

			return;
		}
		$definition = $document['defs']['main'] ?? null;
		if (!is_array($definition)) {
			throw new LexiconException('No record definition for ' . $type);
		}
		if (($definition['type'] ?? '') !== 'record') {
			throw new LexiconException($type . ' is not a record type');
		}
		$this->check($record, $definition['record'] ?? [], $type, '');
	}

	public function hasRecordSchema(string $type): bool {
		return ($this->document($type)['defs']['main']['type'] ?? '') === 'record';
	}

	/**
	 * Checks a value against one definition, `nsid#def`.
	 *
	 * @throws LexiconException
	 */
	public function validate(mixed $value, string $ref): void {
		$this->check($value, $this->definition($ref), explode('#', $ref)[0], '');
	}

	/**
	 * Checks the JSON input of a procedure against its lexicon.
	 *
	 * @throws LexiconException
	 */
	public function validateInput(mixed $body, string $nsid): void {
		$definition = $this->definition($nsid . '#main');
		if (($definition['type'] ?? '') !== 'procedure' || !is_array($definition['input']['schema'] ?? null)) {
			throw new LexiconException($nsid . ' is not a procedure with an input');
		}
		$this->check($body, $definition['input']['schema'], $nsid, 'input');
	}

	/**
	 * Whether a record fits, for callers that ask rather than throw.
	 */
	public function fits(array $record): bool {
		try {
			$this->validateRecord($record);

			return true;
		} catch (LexiconException) {
			return false;
		}
	}

	/**
	 * @throws LexiconException when the reference names nothing known
	 */
	private function definition(string $ref): array {
		[$nsid, $fragment] = array_pad(explode('#', $ref, 2), 2, 'main');
		$document = $this->document($nsid);
		$definition = $document['defs'][$fragment] ?? null;
		if (!is_array($definition)) {
			throw new LexiconException('Unknown lexicon ' . $ref);
		}

		return $definition;
	}

	private function document(string $nsid): ?array {
		if (array_key_exists($nsid, $this->documents)) {
			return $this->documents[$nsid];
		}
		foreach ($this->directories as $directory) {
			$path = $directory . '/' . $nsid . '.json';
			if (is_file($path)) {
				$document = json_decode((string)file_get_contents($path), true);
				if (is_array($document) && ($document['id'] ?? null) === $nsid && is_array($document['defs'] ?? null)) {
					return $this->documents[$nsid] = $document;
				}
			}
		}

		return $this->documents[$nsid] = null;
	}

	/**
	 * Opaque records remain protocol-native and are never projected into Social
	 * objects. Without a vendored Lexicon, bound their DAG-CBOR shape and size.
	 *
	 * @throws LexiconException
	 */
	private function validateOpaqueRecord(array $record): void {
		$count = 0;
		$this->checkOpaqueValue($record, 0, $count);
		try {
			if (strlen(DagCbor::encode($record)) > self::MAX_OPAQUE_RECORD_BYTES) {
				throw new LexiconException('Opaque record is larger than one megabyte');
			}
		} catch (\InvalidArgumentException $e) {
			throw new LexiconException('Opaque record is not valid DAG-CBOR data');
		}
	}

	private function checkOpaqueValue(mixed $value, int $depth, int &$count): void {
		if ($depth > self::MAX_OPAQUE_RECORD_DEPTH || ++$count > self::MAX_OPAQUE_RECORD_VALUES) {
			throw new LexiconException('Opaque record exceeds structural limits');
		}
		if (is_string($value)) {
			if (strlen($value) > self::MAX_OPAQUE_RECORD_BYTES) {
				throw new LexiconException('Opaque record contains an oversized string');
			}
			return;
		}
		if ($value === null || is_bool($value) || is_int($value) || $value instanceof Bytes || $value instanceof Cid) {
			return;
		}
		$objectMap = $value instanceof \stdClass;
		if ($objectMap) {
			$value = get_object_vars($value);
		}
		if (!is_array($value)) {
			throw new LexiconException('Opaque record contains a value that DAG-CBOR cannot store');
		}
		$isList = array_is_list($value);
		foreach ($value as $key => $item) {
			if (!$objectMap && !$isList && !is_string($key)) {
				throw new LexiconException('Opaque record map keys must be strings');
			}
			$this->checkOpaqueValue($item, $depth + 1, $count);
		}
	}

	/**
	 * @param string $nsid the document a relative `#ref` is in
	 * @param string $path where in the record, for the message
	 * @throws LexiconException
	 */
	private function check(mixed $value, array $schema, string $nsid, string $path): void {
		$type = $schema['type'] ?? 'unknown';
		switch ($type) {
			case 'ref':
				$ref = (string)($schema['ref'] ?? '');
				$target = str_starts_with($ref, '#') ? $nsid . $ref : $ref;
				$this->check($value, $this->definition($target), explode('#', $target)[0], $path);

				return;
			case 'union':
				$this->checkUnion($value, $schema, $nsid, $path);

				return;
			case 'object':
				$this->checkObject($value, $schema, $nsid, $path);

				return;
			case 'array':
				if (!is_array($value) || !array_is_list($value)) {
					throw new LexiconException($path . ' must be an array');
				}
				$this->checkLength(count($value), $schema, $path, 'items');
				foreach ($value as $i => $item) {
					$this->check($item, $schema['items'] ?? [], $nsid, $path . '[' . $i . ']');
				}

				return;
			case 'boolean':
				if (!is_bool($value)) {
					throw new LexiconException($path . ' must be a boolean');
				}
				$this->checkConstAndEnum($value, $schema, $path);

				return;
			case 'integer':
				if (!is_int($value)) {
					throw new LexiconException($path . ' must be an integer');
				}
				$this->checkConstAndEnum($value, $schema, $path);
				if (isset($schema['minimum']) && $value < $schema['minimum']) {
					throw new LexiconException($path . ' is below ' . $schema['minimum']);
				}
				if (isset($schema['maximum']) && $value > $schema['maximum']) {
					throw new LexiconException($path . ' is above ' . $schema['maximum']);
				}

				return;
			case 'string':
				$this->checkString($value, $schema, $path);

				return;
			case 'bytes':
				if (!$value instanceof Bytes) {
					throw new LexiconException($path . ' must be bytes');
				}
				$this->checkLength(strlen($value->value), $schema, $path, 'bytes');

				return;
			case 'cid-link':
				if (!$value instanceof Cid) {
					throw new LexiconException($path . ' must be a CID link');
				}

				return;
			case 'blob':
				$this->checkBlob($value, $schema, $path);

				return;
			case 'unknown':
				$value = self::emptyMapAsArray($value);
				if (!is_array($value) || ($value !== [] && array_is_list($value))) {
					throw new LexiconException($path . ' must be an object');
				}
				if (($value['$type'] ?? null) === 'blob') {
					throw new LexiconException($path . ' must be an object, not a blob');
				}

				return;
			case 'token':
				throw new LexiconException($path . ' refers to a token, which is not a value');
			default:
				throw new LexiconException($path . ' has a lexicon type this app does not validate: ' . $type);
		}
	}

	private function checkObject(mixed $value, array $schema, string $nsid, string $path): void {
		$value = self::emptyMapAsArray($value);
		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new LexiconException($path . ' must be an object');
		}
		if ($value instanceof Bytes || $value instanceof Cid) {
			throw new LexiconException($path . ' must be an object');
		}
		$required = $schema['required'] ?? [];
		$nullable = $schema['nullable'] ?? [];
		foreach ($required as $name) {
			if (!array_key_exists($name, $value)) {
				throw new LexiconException($path . '.' . $name . ' is required');
			}
		}
		foreach ($schema['properties'] ?? [] as $name => $property) {
			if (!array_key_exists($name, $value)) {
				continue;
			}
			if ($value[$name] === null) {
				if (!in_array($name, $nullable, true)) {
					throw new LexiconException($path . '.' . $name . ' may not be null');
				}
				continue;
			}
			$this->check($value[$name], $property, $nsid, $path . '.' . $name);
		}
	}

	private function checkUnion(mixed $value, array $schema, string $nsid, string $path): void {
		if (!is_array($value) || !is_string($value['$type'] ?? null)) {
			throw new LexiconException($path . ' must be an object with a $type');
		}
		$type = $value['$type'];
		$refs = array_map(
			static fn (string $ref): string => str_starts_with($ref, '#') ? $nsid . $ref : (str_contains($ref, '#') ? $ref : $ref . '#main'),
			$schema['refs'] ?? [],
		);
		$named = str_contains($type, '#') ? $type : $type . '#main';
		if (in_array($named, $refs, true)) {
			$this->check($value, $this->definition($named), explode('#', $named)[0], $path);

			return;
		}
		if (($schema['closed'] ?? false) === true) {
			throw new LexiconException($path . ' is not one of the types allowed');
		}
		// an open union: a type this app does not know passes through
	}

	private function checkString(mixed $value, array $schema, string $path): void {
		if (!is_string($value)) {
			throw new LexiconException($path . ' must be a string');
		}
		$this->checkConstAndEnum($value, $schema, $path);
		$this->checkLength(strlen($value), $schema, $path, 'bytes');
		if (isset($schema['minGraphemes']) || isset($schema['maxGraphemes'])) {
			$graphemes = self::graphemes($value);
			if (isset($schema['minGraphemes']) && $graphemes < $schema['minGraphemes']) {
				throw new LexiconException($path . ' is shorter than ' . $schema['minGraphemes'] . ' characters');
			}
			if (isset($schema['maxGraphemes']) && $graphemes > $schema['maxGraphemes']) {
				throw new LexiconException($path . ' is longer than ' . $schema['maxGraphemes'] . ' characters');
			}
		}
		$format = $schema['format'] ?? null;
		if ($format === null) {
			return;
		}
		$ok = match ($format) {
			'did' => Syntax::isDid($value),
			'handle' => Syntax::isHandle($value),
			'at-identifier' => Syntax::isDid($value) || Syntax::isHandle($value),
			'nsid' => Syntax::isNsid($value),
			'at-uri' => Syntax::isAtUri($value),
			'cid' => Cid::isValid($value),
			'datetime' => Syntax::isDatetime($value),
			'language' => Syntax::isLanguage($value),
			'uri' => strlen($value) <= 8192 && preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\S+$/', $value) === 1,
			'tid' => Tid::isValid($value),
			'record-key' => Syntax::isRecordKey($value),
			default => throw new LexiconException($path . ' has a string format this app does not know: ' . $format),
		};
		if (!$ok) {
			throw new LexiconException($path . ' is not a valid ' . $format);
		}
	}

	private function checkBlob(mixed $value, array $schema, string $path): void {
		if (!is_array($value)
			|| ($value['$type'] ?? null) !== 'blob'
			|| !($value['ref'] ?? null) instanceof Cid
			|| !is_string($value['mimeType'] ?? null)
			|| !is_int($value['size'] ?? null)) {
			throw new LexiconException($path . ' must be a blob reference');
		}
		if (isset($schema['maxSize']) && $value['size'] > $schema['maxSize']) {
			throw new LexiconException($path . ' is larger than ' . $schema['maxSize'] . ' bytes');
		}
		$accept = $schema['accept'] ?? [];
		if ($accept !== [] && !in_array('*/*', $accept, true)) {
			foreach ($accept as $pattern) {
				// fnmatch() warns on, and matches nothing against, a string past
				// 1024 bytes, and throws on one with a NUL in it
				if (strlen($value['mimeType']) <= self::MAX_MIME_TYPE && !str_contains($value['mimeType'], "\0")
					&& fnmatch($pattern, $value['mimeType'])) {
					return;
				}
			}
			throw new LexiconException($path . ' is not an accepted type: ' . $value['mimeType']);
		}
	}

	private function checkConstAndEnum(mixed $value, array $schema, string $path): void {
		if (array_key_exists('const', $schema) && $value !== $schema['const']) {
			throw new LexiconException($path . ' must be ' . json_encode($schema['const']));
		}
		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
			throw new LexiconException($path . ' is not one of the values allowed');
		}
	}

	private function checkLength(int $length, array $schema, string $path, string $unit): void {
		if (isset($schema['minLength']) && $length < $schema['minLength']) {
			throw new LexiconException($path . ' is shorter than ' . $schema['minLength'] . ' ' . $unit);
		}
		if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
			throw new LexiconException($path . ' is longer than ' . $schema['maxLength'] . ' ' . $unit);
		}
	}

	/**
	 * The empty map as DagCbor models it (a `stdClass` with no properties)
	 * is an object with no fields.
	 */
	private static function emptyMapAsArray(mixed $value): mixed {
		return $value instanceof \stdClass && get_object_vars($value) === [] ? [] : $value;
	}

	/**
	 * Extended grapheme clusters, which is what Bluesky's limits count: a
	 * flag or a family emoji is one, however many code points it takes.
	 */
	public static function graphemes(string $text): int {
		return (int)preg_match_all('/\X/u', $text);
	}
}
