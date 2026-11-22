#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Reads back, through the client API of the upgraded app, what seed.sh made
# with the old release: every post where it was, with its like, boost, reply
# and picture, the follow still standing and bob among alice's followers
# once, the followers-only post still hidden from a reader who is not logged
# in, and the client app still recognised by its secret. Reports everything
# missing and exits non-zero if anything is.
#
#   NC_URL=http://localhost:8080 python3 tests/Upgrade/verify.py

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

NC_URL = os.environ.get('NC_URL', 'http://localhost:8080')
SEED_STATE = os.environ.get('SEED_STATE', '/tmp/social-upgrade-seed.json')
PASSWORD = 'Upgrade-Test-2026!'
API = NC_URL + '/index.php/apps/social/api/v1'


def get(url, user=None):
    request = urllib.request.Request(url, headers={'OCS-APIRequest': 'true', 'Accept': 'application/json'})
    if user is not None:
        token = base64.b64encode(f'{user}:{PASSWORD}'.encode()).decode()
        request.add_header('Authorization', 'Basic ' + token)
    try:
        with urllib.request.urlopen(request) as response:
            return response.status, response.headers.get('Content-Type', ''), response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get('Content-Type', ''), error.read()


def post(url, data):
    request = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(), headers={'Accept': 'application/json'})
    try:
        with urllib.request.urlopen(request) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


def get_json(url, user=None):
    status, _, body = get(url, user)
    check(status == 200, f'GET {url} answered {status}: {body[:300]!r}')
    return json.loads(body)


failures = []


def check(condition, message):
    if not condition:
        failures.append(message)
        print('FAIL ' + message)
    return condition


def find(statuses, text):
    return next((s for s in statuses if text in s.get('content', '')), None)


seed = json.load(open(SEED_STATE))

alice = get_json(API + '/accounts/lookup?acct=alice', 'bob')
relationship = get_json(API + '/accounts/relationships?id[]=' + str(alice['id']), 'bob')
check(relationship and relationship[0].get('following') is True, f'bob no longer follows alice: {relationship}')
followers = get_json(API + '/accounts/' + str(alice['id']) + '/followers?limit=40', 'bob')
bobs = [f for f in followers if f.get('username') == 'bob']
check(len(bobs) == 1, f'bob is among alice\'s followers {len(bobs)} times')

home = get_json(API + '/timelines/home?limit=40', 'bob')
# replies are read in their conversations, not as cards of the home feed:
# the reply is looked for under the public post and on bob's profile below
for key in ('public', 'followers', 'media'):
    check(find(home, seed[key]) is not None, f'the {key} post is missing from bob\'s home timeline')
bob = get_json(API + '/accounts/lookup?acct=bob', 'bob')
bobs_posts = get_json(API + '/accounts/' + str(bob['id']) + '/statuses?limit=40', 'bob')
check(find(bobs_posts, seed['reply']) is not None, 'the reply is missing from bob\'s profile')

public = find(home, seed['public'])
if public is not None:
    check(public.get('favourited') is True, 'bob\'s like of the public post is gone')
    check(public.get('reblogged') is True, 'bob\'s boost of the public post is gone')
    check(any(t.get('name') == 'upgradeseed' for t in public.get('tags', [])), f'the hashtag is gone: {public.get("tags")}')
    # the totals, as a reader who is not logged in sees them: a logged-in
    # reader has them hidden by default
    totals = get_json(API + '/statuses/' + str(public['id']))
    for count in ('favourites_count', 'reblogs_count', 'replies_count'):
        check(totals.get(count, 0) >= 1, f'the public post\'s {count} is {totals.get(count)}')
    context = get_json(API + '/statuses/' + str(public['id']) + '/context', 'bob')
    check(find(context.get('descendants', []), seed['reply']) is not None, 'the reply is no longer threaded under the public post')

media = find(home, seed['media'])
if media is not None and check(len(media.get('media_attachments', [])) == 1, f'the picture is gone: {media.get("media_attachments")}'):
    attachment = media['media_attachments'][0]
    check(attachment.get('type') == 'image', f'the attachment is no longer an image: {attachment.get("type")}')
    check(attachment.get('description') == seed['media_description'],
          f'the attachment\'s description is {attachment.get("description")!r}, was {seed["media_description"]!r}')
    status, content_type, body = get(attachment['url'], 'bob')
    check(status == 200 and content_type.startswith('image/') and len(body) > 0,
          f'the picture does not load: {status} {content_type} at {attachment["url"]}')

followers_only = find(home, seed['followers'])
if followers_only is not None:
    check(followers_only.get('visibility') == 'private', f'the followers-only post is now {followers_only.get("visibility")}')
    status, _, body = get(API + '/statuses/' + str(followers_only['id']))
    check(status != 200 or seed['followers'].encode() not in body, 'the followers-only post is readable without logging in')

public_profile = get_json(API + '/accounts/' + str(alice['id']) + '/statuses?limit=40')
check(find(public_profile, seed['public']) is not None, 'the public post is missing from alice\'s profile for a reader who is not logged in')
check(find(public_profile, seed['followers']) is None, 'the followers-only post is on alice\'s profile for a reader who is not logged in')

# a secret that still matches is given a token for the app itself; one that
# no longer does is an unknown client
client = seed['client']


def app_token(secret):
    return post(NC_URL + '/index.php/apps/social/oauth/token', {
        'grant_type': 'client_credentials',
        'client_id': client['client_id'],
        'client_secret': secret,
    })


status, body = app_token(client['client_secret'])
check(status == 200 and json.loads(body).get('access_token', '') != '',
      f'the client app registered before the upgrade is not recognised by its secret: {status} {body[:200]!r}')
status, body = app_token(client['client_secret'] + 'x')
check(status == 401, f'a wrong secret for the client registered before the upgrade is not refused: {status} {body[:200]!r}')

if failures:
    print(f'{len(failures)} check(s) failed after the upgrade')
    sys.exit(1)
print('everything seeded before the upgrade is still there')
