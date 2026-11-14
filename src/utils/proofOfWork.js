/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The registration form's proof of work (`SignupChallengeService`): find a
 * nonce for which SHA-256(challenge ‖ nonce) starts with a number of zero
 * bits.
 *
 * SHA-256 is computed here rather than with SubtleCrypto: SubtleCrypto
 * exists only in a secure context, so a server reached over plain HTTP could
 * not be registered with, and its one-promise-per-hash interface is several
 * times slower for millions of short inputs.
 */

/** The round constants, in hexadecimal. */
const K = new Uint32Array((
	'428a2f98 71374491 b5c0fbcf e9b5dba5 3956c25b 59f111f1 923f82a4 ab1c5ed5 '
	+ 'd807aa98 12835b01 243185be 550c7dc3 72be5d74 80deb1fe 9bdc06a7 c19bf174 '
	+ 'e49b69c1 efbe4786 0fc19dc6 240ca1cc 2de92c6f 4a7484aa 5cb0a9dc 76f988da '
	+ '983e5152 a831c66d b00327c8 bf597fc7 c6e00bf3 d5a79147 06ca6351 14292967 '
	+ '27b70a85 2e1b2138 4d2c6dfc 53380d13 650a7354 766a0abb 81c2c92e 92722c85 '
	+ 'a2bfe8a1 a81a664b c24b8b70 c76c51a3 d192e819 d6990624 f40e3585 106aa070 '
	+ '19a4c116 1e376c08 2748774c 34b0bcb5 391c0cb3 4ed8aa4a 5b9cca4f 682e6ff3 '
	+ '748f82ee 78a5636f 84c87814 8cc70208 90befffa a4506ceb bef9a3f7 c67178f2 '
).trim().split(' ').map((word) => parseInt(word, 16)))

const INITIAL = Uint32Array.of(0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19)

const W = new Uint32Array(64)

/**
 * @param {number} x a 32-bit word
 * @param {number} n bits to rotate right by
 * @return {number}
 */
function ror(x, n) {
	return (x >>> n) | (x << (32 - n))
}

/**
 * Runs one 64-byte block through the compression function.
 *
 * @param {Uint32Array} state the eight words of the hash so far, updated in place
 * @param {Uint8Array} bytes where the block is
 * @param {number} offset where in `bytes` it starts
 */
function compress(state, bytes, offset) {
	for (let i = 0; i < 16; i++) {
		const o = offset + i * 4
		W[i] = (bytes[o] << 24) | (bytes[o + 1] << 16) | (bytes[o + 2] << 8) | bytes[o + 3]
	}
	for (let i = 16; i < 64; i++) {
		const w15 = W[i - 15]
		const w2 = W[i - 2]
		W[i] = W[i - 16] + (ror(w15, 7) ^ ror(w15, 18) ^ (w15 >>> 3)) + W[i - 7] + (ror(w2, 17) ^ ror(w2, 19) ^ (w2 >>> 10))
	}

	let a = state[0]
	let b = state[1]
	let c = state[2]
	let d = state[3]
	let e = state[4]
	let f = state[5]
	let g = state[6]
	let h = state[7]
	for (let i = 0; i < 64; i++) {
		const t1 = (h + (ror(e, 6) ^ ror(e, 11) ^ ror(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0
		const t2 = ((ror(a, 2) ^ ror(a, 13) ^ ror(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0
		h = g
		g = f
		f = e
		e = (d + t1) | 0
		d = c
		c = b
		b = a
		a = (t1 + t2) | 0
	}
	state[0] += a
	state[1] += b
	state[2] += c
	state[3] += d
	state[4] += e
	state[5] += f
	state[6] += g
	state[7] += h
}

/**
 * Writes the padding after `used` message bytes of a tail buffer: 0x80,
 * zeros, and the length of the whole message in bits.
 *
 * @param {Uint8Array} tail the last blocks of the message, long enough
 * @param {number} used message bytes at the start of `tail`
 * @param {number} total bytes of the whole message
 * @return {number} how many bytes of `tail` are blocks to hash
 */
function pad(tail, used, total) {
	const size = (((used + 8) >> 6) + 1) * 64
	tail[used] = 0x80
	tail.fill(0, used + 1, size - 8)
	const high = Math.floor(total / 0x20000000)
	const low = (total * 8) >>> 0
	for (let i = 0; i < 4; i++) {
		tail[size - 8 + i] = (high >>> (24 - i * 8)) & 0xff
		tail[size - 4 + i] = (low >>> (24 - i * 8)) & 0xff
	}

	return size
}

/**
 * SHA-256 of some bytes.
 *
 * @param {Uint8Array} bytes the message
 * @return {Uint8Array} the 32-byte digest
 */
export function sha256(bytes) {
	const length = bytes.length
	const tail = new Uint8Array((((length + 8) >> 6) + 1) * 64)
	tail.set(bytes)
	const size = pad(tail, length, length)

	const state = INITIAL.slice()
	for (let offset = 0; offset < size; offset += 64) {
		compress(state, tail, offset)
	}

	const digest = new Uint8Array(32)
	for (let i = 0; i < 32; i++) {
		digest[i] = (state[i >> 2] >>> (24 - (i % 4) * 8)) & 0xff
	}

	return digest
}

/**
 * How many bits at the start of some bytes are zero.
 *
 * @param {Uint8Array} bytes the digest
 * @return {number}
 */
export function leadingZeroBits(bytes) {
	let bits = 0
	for (const byte of bytes) {
		if (byte !== 0) {
			return bits + Math.clz32(byte) - 24
		}
		bits += 8
	}

	return bits
}

/**
 * The chance that a solution would have been found within this many
 * attempts, as a whole percentage below 100: the honest "how far along"
 * of a search whose length nobody knows in advance.
 *
 * @param {number} attempts nonces tried so far
 * @param {number} bits the difficulty
 * @return {number}
 */
export function progressOf(attempts, bits) {
	return Math.min(99, Math.floor(100 * (1 - Math.exp(-attempts / 2 ** bits))))
}

/**
 * How many bits at the start of a hash state are zero.
 *
 * @param {Uint32Array} state the eight words
 * @return {number}
 */
function leadingZeroWordBits(state) {
	let bits = 0
	for (const word of state) {
		if (word !== 0) {
			return bits + Math.clz32(word)
		}
		bits += 32
	}

	return bits
}

/** The longest nonce the server accepts (`SignupChallengeService::NONCE_MAX_LENGTH`). */
const NONCE_MAX_LENGTH = 32

/**
 * Finds a nonce, counting up in base 36, for which the challenge followed
 * by the nonce hashes to at least `bits` leading zero bits.
 *
 * The whole blocks of the challenge are hashed once; each attempt hashes
 * only the last ones. Works in batches and waits for the event loop between
 * them, so a caller on the page's own thread stays responsive and a
 * cancelled search stops.
 *
 * @param {string} challenge as the server issued it
 * @param {number} bits the difficulty
 * @param {object} [options] how to report and when to stop
 * @param {function(number): void} [options.onProgress] called with the attempts so far after each batch
 * @param {function(): boolean} [options.cancelled] the search ends, answering null, once this is true
 * @param {number} [options.batch] attempts between two reports
 * @return {Promise<string|null>} the nonce
 */
export async function solve(challenge, bits, { onProgress = () => {}, cancelled = () => false, batch = 50000 } = {}) {
	const prefix = new TextEncoder().encode(challenge)
	const whole = prefix.length - (prefix.length % 64)
	const midstate = INITIAL.slice()
	for (let offset = 0; offset < whole; offset += 64) {
		compress(midstate, prefix, offset)
	}
	const rest = prefix.length - whole
	const tail = new Uint8Array((((rest + NONCE_MAX_LENGTH + 8) >> 6) + 1) * 64)
	tail.set(prefix.subarray(whole))
	const state = new Uint32Array(8)

	let attempts = 0
	for (;;) {
		for (const end = attempts + batch; attempts < end; attempts++) {
			const nonce = attempts.toString(36)
			for (let i = 0; i < nonce.length; i++) {
				tail[rest + i] = nonce.charCodeAt(i)
			}
			const size = pad(tail, rest + nonce.length, prefix.length + nonce.length)
			state.set(midstate)
			for (let offset = 0; offset < size; offset += 64) {
				compress(state, tail, offset)
			}
			if (leadingZeroWordBits(state) >= bits) {
				return nonce
			}
		}
		onProgress(attempts)
		await new Promise((resolve) => setTimeout(resolve, 0))
		if (cancelled()) {
			return null
		}
	}
}
