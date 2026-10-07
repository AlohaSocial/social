// The independent reference implementation must accept PHP's actual database export.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { readCarWithRoot, MemoryBlockstore, Repo, verifyCommitSig } from '@atproto/repo'
const directory = process.argv[2]
assert(directory, 'Pass ATPROTO_INTEROP_EXPORT_DIR as the first argument')
const expected = JSON.parse(readFileSync(join(directory, 'publication.json'), 'utf8'))
const { root, blocks } = await readCarWithRoot(readFileSync(join(directory, 'publication.car')))
const repo = await Repo.load(new MemoryBlockstore(blocks), root)
assert.equal(repo.did, expected.did)
assert.equal(repo.version, 3)
assert(await verifyCommitSig(repo.commit, expected.signingPublic), 'Invalid repository signature')
const records = []
for await (const record of repo.walkRecords()) records.push(record)
const posts = records.filter((r) => r.collection === 'app.bsky.feed.post')
assert.equal(posts.length, expected.posts)
assert(records.some((r) => r.collection === 'app.bsky.actor.profile' && r.rkey === 'self'))
const reply = posts.find((r) => r.record.reply)
assert(reply, 'The actual composer reply must survive the CAR/MST round trip')
const parent = posts.find((r) => `at://${repo.did}/${r.collection}/${r.rkey}` === reply.record.reply.parent.uri)
assert(parent, 'Reply references a missing parent')
assert.equal(reply.record.reply.parent.cid, parent.cid.toString())
console.log(`Reference SDK accepted ${root}: valid signature, complete MST, ${posts.length} composer posts and native reply`)
