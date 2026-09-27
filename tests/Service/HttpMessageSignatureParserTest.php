<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Service\HttpMessageSignatureParser;
use OCA\Social\Tests\Helper\RsaPssSigner;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The wire format of RFC 9421, held against the RFC's own material.
 *
 * Appendix B.2 signs one fixed request (B.2 "test-request") with the keys of
 * B.1. The RSA-PSS examples are the ones an RSA-only verifier can check:
 * B.2.3 covers a full set of components, and B.2.2 covers `@query-param`,
 * which this implementation deliberately refuses.
 */
#[AllowMockObjectsWithoutExpectations]
class HttpMessageSignatureParserTest extends TestCase {
	/** RFC 9421 B.1.2, `test-key-rsa-pss` (a plain rsaEncryption SPKI). */
	private const RFC_RSA_PSS_PUBLIC_KEY = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr4tmm3r20Wd/PbqvP1s2
+QEtvpuRaV8Yq40gjUR8y2Rjxa6dpG2GXHbPfvMs8ct+Lh1GH45x28Rw3Ry53mm+
oAXjyQ86OnDkZ5N8lYbggD4O3w6M6pAvLkhk95AndTrifbIFPNU8PPMO7OyrFAHq
gDsznjPFmTOtCEcN2Z1FpWgchwuYLPL+Wokqltd11nqqzi+bJ9cvSKADYdUAAN5W
Utzdpiy6LbTgSxP7ociU4Tn0g5I6aDZJ7A8Lzo0KSyZYoA485mqcO0GVAdVw9lq4
aOT9v6d+nb4bnNkQVklLQ3fVAvJm+xdDOp9LCNCN48V2pnDOkFV6+U9nV5oyc6XI
2wIDAQAB
-----END PUBLIC KEY-----
';

	/** RFC 9421 B.2.3 "Full Coverage Using rsa-pss-sha512". */
	private const B23_SIGNATURE_INPUT = 'sig-b23=("date" "@method" "@path" "@query" "@authority" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-rsa-pss"';
	private const B23_SIGNATURE = 'sig-b23=:bbN8oArOxYoyylQQUU6QYwrTuaxLwjAC9fbY2F6SVWvh0yBiMIRGOnMYwZ/5MR6fb0Kh1rIRASVxFkeGt683+qRpRRU5p2voTp768ZrCUb38K0fUxN0O0iC59DzYx8DFll5GmydPxSmme9v6ULbMFkl+V5B1TP/yPViV7KsLNmvKiLJH1pFkh/aYA2HXXZzNBXmIkoQoLd7YfW91kE9o/CCoC1xMy7JA1ipwvKvfrs65ldmlu9bpG6A9BmzhuzF8Eim5f8ui9eH8LZH896+QIF61ka39VBrohr9iyMUJpvRX2Zbhl5ZJzSRxpJyoEZAFL2FUo5fTIztsDZKEgM4cUA==:';
	private const B23_SIGNATURE_BASE = '"date": Tue, 20 Apr 2021 02:07:55 GMT
"@method": POST
"@path": /foo
"@query": ?param=Value&Pet=dog
"@authority": example.com
"content-type": application/json
"content-digest": sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:
"content-length": 18
"@signature-params": ("date" "@method" "@path" "@query" "@authority" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-rsa-pss"';

	/** RFC 9421 B.2.2 "Selective Covered Components", which uses @query-param. */
	private const B22_SIGNATURE_INPUT = 'sig-b22=("@authority" "content-digest" "@query-param";name="Pet");created=1618884473;keyid="test-key-rsa-pss";tag="header-example"';

	private HttpMessageSignatureParser $parser;

	protected function setUp(): void {
		$this->parser = new HttpMessageSignatureParser();
	}

	/** RFC 9421 B.2 "test-request", as an inbound request. */
	private function rfcTestRequest(array $headerOverrides = []): IRequest|MockObject {
		return $this->request('POST', '/foo?param=Value&Pet=dog', array_merge([
			'host' => 'example.com',
			'date' => 'Tue, 20 Apr 2021 02:07:55 GMT',
			'content-type' => 'application/json',
			'content-digest' => 'sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:',
			'content-length' => '18',
		], $headerOverrides));
	}

	private function request(string $method, string $uri, array $headers, string $scheme = 'https'): IRequest|MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $headers[strtolower($name)] ?? '');
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn($uri);
		$request->method('getServerProtocol')->willReturn($scheme);

		return $request;
	}

	/** @return list<array{name: string, params: array}> */
	private function components(string ...$names): array {
		return array_map(fn (string $name): array => ['name' => $name, 'params' => []], $names);
	}

	// RFC 9421 Appendix B vectors

	public function testParsesTheSignatureInputOfRfcExampleB23(): void {
		$inputs = $this->parser->parseSignatureInput(self::B23_SIGNATURE_INPUT);

		$this->assertSame(['sig-b23'], array_keys($inputs));
		$this->assertSame(
			['date', '@method', '@path', '@query', '@authority', 'content-type', 'content-digest', 'content-length'],
			array_column($inputs['sig-b23']['components'], 'name')
		);
		$this->assertSame(['created' => 1618884473, 'keyid' => 'test-key-rsa-pss'], $inputs['sig-b23']['params']);
		$this->assertSame(
			'("date" "@method" "@path" "@query" "@authority" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-rsa-pss"',
			$inputs['sig-b23']['serialized'],
			'@signature-params is the member exactly as received'
		);
	}

	public function testRebuildsTheSignatureBaseOfRfcExampleB23ByteForByte(): void {
		$input = $this->parser->parseSignatureInput(self::B23_SIGNATURE_INPUT)['sig-b23'];

		$base = $this->parser->signatureBase($this->rfcTestRequest(), $input['components'], $input['serialized'], 'example.com');

		$this->assertSame(self::B23_SIGNATURE_BASE, $base);
	}

	/**
	 * The vector proves the RSA-PSS verifier independently of anything this
	 * code base signs: the RFC's key, the RFC's signature, the RFC's base.
	 */
	public function testVerifiesRfcExampleB23WithRsaPssSha512(): void {
		$signature = $this->parser->parseSignature(self::B23_SIGNATURE)['sig-b23'];
		$this->assertSame(256, strlen($signature), 'a 2048-bit RSA signature');

		$this->assertTrue($this->parser->verify(
			HttpMessageSignatureParser::ALG_RSA_PSS_SHA512,
			self::RFC_RSA_PSS_PUBLIC_KEY,
			self::B23_SIGNATURE_BASE,
			$signature
		));
	}

	public function testRefusesRfcExampleB23OverATamperedBase(): void {
		$signature = $this->parser->parseSignature(self::B23_SIGNATURE)['sig-b23'];
		$tampered = str_replace('"@path": /foo', '"@path": /bar', self::B23_SIGNATURE_BASE);

		$this->assertFalse($this->parser->verify(
			HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, self::RFC_RSA_PSS_PUBLIC_KEY, $tampered, $signature
		));
	}

	public function testAPssSignatureDoesNotVerifyAsPkcs1V15(): void {
		$signature = $this->parser->parseSignature(self::B23_SIGNATURE)['sig-b23'];

		$this->assertFalse($this->parser->verify(
			HttpMessageSignatureParser::ALG_RSA_V1_5_SHA256, self::RFC_RSA_PSS_PUBLIC_KEY, self::B23_SIGNATURE_BASE, $signature
		));
	}

	public function testRefusesRfcExampleB22BecauseItCoversAQueryParameter(): void {
		$input = $this->parser->parseSignatureInput(self::B22_SIGNATURE_INPUT)['sig-b22'];
		$this->assertSame(['name' => 'Pet'], $input['components'][2]['params']);
		$this->assertSame('header-example', $input['params']['tag']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported derived component: @query-param');
		$this->parser->signatureBase($this->rfcTestRequest(), $input['components'], $input['serialized'], 'example.com');
	}

	// the two RSA algorithms

	/** @return array{string, string} private and public PEM */
	private static function keyPair(): array {
		$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($res, $private);

		return [$private, openssl_pkey_get_details($res)['key']];
	}

	public function testVerifiesRsaV15Sha256(): void {
		[$private, $public] = self::keyPair();
		openssl_sign('the base', $signature, $private, OPENSSL_ALGO_SHA256);

		$this->assertTrue($this->parser->verify(HttpMessageSignatureParser::ALG_RSA_V1_5_SHA256, $public, 'the base', $signature));
		$this->assertFalse($this->parser->verify(HttpMessageSignatureParser::ALG_RSA_V1_5_SHA256, $public, 'another base', $signature));
	}

	public function testVerifiesAFreshRsaPssSha512Signature(): void {
		[$private, $public] = self::keyPair();
		$signature = RsaPssSigner::sign('the base', $private);

		$this->assertTrue($this->parser->verify(HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, $public, 'the base', $signature));
		$this->assertFalse($this->parser->verify(HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, $public, 'another base', $signature));
	}

	/**
	 * Where OpenSSL itself can do PSS, it has to agree with the hand-written
	 * side in both directions.
	 */
	public function testAgreesWithOpenSslOnRsaPss(): void {
		if (PHP_VERSION_ID < 80500) {
			$this->markTestSkipped('openssl_sign()/openssl_verify() take a padding argument from PHP 8.5');
		}
		[$private, $public] = self::keyPair();

		$this->assertTrue(
			openssl_verify('the base', RsaPssSigner::sign('the base', $private), $public, OPENSSL_ALGO_SHA512, OPENSSL_PKCS1_PSS_PADDING) === 1,
			'OpenSSL accepts what the test signer produces'
		);

		openssl_sign('the base', $native, $private, OPENSSL_ALGO_SHA512, OPENSSL_PKCS1_PSS_PADDING);
		$this->assertTrue(
			$this->parser->verify(HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, $public, 'the base', $native),
			'the verifier accepts what OpenSSL produces'
		);
	}

	public function testAcceptsAMaximumLengthSaltAsOpenSslSometimesProducesIt(): void {
		// RFC 9421 asks a signer for a 64-byte salt, but OpenSSL chooses the
		// longest salt that fits on some builds — which is what CI runs. A
		// verifier that insists on its own guess rejects a valid signature.
		[$private, $public] = self::keyPair();
		$signature = RsaPssSigner::sign('the base', $private, RsaPssSigner::SALT_MAX);

		$this->assertTrue(
			$this->parser->verify(HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, $public, 'the base', $signature)
		);
		$this->assertFalse(
			$this->parser->verify(HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, $public, 'another base', $signature),
			'a recovered salt length must not make the signature itself optional'
		);
	}

	public function testVerifyRefusesAnAlgorithmItDoesNotImplement(): void {
		[, $public] = self::keyPair();
		$this->assertFalse($this->parser->isSupportedAlgorithm('ed25519'));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported signature algorithm: ed25519');
		$this->parser->verify('ed25519', $public, 'the base', 'sig');
	}

	public function testVerifyWithAnUnusableKeyIsFalseNotAWarning(): void {
		$this->assertFalse($this->parser->verify(HttpMessageSignatureParser::ALG_RSA_V1_5_SHA256, '', 'the base', 'sig'));
		$this->assertFalse($this->parser->verify(HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, 'not a key', 'the base', 'sig'));
	}

	public function testAPssSignatureOfTheWrongLengthIsFalse(): void {
		$this->assertFalse($this->parser->verify(
			HttpMessageSignatureParser::ALG_RSA_PSS_SHA512, self::RFC_RSA_PSS_PUBLIC_KEY, self::B23_SIGNATURE_BASE, 'short'
		));
	}

	// derived components and field values

	public function testRebuildsEveryRequestSideDerivedComponent(): void {
		$request = $this->request('POST', '/apps/social/@alice/inbox?x=1', ['host' => 'Cloud.Example.com']);

		$base = $this->parser->signatureBase(
			$request,
			$this->components('@method', '@target-uri', '@authority', '@scheme', '@request-target', '@path', '@query'),
			'("@method")',
			'cloud.example.com'
		);

		$this->assertSame(implode("\n", [
			'"@method": POST',
			'"@target-uri": https://cloud.example.com/apps/social/@alice/inbox?x=1',
			'"@authority": cloud.example.com',
			'"@scheme": https',
			'"@request-target": /apps/social/@alice/inbox?x=1',
			'"@path": /apps/social/@alice/inbox',
			'"@query": ?x=1',
			'"@signature-params": ("@method")',
		]), $base);
	}

	public function testARequestWithoutAQueryIsSignedAsAQuestionMark(): void {
		$request = $this->request('POST', '/inbox', []);

		$base = $this->parser->signatureBase($request, $this->components('@query', '@path'), '()', 'h');

		$this->assertSame('"@query": ?' . "\n" . '"@path": /inbox' . "\n" . '"@signature-params": ()', $base);
	}

	public function testTheAuthorityGivenStandsInForTheHostHeaderToo(): void {
		// the same substitution the draft-cavage path makes for `host`
		$request = $this->request('POST', '/inbox', ['host' => 'other.example']);

		$base = $this->parser->signatureBase($request, $this->components('host'), '()', 'cloud.example.com');

		$this->assertStringStartsWith('"host": cloud.example.com' . "\n", $base);
	}

	public function testFieldValuesAreTrimmedAndUnfolded(): void {
		$request = $this->request('POST', '/inbox', ['x-thing' => "  a,\r\n\t b  "]);

		$base = $this->parser->signatureBase($request, $this->components('x-thing'), '()', 'h');

		$this->assertStringStartsWith('"x-thing": a, b' . "\n", $base);
	}

	public function testACoveredHeaderThatIsAbsentIsAnError(): void {
		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('covered header is missing: content-digest');
		$this->parser->signatureBase($this->request('POST', '/inbox', []), $this->components('content-digest'), '()', 'h');
	}

	public function testAComponentCoveredTwiceIsAnError(): void {
		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('covered twice: @method');
		$this->parser->signatureBase($this->request('POST', '/inbox', []), $this->components('@method', '@method'), '()', 'h');
	}

	public function testTheResponseOnlyStatusComponentIsRefusedByName(): void {
		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported derived component: @status');
		$this->parser->signatureBase($this->request('POST', '/inbox', []), $this->components('@status'), '()', 'h');
	}

	public function testAnUnknownDerivedComponentIsAnError(): void {
		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unknown derived component: @nonsense');
		$this->parser->signatureBase($this->request('POST', '/inbox', []), $this->components('@nonsense'), '()', 'h');
	}

	/** @return array<string, array{string}> */
	public static function refusedComponentParameters(): array {
		return array_combine(
			HttpMessageSignatureParser::REFUSED_COMPONENT_PARAMETERS,
			array_map(fn (string $p): array => [$p], HttpMessageSignatureParser::REFUSED_COMPONENT_PARAMETERS)
		);
	}

	#[DataProvider('refusedComponentParameters')]
	public function testEachSerialisationChangingComponentParameterIsRefusedByName(string $parameter): void {
		$input = $this->parser->parseSignatureInput('sig=("content-digest";' . $parameter . ' "@method");created=1')['sig'];
		$request = $this->request('POST', '/inbox', ['content-digest' => 'sha-256=:x:']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported component parameter: ' . $parameter . ' on content-digest');
		$this->parser->signatureBase($request, $input['components'], $input['serialized'], 'h');
	}

	// RFC 8941 structured fields

	public function testParsesSeveralLabelsAndEveryParameterType(): void {
		$header = 'sig1=("@method" "@target-uri" "content-digest");created=1618884473;keyid="https://remote.example/users/bob#main-key";alg="rsa-v1_5-sha256";nonce="b3k2pp5k7z-50gnwp.yemd";tag="ap", sig2=();created=1;expires=2;keyid="k";alg=ed25519;flag';

		$inputs = $this->parser->parseSignatureInput($header);

		$this->assertSame(['sig1', 'sig2'], array_keys($inputs));
		$this->assertSame(['@method', '@target-uri', 'content-digest'], array_column($inputs['sig1']['components'], 'name'));
		$this->assertSame([
			'created' => 1618884473,
			'keyid' => 'https://remote.example/users/bob#main-key',
			'alg' => 'rsa-v1_5-sha256',
			'nonce' => 'b3k2pp5k7z-50gnwp.yemd',
			'tag' => 'ap',
		], $inputs['sig1']['params']);
		$this->assertSame([], $inputs['sig2']['components']);
		$this->assertSame(
			['created' => 1, 'expires' => 2, 'keyid' => 'k', 'alg' => 'ed25519', 'flag' => true],
			$inputs['sig2']['params'],
			'a token and a valueless parameter parse too'
		);
		$this->assertSame('();created=1;expires=2;keyid="k";alg=ed25519;flag', $inputs['sig2']['serialized']);
	}

	public function testParsesASignatureHeaderIntoRawBytesPerLabel(): void {
		$signatures = $this->parser->parseSignature('sig1=:' . base64_encode('one') . ':, sig2=:' . base64_encode('two') . ':');

		$this->assertSame(['sig1' => 'one', 'sig2' => 'two'], $signatures);
	}

	public function testAnEmptyHeaderHasNoMembers(): void {
		$this->assertSame([], $this->parser->parseSignatureInput(''));
		$this->assertSame([], $this->parser->parseSignature(''));
	}

	public function testAStringWithEscapesRoundTrips(): void {
		$inputs = $this->parser->parseSignatureInput('s=();keyid="a\\"b\\\\c"');

		$this->assertSame('a"b\\c', $inputs['s']['params']['keyid']);
	}

	/** @return array<string, array{string, string}> */
	public static function malformedSignatureInputs(): array {
		return [
			'not an inner list' => ['sig1="@method"', 'is not an inner list'],
			'component that is a token, not a string' => ['sig1=(method)', 'component identifiers must be strings'],
			'unclosed inner list' => ['sig1=("@method"', 'not closed'],
			'items not separated' => ['sig1=("@method""@path")', 'separated by a space'],
			'trailing comma' => ['sig1=();created=1,', 'trailing comma'],
			'unterminated string' => ['sig1=("@method);created=1', 'unterminated string'],
			'bad escape' => ['sig1=();keyid="a\\nb"', 'bad escape'],
			'uppercase label' => ['Sig1=()', 'expected a key'],
			'garbage between members' => ['sig1=() sig2=()', 'unexpected'],
			'bad boolean' => ['sig1=();flag=?2', 'bad boolean'],
		];
	}

	#[DataProvider('malformedSignatureInputs')]
	public function testAMalformedSignatureInputIsRefusedAsASignatureError(string $header, string $message): void {
		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage($message);
		$this->parser->parseSignatureInput($header);
	}

	/** @return array<string, array{string, string}> */
	public static function malformedSignatures(): array {
		return [
			'not a byte sequence' => ['sig1="abc"', 'is not a byte sequence'],
			'unterminated byte sequence' => ['sig1=:abc', 'bad byte sequence'],
			'not base64' => ['sig1=:ab=c:', 'not base64'],
			'no value' => ['sig1', 'is not a byte sequence'],
		];
	}

	#[DataProvider('malformedSignatures')]
	public function testAMalformedSignatureIsRefusedAsASignatureError(string $header, string $message): void {
		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage($message);
		$this->parser->parseSignature($header);
	}
}
