#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Checks a live instance from the outside the way the Bluesky network sees
# it: the PDS, the service identity, one account's handle and DID, the
# firehose, the relay and the AppView. Read-only: GET requests and one
# WebSocket handshake, nothing is written anywhere.
#
#   contrib/atproto-live-probe.sh <instance host> <handle>
#   contrib/atproto-live-probe.sh social.example.org alice.social.example.org
#
# PLC, RELAY and APPVIEW override the network's addresses. The exit status
# is the number of failed checks. See docs/Atproto-Live-Test.md.

set -u

HOST=${1:-}
HANDLE=${2:-}
PLC=${PLC:-https://plc.directory}
RELAY=${RELAY:-https://bsky.network}
APPVIEW=${APPVIEW:-https://public.api.bsky.app}
CHAT=${CHAT:-https://api.bsky.chat}

if [ -z "$HOST" ] || [ -z "$HANDLE" ]; then
	echo "usage: $0 <instance host> <handle>" >&2
	exit 64
fi
for tool in curl jq; do
	command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 64; }
done

failed=0
pass() { printf 'PASS  %s\n' "$1"; }
warn() { printf 'WARN  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; failed=$((failed + 1)); }

# the body of a GET, or nothing when it is not a 200
get() {
	curl -fsS --max-time 15 -H 'Accept: application/json' "$1" 2>/dev/null
}

xrpc() {
	get "$1/xrpc/$2"
}

# --- the PDS --------------------------------------------------------------

health=$(xrpc "https://$HOST" _health)
if [ -n "$health" ]; then
	pass "PDS answers at https://$HOST/xrpc/ ($(jq -r '.version // "no version"' <<<"$health"))"
else
	fail "https://$HOST/xrpc/_health does not answer: the root rules for /xrpc/ are missing (contrib/webserver)"
fi

server=$(xrpc "https://$HOST" com.atproto.server.describeServer)
if [ "$(jq -r '.did // ""' <<<"$server" 2>/dev/null)" = "did:web:$HOST" ]; then
	pass "describeServer names did:web:$HOST"
else
	fail "describeServer does not name did:web:$HOST"
fi

service=$(get "https://$HOST/.well-known/did.json")
if [ "$(jq -r '.id // ""' <<<"$service" 2>/dev/null)" = "did:web:$HOST" ]; then
	pass "/.well-known/did.json is the service identity"
else
	fail "/.well-known/did.json is not did:web:$HOST: the root rule for it is missing"
fi

# --- the account ------------------------------------------------------------

did=$(curl -fsS --max-time 15 "https://$HANDLE/.well-known/atproto-did" 2>/dev/null | tr -d '[:space:]')
case "$did" in
	did:plc:*) pass "https://$HANDLE/.well-known/atproto-did is $did" ;;
	*)
		fail "https://$HANDLE/.well-known/atproto-did does not answer a DID: check the wildcard DNS record, the wildcard certificate and the wildcard-host rule"
		echo "No DID, so nothing more about the account can be checked."
		exit "$failed"
		;;
esac

if command -v dig >/dev/null; then
	txt=$(dig +short TXT "_atproto.$HANDLE" | tr -d '"')
	if [ "$txt" = "did=$did" ]; then
		pass "_atproto.$HANDLE TXT names the same DID"
	elif [ -z "$txt" ]; then
		warn "no _atproto.$HANDLE TXT record; the HTTPS way is enough"
	else
		fail "_atproto.$HANDLE TXT says $txt, not did=$did"
	fi
fi

doc=$(get "$PLC/$did")
if [ -z "$doc" ]; then
	fail "$PLC does not know $did"
else
	[ "$(jq -r '.alsoKnownAs[0] // ""' <<<"$doc")" = "at://$HANDLE" ] \
		&& pass "the DID document says at://$HANDLE" \
		|| fail "the DID document's alsoKnownAs is $(jq -c '.alsoKnownAs' <<<"$doc"), not at://$HANDLE"
	endpoint=$(jq -r '.service[] | select(.id == "#atproto_pds") | .serviceEndpoint' <<<"$doc")
	[ "$endpoint" = "https://$HOST" ] \
		&& pass "the DID document's PDS is https://$HOST" \
		|| fail "the DID document's PDS is '$endpoint', not https://$HOST"
fi

resolved=$(xrpc "https://$HOST" "com.atproto.identity.resolveHandle?handle=$HANDLE" | jq -r '.did // ""' 2>/dev/null)
[ "$resolved" = "$did" ] && pass "the PDS resolves the handle" || fail "the PDS resolves the handle to '$resolved'"

commit=$(xrpc "https://$HOST" "com.atproto.sync.getLatestCommit?did=$did")
rev=$(jq -r '.rev // ""' <<<"$commit" 2>/dev/null)
[ -n "$rev" ] && pass "the repository's head is at rev $rev" || fail "getLatestCommit does not answer for $did"

# --- the firehose -----------------------------------------------------------

upgrade=$(curl -sS --http1.1 --max-time 5 -o /dev/null -w '%{http_code}' \
	-H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
	-H 'Sec-WebSocket-Key: c29jaWFsLXByb2JlLWtleQ==' \
	"https://$HOST/xrpc/com.atproto.sync.subscribeRepos" 2>/dev/null)
[ "$upgrade" = "101" ] \
	&& pass "the firehose takes a WebSocket" \
	|| fail "the firehose answers $upgrade, not 101: is occ social:atproto:serve running, and the proxy rule for subscribeRepos in place?"

# --- the network ------------------------------------------------------------

relay=$(xrpc "$RELAY" "com.atproto.sync.getRepoStatus?did=$did")
if [ -z "$relay" ]; then
	fail "$RELAY does not know $did: run occ social:atproto:crawl and look again in a minute"
else
	[ "$(jq -r '.active' <<<"$relay")" = "true" ] && pass "$RELAY has the repository, active" || fail "$RELAY has the repository but not active: $(jq -c . <<<"$relay")"
	relayRev=$(jq -r '.rev // ""' <<<"$relay")
	if [ "$relayRev" = "$rev" ]; then
		pass "$RELAY is at the same rev"
	else
		warn "$RELAY is at rev '$relayRev', the PDS at '$rev': fine for a moment after a post, not for long"
	fi
fi

# a relay limits how many accounts and events a new host may have, and
# throttles it past that: the posts of the accounts over the limit stop there
hoststatus=$(curl -sS --max-time 15 -H 'Accept: application/json' "$RELAY/xrpc/com.atproto.sync.getHostStatus?hostname=$HOST" 2>/dev/null)
case "$(jq -r '.status // .error // ""' <<<"$hoststatus" 2>/dev/null)" in
	active | idle) pass "$RELAY carries $HOST: $(jq -r '.status' <<<"$hoststatus"), $(jq -r '.accountCount' <<<"$hoststatus") accounts" ;;
	throttled) fail "$RELAY throttles $HOST at $(jq -r '.accountCount' <<<"$hoststatus") accounts: ask the relay's operator to raise this host's limit" ;;
	banned) fail "$RELAY has banned $HOST" ;;
	offline) fail "$RELAY finds $HOST offline: the firehose is not reachable from outside" ;;
	HostNotFound) fail "$RELAY has not crawled $HOST: run occ social:atproto:crawl" ;;
	*) warn "$RELAY did not say how it sees $HOST" ;;
esac

profile=$(xrpc "$APPVIEW" "app.bsky.actor.getProfile?actor=$did")
shown=$(jq -r '.handle // ""' <<<"$profile" 2>/dev/null)
case "$shown" in
	"$HANDLE") pass "$APPVIEW shows the account as $HANDLE" ;;
	handle.invalid) fail "$APPVIEW shows handle.invalid: it could not verify the handle against https://$HANDLE/.well-known/atproto-did" ;;
	"") fail "$APPVIEW does not know $did yet" ;;
	*) fail "$APPVIEW shows the account as $shown" ;;
esac

newest=$(xrpc "https://$HOST" "com.atproto.repo.listRecords?repo=$did&collection=app.bsky.feed.post&limit=1" | jq -r '.records[0].uri // ""' 2>/dev/null)
if [ -z "$newest" ]; then
	warn "the account has no post on Bluesky yet: write a public one and run this again"
else
	seen=$(xrpc "$APPVIEW" "app.bsky.feed.getPosts?uris=$newest" | jq -r '.posts[0].uri // ""' 2>/dev/null)
	[ "$seen" = "$newest" ] && pass "$APPVIEW has the newest post" || fail "$APPVIEW does not have $newest"
fi

# --- Bluesky apps -----------------------------------------------------------

chat=$(curl -sS --max-time 15 -o /dev/null -w '%{http_code}' "$CHAT/xrpc/_health" 2>/dev/null)
[ "$chat" = "200" ] && pass "the chat service answers" || warn "the chat service ($CHAT) answers $chat: direct messages with Bluesky will not work"

meta=$(get "https://$HOST/.well-known/oauth-authorization-server")
if [ -z "$meta" ]; then
	fail "/.well-known/oauth-authorization-server does not answer: Bluesky apps cannot sign in with OAuth"
elif jq -e '.dpop_signing_alg_values_supported | index("ES256")' <<<"$meta" >/dev/null 2>&1; then
	pass "OAuth for Bluesky apps is offered (DPoP, ES256)"
else
	fail "the OAuth metadata has no DPoP ES256, which Bluesky apps need"
fi
resource=$(get "https://$HOST/.well-known/oauth-protected-resource")
[ -n "$resource" ] && pass "/.well-known/oauth-protected-resource answers" || fail "/.well-known/oauth-protected-resource does not answer"

echo
[ "$failed" -eq 0 ] && echo "Everything the network needs is in place." || echo "$failed check(s) failed."
exit "$failed"
