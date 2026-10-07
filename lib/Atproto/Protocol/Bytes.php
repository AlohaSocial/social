<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Protocol;
/** Explicit CBOR byte string; PHP strings otherwise encode as UTF-8 text. */
final class Bytes {
	public function __construct(public readonly string $value) {}
}
