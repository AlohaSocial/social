# AT Protocol interoperability fixtures

Vendored from [bluesky-social/atproto-interop-tests](https://github.com/bluesky-social/atproto-interop-tests), downloaded 2026-10-07. The upstream fixtures are CC0 (see `LICENSE-CC0`).

`ProtocolTest` checks byte-for-byte canonical encoding/CIDs, official K256 signature acceptance (including rejecting high-S signatures), and MST key heights. These tests run offline and do not register accounts on the public network. They do not replace a relay/AppView integration test.
