# AT Protocol interoperability fixtures

Vendored from [bluesky-social/atproto-interop-tests](https://github.com/bluesky-social/atproto-interop-tests), downloaded 2026-10-07. The upstream fixtures are CC0 (see `LICENSE-CC0`).

`ProtocolTest` checks byte-for-byte canonical encoding/CIDs, official K256 signature acceptance (including rejecting high-S signatures), and MST key heights. These tests run offline and do not register accounts on the public network. They do not replace a relay/AppView integration test.

`mst-reference-roots.json` records roots independently compared against
`@atproto/repo` 0.11.0 (`MST.create(new MemoryBlockstore())`, then `add` for
each generated path and `getPointer`). Cases cover 0, 1, 2, 10, 100 and 1000
records. Path i is `app.bsky.feed.post/` plus the first 13 hex characters of
SHA256(decimal i); value CID is DAG-CBOR SHA256 of `{text: "record " + i}`.
The corresponding PHP test regenerates these inputs. Verified 2026-10-07.
