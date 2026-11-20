/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The offline recovery tool's computations, mirroring the PHP: the key a
 * recovery phrase stands for (`Mnemonic`), its did:key, DAG-CBOR and CIDs
 * (`DagCbor`), the PLC operation that moves the DID to another server
 * (`PlcOperation`) and its low-S ECDSA signature (`PrivateKey::sign()`).
 */

import { hmac } from '@noble/hashes/hmac.js'
import { sha256 } from '@noble/hashes/sha2.js'
import * as secp from '@noble/secp256k1'

secp.hashes.sha256 = sha256
secp.hashes.hmacSha256 = (key, message) => hmac(sha256, key, message)

/** what the entropy is hashed after: `Mnemonic::KEY_PREFIX` */
export const KEY_PREFIX = 'social.atproto.recovery.v1'

const BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'
const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567'
const DID_KEY_PREFIX = [0xe7, 0x01]
const PRIVATE_KEY_PREFIX = [0x81, 0x26]
const CID_PREFIX = [0x01, 0x71, 0x12, 0x20]

/** A message for the person, by the key the page's strings use. */
export class RecoveryError extends Error {
	/**
	 * @param {string} code which of the page's messages says what went wrong
	 */
	constructor(code) {
		super(code)
		this.code = code
	}
}

/**
 * @param {...(Uint8Array|number[])} parts the pieces, in order
 * @return {Uint8Array}
 */
function concat(...parts) {
	const out = new Uint8Array(parts.reduce((length, part) => length + part.length, 0))
	let offset = 0
	for (const part of parts) {
		out.set(part, offset)
		offset += part.length
	}
	return out
}

/**
 * @param {Uint8Array} bytes what to write
 * @return {string}
 */
export function toHex(bytes) {
	return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/**
 * @param {string} hex two digits a byte
 * @return {Uint8Array}
 */
export function fromHex(hex) {
	if (!/^(?:[0-9a-f]{2})*$/i.test(hex)) {
		throw new RecoveryError('not-a-key')
	}
	return Uint8Array.from(hex.match(/../g) ?? [], (pair) => parseInt(pair, 16))
}

/**
 * @param {Uint8Array} bytes what to write
 * @return {string} base58btc, the Bitcoin alphabet
 */
export function base58(bytes) {
	let number = 0n
	for (const byte of bytes) {
		number = number * 256n + BigInt(byte)
	}
	let out = ''
	while (number > 0n) {
		out = BASE58[Number(number % 58n)] + out
		number /= 58n
	}
	for (const byte of bytes) {
		if (byte !== 0) {
			break
		}
		out = '1' + out
	}
	return out
}

/**
 * @param {string} text what to read
 * @return {Uint8Array}
 */
export function fromBase58(text) {
	let number = 0n
	for (const character of text) {
		const digit = BASE58.indexOf(character)
		if (digit < 0) {
			throw new RecoveryError('not-a-key')
		}
		number = number * 58n + BigInt(digit)
	}
	const bytes = []
	while (number > 0n) {
		bytes.unshift(Number(number % 256n))
		number /= 256n
	}
	for (const character of text) {
		if (character !== '1') {
			break
		}
		bytes.unshift(0)
	}
	return Uint8Array.from(bytes)
}

/**
 * @param {Uint8Array} bytes what to write
 * @return {string} RFC 4648 base32, lower case, without padding
 */
function base32(bytes) {
	let out = ''
	let buffer = 0
	let bits = 0
	for (const byte of bytes) {
		buffer = (buffer << 8) | byte
		bits += 8
		while (bits >= 5) {
			out += BASE32[(buffer >>> (bits - 5)) & 31]
			bits -= 5
		}
	}
	if (bits > 0) {
		out += BASE32[(buffer << (5 - bits)) & 31]
	}
	return out
}

/**
 * @param {Uint8Array} bytes what to write
 * @return {string} base64url without padding
 */
function base64url(bytes) {
	return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * @param {string} text what to read
 * @return {Uint8Array}
 */
function fromBase64url(text) {
	const plain = atob(text.replace(/-/g, '+').replace(/_/g, '/'))
	return Uint8Array.from(plain, (character) => character.charCodeAt(0))
}

/**
 * The private key twelve words stand for, as `Mnemonic::keyFromPhrase()`
 * derives it: BIP-39's eleven-bit indexes and checksum over sixteen bytes
 * of entropy, then SHA-256 of the prefix and the entropy.
 *
 * @param {string} phrase the twelve words, any case and spacing
 * @param {string[]} words BIP-39's English list
 * @return {Uint8Array} the 32-byte secp256k1 secret
 */
export function keyFromPhrase(phrase, words) {
	const given = phrase.trim().toLowerCase().split(/\s+/)
	if (given.length !== 12) {
		throw new RecoveryError('not-twelve-words')
	}
	let bits = ''
	for (const word of given) {
		const index = words.indexOf(word)
		if (index < 0) {
			throw new RecoveryError('unknown-word')
		}
		bits += index.toString(2).padStart(11, '0')
	}
	const entropy = Uint8Array.from({ length: 16 }, (_, i) => parseInt(bits.slice(i * 8, i * 8 + 8), 2))
	if ((sha256(entropy)[0] >> 4) !== parseInt(bits.slice(128), 2)) {
		throw new RecoveryError('phrase-does-not-check')
	}
	return sha256(concat(new TextEncoder().encode(KEY_PREFIX), entropy))
}

/**
 * The private key from what the person typed: the twelve words, the key
 * as hex (64 characters) or as multibase (`z…`, secp256k1-priv).
 *
 * @param {string} input as the person typed it
 * @param {string[]} words BIP-39's English list
 * @return {Uint8Array}
 */
export function privateKeyFrom(input, words) {
	const text = input.trim()
	if (/^[0-9a-f]{64}$/i.test(text)) {
		return fromHex(text)
	}
	if (/^z[1-9A-HJ-NP-Za-km-z]+$/.test(text)) {
		const bytes = fromBase58(text.slice(1))
		if (bytes.length !== 34 || bytes[0] !== PRIVATE_KEY_PREFIX[0] || bytes[1] !== PRIVATE_KEY_PREFIX[1]) {
			throw new RecoveryError('not-a-key')
		}
		return bytes.slice(2)
	}
	return keyFromPhrase(text, words)
}

/**
 * @param {Uint8Array} secret the 32-byte private key
 * @return {{didKey: string, multibase: string, hex: string}} the key as the settings showed it
 */
export function keyForms(secret) {
	if (!secp.utils.isValidSecretKey(secret)) {
		throw new RecoveryError('not-a-key')
	}
	return {
		didKey: 'did:key:z' + base58(concat(DID_KEY_PREFIX, secp.getPublicKey(secret, true))),
		multibase: 'z' + base58(concat(PRIVATE_KEY_PREFIX, secret)),
		hex: toHex(secret),
	}
}

/**
 * @param {number} major the CBOR major type
 * @param {number} argument the length or the value
 * @return {number[]} the shortest CBOR head
 */
function head(major, argument) {
	const type = major << 5
	if (argument < 24) {
		return [type | argument]
	}
	if (argument <= 0xff) {
		return [type | 24, argument]
	}
	if (argument <= 0xffff) {
		return [type | 25, argument >> 8, argument & 0xff]
	}
	if (argument <= 0xffffffff) {
		return [type | 26, (argument >>> 24) & 0xff, (argument >> 16) & 0xff, (argument >> 8) & 0xff, argument & 0xff]
	}
	throw new RecoveryError('not-an-operation')
}

/**
 * @param {string} a one map key
 * @param {string} b the other
 * @return {number} DRISL's map key order: shorter first, then by bytes
 */
function keyOrder(a, b) {
	const left = new TextEncoder().encode(a)
	const right = new TextEncoder().encode(b)
	if (left.length !== right.length) {
		return left.length - right.length
	}
	for (let i = 0; i < left.length; i++) {
		if (left[i] !== right[i]) {
			return left[i] - right[i]
		}
	}
	return 0
}

/**
 * The DAG-CBOR of a JSON value, as `DagCbor::encode()` writes it: what a
 * PLC operation holds — maps, lists, text, whole numbers, booleans, null.
 *
 * @param {object|Array<unknown>|string|number|boolean|null} value a JSON value
 * @return {Uint8Array}
 */
export function dagCbor(value) {
	const out = []
	const write = (item) => {
		if (item === null) {
			out.push(0xf6)
		} else if (item === true || item === false) {
			out.push(item ? 0xf5 : 0xf4)
		} else if (typeof item === 'number') {
			if (!Number.isSafeInteger(item)) {
				throw new RecoveryError('not-an-operation')
			}
			out.push(...(item >= 0 ? head(0, item) : head(1, -1 - item)))
		} else if (typeof item === 'string') {
			const bytes = new TextEncoder().encode(item)
			out.push(...head(3, bytes.length), ...bytes)
		} else if (Array.isArray(item)) {
			out.push(...head(4, item.length))
			item.forEach(write)
		} else if (typeof item === 'object') {
			const keys = Object.keys(item).sort(keyOrder)
			out.push(...head(5, keys.length))
			for (const key of keys) {
				write(key)
				write(item[key])
			}
		} else {
			throw new RecoveryError('not-an-operation')
		}
	}
	write(value)
	return Uint8Array.from(out)
}

/**
 * @param {object} operation a signed operation
 * @return {string} its CID, which the next operation names as `prev`
 */
export function cidOf(operation) {
	return 'b' + base32(concat(CID_PREFIX, sha256(dagCbor(operation))))
}

/**
 * @param {object} operation a PLC operation
 * @return {object} the operation without its signature
 */
function unsigned(operation) {
	const { sig, ...rest } = operation // eslint-disable-line no-unused-vars
	return rest
}

/**
 * The keys an operation gives authority over the next one, highest first.
 *
 * @param {object} operation a PLC operation
 * @return {string[]}
 */
export function rotationKeysOf(operation) {
	if (operation?.type === 'create') {
		return [operation.recoveryKey, operation.signingKey].filter((key) => typeof key === 'string')
	}
	return Array.isArray(operation?.rotationKeys) ? operation.rotationKeys.filter((key) => typeof key === 'string') : []
}

/**
 * The operation that hands the DID to another server, as
 * `PlcOperation::fromCredentials()` builds it: the person's key first,
 * then the rotation keys the new server recommends, at most five.
 *
 * @param {object} credentials the new server's `getRecommendedDidCredentials`
 * @param {string} didKey the person's own key
 * @param {string} prev the CID of the DID's latest operation
 * @return {object} the unsigned operation
 */
export function buildOperation(credentials, didKey, prev) {
	const signing = String(credentials?.verificationMethods?.atproto ?? '')
	const handle = String(credentials?.alsoKnownAs?.[0] ?? '').replace(/^at:\/\//, '')
	const endpoint = String(credentials?.services?.atproto_pds?.endpoint ?? '')
	const rotation = [didKey]
	for (const key of Array.isArray(credentials?.rotationKeys) ? credentials.rotationKeys : []) {
		if (typeof key === 'string' && key.startsWith('did:key:') && !rotation.includes(key)) {
			rotation.push(key)
		}
	}
	if (!signing.startsWith('did:key:') || handle === '' || !/^https?:\/\//.test(endpoint)) {
		throw new RecoveryError('credentials-unusable')
	}
	return {
		type: 'plc_operation',
		rotationKeys: rotation.slice(0, 5),
		verificationMethods: { atproto: signing },
		alsoKnownAs: ['at://' + handle],
		services: { atproto_pds: { type: 'AtprotoPersonalDataServer', endpoint } },
		prev,
	}
}

/**
 * Signs with the person's key, as `PlcOperation::sign()` does: ECDSA over
 * SHA-256 of the DAG-CBOR without `sig`, low-S, raw r‖s, base64url.
 *
 * @param {object} operation a PLC operation
 * @param {Uint8Array} secret the 32-byte private key
 * @return {object} the signed operation
 */
export function signOperation(operation, secret) {
	const signature = secp.sign(dagCbor(unsigned(operation)), secret, { lowS: true })
	return { ...unsigned(operation), sig: base64url(signature) }
}

/**
 * @param {object} operation a signed operation
 * @param {string} didKey a secp256k1 did:key
 * @return {boolean} whether $didKey made the signature, in its low-S form
 */
export function verifyOperation(operation, didKey) {
	if (typeof operation?.sig !== 'string' || !didKey.startsWith('did:key:z')) {
		return false
	}
	const prefixed = fromBase58(didKey.slice('did:key:z'.length))
	if (prefixed[0] !== DID_KEY_PREFIX[0] || prefixed[1] !== DID_KEY_PREFIX[1]) {
		return false
	}
	try {
		return secp.verify(fromBase64url(operation.sig), dagCbor(unsigned(operation)), prefixed.slice(2), { lowS: true })
	} catch {
		return false
	}
}

/**
 * Everything the page needs to send: the signed operation that points the
 * DID at the new server, once it is clear the key may sign it.
 *
 * @param {object} options what the person gave
 * @param {Uint8Array} options.secret their key
 * @param {object} options.last the DID's latest operation, signed
 * @param {object} options.credentials the new server's recommendation
 * @return {{operation: object, cid: string, prev: string, didKey: string}}
 */
export function recover({ secret, last, credentials }) {
	if (last?.type !== 'plc_operation' && last?.type !== 'create') {
		throw new RecoveryError(last?.type === 'plc_tombstone' ? 'did-tombstoned' : 'not-an-operation')
	}
	const { didKey } = keyForms(secret)
	if (!rotationKeysOf(last).includes(didKey)) {
		throw new RecoveryError('key-not-listed')
	}
	const prev = cidOf(last)
	const operation = signOperation(buildOperation(credentials, didKey, prev), secret)
	return { operation, cid: cidOf(operation), prev, didKey }
}

/**
 * @return {Uint8Array} a fresh secp256k1 secret, for a signing key of the person's own
 */
export function newSigningKey() {
	return secp.utils.randomSecretKey()
}

/**
 * Gives the DID a signing key of the person's own and puts their key first,
 * keeping the rest of the latest operation: for when the old server can no
 * longer sign for the account.
 *
 * @param {object} options what the person gave
 * @param {Uint8Array} options.secret their key, which signs
 * @param {object} options.last the DID's latest operation, signed
 * @param {Uint8Array} options.signingSecret the new signing key
 * @return {{operation: object, cid: string, prev: string, didKey: string}}
 */
export function rekey({ secret, last, signingSecret }) {
	if (last?.type !== 'plc_operation') {
		throw new RecoveryError(last?.type === 'plc_tombstone' ? 'did-tombstoned' : 'not-an-operation')
	}
	const { didKey } = keyForms(secret)
	const listed = rotationKeysOf(last)
	if (!listed.includes(didKey)) {
		throw new RecoveryError('key-not-listed')
	}
	const prev = cidOf(last)
	const operation = signOperation({
		type: 'plc_operation',
		rotationKeys: [didKey, ...listed.filter((key) => key !== didKey)].slice(0, 5),
		verificationMethods: { ...(last.verificationMethods ?? {}), atproto: keyForms(signingSecret).didKey },
		alsoKnownAs: Array.isArray(last.alsoKnownAs) ? last.alsoKnownAs : [],
		services: last.services ?? {},
		prev,
	}, secret)
	return { operation, cid: cidOf(operation), prev, didKey }
}

/**
 * The inter-service token a new server takes for creating an account
 * with an existing DID, as `ServiceAuth::token()` writes one: ES256K,
 * signed with the DID's signing key.
 *
 * @param {object} options what to sign and for whom
 * @param {Uint8Array} options.signingSecret the DID's signing key
 * @param {string} options.did the issuer
 * @param {string} options.audience the new server's DID
 * @param {string} options.method the method the token is good for
 * @param {number} options.now seconds since the epoch
 * @param {string} options.jti a one-time id
 * @return {string}
 */
export function serviceAuthToken({ signingSecret, did, audience, method, now, jti }) {
	const encode = (value) => base64url(new TextEncoder().encode(JSON.stringify(value)))
	const signed = encode({ typ: 'JWT', alg: 'ES256K' }) + '.' + encode({ iss: did, aud: audience, lxm: method, jti, iat: now, exp: now + 180 })
	return signed + '.' + base64url(secp.sign(new TextEncoder().encode(signed), signingSecret, { lowS: true }))
}
