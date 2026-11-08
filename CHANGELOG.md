<!--
  - SPDX-FileCopyrightText: 2018-2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

## Unreleased

### Features

- **recommendations:** Remove required For You setup and combine automatically learned hashtag relevance, content signals, follows, freshness, and separately consented activity signals in one shared ranking path. Readers do not choose topics.
- **recommendations:** Recall topic-matching posts already read from a person’s saved Bluesky custom feeds, using a short-lived local cache and no synchronous AppView request.
- **recommendations:** Add a capped local public-trend candidate source to ranked For You discovery. Trend candidates still pass viewer visibility, language, moderation and explicit topic-match checks, and trend popularity does not grant a ranking bonus.
- **recommendations:** Recall a bounded set of public topic matches from the reader's second-degree follow graph, using only accepted local follow edges and rechecking post visibility before ranking.
- **recommendations:** Add an administrator-only ActivityPub/ATProto discovery health panel with seven-day hourly coverage, failure, rate-limit and latency summaries. The aggregates contain no search terms, topics, reader identities or post identifiers.
- **recommendations:** Add account-local behavioural learning for For You, with explicit off/on/paused consent, generation-safe resets, bounded fast/slow topic, author and format affinities, idempotent events, impressions and video-watch ratios. Learning defaults off and never infers topic or stance from reply text.
- **recommendations:** Use consented signals and accepted-follow relationships in new ranked sessions; apply bounded exposure-aware novelty and preserve an explanation for constrained exploration placements. Existing chronological feeds and delivered snapshot order remain stable.
- **recommendations:** Limit topic concentration to three placements in a rolling 20-post window and increase the penalty for additional repeats, so high-scoring topic clusters make room for varied posts when alternatives are available.
- **recommendations:** Add the disabled-by-default For You foundation: a bilingual internal classification catalog, revisioned activity-learning consent and reset/export controls, and an administrator kill switch. The existing hashtag feed remains available as a fallback.
- **recommendations:** Add bounded, ACL-filtered automatic candidate retrieval from indexed or capped recent posts, deterministic content features, and throttled background ActivityPub/Bluesky coverage fills.
- **recommendations:** Add a reproducible synthetic offline evaluation harness for For You ranking baselines, coverage, safety constraints, diversity, and runtime.

### Bug fixes

- **recommendations:** Batch Fediverse tag-provider requests, honour the shared host circuit breaker and `Retry-After`, and cap individual post hydration plus the total discovery pass.
- **recommendations:** Remove reader topic controls and their feedback operations; similarity feedback is derived from post content, and legacy topic feedback is inert. Remove the unused topic-specific candidate service so the For You pipeline has one automatic entry point.

## 0.26.239 (2026-10-13)

### Features

- **benchmark:** Limits, JSON report and a seed with media, mentions and tags ([3df77edca](https://github.com/AlohaSocial/social/commit/3df77edca331acad1c107bd4ce6f08f683d0ca3d))
- **web:** Fill the shorts feed with shorts only ([a20f286b1](https://github.com/AlohaSocial/social/commit/a20f286b1407d4523718ae0f3ba75ee85bc8b8b6))
- **api:** A timeline filter for short videos (only_short) ([608a7020d](https://github.com/AlohaSocial/social/commit/608a7020d4e6a974b93f6c1ccf70ca73098350d1))
- **occ:** Strip container metadata from stored videos and audio ([9d869d60d](https://github.com/AlohaSocial/social/commit/9d869d60d89f98a37f9d77af20276a99cf1b8b8b))
- **social:** Quote counts, and new counts on the page without a reload ([32c45aff8](https://github.com/AlohaSocial/social/commit/32c45aff83c253e46f5570512a9f62721c0cec1b))
- **social:** The instance verifies accounts, here and on Bluesky ([ee3d9945f](https://github.com/AlohaSocial/social/commit/ee3d9945f481192896bdc91fb82cef29ed8282fe))
- **social:** A person can switch their own presence on Bluesky off ([a11978f1f](https://github.com/AlohaSocial/social/commit/a11978f1fe552aba9e335b9a7031038f1720701f))
- **social:** A person's lists are one set, here and on Bluesky ([06ae46f32](https://github.com/AlohaSocial/social/commit/06ae46f32534e713773d788dde6f90a9cb1d43ba))
- **social:** Keep the counts of posts from elsewhere current ([f8522bc05](https://github.com/AlohaSocial/social/commit/f8522bc0517779c093c4279abecb6fc7eaf0436f))
- **social:** Direct messages with Bluesky behave like any conversation ([6bed58821](https://github.com/AlohaSocial/social/commit/6bed58821a2ebb9a7d65b7c40b8ad056f0669715))
- **atproto:** Say whether the relays carry this server ([ff462aa89](https://github.com/AlohaSocial/social/commit/ff462aa897669ed840a1febe882dd81ace41f765))
- **social:** Followed hashtags bring in their posts from every network ([f55db23dd](https://github.com/AlohaSocial/social/commit/f55db23dd9cff8b548de8a4d5c1ad67a2019e972))
- **social:** Followers and following lists show who an account has where it lives ([45511a77f](https://github.com/AlohaSocial/social/commit/45511a77f2efe334d5b0f343d6065716de744035))
- **social:** Honour blocks of local accounts, wherever they are made ([8485ebfdd](https://github.com/AlohaSocial/social/commit/8485ebfdd80710b662d30c36e6313d43459daa28))
- **social:** Direct messages with people on Bluesky, in Messages ([1dee7a243](https://github.com/AlohaSocial/social/commit/1dee7a243fa47a4e644ffa63f3c8c1bcd5f7be91))
- **social:** Subscribe to shared lists that mute or block everybody on them ([7e9fefe5c](https://github.com/AlohaSocial/social/commit/7e9fefe5c904ae7fdf71f12a11042e1b9a6e8b2b))
- **social:** Who can reply, as any mix of people and your own lists ([8a3c38b94](https://github.com/AlohaSocial/social/commit/8a3c38b940184778c356fbda6d2138be138f6464))
- **social:** Hide a reply in a conversation you started, everywhere ([e7b0f60ff](https://github.com/AlohaSocial/social/commit/e7b0f60ff66ccaba8a4b2dc75dce5d014f5f5ecb))
- **atproto:** Who may notify you, one setting in Social and in Bluesky apps ([ef951580c](https://github.com/AlohaSocial/social/commit/ef951580ccc6217e52e99dede0422df72aac11cb))
- **atproto:** Bookmarks the same in Social and in Bluesky apps ([59dfa4017](https://github.com/AlohaSocial/social/commit/59dfa401794baa22f5171a449e4323af16d03e20))
- **atproto:** Notice a quote its Bluesky author detached ([552758239](https://github.com/AlohaSocial/social/commit/552758239491aa486a335753d4679e210bdc9ece))
- **social:** Hashtags and searches that reach beyond this server ([46d6d47a7](https://github.com/AlohaSocial/social/commit/46d6d47a770d3672c18c765c9e0deb6f60d58878))
- **social:** Who liked, boosted and quoted a post, wherever they did ([fd3a01bc5](https://github.com/AlohaSocial/social/commit/fd3a01bc5633d6c8ea35e38a4f953f348f0e2633))
- **social:** Whole conversations, wherever the replies were written ([db20b8679](https://github.com/AlohaSocial/social/commit/db20b86798f605f1da71c2dd37655e2a48658a22))
- **atproto:** Read followed Bluesky accounts from Jetstream as they post ([f70e61fdd](https://github.com/AlohaSocial/social/commit/f70e61fdd219b145b4326e7ec40b77abfe871926))
- **atproto:** Mutes and muted words the same here and in Bluesky apps ([93a1e804c](https://github.com/AlohaSocial/social/commit/93a1e804c4d44b81de077105479893017d8e438b))
- **atproto:** Pronouns and website, both ways ([66a67c7c0](https://github.com/AlohaSocial/social/commit/66a67c7c0f8fd3f67aa505a19638b32507f0aea3))
- **atproto:** Detach a Bluesky quote, and show Bluesky quotes as standing ([7dcf582ba](https://github.com/AlohaSocial/social/commit/7dcf582bab485994524bffc9f778dd11483be501))
- **atproto:** Who may reply to a post, here, on peers and on Bluesky ([f0e325ce2](https://github.com/AlohaSocial/social/commit/f0e325ce2635e029ae7aacaa2fa46e7e2008b0c2))
- **atproto:** Publish blocks of Bluesky accounts, when the person chooses ([2b210807a](https://github.com/AlohaSocial/social/commit/2b210807a4866c3f06bd78f8887d6b92f17cdee4))
- **atproto:** A Bluesky app's direct messages, through this server ([a9ea2f7c4](https://github.com/AlohaSocial/social/commit/a9ea2f7c437ad043d46e44e362316f91b2e89c5d))
- **atproto:** Tell what only Bluesky tells, and keep the bell in step ([97e7b4aa8](https://github.com/AlohaSocial/social/commit/97e7b4aa8651ffbf917a28c0b742260a48e7c987))
- **atproto:** Show Bluesky's verified check on Bluesky accounts ([58810328e](https://github.com/AlohaSocial/social/commit/58810328e93f7489299f2baf363ba4249354fbba))
- **atproto:** Bluesky's trends and suggested accounts on Discover ([79680134d](https://github.com/AlohaSocial/social/commit/79680134df25c4d1c82b01de040d78e8a8a10233))
- **atproto:** Open a Bluesky starter pack and follow everybody in it ([353657ae8](https://github.com/AlohaSocial/social/commit/353657ae8ec83d9b52db5b664bf2409418545c1c))
- **atproto:** A Bluesky post's embedded feed, list or starter pack is a card ([a7ea9b357](https://github.com/AlohaSocial/social/commit/a7ea9b357e123d99d09d260837cc27e7e3af9ba7))
- **atproto:** Respect a Bluesky author's choice to turn quotes off ([b968ed392](https://github.com/AlohaSocial/social/commit/b968ed3925fffa9aca287e33cb78d9e1eea43361))
- **atproto:** Custom feeds and lists, and apps writing them ([b28e46ba6](https://github.com/AlohaSocial/social/commit/b28e46ba63d2582b95bf39233f41d5b57296202c))
- **atproto:** Granular OAuth permissions, and trusted apps ([124ccf6c4](https://github.com/AlohaSocial/social/commit/124ccf6c4c21db037f01c86252fa56eb83a61ce5))
- **atproto:** Honour a Bluesky thread's reply gate ([d24934ab4](https://github.com/AlohaSocial/social/commit/d24934ab4a03b3910710a045bf25319d287a280b))
- **atproto:** Pinned posts and banners on Bluesky, both ways ([e1ece4e84](https://github.com/AlohaSocial/social/commit/e1ece4e8476e14dfa644ffe03b9d195bb962765a))
- **atproto:** A moved account's likes, reposts and replies, as they were ([34fecd6de](https://github.com/AlohaSocial/social/commit/34fecd6de6f9d773312bb2df1627bf1bd871c5f0))
- **atproto:** Find a Bridgy Fed twin that has a domain of its own ([d13186c3f](https://github.com/AlohaSocial/social/commit/d13186c3ffdc31798e32134d2a786e5c4623c43c))
- **atproto:** A Bluesky app's profile editor sets the avatar and banner ([5723f712f](https://github.com/AlohaSocial/social/commit/5723f712fe2236ec4d38069088e4a9a808212587))
- **atproto:** A link card on Bluesky carries the page's picture ([49424a048](https://github.com/AlohaSocial/social/commit/49424a04805330643978cea68629adce607c3f2c))
- **atproto:** Phase 4e, a moved account's posts in its timeline ([ad2393125](https://github.com/AlohaSocial/social/commit/ad23931255dc5f09fd457696e719165f81912586))
- **atproto:** Phase 4d, Bridgy Fed twins and migration tools move an account here ([f9f0f243d](https://github.com/AlohaSocial/social/commit/f9f0f243d2c78f1d747beaa84947e61d0426fed6))
- **atproto:** Phase 4c, move a Bluesky account here ([950b9d045](https://github.com/AlohaSocial/social/commit/950b9d0458912d0a1b3822c44203f316a5a58004))
- **atproto:** Phase 4b, move a Bluesky account away ([cef8d3eac](https://github.com/AlohaSocial/social/commit/cef8d3eac63300fb3952073be8a08798ff207a40))
- **atproto:** Phase 4a, custom handles ([cecd3446e](https://github.com/AlohaSocial/social/commit/cecd3446e1bcca444c973883c247ed2f56d4084d))
- **atproto:** Phase 3e, Bluesky apps sign in with OAuth ([1abdb5606](https://github.com/AlohaSocial/social/commit/1abdb56064bc17925504f5f7f42a5adff08acd38))
- **atproto:** Phase 3d, video both ways ([042aec6a0](https://github.com/AlohaSocial/social/commit/042aec6a039f7061d591185677757178338f4fba))
- **atproto:** Phase 3c, Bluesky apps sign in with app passwords ([da92eeb69](https://github.com/AlohaSocial/social/commit/da92eeb698a59b12231575d56c9cb99f103fce37))
- **atproto:** Phase 3b, Bluesky moderation: blocks, reports, labelers ([02ba316ca](https://github.com/AlohaSocial/social/commit/02ba316ca7fc6eee7ef6dee39b5b8d48d80b45c6))
- **atproto:** Phase 3a, threads, quotes and deletes across both networks ([7a3ca0ecc](https://github.com/AlohaSocial/social/commit/7a3ca0ecc073245470ce6ac0ff68573157401ef2))
- **atproto:** Phase 2, reading Bluesky ([c9324095b](https://github.com/AlohaSocial/social/commit/c9324095bf37a280f03ee9db2fba02d843c87af3))
- **atproto:** Every account is a Bluesky account, hosted here ([edf12b682](https://github.com/AlohaSocial/social/commit/edf12b682e9e274d5f7e5b8917367f9a03fd1a5c))
- **web:** Activities as rows, like the posts in My Feed ([53c7fe8c6](https://github.com/AlohaSocial/social/commit/53c7fe8c6e951e2c16bba8f5cc380e2117734d32))
- **web:** Settings in six groups, with a search ([4f1c18808](https://github.com/AlohaSocial/social/commit/4f1c1880840dc8bb9bd9856fa8051b818a19423e))
- **admin:** Administration page in six groups, with a search ([2b9b7e0c6](https://github.com/AlohaSocial/social/commit/2b9b7e0c656e988d393a6ed6cb181c96ea45d62b))
- **web:** Give Aloha Social a look of its own ([d460c17ad](https://github.com/AlohaSocial/social/commit/d460c17ad5c4eb7f8bf6f4107c71ae64b16d1547))
- **files:** Show replies to a post made from Files as comments on the file ([d26757c9a](https://github.com/AlohaSocial/social/commit/d26757c9ad44d3528b523decd36d8fff504881e1))
- **web:** Direct messages in the feed's own look ([6f2686439](https://github.com/AlohaSocial/social/commit/6f26864390b0e4c21052ba41bd8939e9a44a5e4a))
- **web:** Switch between the feed's scopes without the page flickering ([d3383a12c](https://github.com/AlohaSocial/social/commit/d3383a12c16aca2c96172f1f75425f34675a7cb0))
- **web:** Mute a conversation from a mention or reply in Activities ([e09c53ba4](https://github.com/AlohaSocial/social/commit/e09c53ba4caaaa98f945ae09d3268778b5926c4e))
- **web:** The people waiting, on Activities and in its count ([d62b96834](https://github.com/AlohaSocial/social/commit/d62b96834ce4a60736ea1b1888d80f064677d93b))
- **web:** Who may reach you is part of Settings → Notifications ([e0520b569](https://github.com/AlohaSocial/social/commit/e0520b56968e9e770aad57e784bff16a276da7ba))
- **web:** The held senders at the top of Activities, where they are looked for ([f67007d17](https://github.com/AlohaSocial/social/commit/f67007d1727f2d18ec89dd9f06ad1ea64907fbcf))
- **web:** Who may reach you — the notification policy in Settings ([7284275f8](https://github.com/AlohaSocial/social/commit/7284275f881dc1f2a57fb2cb49185440a95365ee))
- **web:** Mute a conversation from the post menu ([db2409aeb](https://github.com/AlohaSocial/social/commit/db2409aebd19d0aa752af3add16f1076af5cd087))
- **api:** A status says whether the viewer muted its conversation ([ba3d0f76c](https://github.com/AlohaSocial/social/commit/ba3d0f76c7b5906dfc4e4a0c6d433601def8f2b4))
- **notifications:** Who may reach you — held and muted never ring, calm defaults for new accounts ([2033a28ed](https://github.com/AlohaSocial/social/commit/2033a28ed7cae55b740d60d03a70776384259d8f))
- **web:** A calmer feed — rows instead of cards, and the controls out of the way ([75deb7546](https://github.com/AlohaSocial/social/commit/75deb754651f4246993f9349fdcbf98486d22f09))
- **web:** Activities in the sidebar, no name in the composer, the Shorts bar only when there are some ([a070560bf](https://github.com/AlohaSocial/social/commit/a070560bfbadea9704a3c46707d344d9e3d4da56))
- **shorts:** The Home bar becomes the Shorts bar ([9b964b0df](https://github.com/AlohaSocial/social/commit/9b964b0df03236875dea61ca8a365118cb007b3c))
- **shorts:** Play 24-hour shorts at the top of the Shorts feed ([2cd390ff0](https://github.com/AlohaSocial/social/commit/2cd390ff0c1923890a262b59804a3bc2305cdd13))
- **shorts:** One New short dialog with a lifetime choice ([0fb64029f](https://github.com/AlohaSocial/social/commit/0fb64029f860d9cc747093fb0af7c13f3a4ad363))
- **web:** Hide the like, boost and follower numbers in the web app ([fcd489722](https://github.com/AlohaSocial/social/commit/fcd489722cd9134b2de06df876ad309301f04104))
- **api:** Hide the like, boost and follower numbers, unless the reader shows them ([a53523dc3](https://github.com/AlohaSocial/social/commit/a53523dc3328f7e1590a46e819dab987ba65e8a6))
- **api:** Honour account_id and paging in api/v2/search ([589906897](https://github.com/AlohaSocial/social/commit/589906897f95374ac8589db07d3c84ffcda22d94))
- **atproto:** Serve followers, following and Bluesky feeds ([4e597607e](https://github.com/AlohaSocial/social/commit/4e597607e30d87703d13279e17c6664e756e7bd4))
- **profile:** Add Fediverse and Bluesky network switch ([80ba9fc09](https://github.com/AlohaSocial/social/commit/80ba9fc09bd414500805df50f2d81d325582542d))
- **atproto:** Reject oversized profile images early ([a9a45ae8f](https://github.com/AlohaSocial/social/commit/a9a45ae8fe689b053c29b03f5968fc1fee8a386e))
- **atproto:** Allow removing profile images ([5dfefe413](https://github.com/AlohaSocial/social/commit/5dfefe413f0e5d2da7d8a545982df1874d8f9d29))
- **atproto:** Support native profile image uploads ([57dab9890](https://github.com/AlohaSocial/social/commit/57dab9890069da77578c4043ef58ced0eba4438e))
- **atproto:** Link profiles to canonical Bluesky ([d6d1c8c91](https://github.com/AlohaSocial/social/commit/d6d1c8c9194884351b332457a467b1f8aa17202e))
- **atproto:** Render profile banners ([86f134b7c](https://github.com/AlohaSocial/social/commit/86f134b7cc1ea8c8e344d75883039f9a30696ebd))
- **atproto:** Simplify app-password onboarding ([dc82d8416](https://github.com/AlohaSocial/social/commit/dc82d84167d1499e349f9d3387f86205d32ca0a6))
- **atproto:** Paginate the own profile feed ([ac5c442dd](https://github.com/AlohaSocial/social/commit/ac5c442dd5a57d0450c96c30a96eb29748249cd0))
- **atproto:** Preserve native media dimensions ([7fa166871](https://github.com/AlohaSocial/social/commit/7fa166871532cdda4782856cf69d194062641e1e))
- **profile:** Link native and fediverse views ([aaf69642a](https://github.com/AlohaSocial/social/commit/aaf69642a1c9e990438277965a9c0e2e93bd4b9f))
- **profile:** Add native reply composer ([a1f34a74f](https://github.com/AlohaSocial/social/commit/a1f34a74f648c94562fe0bb26fae75e3b1deb8f4))
- **profile:** Add native protocol switch ([67aa6949e](https://github.com/AlohaSocial/social/commit/67aa6949e67defb83cc8d3cd0d652f9f1051c7b2))
- **atproto:** Preserve AppView moderation labels ([c7791c976](https://github.com/AlohaSocial/social/commit/c7791c976b1617bff88be2dbffe3bc501f1477e0))
- **atproto:** Add reconnect guidance ([8811c7630](https://github.com/AlohaSocial/social/commit/8811c763077c5adfaa324461e6caf275b28a7bc4))
- **atproto:** Show profile statistics ([b1b86c04d](https://github.com/AlohaSocial/social/commit/b1b86c04d91e3e6119e7b75325dfef29159e838b))
- **atproto:** Retry failed mirror jobs ([df40d707d](https://github.com/AlohaSocial/social/commit/df40d707d2f84344be81a903da89c5b6313bbe1d))
- **atproto:** Show native profile metadata ([107d7de7c](https://github.com/AlohaSocial/social/commit/107d7de7cd07e188a6c26792875e912e5a2c91d8))
- **atproto:** Load native post threads ([89435f5e0](https://github.com/AlohaSocial/social/commit/89435f5e0e933af6d1b3522f7063fbcb8b5692f3))
- **atproto:** Paginate public profile feeds ([98819417f](https://github.com/AlohaSocial/social/commit/98819417fec51315c8430f33518677d2f20e4925))
- **atproto:** Publish native media blobs ([41c34fbad](https://github.com/AlohaSocial/social/commit/41c34fbad79902d981b64d81d5dd8551592400d8))
- **atproto:** Edit own native posts ([d79ff2952](https://github.com/AlohaSocial/social/commit/d79ff29524158507843115b503b6b65e5bb4db95))
- **atproto:** Add linked profile to account menu ([6b12a821f](https://github.com/AlohaSocial/social/commit/6b12a821f8b05227eafd1dece97ecc97baffbdbe))
- **atproto:** Allow own native post deletion ([0d8654a38](https://github.com/AlohaSocial/social/commit/0d8654a38976b2c3325af75292541030ad0ad2a5))
- **atproto:** Emit DID mention facets ([90541a8e7](https://github.com/AlohaSocial/social/commit/90541a8e77c33066316f533ab1a1a70fe15027ee))
- **atproto:** Mirror native quote embeds ([78b36c376](https://github.com/AlohaSocial/social/commit/78b36c37692fafb6be81fbad899c3cf0a9aedb49))
- **atproto:** Hydrate native viewer engagement state ([14689b299](https://github.com/AlohaSocial/social/commit/14689b299a796efb5114d939bde5fa91bd279c42))
- **atproto:** Edit own profile from native profile page ([f134396c5](https://github.com/AlohaSocial/social/commit/f134396c570295a48c096d8258f7e55a2d4a9a0c))
- **atproto:** Preserve rich post facets and link cards ([8f4225d29](https://github.com/AlohaSocial/social/commit/8f4225d299e173bbf5c2e71d5f6dd5e6b3aa325d))
- **atproto:** Preserve language labels on mirrored posts ([8bf09815a](https://github.com/AlohaSocial/social/commit/8bf09815ac69868b918a64c061c2ecc9ab7f4988))
- **atproto:** Link to own Bluesky profile ([769db968b](https://github.com/AlohaSocial/social/commit/769db968b93c25fdccd811ca5d3ead7ed707dafc))
- **atproto:** Enable native profile replies ([a8c4316d5](https://github.com/AlohaSocial/social/commit/a8c4316d5ef365e3e0a797e1b4e50a7a7fe31e11))
- **atproto:** Follow profiles natively ([baeeadab5](https://github.com/AlohaSocial/social/commit/baeeadab552b286a09992843018482eb00faafd2))
- **atproto:** Preserve profile interaction counters ([b53e3efc0](https://github.com/AlohaSocial/social/commit/b53e3efc0e010c448dc699f8af6967e61d96dba0))
- **atproto:** Edit linked profile metadata ([ad2529758](https://github.com/AlohaSocial/social/commit/ad25297586c030a104de2fa6e57a2f7480cbbba2))
- **atproto:** Add profile follow action ([735308b13](https://github.com/AlohaSocial/social/commit/735308b1338b05a01d4d322bac954250741b5b1b))
- **atproto:** Use native reactions for mirrored posts ([d1492f85a](https://github.com/AlohaSocial/social/commit/d1492f85ab191a570c263dace596b3a7488f2ff7))
- **atproto:** Route actor profiles through shared Social UI ([b47cd6361](https://github.com/AlohaSocial/social/commit/b47cd6361df7d5fdd69fa6669074a4b0a19c649b))
- **atproto:** Store explicit PDS endpoint separately ([2c70c3105](https://github.com/AlohaSocial/social/commit/2c70c3105c0b1ccb0036a0f3aa0a83a44d7bebb9))
- **atproto:** Add native engagement and handle profiles ([a247f8353](https://github.com/AlohaSocial/social/commit/a247f8353e4c12b82d6306a017b4a8a5448ab8ff))
- **atproto:** Add publication targets and shared profile feed ([9524bd511](https://github.com/AlohaSocial/social/commit/9524bd511fc3eb8510a790ebc07e87f50689349e))
- **atproto:** Mirror durable post edits ([78dd29507](https://github.com/AlohaSocial/social/commit/78dd295071aa88193c4434d99422879adea1277e))
- **atproto:** Mirror public posts and add account settings ([73e11a833](https://github.com/AlohaSocial/social/commit/73e11a833821e5428051701df900d68d2e5eb8ca))
- **atproto:** Resolve, read and follow Bluesky accounts ([d11f2dd7c](https://github.com/AlohaSocial/social/commit/d11f2dd7cbe9b350e6b1bcce056691b109cf2a70))
- **atproto:** The three Bluesky tables, the settings that govern them, and the reads of them ([b893a5e96](https://github.com/AlohaSocial/social/commit/b893a5e96dea4e23b70e92a9dc88cf89fa59111c))
- **ai:** Hide posts made with AI, mark your own, and say so on a post ([06e9f222f](https://github.com/AlohaSocial/social/commit/06e9f222f69e0e5ef205f6872bea256c10c07117))
- **ai:** A service for the mark on posts made with AI and the setting that hides them ([ee842c664](https://github.com/AlohaSocial/social/commit/ee842c6647bd8064f350964d02803c9b5d5210ea))
- **filters:** Label and hide posts made with AI in every list ([1ad004ad6](https://github.com/AlohaSocial/social/commit/1ad004ad6950a7ee86520ad9bad1ffc4dc504f15))
- **media:** Record the machine-generation provenance a picture states ([b783012a9](https://github.com/AlohaSocial/social/commit/b783012a965eafef4dff595cce0d214353145c4b))
- **filters:** A per-user switch to hide posts made with AI ([85637bf2e](https://github.com/AlohaSocial/social/commit/85637bf2e24c83b1313baec2a61c5db02a00f659))
- **migration:** Finish a move from the new side, when the old server is this app too ([bd8789d52](https://github.com/AlohaSocial/social/commit/bd8789d52801962bd38233f50f298f202e595b75))
- **migration:** Copy the newer posts of a finished move, from the same button ([9c80f5b82](https://github.com/AlohaSocial/social/commit/9c80f5b8249fa1e23616962a84acfc738f03555f))
- **migration:** Bookmarks and blocked domains come along, in and out ([d25d60599](https://github.com/AlohaSocial/social/commit/d25d60599fbef96fdf4b18cae4ef45d67f6e5da7))
- **migration:** Name the posts that could not be copied, with the reason ([45e6e712d](https://github.com/AlohaSocial/social/commit/45e6e712d18e848f71f01b463169c40332f4fb5a))
- **timeline:** Draw the home feed's line from the marker every client shares ([cec8d4f59](https://github.com/AlohaSocial/social/commit/cec8d4f594bd8deb82a55a2c98ac96ca66cdafb1))
- **migration:** Move away from the page, and behave moved afterwards ([3210cf606](https://github.com/AlohaSocial/social/commit/3210cf6060f3f1e1d12e96542d6518ec82f4674b))
- **migration:** Move here from nothing but the old handle ([f680588ef](https://github.com/AlohaSocial/social/commit/f680588efc6bff3ce9dde0e0180f56b4e3e8303e))
- **migration:** Run every import in the background, and show where it is ([1809bcf04](https://github.com/AlohaSocial/social/commit/1809bcf041bef43b10947b954c5fa1320b3885e1))
- **migration:** Take a handle wherever an actor address was demanded ([a053e802d](https://github.com/AlohaSocial/social/commit/a053e802d0053bc0cf71437448cdad0170918e30))
- **notifications:** Choose when notifications are delivered ([f19264297](https://github.com/AlohaSocial/social/commit/f192642979f5463ee6c7a52a3bc7843d27c6964a))
- **notifications:** A service for when notifications are delivered ([d0591ad63](https://github.com/AlohaSocial/social/commit/d0591ad63631c94b154b2f8861937ebaaf37a944))
- **api:** Read and change the notification delivery setting ([d21e5a651](https://github.com/AlohaSocial/social/commit/d21e5a651900cd815121296ee2552ac91e3524d4))
- **notifications:** Raise the held notifications as one digest ([5dadc76c6](https://github.com/AlohaSocial/social/commit/5dadc76c6657eb306b2e6281948532304f0d7020))
- **notifications:** Hold the bell for a digest or quiet hours ([419dc9cde](https://github.com/AlohaSocial/social/commit/419dc9cded9f413e1295c10f407af4fda56be041))
- **notifications:** Model and storage for a per-user delivery setting ([8f5821ddf](https://github.com/AlohaSocial/social/commit/8f5821ddf2b4c0eca039c20b8698a4169a9a0782))
- **interests:** Fill a short For you feed with what is popular, whatever its kind ([8a52c1e9b](https://github.com/AlohaSocial/social/commit/8a52c1e9bd8745792a9481678f31809ea66336a3))
- **counts:** Ask each remote server what a post's counts are now ([536e70e66](https://github.com/AlohaSocial/social/commit/536e70e6613979c1181ba39971aa7769033c7771))
- **timeline:** Say when a video post is still being converted ([fd0f51a4c](https://github.com/AlohaSocial/social/commit/fd0f51a4c26422aa0c3bc9766583b2c1a6bd0d1a))
- **api:** Tell the author when a status is held for its video ([1d1314fd1](https://github.com/AlohaSocial/social/commit/1d1314fd1a1eee8a58adcafa8af5f0e8da03edcd))
- The Aloha Social flower as the app icon ([0eccc294d](https://github.com/AlohaSocial/social/commit/0eccc294d21aab833a8d57eb92cdc1a1f369314b))
- Show the app as Aloha Social ([4c6e6bea2](https://github.com/AlohaSocial/social/commit/4c6e6bea22b8aed3e4c257158257698baca3fb2e))
- **interests:** For you for photos, videos and shorts ([b7c2d738f](https://github.com/AlohaSocial/social/commit/b7c2d738f0aa6a4b01f8367536a72c535528b963))
- **media:** Convert videos by default and hold a post until its video is ([1f95ab29f](https://github.com/AlohaSocial/social/commit/1f95ab29f1c051128d5406ab6a74f838a09810a7))
- **external:** Let people without an account register for Social ([8283f7eee](https://github.com/AlohaSocial/social/commit/8283f7eeee4933b53fdeaac30820f91375a5a2d4))
- **navigation:** Draw the open account drawer as liquid glass ([2b383e6f3](https://github.com/AlohaSocial/social/commit/2b383e6f36559e8a2a37e7ad1c009fa4a4a15697))
- **stories:** Add a story with the short composer ([250f627af](https://github.com/AlohaSocial/social/commit/250f627af3f94b35d56d23c73e7817dd30c34388))
- **shorts:** A composer made for posting a short ([28ed286e2](https://github.com/AlohaSocial/social/commit/28ed286e25212f70966f23e98d8035fb33532e84))
- **navigation:** Shorts in the sidebar, instead of a button on Videos ([f6f64d391](https://github.com/AlohaSocial/social/commit/f6f64d39121060e897b667a022563a8a2cbda36f))
- **navigation:** The account drawer is frosted glass ([9a56b8200](https://github.com/AlohaSocial/social/commit/9a56b82000a1abaee5537e9b2f86ca47b82a6e2a))
- **discover:** Hearts on reels, and suggestions as a constellation ([a65f98f06](https://github.com/AlohaSocial/social/commit/a65f98f06dc6a9436feaf8e152c3f8c3c3efec91))
- **composer:** The composer as a toy ([072a45735](https://github.com/AlohaSocial/social/commit/072a457350c2c7b8267ca767efc8522fef006553))
- **senses:** Sound and touch, switched per device ([b8354f20e](https://github.com/AlohaSocial/social/commit/b8354f20e5bdd3e9153a3d76ef6ccb60bc311820))
- Remove News from the sidebar, Discover and the Sections card ([ea6c4a808](https://github.com/AlohaSocial/social/commit/ea6c4a80821a670c8786c040b742af016ecc4c54))
- **navigation:** Activities moves into the account menu ([e541b2ab5](https://github.com/AlohaSocial/social/commit/e541b2ab5958b547a696e2569cff8cab05290ee3))
- **navigation:** The account menu pops open ([7853695f3](https://github.com/AlohaSocial/social/commit/7853695f33da0d8d566fef4b7261ce36dea02449))
- **interests:** The My interests feed, its reading tracker and the settings cloud ([afc0e85f2](https://github.com/AlohaSocial/social/commit/afc0e85f208c5c9457f43098e2898785cd3c12ac))
- **interests:** Learn a reader's hashtag interests and rank a feed from them ([f98b4d3f1](https://github.com/AlohaSocial/social/commit/f98b4d3f1e706d467a4cc3c86d0d6ef872336779))
- **scheduled:** Change the time of a scheduled post from Settings ([490865d0f](https://github.com/AlohaSocial/social/commit/490865d0f6e233e71fd60a2217de67116b7a0e88))
- **subscriptions:** Follow a blog or a channel, and read what it publishes ([77cbab8a6](https://github.com/AlohaSocial/social/commit/77cbab8a6675c3be60e3331f737f192e74ab748b))
- **subscriptions:** Find a feed from a page, and read what it says ([036296854](https://github.com/AlohaSocial/social/commit/036296854a831c2edc965c5b5a098afb47a63967))
- **migration:** The three steps of Moving in that were never written ([648ed6cc5](https://github.com/AlohaSocial/social/commit/648ed6cc58cb043f07b0ae5e9e5635cbf7d8a828))
- **migration:** The way in from another network, back on the page ([a0bdcd48e](https://github.com/AlohaSocial/social/commit/a0bdcd48ee5a5e7f59f5fe6b3b6e3af8d95e625e))
- **migration:** A page of its own, in the account menu ([a7074c01b](https://github.com/AlohaSocial/social/commit/a7074c01b50ced96a82c937fcd0614c594cde1ae))
- **videos:** Watch them one at a time instead of choosing from a grid ([71a111312](https://github.com/AlohaSocial/social/commit/71a111312b29e4040bf89a48c14efc5502180da6))
- **fediverse:** Import a block list, and follow ones that are published ([1ac5e2872](https://github.com/AlohaSocial/social/commit/1ac5e2872e92ba08bcaa328e4331e4fbeae8e6ff))
- **statistics:** Put the new figures on the page ([a060d639b](https://github.com/AlohaSocial/social/commit/a060d639bd69ef5145e579772d73f8929ccbbeee))
- **statistics:** What you did, who you talk with, and a copy to keep ([19a857f2a](https://github.com/AlohaSocial/social/commit/19a857f2afb35d10bc73d83dc54b8a71109336db))
- **social:** Improve profile feeds and direct chats ([4de602bde](https://github.com/AlohaSocial/social/commit/4de602bde65ec4b6f7beaf1c34b36968c5273856))
- **public:** Give a visitor a way into their own account ([7c630b81a](https://github.com/AlohaSocial/social/commit/7c630b81a3c53da75de7ccda8689102c74403eda))
- **direct:** Say in the sidebar when a direct message is waiting ([cc6204127](https://github.com/AlohaSocial/social/commit/cc6204127c5df219b755043912cffb85cc9a2e29))
- **discover:** Find a hashtag, and see what is busy elsewhere ([2e00c1157](https://github.com/AlohaSocial/social/commit/2e00c11574310f02d5b0a81f267a30d3027747f2))
- **discover:** Ask other servers what hashtags they are busy with ([517bc7ccf](https://github.com/AlohaSocial/social/commit/517bc7ccf014c5dc4d93a9e5c792247b73e3f0b1))
- **discover:** Say what each list of people is ([6b7748ba6](https://github.com/AlohaSocial/social/commit/6b7748ba6d752384ecdd265360fc5a534b97f1b0))
- **api:** Publish events other apps can listen to ([27c40ff59](https://github.com/AlohaSocial/social/commit/27c40ff59a420e144e44f2f4783612e582d56127))
- **discover:** Show recent media where nothing is trending yet ([e919cb27f](https://github.com/AlohaSocial/social/commit/e919cb27f1a4dd3ed5dcf3e429e5db7b2fc4c187))
- **frontend:** Draw a follow before the server has confirmed it ([be66e946c](https://github.com/AlohaSocial/social/commit/be66e946c3f2848b1103714ac7974d992b0a8b7a))
- **statistics:** What the fediverse is made of, and a chart worth looking at ([16e139b0e](https://github.com/AlohaSocial/social/commit/16e139b0e182b48358c37d43e2a825e97b8b8968))
- **statistics:** Show how the fediverse has grown, not just how big it is ([803a78353](https://github.com/AlohaSocial/social/commit/803a78353267da9331a1b8f3bf5bdd109d35bf7e))
- **admin:** Show when each background job last ran ([5d0a48851](https://github.com/AlohaSocial/social/commit/5d0a4885115fc83b436594be351a3b84d74f5ed4))
- **admin:** Say how long the queue has been stuck ([3f21ea594](https://github.com/AlohaSocial/social/commit/3f21ea594c7a940934df9c6155e2c305cd7a24d6))
- **video:** Send the dislike PeerTube has always published ([18fc12792](https://github.com/AlohaSocial/social/commit/18fc12792a6bdf9b928de8ba54b0044de8c21b44))
- **federation:** Honour the other three interaction policies ([7582d590d](https://github.com/AlohaSocial/social/commit/7582d590d07a45e7f14a3bb874b8410ae8ce6737))
- **security:** Cap how much disk one other server may take ([d2bce3175](https://github.com/AlohaSocial/social/commit/d2bce317576815fbc3127c9a4beef6e62fd0584d))
- **security:** Cap how fast one account can follow ([e298924d2](https://github.com/AlohaSocial/social/commit/e298924d2e4482c0d68e93b5f40e736ec7dfef6b))
- **security:** A budget for the routes nobody gave one to ([23639551f](https://github.com/AlohaSocial/social/commit/23639551f4da951e772dfc7c8150706b17eb28d9))
- **statistics:** Say how big the network these posts went out to is ([760ef0fb6](https://github.com/AlohaSocial/social/commit/760ef0fb6ea90c8736cec0f76fa4f29efad17b32))
- **discover:** Suggest the accounts the people you follow follow ([a0a5f231a](https://github.com/AlohaSocial/social/commit/a0a5f231a8f01702c3376fcdc23118f9745ce731))
- **directory:** More places to look for people, and this server's own ([0a4db9cf6](https://github.com/AlohaSocial/social/commit/0a4db9cf608be96f780ddb91692af5448d3d0c8b))
- **checks:** Warn in the app when Mastodon apps cannot connect ([1a49e81fb](https://github.com/AlohaSocial/social/commit/1a49e81fb689ef62bb860b066b679b6285f4882e))
- **api:** Let Mastodon apps reach this instance ([d79142420](https://github.com/AlohaSocial/social/commit/d79142420402709d53989e504d13d7b20bde50a9))
- **frontend:** Sixteen small things that make the app feel made ([5831f43b7](https://github.com/AlohaSocial/social/commit/5831f43b7e9c772f37dc69af470ebf0c573d9b0a))
- **app:** Give moving between pages a direction, and something to watch ([3350a473f](https://github.com/AlohaSocial/social/commit/3350a473f955d7497dd4f6aa2cf0b032439f0af4))
- **media:** Sweep the documents nothing can refer to any more ([a59a4a3ae](https://github.com/AlohaSocial/social/commit/a59a4a3ae13d59493d003c55ab28afa584b6b3fd))
- **frontend:** Name the scopes and the redirect target on the consent screen ([a9fddd315](https://github.com/AlohaSocial/social/commit/a9fddd315508befa90ac09ee5c55ccfdf50e6752))
- **admin:** Group the administration page, and give it a rail ([aca652a46](https://github.com/AlohaSocial/social/commit/aca652a46ee65ade2acfb8e34a64334bae50a0c2))
- **admin:** Let an administrator choose what the app offers ([e890ba651](https://github.com/AlohaSocial/social/commit/e890ba651cd20c70ac37e402c89d20a64980f893))
- **app:** Animate moving between the pages the sidebar lists ([f27f01c3e](https://github.com/AlohaSocial/social/commit/f27f01c3ee7a3a633f5a913f20e20cea7a1b5ce4))
- **settings:** Give the settings page a rail, and describe its sections ([4c82d3069](https://github.com/AlohaSocial/social/commit/4c82d3069b2c9423040b9355b36d4beb4bd39e65))
- **blocking:** Fold the filtered notifications into the Blocking page ([1059c99b2](https://github.com/AlohaSocial/social/commit/1059c99b261b8bb01e04f8bdb5fae27460bd358e))
- **blocking:** Call the sidebar entry Blocking ([a37390d82](https://github.com/AlohaSocial/social/commit/a37390d82a060ee8ee8c2f264d088f56303bd294))
- **discover:** A search box at the top of the People tab ([d24258d99](https://github.com/AlohaSocial/social/commit/d24258d993307a4dcbe3c1c939b29f9c55b36b1f))
- **discover:** Ask other servers' directories who is there ([8de2fa4bf](https://github.com/AlohaSocial/social/commit/8de2fa4bf355b4dcd451c9cdb02944db11c01871))
- **news:** A News page in the sidebar, and the links behind it ([6dffd71b9](https://github.com/AlohaSocial/social/commit/6dffd71b941ad11518ab48778e6fc5e07be17da8))
- **timeline:** A post can be news, and the database knows which ([79a97027d](https://github.com/AlohaSocial/social/commit/79a97027d946940f1a7427a728af82ea6183e833))
- **timeline:** The foot of a card is one row ([20f024dd9](https://github.com/AlohaSocial/social/commit/20f024dd9901904ac92ebca0fdcc83dcdb6f4135))
- **timeline:** The counts stay, the controls arrive ([aa836dd17](https://github.com/AlohaSocial/social/commit/aa836dd17d79679822a8ca110441caee5a4cd9fb))
- **timeline:** The instance moves off the byline and into the account card ([4c1ef08cc](https://github.com/AlohaSocial/social/commit/4c1ef08ccf8f2d7c2cccf12652d0010f501d10cd))
- **blocking:** One card per decision, instead of four runs of text ([ac85fdffe](https://github.com/AlohaSocial/social/commit/ac85fdffebac17086880840022b7f22d440ad7aa))
- **composer:** 881 animated emoji in the GIF picker, fetched and kept by the instance ([6f9e05c73](https://github.com/AlohaSocial/social/commit/6f9e05c73f9b7666fcbcaaa8be5b547ab2780245))
- **blocking:** The keyword filters move to the page where accounts are silenced ([f32850164](https://github.com/AlohaSocial/social/commit/f32850164a5ab3ca0a1759f7af8eb3d1dd603188))

### Bug fixes

- **social:** Take a local actor's name from the user backend ([c09053637](https://github.com/AlohaSocial/social/commit/c090536373f4953bdc7df029b76e028cffe30e0d))
- **api:** Name the Nextcloud session in the from-file refusal ([cc1850fd4](https://github.com/AlohaSocial/social/commit/cc1850fd402893782ffaa077542fa3fe117fa403))
- **api:** Apply exclude_replies and tagged to an account's statuses ([7b38d8be9](https://github.com/AlohaSocial/social/commit/7b38d8be99f87619ac51642859eb0e97b3340c01))
- **api:** List an account's boosts on its statuses ([eff5e3f6c](https://github.com/AlohaSocial/social/commit/eff5e3f6c0883eaa74db9e3d4526876d2e3d7237))
- **social:** Send visitors to the login from the reader's own pages ([72afb6d04](https://github.com/AlohaSocial/social/commit/72afb6d0423ea3da2a21ac4e1d90edd9ed53c5f3))
- **composer:** Let a post wait for the description its field just sent ([0059f0d80](https://github.com/AlohaSocial/social/commit/0059f0d80e4f7331e2f13da6ee2e60e6176d32a2))
- **social:** Find a local account's row again after the social URL changed ([29966425b](https://github.com/AlohaSocial/social/commit/29966425bfa827e95ffbb28185211962f0067121))
- **api:** Apply only_news to a list timeline ([b8bd9bea4](https://github.com/AlohaSocial/social/commit/b8bd9bea4ea18d03a6407afccd0d6f1f650a27f2))
- **api:** Serve a boost asked for by its own id with the status it boosts ([94b6c85d1](https://github.com/AlohaSocial/social/commit/94b6c85d142b36fab2f61b6f2fb4ec2a9b6d8f62))
- **api:** Let browser clients of another origin call the client API ([6a51567aa](https://github.com/AlohaSocial/social/commit/6a51567aaebfac16877d1f959f1647c1daf920e1))
- **social:** Delete a profile banner with the account it is on ([04b6b67b4](https://github.com/AlohaSocial/social/commit/04b6b67b4e2e32f28f4419feaa0dbf9fec33e536))
- **federation:** Remember an accepted signature for longer than it is accepted ([a60412606](https://github.com/AlohaSocial/social/commit/a60412606aa59419dd9bf9a775aa18f9ef3f9575))
- **migration:** Let the move-out button wrap instead of widening the page ([bf4467507](https://github.com/AlohaSocial/social/commit/bf4467507c8598e5373775a07950c965c37bc082))
- **polls:** Let the closed-poll sweep run on MySQL and PostgreSQL ([570fcd584](https://github.com/AlohaSocial/social/commit/570fcd58419b3823904e2685210375243e2561c6))
- **banner:** Sign the banner's Update{Person} with the account's own key ([9166f9a82](https://github.com/AlohaSocial/social/commit/9166f9a829da8f0d06ccb0ba5964b90313a8dc2c))
- **migration:** Export the profile banner from the account's cached copy ([8b913d7a7](https://github.com/AlohaSocial/social/commit/8b913d7a7cea98dfd45f27f3440cdbb80dcdd108))
- **migration:** Carry the lists and blocked domains in the account archive ([429a8eaa9](https://github.com/AlohaSocial/social/commit/429a8eaa9474c5c70dde2c7fdeba3a2e5a582ff1))
- **notifications:** A poll notification carries the poll ([762857ce4](https://github.com/AlohaSocial/social/commit/762857ce471bfdca0e5d56b3781fdf3a6b3447ca))
- **polls:** A poll is over on its end time ([15167e543](https://github.com/AlohaSocial/social/commit/15167e54324080d2fdb0fb916afb3a02b5d2ae6e))
- **l10n:** Restore the German plural for "%n entry" ([bc3a63c7a](https://github.com/AlohaSocial/social/commit/bc3a63c7a834620e11ed3228a6b47569146b98b3))
- **atproto:** Refuse a blob mime type with a nul byte in it ([b4a8c4a42](https://github.com/AlohaSocial/social/commit/b4a8c4a427fa71cc43f6c4b6680fb6d086fc75e5))
- **federation:** Read an object where an actor or post has text as nothing ([bcffcbf2b](https://github.com/AlohaSocial/social/commit/bcffcbf2b641bf9a5e18ee09e2a9e493d459f70d))
- **federation:** An ld signature the normaliser cannot read is not verified ([1bb772d13](https://github.com/AlohaSocial/social/commit/1bb772d1323a7a6801a5fe7182eee6f94dc75f23))
- **atproto:** Refuse a blob mime type longer than one can be ([cd9e7a367](https://github.com/AlohaSocial/social/commit/cd9e7a36760add655704a6318ed53d57e5908ca5))
- **peertube:** Read a video field that is an object as no text ([55aef517d](https://github.com/AlohaSocial/social/commit/55aef517df72b8611f76522dc4562cc49839d75d))
- **atproto:** Refuse a dag-cbor map whose keys are out of order ([e3dff3d03](https://github.com/AlohaSocial/social/commit/e3dff3d03b0e4a50bcf77c51e266808266021d1a))
- **federation:** An ld signature over a document json cannot encode is not verified ([bd321c425](https://github.com/AlohaSocial/social/commit/bd321c425f2f962cbd996dff42ea9ab0707bc715))
- **atproto:** Refuse a jetstream frame whose length has the top bit set ([6d106ca05](https://github.com/AlohaSocial/social/commit/6d106ca05581a11934f020ee440d72804f672822))
- **api:** A list timeline exports its statuses as its reader ([a4cee5f1b](https://github.com/AlohaSocial/social/commit/a4cee5f1b4869f0986d36c318ab70e4e31cd7bd9))
- **delivery:** Let a peer's Retry-After hold its breaker ([caaae03e2](https://github.com/AlohaSocial/social/commit/caaae03e2c0beb6dbab1ee295f46ab9b4514f66e))
- **federation:** Send a delivery wave really in parallel ([03479af68](https://github.com/AlohaSocial/social/commit/03479af686af84ac11abc768b4b71f6a37b4338a))
- **federation:** Mention the author a direct reply answers ([8bef8ceef](https://github.com/AlohaSocial/social/commit/8bef8ceef9b1ed862505dfe7a2d886bb45891929))
- **federation:** Let a peer's interaction policy say yes to whom it names ([304be5e04](https://github.com/AlohaSocial/social/commit/304be5e04b1a318a732e2caa934bbe142e9c7574))
- **federation:** Verify a GoToSocial signature with its key's owner ([db7a2e618](https://github.com/AlohaSocial/social/commit/db7a2e6181286b5e34d417b2b6752c4b415af5ad))
- **migration:** An uploaded import is queued instead of failing in fclose() ([3722a621c](https://github.com/AlohaSocial/social/commit/3722a621ccf96fb84f44ab043d6d5d5ff140054a))
- **router:** Keep the index.php base when the app is opened through it ([e937ebb22](https://github.com/AlohaSocial/social/commit/e937ebb229283bbc605718490b76061b22106dfd))
- **api:** Give a status the same reactions, places and tagged people wherever it is served ([4bf6711b2](https://github.com/AlohaSocial/social/commit/4bf6711b2a700b32928e590753ce313d8f9b2c73))
- **api:** Accept the Social sign-in token on the memories, statistics, channel, migration and authorized-apps routes ([ce1b2107d](https://github.com/AlohaSocial/social/commit/ce1b2107d4b6c3f0ae105ffe9c99a6f1e90e421e))
- **timeline:** Fill a bookmarks or favourites page past posts with no cached author ([e304bdf41](https://github.com/AlohaSocial/social/commit/e304bdf4146b81db828a573f003ca55e93eca7e1))
- **docs:** Describe the announced Mastodon version and avatars as they are ([94b41a1e0](https://github.com/AlohaSocial/social/commit/94b41a1e0659e9a5dc5f7b747f85ec3b8526d89d))
- **tests:** Remove every note TrendsRequestTest writes in its tearDown ([ff06cccdf](https://github.com/AlohaSocial/social/commit/ff06cccdf9ce80abfd87b03aecb74378e3c232af))
- **e2e:** Start the composer draft test from an empty box ([5bf5dcfb2](https://github.com/AlohaSocial/social/commit/5bf5dcfb2c2354ca7790517e07bab40b2027553e))
- **composer:** Put the caret in the composer when Reply is pressed ([db182f737](https://github.com/AlohaSocial/social/commit/db182f737a77d2f598b73cc1f086dda482978192))
- **search:** Find a hashtag as soon as a public post carries it ([df01cfc83](https://github.com/AlohaSocial/social/commit/df01cfc837031edcd7b03d9e2af4a063f4358ead))
- **notifications:** Never hold a followed account for being new or limited ([3117e9cba](https://github.com/AlohaSocial/social/commit/3117e9cbad1cfb6bf3daa9816aef52caeb41e943))
- **timeline:** Fill a public timeline page past posts with no cached author ([01ccfef35](https://github.com/AlohaSocial/social/commit/01ccfef358a3340b81822dd89e942f7fd660b751))
- **api:** Answer NodeInfo's usage as an object without an instance row ([6286b71e8](https://github.com/AlohaSocial/social/commit/6286b71e8af89ebff6386214161c231e4f3c589f))
- **api:** Refuse a poll past the announced limits with a 422 ([dd84c80ae](https://github.com/AlohaSocial/social/commit/dd84c80ae6625f865395c8c24753f3aadd7e91fd))
- **social:** Keep a status's mentions in step with its edited text ([c146ec2e2](https://github.com/AlohaSocial/social/commit/c146ec2e2e62b7882aa820dd65e4aec106a48f1a))
- **api:** Answer min_id on conversations with the page next to the cursor ([07e9dd858](https://github.com/AlohaSocial/social/commit/07e9dd8584ef302d0394417db01b4b5d441bd3db))
- **migration:** An upgrade from a release before 0.23 reaches the fresh schema ([0576ae4c7](https://github.com/AlohaSocial/social/commit/0576ae4c78f3440653ea3d61e8010e20d7ed2d59))
- **atproto:** Serve blobs sandboxed, with the media type of their bytes ([0bf224c38](https://github.com/AlohaSocial/social/commit/0bf224c3830914625c791cf6a2bf757b5cb97208))
- **atproto:** Answer an inactive repository's state on the sync and repo reads ([e163094e4](https://github.com/AlohaSocial/social/commit/e163094e41824f9664c4d6d069c27e95bf6ad3ce))
- **web:** Quote the banner url in the profile header's css ([b193757a8](https://github.com/AlohaSocial/social/commit/b193757a8a97d95b5f257f63b58f972589a5a2d2))
- **migration:** Refuse archive entries past a size ceiling on import ([31f4db2f8](https://github.com/AlohaSocial/social/commit/31f4db2f8fa81488b4e4c285ae9b684f5b93a71c))
- **gifs:** Read a fetched pack picture no further than its size ceiling ([33b73ca41](https://github.com/AlohaSocial/social/commit/33b73ca4160fc8468a0de909274cf2272392b70c))
- **media:** Keep no container metadata in stored video and audio ([6b9faa0f5](https://github.com/AlohaSocial/social/commit/6b9faa0f5edacb9a7b5073a4084b540e11b90307))
- **model:** Serve remote profile fields as plain-text names and sanitised values ([f22bbb0e4](https://github.com/AlohaSocial/social/commit/f22bbb0e44e4f15571035d00daa165a76a1344a7))
- **model:** Sanitise remote post content when it is imported ([3595c6643](https://github.com/AlohaSocial/social/commit/3595c664362bb85b48f5d921f784fc915107f6b2))
- **search:** Serve the global account search as Account entities ([e743fa46f](https://github.com/AlohaSocial/social/commit/e743fa46fd670a3ba317df2dbcccf473ce301555))
- **model:** Keep only http(s) banner URLs on remote actors ([3f2d4c8fc](https://github.com/AlohaSocial/social/commit/3f2d4c8fce07a0d971242b23ddf6e8e55e01981f))
- **security:** Keep every special-purpose address range off the public list ([6422f54b7](https://github.com/AlohaSocial/social/commit/6422f54b7cc5c59895d4989f794d84ea50fc4b97))
- **curl:** Refuse URLs that are not http(s) before anything is sent ([9061de186](https://github.com/AlohaSocial/social/commit/9061de1867f8e15cc6b77947982d861c8767d531))
- **db:** Never count a decrement below zero ([893115e23](https://github.com/AlohaSocial/social/commit/893115e2351130cee93815c680100d41ba842aa6))
- **media:** Attach a Nextcloud file only through the web app's session ([ad7995e01](https://github.com/AlohaSocial/social/commit/ad7995e01909c75faaeb017a14e08e376e2c0139))
- **federation:** Take a reply approval only from the author of the post replied to ([63368f081](https://github.com/AlohaSocial/social/commit/63368f081abbb582e41a40dda3ba1a26c8b9c631))
- **federation:** Move the follower count only with the follow row an Undo removes ([8263f0f11](https://github.com/AlohaSocial/social/commit/8263f0f11ad345adbf3ea85997c7bd9a366f40ce))
- **statuses:** Accept a reply only to a post its writer may read ([272a4e82b](https://github.com/AlohaSocial/social/commit/272a4e82bfa835ff71c4b29ac18b9c3c7b82c7fe))
- **timeline:** Serve bookmarks and favourites only for posts the reader may read ([7fba21794](https://github.com/AlohaSocial/social/commit/7fba217946396334e727fa17c21483ca14a6b411))
- **migration:** Write the recipient rows' nid on PostgreSQL too ([98644b06e](https://github.com/AlohaSocial/social/commit/98644b06e7d6899091ef9c4c54f5e5869b1ee8aa))
- **media:** Count only the posts an upload's rebuild actually changed ([5836772ef](https://github.com/AlohaSocial/social/commit/5836772efebb91b9cd720eec02947d3529e49d03))
- **media:** Let a client describe an upload before it is posted ([81a1ef040](https://github.com/AlohaSocial/social/commit/81a1ef0401b8c5fc41318816acf25c53503339af))
- **announcements:** Write an all-day window's end as a UTC date ([ba0e1ac5e](https://github.com/AlohaSocial/social/commit/ba0e1ac5e91c47ed7b7ce742403db7270f3b2e44))
- **db:** Read and write stored datetimes as UTC in any PHP zone ([b46a4bd24](https://github.com/AlohaSocial/social/commit/b46a4bd245e2d792b8451c8f5f3324a3a59faf23))
- **statistics:** Keep twelve month columns on the 29th to 31st ([604693591](https://github.com/AlohaSocial/social/commit/604693591ae838df441f0c141136390e2583055e))
- **l10n:** The last multi-line plural, German for the strings now found, and the bundle ([0bef2efb2](https://github.com/AlohaSocial/social/commit/0bef2efb2b5f5d37eb3edd6ea85d1c8176fab19c))
- **l10n:** Extract every string, and sync with AlohaSocial's Transifex ([237ff9af6](https://github.com/AlohaSocial/social/commit/237ff9af64e8a57a4bba6b4c1561073f57ea8889))
- **upgrade:** Widen the hashtag column an upgrade from 0.24.1 kept at 63 ([5fc8040c2](https://github.com/AlohaSocial/social/commit/5fc8040c22ab74e28ae02713cf6c9cd693954a45))
- **composer:** Draw the attached picture in the composer again ([0b576690b](https://github.com/AlohaSocial/social/commit/0b576690b2c41482980b5803f9ff72fa5406fe52))
- **atproto:** Keep an app's empty map an empty map ([befafff61](https://github.com/AlohaSocial/social/commit/befafff6177591206036f0103b0ff917878add73))
- **atproto:** Close the firehose connection on an unmasked client frame ([4c25adc4d](https://github.com/AlohaSocial/social/commit/4c25adc4d92e2d7c9a826f773533fd5f152ed043))
- **atproto:** Refuse a moved-in tree whose keys do not strictly ascend ([9ac5bf610](https://github.com/AlohaSocial/social/commit/9ac5bf610125aeb20773d13eed20c097443ffb22))
- **atproto:** Read a v3 commit whose prev is a link ([e3cc94a08](https://github.com/AlohaSocial/social/commit/e3cc94a081ea62e62b6c7b21a9d8e76a08d56a0e))
- **atproto:** Encode the empty map as a cbor map, not as the empty list ([40dd4645b](https://github.com/AlohaSocial/social/commit/40dd4645ba0b9ed1d2908c7a5bba263b3f57bcd1))
- **atproto:** Treat a websocket length with its top bit set as too long ([932c254c8](https://github.com/AlohaSocial/social/commit/932c254c8366ba9e9a9489d905359e3df8f41cdd))
- **atproto:** Vendor the gallery embed lexicon the post lexicon names ([f8aa07745](https://github.com/AlohaSocial/social/commit/f8aa07745679ca7aee8fa34c0ec6101b1c63f098))
- **scheduled:** Count an account's waiting posts on PostgreSQL and MySQL ([a55135f1f](https://github.com/AlohaSocial/social/commit/a55135f1f5b27e0302188e8d86da20656808fd84))
- **api:** Give the author of a boosted or notified-about post their id ([b83ee7516](https://github.com/AlohaSocial/social/commit/b83ee751663cf5e6b467dd59ecba69a70ad34565))
- **api:** Count a post's length the way the instance tells clients to ([f0ca332d4](https://github.com/AlohaSocial/social/commit/f0ca332d4e9492d236872e6b575ef950c8c4d15b))
- **api:** Read Mastodon's since_id on the follower and following lists ([cae05636a](https://github.com/AlohaSocial/social/commit/cae05636a553b2b570a4b2aadd0a76924b2764e8))
- **api:** Give the accounts read through the actor join their ids ([a400c2548](https://github.com/AlohaSocial/social/commit/a400c25482b056e7790a638871de055d0f946f73))
- **api:** Call an attachment of no known kind `unknown` ([0aba73df5](https://github.com/AlohaSocial/social/commit/0aba73df505888c162941cdc62de3470c480d6e7))
- **api:** Leave an attachment's unknown size out instead of sending a list ([c8b29be9b](https://github.com/AlohaSocial/social/commit/c8b29be9b1adfe33edccdbc48d4e20a27a24413e))
- **api:** Answer an application's scopes and redirect URIs as lists ([990648c6b](https://github.com/AlohaSocial/social/commit/990648c6b762ee5d5c86d4d68280dde5a36892af))
- **api:** Leave the reader out of their own familiar followers ([19229dda3](https://github.com/AlohaSocial/social/commit/19229dda3860b9662a98d806c0e69ed85bf0a59d))
- **api:** Send a scheduled post's missing language as null ([45058be67](https://github.com/AlohaSocial/social/commit/45058be676b55e2b4798160f8d35775d11af9b9d))
- **api:** Date a status edit the way the client API dates everything ([9bf6e14fd](https://github.com/AlohaSocial/social/commit/9bf6e14fd43b4cded5634b17892098139db016cf))
- **api:** Write a poll's expires_at as a client API time, and only once ([02f73d422](https://github.com/AlohaSocial/social/commit/02f73d422164d5e3edf01b23f072942f2c2f804c))
- **api:** Keep the stored ActivityPub document when a post is edited ([8b2e0a93f](https://github.com/AlohaSocial/social/commit/8b2e0a93f5e1e77ebf456dd4c5b28bf49e63e0d6))
- **api:** List the accounts a status written here mentions ([cc5569386](https://github.com/AlohaSocial/social/commit/cc55693863c5ed13f881d4fc989ce88d761fde44))
- **delivery:** Send each wave to the servers with the most rows left ([002777183](https://github.com/AlohaSocial/social/commit/002777183fee745226e6dab2cadc05b2ff991fbe))
- **db:** Tell follower inboxes apart by key, not by in_array ([2ff015145](https://github.com/AlohaSocial/social/commit/2ff0151452b5b25c18e130835548d6672913fee5))
- **api:** Clamp the page of scheduled statuses to Mastodon's forty ([4c5a9aaab](https://github.com/AlohaSocial/social/commit/4c5a9aaabc2377faaeb95662fbfca1f7a320f0cc))
- **api:** Tag the head of the home timeline when no cursor is sent ([abfa7fb7d](https://github.com/AlohaSocial/social/commit/abfa7fb7da28de91e73377614c14b623b67926bb))
- **federation:** Keep a post deleted when its Delete arrives first ([e6150279c](https://github.com/AlohaSocial/social/commit/e6150279c0a678f346c72a1c2d2c65a483a40c8c))
- **federation:** Do not let an older edit overwrite a newer one ([f93e5632f](https://github.com/AlohaSocial/social/commit/f93e5632f9268f6040285e6f318a3f2eda4d77b7))
- **federation:** Read a QuoteRequest whose instrument is the post itself ([ae689f2de](https://github.com/AlohaSocial/social/commit/ae689f2de36526e2a6085b27cfc896f4d0f5a632))
- **federation:** Send the featured collection as the target of a pin ([6490fe676](https://github.com/AlohaSocial/social/commit/6490fe6769fdd79c35101806bef7a2722b7ad7fa))
- **activitypub:** Keep a post's root @context out of its Create ([de00f224b](https://github.com/AlohaSocial/social/commit/de00f224b858104e6354f218e865cf8d2eb87fca))
- **activitypub:** Render a local profile field once, not twice ([df912e5ce](https://github.com/AlohaSocial/social/commit/df912e5cec31068db69dcea6bcc39dee821d2852))
- **dm:** Parse a message's markup in a document of its own ([ce80bda8f](https://github.com/AlohaSocial/social/commit/ce80bda8f620e72cdf6dc5f697d5865fc68f26f9))
- **media:** Stop reopening an HLS playlist for every segment it serves ([4cef26915](https://github.com/AlohaSocial/social/commit/4cef269155e91a2e45c26635365dd1350e1e9974))
- **migration:** Write the recipient rows' nid on PostgreSQL too ([871c1548b](https://github.com/AlohaSocial/social/commit/871c1548b6c3a907aa5f786c82c7cd4c58b252cf))
- **media:** Count only the posts an upload's rebuild actually changed ([f134df2e6](https://github.com/AlohaSocial/social/commit/f134df2e608e437747339e6cc178e0a278fccabb))
- **media:** Let a client describe an upload before it is posted ([91242dc74](https://github.com/AlohaSocial/social/commit/91242dc7485f4e8674d0416514476ea2c32d4263))
- **announcements:** Write an all-day window's end as a UTC date ([0b943316a](https://github.com/AlohaSocial/social/commit/0b943316aad545eeb1471995b14738f323cd88d6))
- **db:** Read and write stored datetimes as UTC in any PHP zone ([555306991](https://github.com/AlohaSocial/social/commit/5553069918da95b8f3d8203274f95e72051464be))
- **statistics:** Keep twelve month columns on the 29th to 31st ([92d9d4888](https://github.com/AlohaSocial/social/commit/92d9d4888e55325589d68a46290bebc5624ffd93))
- **social:** A deleted account's quote approvals go with it ([a16f656f6](https://github.com/AlohaSocial/social/commit/a16f656f6cd6c2cf1effbf42071914ba20f399ac))
- **social:** An account's channels are deleted with it ([42d1a5bfc](https://github.com/AlohaSocial/social/commit/42d1a5bfc6aaf6b64fb782259e6db48aa1859aab))
- **atproto:** An identity event's handle is taken from the AppView, not the event ([c66c0aa78](https://github.com/AlohaSocial/social/commit/c66c0aa78c91d9d564f8a746611625a85d04c479))
- **social:** A deleted account leaves nothing behind in seven more tables ([0b2001922](https://github.com/AlohaSocial/social/commit/0b200192234bb63c8b2e55918bb8a26ddcb58ff8))
- **social:** What a deleted account, a late public list and an orphan item leave behind ([96719e368](https://github.com/AlohaSocial/social/commit/96719e3689c1643039a5a9912d6f1eb3abd10c1a))
- **atproto:** A Bluesky profile carries the account's name, not its username ([c1ea76572](https://github.com/AlohaSocial/social/commit/c1ea76572516658e3423a1ec6ee3bdf9b148626b))
- **atproto:** What was written while off stays off, for the cached author too ([81465979e](https://github.com/AlohaSocial/social/commit/81465979e30cc8c1e04e90f26916c0e72a780e7e))
- **social:** A reply to a post its author's block hides is refused ([18ecd1fc2](https://github.com/AlohaSocial/social/commit/18ecd1fc25902f5c3c15cfdafb623cf30f87a1a0))
- **atproto:** A procedure without output is no failure ([463618073](https://github.com/AlohaSocial/social/commit/463618073a50c3421f2c12111395b6d3b9c36d0d))
- **social:** Who reacted reaches the page where APCu is the only cache ([fc20c67cf](https://github.com/AlohaSocial/social/commit/fc20c67cfa2e61be9b1280da7e8c9c34d83f3c03))
- **atproto:** A Bluesky account found by search has an id ([0dfa8790c](https://github.com/AlohaSocial/social/commit/0dfa8790c7183375e2e26de0d55eb05f36fa1c33))
- **social:** A profile Update carries the picture and the banner ([047c7e804](https://github.com/AlohaSocial/social/commit/047c7e804845d4faa48f9c33bd8ac9f4b4a7fa4a))
- **social:** Tell an avatar change from where it is made, once ([0b4992550](https://github.com/AlohaSocial/social/commit/0b49925503acc576d0399ae83479763bdefb8499))
- **social:** A new avatar reaches the followers and the web app ([24094829d](https://github.com/AlohaSocial/social/commit/24094829dc8ac5b03998c8c4d6a281e50f03ed84))
- **atproto:** Find the account of an actor read from the actors cache ([467ce752f](https://github.com/AlohaSocial/social/commit/467ce752fece0b6a5229b79d0f4ab162e7b382a0))
- **atproto:** The Bluesky avatar is the account's own Nextcloud picture ([40c89948b](https://github.com/AlohaSocial/social/commit/40c89948b429b7f4be8c73bcb2314f0c2a1a37c9))
- **social:** Read a local actor as written within the same request ([63db986cb](https://github.com/AlohaSocial/social/commit/63db986cb396ce02de7bb790e4231451f594e9ed))
- **atproto:** Publish a local account's avatar to Bluesky ([15f4e93b0](https://github.com/AlohaSocial/social/commit/15f4e93b0cbf5d69c358d862fcfd9e142ce2ee9b))
- **atproto:** Publish a profile an app saved as the change left it ([27271ed0f](https://github.com/AlohaSocial/social/commit/27271ed0f1ba0f7467039e08b091590420d773af))
- **atproto:** Cut a Bluesky app's avatar that is not square to its middle ([db46ac49f](https://github.com/AlohaSocial/social/commit/db46ac49f22eea39eb053d4c51220af622f63378))
- **security:** Keep remote attachments off documents they do not own ([2327ca218](https://github.com/AlohaSocial/social/commit/2327ca218caffb2f0daf0403ea61df307795198a))
- **polls:** Find closed polls by an indexed end time ([c1926c1c6](https://github.com/AlohaSocial/social/commit/c1926c1c6d395b148cbcdcca3406b45f5a3665e2))
- **security:** Serve stored non-media files as sandboxed downloads ([c46caa3ac](https://github.com/AlohaSocial/social/commit/c46caa3ac89ce43b0967a7e7c335fdd3748dff3c))
- **security:** Keep only known classes in client-sanitised HTML ([d38b87d0f](https://github.com/AlohaSocial/social/commit/d38b87d0f0266c6568549f677093613b39255aa9))
- **security:** Resolve relative links in remote HTML and parse it as HTML5 ([d85fdf0bf](https://github.com/AlohaSocial/social/commit/d85fdf0bf20b95eb8b5e835348b581919b410b2b))
- **federation:** Escape and shorten sender values in signature errors ([1a17532a1](https://github.com/AlohaSocial/social/commit/1a17532a17fdca700ded2a09d605b91c0b4796cb))
- **federation:** Read the signature Date as an HTTP date only ([9f7c1bdea](https://github.com/AlohaSocial/social/commit/9f7c1bdea9e79b84eae9eda0998536342f155150))
- **federation:** Refuse a blocked instance before fetching its key ([a0c399cca](https://github.com/AlohaSocial/social/commit/a0c399cca585e922af76cdf25439cf0589635fb0))
- **api:** Limit token clients on every public route that limits sessions ([b01f73565](https://github.com/AlohaSocial/social/commit/b01f735659e0cf13a5f3ec61d5836fbe98618bb8))
- **queue:** Only deliver on a self-call this server signed ([47bc5f6d1](https://github.com/AlohaSocial/social/commit/47bc5f6d1ce9bfe882c05439b3e2751532b7e6bb))
- **oauth:** Hash or revoke the last plaintext credentials, stop accepting them ([79af69333](https://github.com/AlohaSocial/social/commit/79af693332114e118ca0ac6d5711f3eeb4ccf78b))
- **oauth:** Give tokens an absolute lifetime and show it in Authorized apps ([57098088f](https://github.com/AlohaSocial/social/commit/57098088f4af4816ac80e7372719149a0b49e7ba))
- **api:** Rate-limit app registration and reports, sweep unused registrations ([224f6dd88](https://github.com/AlohaSocial/social/commit/224f6dd881cc16e4177096bf4d116ad99ea0dbf6))
- **admin-api:** Refuse a report_id that is not about the account acted on ([e3d3fe6ec](https://github.com/AlohaSocial/social/commit/e3d3fe6ec9cb53f96676d6b1d58a01a2b145cccf))
- **moderation:** Keep delegates off administrators' accounts and everyone off their own ([285ea8e3d](https://github.com/AlohaSocial/social/commit/285ea8e3db5f9faf81f5fabf18e7a06185d8bdfa))
- **pixelfed:** Hold app settings writes and push changes to a write scope ([3a43290bc](https://github.com/AlohaSocial/social/commit/3a43290bc0b9a063f6e30ced11f6bc9070e0e823))
- **api:** Require write:conversations to mark every conversation read ([761e89600](https://github.com/AlohaSocial/social/commit/761e8960059a5c7b1b50de65c3a93468426e12c8))
- **cron:** Bound the actor memo and empty it between cron batches ([50ecca112](https://github.com/AlohaSocial/social/commit/50ecca112abde01e1af37a9fb95ab83588fa0c24))
- **web:** Keep a like from also opening the post ([b43cbc8c6](https://github.com/AlohaSocial/social/commit/b43cbc8c6ecd9cc6ec779f036ae9043dd55326db))
- **web:** No core button grey inside a conversation row, menu behind …, one focus ring ([12503ab81](https://github.com/AlohaSocial/social/commit/12503ab8101e6ddc068efd9980541a594d581c94))
- **web:** Set each count against its own glyph ([4a0b5dd5d](https://github.com/AlohaSocial/social/commit/4a0b5dd5d57b58b1bf0331ed0dada1b57b5e02cd))
- **web:** Keep the numbers off the timestamp when counts are shown ([34d121c30](https://github.com/AlohaSocial/social/commit/34d121c30f37d64ea42b5f083907db3aeb425beb))
- **shorts:** Hold a 24-hour picture with a real button ([ef04a2cdf](https://github.com/AlohaSocial/social/commit/ef04a2cdf1da24c09c66f1d29e3b2161530bd81a))
- **shorts:** Say "24-hour shorts" wherever stories were named ([69f1f08f2](https://github.com/AlohaSocial/social/commit/69f1f08f2674ba5b3ddce8da542f7639acec2b6d))
- **counts:** Hide the reply count too when the numbers are hidden ([a89a10e7a](https://github.com/AlohaSocial/social/commit/a89a10e7a3c1ae7c025b088cf0645322f4f1c653))
- **media:** Read a document's transcode and ladder state back ([36b7897e3](https://github.com/AlohaSocial/social/commit/36b7897e3b6b533682120558abd83222a20bbc66))
- **federation:** Name the author a reply answers among its recipients ([89c0446a6](https://github.com/AlohaSocial/social/commit/89c0446a6f34c871a71cea9a0838210940c1a9d2))
- **federation:** Address an actor Update to the public and the followers ([c6a487ace](https://github.com/AlohaSocial/social/commit/c6a487aceb0a643e1aef175a803df62aeb59161d))
- **federation:** Send a post's page as its url ([f69b1351a](https://github.com/AlohaSocial/social/commit/f69b1351a7079b2769ea256474abb268ced156f3))
- **federation:** Answer a repeated Follow with the Accept again ([a82fe1c7b](https://github.com/AlohaSocial/social/commit/a82fe1c7b6a31e444eed733cf66f1a5a5d63c4e2))
- **federation:** Let a channel's owner delete its video by Tombstone ([ea1966cb6](https://github.com/AlohaSocial/social/commit/ea1966cb60b7bd1b9d38da6c38531c64c4db15ac))
- **timeline:** A followed PeerTube channel's videos reach the home timeline ([21d7966b6](https://github.com/AlohaSocial/social/commit/21d7966b64b3033e9a1e5f8df3dd970e6299fc64))
- **federation:** One Update for one profile edit ([99a3691e4](https://github.com/AlohaSocial/social/commit/99a3691e4026bff2b98b42788526004f18ac8222))
- **federation:** Keep the new avatar a profile Update names ([bc59dabd5](https://github.com/AlohaSocial/social/commit/bc59dabd5bd0c3994ebcfc5ad37bebcf729e06ed))
- **polls:** Count the votes Mastodon casts on our polls ([95af319ca](https://github.com/AlohaSocial/social/commit/95af319ca62bde014dc2dfbc46b20bd686bd2413))
- **federation:** Tell followers about name, field, lock and flag changes ([ebc89ffcb](https://github.com/AlohaSocial/social/commit/ebc89ffcbe5de83e9a3dc582e45f3b36341caecb))
- **federation:** Take a PeerTube video's Update and Delete from its channel's owner ([4e0d0be27](https://github.com/AlohaSocial/social/commit/4e0d0be27b7f03bd768db82af3aa82b879b0215d))
- **notifications:** Show the actor's avatar or a picture of the action ([7fb249b40](https://github.com/AlohaSocial/social/commit/7fb249b40c26ee41ded212b5e35fed9dfab8b485))
- **api:** Repair avatar-as-header, one avatar on every route, flag placeholders ([4c6d4f67c](https://github.com/AlohaSocial/social/commit/4c6d4f67c3ba1e1aecbd26c371a5ded4277a0418))
- **profile:** Keep a display name core's fediverse check fails on and never answer 500 over half a profile ([d515e1555](https://github.com/AlohaSocial/social/commit/d515e155511112aaa0e0f2f4ce36b8d9fa6e4033))
- **media:** Advertise the upload limit PHP enforces and say why an upload failed ([ffd902b29](https://github.com/AlohaSocial/social/commit/ffd902b299f76c8c3cbbcecb36d1f67bc6271910))
- **api:** Record each version's media in the status history ([fc712c9af](https://github.com/AlohaSocial/social/commit/fc712c9af5c301a768c324e5e54704ab71c9e92f))
- **build:** Refresh composer.lock and fail the suite when it goes stale ([1c81e96ee](https://github.com/AlohaSocial/social/commit/1c81e96eef6ffcac0398401acf0ae1beeee55ffb))
- **composer:** Never offer a Bluesky target the reader cannot use ([5dbfd3e89](https://github.com/AlohaSocial/social/commit/5dbfd3e89a865f6fc0e0d3cabf82329dc5898c11))
- **atproto:** Clear the failing checks on the atproto branch ([597dab709](https://github.com/AlohaSocial/social/commit/597dab70906b8cbfb93af1935bd8effd889b0564))
- **atproto:** Expose linked profile on local account page ([5290dac85](https://github.com/AlohaSocial/social/commit/5290dac8512271476095c711ae6ab949bc4a4886))
- **atproto:** Normalize common profile image mime aliases ([3c3335e84](https://github.com/AlohaSocial/social/commit/3c3335e84d7dc1fdf338e3b1270ec6b356207949))
- **atproto:** Verify profile image content type ([5d5963bb4](https://github.com/AlohaSocial/social/commit/5d5963bb47f1a97cdd7e860ed5c811a69237cb9e))
- **atproto:** Validate actual profile upload size ([079d88519](https://github.com/AlohaSocial/social/commit/079d88519b0f52dfa5c0b6cb4514e9d9635867dd))
- **atproto:** Use post for multipart profile edits ([9f077bf87](https://github.com/AlohaSocial/social/commit/9f077bf87f024c1c003dfb7df39e9327d286b711))
- **atproto:** Refresh profile media after save ([e742bec63](https://github.com/AlohaSocial/social/commit/e742bec63155cbd750079c055be50725d4801bbe))
- **atproto:** Whitelist native media mime types ([de5b491ff](https://github.com/AlohaSocial/social/commit/de5b491ff9fd0ab573a7e47bb743c29fbf538c85))
- **atproto:** Guard native post writes on broken sessions ([a07e0dbee](https://github.com/AlohaSocial/social/commit/a07e0dbeeb0821cab9dd0ff21ddd8e938015760c))
- **atproto:** Reject broken sessions for native engagement ([ed3d06190](https://github.com/AlohaSocial/social/commit/ed3d061900d2568db98e7953e54fd6288b60fd89))
- **atproto:** Keep own profile actions available during appview outages ([ea52978f8](https://github.com/AlohaSocial/social/commit/ea52978f84526d5e55f1a92bd8d29c2210dc0775))
- **atproto:** Block edits for broken sessions ([53580509d](https://github.com/AlohaSocial/social/commit/53580509df0eb1ceb332ad1cb8c292582b94589b))
- **atproto:** Allow profile retries ([669d85762](https://github.com/AlohaSocial/social/commit/669d85762c9979a442507d903d64239e9488ddd0))
- **atproto:** Preserve facets when editing posts ([c4da18377](https://github.com/AlohaSocial/social/commit/c4da18377ea6b726a55f93bc4873c82c6b640bee))
- **atproto:** Make own-feed cursors stable ([2108462a3](https://github.com/AlohaSocial/social/commit/2108462a3903d51726122bc0b5ae1a9e0df92824))
- **atproto:** Gate actions on healthy linked sessions ([313409f8f](https://github.com/AlohaSocial/social/commit/313409f8ff0939916895fbc7b7c6332d86508fa2))
- **profile:** Resolve signed-in identity reliably ([c96b02158](https://github.com/AlohaSocial/social/commit/c96b021589f470ee1fd8cf04515e5ad2fd9d1efe))
- **profile:** Surface native action failures ([b179f2ff2](https://github.com/AlohaSocial/social/commit/b179f2ff27d1e9ba65b49f615670bddaab6c196e))
- **atproto:** Preserve profile on pagination errors ([25012a319](https://github.com/AlohaSocial/social/commit/25012a3198d5e57c11c59184b79601a5ccb0480e))
- **profile:** Keep native reactions reactive ([2523b5306](https://github.com/AlohaSocial/social/commit/2523b5306d6581d31aba7142b0c1593b60fe93ba))
- **profile:** Route Bluesky actions natively ([6258736dd](https://github.com/AlohaSocial/social/commit/6258736dda0d3fe59c97aa46f927d11024a1c0af))
- **profile:** Fall back to Nextcloud identity ([442079561](https://github.com/AlohaSocial/social/commit/442079561a99db427234f318f71433d7bca7b5cc))
- **profile:** Keep own profile actions available ([467077b10](https://github.com/AlohaSocial/social/commit/467077b1066d90eb7b646e596b51e00134da7272))
- **atproto:** Bind sessions to resolved handles ([aed710012](https://github.com/AlohaSocial/social/commit/aed7100129039a18ee4893574d796368cce09475))
- **atproto:** Reconcile gone native posts ([f51474e38](https://github.com/AlohaSocial/social/commit/f51474e385569677f8ba17dae7f04f53ba79d924))
- **atproto:** Surface broken account sessions ([cda3b1d42](https://github.com/AlohaSocial/social/commit/cda3b1d4262f383620192d8f00b0a71706b5025c))
- **atproto:** Reconcile deleted remote records ([9099dd4b6](https://github.com/AlohaSocial/social/commit/9099dd4b6f2924e0f01cc3c6b9b1b93891a8c6ee))
- **atproto:** Keep profile links as at-handle URLs ([a493b3725](https://github.com/AlohaSocial/social/commit/a493b3725e938375e409781d50cfda8d8f0132a7))
- **profile:** Restore own post deletion ([2334db5ab](https://github.com/AlohaSocial/social/commit/2334db5abb69ed83e9005c2bf701a621f904779f))
- **atproto:** Preserve native reply targets ([cf0254db8](https://github.com/AlohaSocial/social/commit/cf0254db8752da29aab0fed5b0a71d890157f292))
- **atproto:** Page native viewer records ([3926ecff6](https://github.com/AlohaSocial/social/commit/3926ecff61ce60827bfa93c7da9b51222165e41a))
- **atproto:** Keep dual-published actions usable ([6ed78cd16](https://github.com/AlohaSocial/social/commit/6ed78cd162f785aca15fe6419f4ce790e97648bb))
- **atproto:** Show composer only for own profile or replies ([e64555457](https://github.com/AlohaSocial/social/commit/e6455545765f859b04e411d27854f16f0a752c13))
- **profile:** Keep own post actions across handle spellings ([6fc8e4849](https://github.com/AlohaSocial/social/commit/6fc8e4849e6aca4b6e62564927de51eef2d4a5cb))
- **profile:** Preserve own profile actions ([9eb5d5593](https://github.com/AlohaSocial/social/commit/9eb5d5593282c8dd911d03f1beebb43a7fec21fb))
- **profile:** Keep own post actions visible ([aa7a5049e](https://github.com/AlohaSocial/social/commit/aa7a5049e1ef3edf4d92faf81bf87ecc37381e76))
- **profile:** Serve local at-handle shells ([a9f9c7a40](https://github.com/AlohaSocial/social/commit/a9f9c7a40652e16afac1f40c43a7d1b9ed1e5df4))
- **atproto:** Use natural at-handle profile URLs ([d5587c7dc](https://github.com/AlohaSocial/social/commit/d5587c7dce1057b996fd84bddbf83a55750a5a85))
- **profile:** Serve direct ATProto handles ([c2c02eb78](https://github.com/AlohaSocial/social/commit/c2c02eb785dabdd0a1b5722d9f516219864a0732))
- **atproto:** Restore shared profile URLs ([3eb37ee25](https://github.com/AlohaSocial/social/commit/3eb37ee25843b7671adbb44ce6aefdc0871b727d))
- **atproto:** Satisfy frontend lint bundles ([e9ed8fabb](https://github.com/AlohaSocial/social/commit/e9ed8fabb19a13538f49fd983bd7ef840154728e))
- **composer:** Type publication target drafts ([a17b752a1](https://github.com/AlohaSocial/social/commit/a17b752a1301cc97807db71e4f4ac6a65d3889b3))
- **atproto:** Serialize public profile feed as local statuses ([3a02d4a3c](https://github.com/AlohaSocial/social/commit/3a02d4a3ce51279506529fd2c3c3e5ecf0123018))
- **atproto:** Satisfy lint and licensing checks ([23a2ccf40](https://github.com/AlohaSocial/social/commit/23a2ccf40280d7a1f789e80cf331e9a4db6735f0))
- **atproto:** Rebuild bundles after validation fixes ([cc3aaa9aa](https://github.com/AlohaSocial/social/commit/cc3aaa9aab4ca6332fc476ea091fc336b9262cfb))
- **atproto:** Satisfy static analysis and style checks ([1b6f99b21](https://github.com/AlohaSocial/social/commit/1b6f99b213a9ba0bbf866f56fcd931806a5746b9))
- **profile:** Draw no banner for the header placeholder, not for a header that equals the avatar ([4bc5e60ac](https://github.com/AlohaSocial/social/commit/4bc5e60acaad02fa3ba75de8f8a9bb1e6187202c))
- **api:** An account always has an avatar, and never its avatar as the header ([40006ed7c](https://github.com/AlohaSocial/social/commit/40006ed7c73b4029d13667752178d8fb9db7e492))
- **moderation:** A moved account is refused before the review queue holds its post ([45bf1d324](https://github.com/AlohaSocial/social/commit/45bf1d324c7e2e11ddfbf64721ac9cdfd43cd598))
- **migration:** A picture nobody asked for is not a failure ([d3616ca16](https://github.com/AlohaSocial/social/commit/d3616ca16ae3817693b526c0c98fe9e4a6f16f3e))
- **migration:** Say why a server would not answer, when that is the reason ([cc3beddf6](https://github.com/AlohaSocial/social/commit/cc3beddf667edffe55ef616d01b6c6d987de1fe6))
- **types:** An Account may carry the account it moved to ([929fbcee8](https://github.com/AlohaSocial/social/commit/929fbcee8e5d6832d7f6445457ec63286e76b55f))
- **federation:** Record where an account that moved here went ([8e52b5a2c](https://github.com/AlohaSocial/social/commit/8e52b5a2cef7102a5f24d45f71e21fd56c5954f9))
- **timeline:** Hold the last list over only for a feed known to have something ([0bebb077b](https://github.com/AlohaSocial/social/commit/0bebb077bfd479a5b2bd8f063b25e7ee492a084e))
- **video:** Publish likes and dislikes, so PeerTube can take a video in ([7d5484366](https://github.com/AlohaSocial/social/commit/7d548436620467e44a3307ff3221d7fe53e30e3e))
- **video:** A uuid PeerTube accepts ([7c8143a76](https://github.com/AlohaSocial/social/commit/7c8143a762ad7a2c72acdb6758f49b3a9f79aa03))
- **federation:** Send an edit as an ActivityPub object ([0bc25f0aa](https://github.com/AlohaSocial/social/commit/0bc25f0aa72f95ee20b52f399bcceebd7c36b051))
- **video:** Deliver a video to the followers of its channel ([67495ed9b](https://github.com/AlohaSocial/social/commit/67495ed9b102230187a6ad59d8e5d0a9c1cee98c))
- **timeline:** Draw a scope switch as one swap, at the width of the column ([05356d3b3](https://github.com/AlohaSocial/social/commit/05356d3b31ac08a844fee8e311bb138534a21cba))
- **fetch:** Read the response a promise settles with ([2592156ba](https://github.com/AlohaSocial/social/commit/2592156ba81d693b682aece734e94a40d2fd7b4d))
- **api:** A Mastodon role on the CredentialAccount, and a 403 that stays a 403 ([eaceda223](https://github.com/AlohaSocial/social/commit/eaceda223073866b206c99b54c75bb0ede38a1f9))
- **timeline:** Type the entries the shared status index holds ([8e8f896e5](https://github.com/AlohaSocial/social/commit/8e8f896e536ffef383b0848e33d6c0004e7c3fc8))
- **ostatus:** Follow the remote link only when it is a web address ([57bebec4d](https://github.com/AlohaSocial/social/commit/57bebec4d96b8bebc55b7097d06358db712882d7))
- **composer:** Hold a video to the server's video ceiling before it is sent ([4e53dbc18](https://github.com/AlohaSocial/social/commit/4e53dbc186696199e5abc327eb7a12b9ce1f1e62))
- **timeline:** Share one status index across the remembered lists ([23b109a8a](https://github.com/AlohaSocial/social/commit/23b109a8af13cc1f5c296b326c24ca2606573ea6))
- **composer:** Keep a reply's draft apart from the post's own ([bddbf1057](https://github.com/AlohaSocial/social/commit/bddbf105789bff145a8cae94f68f9ed3d7da152f))
- **notifications:** Draw a notification's post from the store ([7a78db23a](https://github.com/AlohaSocial/social/commit/7a78db23a95476bcd1b5d030fd9ee0abd87f0807))
- **boost:** One Announce row per booster ([15b3f3100](https://github.com/AlohaSocial/social/commit/15b3f3100a19060884afd2bd64c64dea251d7fb1))
- **collections:** Read the posts of a collection as the reader ([4ec0cfd19](https://github.com/AlohaSocial/social/commit/4ec0cfd192177e87575e52f94413518d294522e5))
- **stream:** Read the author from the cache inside the save transaction ([c4ab411d6](https://github.com/AlohaSocial/social/commit/c4ab411d62002428c8000ee4f6483831de8578b2))
- **media:** Transcode videos uploaded here only ([0e8bd3b33](https://github.com/AlohaSocial/social/commit/0e8bd3b33cfed24413cd2c5f3d60c423f1bb8867))
- **retention:** Purge queues and old notifications whatever retention_days says ([848284124](https://github.com/AlohaSocial/social/commit/848284124cb07895508532fd1027c215acfacdcb))
- **visibility:** Close six small leaks across replies, blocks, reports and links ([aaf7273ae](https://github.com/AlohaSocial/social/commit/aaf7273aeedf7b7118b7bb21cf7f82e53a6c2bcf))
- **timelines:** Keep unlisted posts off the tag timelines ([ac03c4576](https://github.com/AlohaSocial/social/commit/ac03c4576deae7a4f3827b81371cbd72ea8ea2e5))
- **highlights:** Count a profile's top hashtags over public posts only ([905bdd4e6](https://github.com/AlohaSocial/social/commit/905bdd4e63bbe797733fabdea48f39eea07e4b92))
- **quotes:** Approve a quote only by policy or by a recorded grant ([a0e82ea7e](https://github.com/AlohaSocial/social/commit/a0e82ea7e928d23caea39ff21132c75d3bc5f00e))
- **oauth:** Keep unsafe schemes off the consent page's links ([c3991c493](https://github.com/AlohaSocial/social/commit/c3991c4933b95e0fa9da70312d27a771809a274e))
- **oauth:** Bind the authorization code to a registered redirect_uri ([fc5ed2dd8](https://github.com/AlohaSocial/social/commit/fc5ed2dd8c9362dc3818910916a333c3c9764c80))
- Show only Aloha in the Nextcloud top bar ([9c89a987e](https://github.com/AlohaSocial/social/commit/9c89a987eec830ed8db01920b02941d7db205b11))
- **api:** Edit the descriptions of a published post's media ([a53a178b5](https://github.com/AlohaSocial/social/commit/a53a178b511568e1bc3b55398acece72a4b33ce8))
- **api:** An avatar with no local copy yet no longer answers 500 ([b1e5efffd](https://github.com/AlohaSocial/social/commit/b1e5efffdcf4b2ad2fde5e8b9e0f26537755f845))
- **api:** Read a multipart PATCH to update_credentials ([3b1bd8df1](https://github.com/AlohaSocial/social/commit/3b1bd8df1742ca26e87e01af2f95d60f754b741a))
- **media:** Play shorts and stories with sound, one element per player ([633e930b2](https://github.com/AlohaSocial/social/commit/633e930b27378687f7d86b2f98e252614d7a656f))
- **media:** Bake photo filters in on safari and iphone, and shrink large photos ([705aae5f4](https://github.com/AlohaSocial/social/commit/705aae5f4cdff6a5b02997a653488da14e24539d))
- **l10n:** German for the switch wizard's rewritten YouTube step ([f85994cd6](https://github.com/AlohaSocial/social/commit/f85994cd6aa59290790e7ff735f7772ecf86da62))
- **l10n:** Restore and validate complete German catalog ([93a5f8308](https://github.com/AlohaSocial/social/commit/93a5f830886ab2682c647471d17ac291d81d4365))
- **l10n:** Keep German catalogs in sync ([eb4b5e814](https://github.com/AlohaSocial/social/commit/eb4b5e8144806fa854c13d8bc228503b584e3a20))
- **subscriptions:** Validate feed website links ([68f016efd](https://github.com/AlohaSocial/social/commit/68f016efd77bc38f60a9b98dc97db9c3a5a9bd28))
- **instance:** Store the local-instance flag as an int, which PostgreSQL requires ([06b297d12](https://github.com/AlohaSocial/social/commit/06b297d12aefe223a655327ea2ddd5a0db072af7))
- **instance:** Count the weekly activity statuses against a date ([07ff421da](https://github.com/AlohaSocial/social/commit/07ff421da67fd060d5d5568e332fbd08e7097934))
- **subscriptions:** Satisfy strict static analysis ([dd93f26c4](https://github.com/AlohaSocial/social/commit/dd93f26c478cf1554b9e4063a52c74a83060eb34))
- **social:** Harden subscriptions, videos and routing ([c1c90cc40](https://github.com/AlohaSocial/social/commit/c1c90cc40828184f537cd50fee18d6fbfa48fd08))
- **api:** Two routes were caught by a catch-all read before them ([cb27345dd](https://github.com/AlohaSocial/social/commit/cb27345dd9234f7c074a00298bbda694255278fb))
- **api:** A granular scope no longer opens its whole parent on filters and tags ([a317a8478](https://github.com/AlohaSocial/social/commit/a317a84783cf2b3f779c2c136865f90c737d0134))
- **composer:** Escape what the mention and hashtag menus draw ([fd7717afb](https://github.com/AlohaSocial/social/commit/fd7717afba2729fa2e532d7876a2ca35e9790045))
- **shorts:** The sound button works, sound starts on, and a + for a new short ([e6749679a](https://github.com/AlohaSocial/social/commit/e6749679aad7b2160c85090d0c8ca5d5e04fa709))
- **statistics:** Choose the window with the app's own switcher ([eb5b77436](https://github.com/AlohaSocial/social/commit/eb5b77436589edb98ca05f5c114a98d8d010abb1))
- **timeline:** Show a short the shape it was shot ([cc2eceff2](https://github.com/AlohaSocial/social/commit/cc2eceff2478df2f90c02c2a510be745e1a94f41))
- **navigation:** Draw the account menu as a panel, not more of the list ([30d3835ab](https://github.com/AlohaSocial/social/commit/30d3835abb8d6615223d8d3958257cc6bacd6890))
- **subscriptions:** Keep what a feed published, however long ago ([2f29ed2b1](https://github.com/AlohaSocial/social/commit/2f29ed2b18cec85c1373100985cc725ee86707e5))
- **navigation:** Subscriptions belongs with the things to read ([af8bc5065](https://github.com/AlohaSocial/social/commit/af8bc50653d3f81f02301b5760888863e7e08baf))
- **navigation:** A way to Subscriptions ([ed83aea3a](https://github.com/AlohaSocial/social/commit/ed83aea3aff5f82c45873a7f7c893c769a558b5c))
- **interests:** The migration number, and the JSDoc TypeScript 7 rejects ([1a0a44715](https://github.com/AlohaSocial/social/commit/1a0a447155eaf9bb6c3a6cf73a52c3e0497fd8f4))
- **typecheck:** One baseline key wherever the checkout sits ([4faf49514](https://github.com/AlohaSocial/social/commit/4faf49514a793fc919d420b4b2158d1b942854c7))
- **fediverse:** An allowed instance may serve its pictures from its own media host ([b37cfdcc5](https://github.com/AlohaSocial/social/commit/b37cfdcc5147d8e4295ab9241855da111e3d284b))
- **federation:** Say which attachments a post lost, and why ([a101273e1](https://github.com/AlohaSocial/social/commit/a101273e193ccefe8f974723218ea33abdfcd5d7))
- **playlists:** Hold a received playlist's owner to the actor that sent it ([076dd78fa](https://github.com/AlohaSocial/social/commit/076dd78faca11e0abf542bfef878c6ae8ea7fa31))
- **media:** An attachment with no copy here names no link to here ([71a87e87e](https://github.com/AlohaSocial/social/commit/71a87e87e1b0d419a655e53135e7e031b7a13aff))
- **cache:** Keep the Idempotency-Key record in DurableCache ([08512c5e1](https://github.com/AlohaSocial/social/commit/08512c5e1655158462b6fc3ab2a4cf3cc6fceb81))
- **docs:** Correct the claims the code refutes and stop counting in prose ([857dbf7ef](https://github.com/AlohaSocial/social/commit/857dbf7ef8bd0409198ece0fae441071aa721486))
- **config:** Derive social_url from cloud_url, not from the first request ([653ebb08d](https://github.com/AlohaSocial/social/commit/653ebb08d7e861666d9e757c6e0fc68b09b41df8))
- **moderation:** Register the daily block-list re-read ([8e6821aa8](https://github.com/AlohaSocial/social/commit/8e6821aa8800de691a3a8647280ea05d7bd8e7c9))
- **discover:** Stop the directory listing waiting on remote servers ([a723374af](https://github.com/AlohaSocial/social/commit/a723374af9ae5134852bdfcedf9dec3a7abf60e5))
- **statistics:** Keep the computed page without a memcache ([d48d907b8](https://github.com/AlohaSocial/social/commit/d48d907b87f75b0ad6b65a0d75601f2c7bbeab65))
- **cache:** Keep the inbox throttle and ld replay records without a memcache ([f2a097bbb](https://github.com/AlohaSocial/social/commit/f2a097bbbcea4a814ad2bf8fb735dfb512ba0015))
- **app:** Drop the second reduced-motion block for the page change ([045e87b27](https://github.com/AlohaSocial/social/commit/045e87b272940d82f238c54f530d37b88a5a8066))
- **switch:** Draw the card once the announcement arrives, and say when it does not ([a617613a3](https://github.com/AlohaSocial/social/commit/a617613a34118a15ddbcdf3e6cec600cc5035826))
- **stories:** Keep the story composer's file input out of the tab order ([a0b2802d2](https://github.com/AlohaSocial/social/commit/a0b2802d2852fb89e02588755ba4ff7d4e7c5f32))
- **first-run:** Let the introduction be opened again, and take Pixelfed's file ([04094c929](https://github.com/AlohaSocial/social/commit/04094c9294348438d7b29e4925b4002d2786da40))
- **emoji:** Draw the character itself when there is no picture for it ([74c61a284](https://github.com/AlohaSocial/social/commit/74c61a2840e267ddfbacac666fe7bacada25e440))
- **setup:** Draw the setup page when the server sends no checks ([ae0c0d1c9](https://github.com/AlohaSocial/social/commit/ae0c0d1c9e0451a0c183d351ea2c9ef6349ce48a))
- **profile:** Do not ask for familiar followers on a public page ([3330a8f58](https://github.com/AlohaSocial/social/commit/3330a8f581740896d4f1144a762963d3260ebec5))
- **app:** Fetch the emoji picker only when a reaction is asked for ([1a652c5de](https://github.com/AlohaSocial/social/commit/1a652c5de94e9539f0088cce1e35aaaae698ccd6))
- **statistics:** Let the "Counted over" choices wrap on a phone ([bdf1a69de](https://github.com/AlohaSocial/social/commit/bdf1a69debb3210ab8c62873ab81c14f9675e94d))
- **migration:** Keep the hidden file inputs out of the tab order ([c22fcf226](https://github.com/AlohaSocial/social/commit/c22fcf226570a0cb337c83a8ff211ada42b7631b))
- **admin:** Apply a block list source when it is switched on ([c234de763](https://github.com/AlohaSocial/social/commit/c234de763f568df4eb176fbf4d713ee62da9bf92))
- **reels:** Name the stack as a region, respect reduced motion, follow scope ([fea8ddd53](https://github.com/AlohaSocial/social/commit/fea8ddd53566f293b1c7ddb7c1d566cdc432abf6))
- **reels:** Show a spinner while loading and an error when it fails ([1795be156](https://github.com/AlohaSocial/social/commit/1795be1568e5cd0c0630099653ac3947eb811fef))
- **reels:** Key each slide by its video rather than its post ([80093a1a1](https://github.com/AlohaSocial/social/commit/80093a1a19d39feee693a76e3c001db3d743c1ef))
- **reels:** Name the account in the link to a slide's post ([915f319f2](https://github.com/AlohaSocial/social/commit/915f319f29012c52ae78a82ac4625ffd8911ffab))
- **statistics:** Name months and weekdays in UTC, as they are counted ([87b4992d8](https://github.com/AlohaSocial/social/commit/87b4992d822e9dde0f177a36087c86e935ce7825))
- **subscriptions:** Stop drawing thumbnails the page CSP blocks ([13251763e](https://github.com/AlohaSocial/social/commit/13251763e5866b7d53b0d2d71fa552b6ddf0fcc6))
- **subscriptions:** Say when the entries could not be loaded ([123d18abf](https://github.com/AlohaSocial/social/commit/123d18abf50c7aae10e168f75c064311cc805cc2))
- **subscriptions:** List the entries again after an add, remove or import ([c287107b3](https://github.com/AlohaSocial/social/commit/c287107b30167ae707c01958a764121ea060955c))
- **delivery:** Deliver to many servers at once and keep draining while time is left ([505388d30](https://github.com/AlohaSocial/social/commit/505388d307cbf014933aec69d2375369a202eb6c))
- **delivery:** Keep the per-host circuit breaker in the database ([2f4ae1766](https://github.com/AlohaSocial/social/commit/2f4ae17668116609c4bc6ed9427abb6f0d866719))
- **cron:** Refresh cached remote actors for a time budget, not one batch ([938790abf](https://github.com/AlohaSocial/social/commit/938790abf30ceb2ddbd195aec0bc99c9378f3d0e))
- **subscriptions:** Budget the feed re-read by time and read feeds concurrently ([46cf7cfe1](https://github.com/AlohaSocial/social/commit/46cf7cfe17cdee288d8261cb777b99acbb1be8a4))
- **subscriptions:** Read followed feeds in the job and prune what they keep ([eeb008dee](https://github.com/AlohaSocial/social/commit/eeb008deed992d300721878702a957a77878c312))
- **subscriptions:** Stream feed and block-list bodies with a hard byte cap ([f4a3e8e34](https://github.com/AlohaSocial/social/commit/f4a3e8e347515caf70c78e3339674edbc145abeb))
- **api:** Answer the polled sidebar routes with an ETag and 304 ([51bddc860](https://github.com/AlohaSocial/social/commit/51bddc860e2f1abe449c99bd621e1b667c2b4169))
- **trends:** Count all five trend windows in one pass ([0adc6949f](https://github.com/AlohaSocial/social/commit/0adc6949fcdaaf428a828aa4a414de6c9cd1a446))
- **cron:** Stop reading every local account twice per cache pass ([2815f5622](https://github.com/AlohaSocial/social/commit/2815f56222cbecd2317460e00aff9fe9a0d30d5a))
- **moderation:** Apply a block list with one read and one write per list ([be4571fd7](https://github.com/AlohaSocial/social/commit/be4571fd748af98c302790df91472eb666068499))
- **migration:** Page the cached-actor host backfill by keyset ([ded40b410](https://github.com/AlohaSocial/social/commit/ded40b4109b177085d036519cce310281448578a))
- **activitypub:** Page the outbox, followers, following and replies by cursor ([f5385813e](https://github.com/AlohaSocial/social/commit/f5385813e4ad9b3e0a5df4be2b45414020496c48))
- **db:** Search cached accounts on an indexed lowercase handle ([2e22afa6f](https://github.com/AlohaSocial/social/commit/2e22afa6f67578aaf7ee6d17d4b4b7174a0d892b))
- **db:** Store post hashtags normalised and compare them as they stand ([09a009bb2](https://github.com/AlohaSocial/social/commit/09a009bb26425ca498f22cc1e2ee0a7586e2f3bb))
- **db:** Bound the home timeline read by its collections and by time ([503595e2c](https://github.com/AlohaSocial/social/commit/503595e2c9c0e79667780facd6845d39d9489720))
- **db:** Count unread notifications in the database ([b845034a4](https://github.com/AlohaSocial/social/commit/b845034a4ae37ebdd03d71f747551cc0d79f5d97))
- **db:** Page public, notification and direct timelines on the recipient nid ([112bf04fe](https://github.com/AlohaSocial/social/commit/112bf04fe95fcec7e4f22b29eef8a0dcf22c20aa))
- **db:** Join the follow, like and search pages on the prim columns ([05b8758b6](https://github.com/AlohaSocial/social/commit/05b8758b6e6176327132732a0e518edfdd28d8c7))
- **activitypub:** Accept a signed date up to twelve hours old, once ([bacde1722](https://github.com/AlohaSocial/social/commit/bacde1722bf8eb1152c21df35e7878e1394e32f3))
- **activitypub:** Read an embedded inreplyto and a single alsoknownas ([a85203c9d](https://github.com/AlohaSocial/social/commit/a85203c9d1324b3830dc6a80821cdb1e12d0f921))
- **activitypub:** Fetch the object of a create that names it by id ([8ce7a8ba9](https://github.com/AlohaSocial/social/commit/8ce7a8ba9f00080c48efb81781d0300705e890db))
- **activitypub:** Give every update of an object its own activity id ([ec36008d6](https://github.com/AlohaSocial/social/commit/ec36008d6255f18fed6e54dc8dc270f30701977e))
- **activitypub:** Serve local profile field values as escaped html ([0db8ca419](https://github.com/AlohaSocial/social/commit/0db8ca4192c10e7f67103ca1eba89ce50cdac7f1))
- **webfinger:** Answer the account's own handle as the subject ([b4c40a39b](https://github.com/AlohaSocial/social/commit/b4c40a39b40187411549d9f9e86ee134f322051b))
- **activitypub:** Serve outbox and featured posts without internal bookkeeping ([547063ee3](https://github.com/AlohaSocial/social/commit/547063ee3cb51473e77de03b64d440bce473a93f))
- **activitypub:** State the banner's real media type ([10e081deb](https://github.com/AlohaSocial/social/commit/10e081deb7ee5a396a9c3f1812369e2c267ffa84))
- **activitypub:** Read as:Public and Public as the public collection ([709619cc1](https://github.com/AlohaSocial/social/commit/709619cc10c503ae1e3fa21445638efa4494413f))
- **activitypub:** Verify the signed host with the configured port ([b64f23b55](https://github.com/AlohaSocial/social/commit/b64f23b558c8ab21112d422b73bec5f868310749))
- **activitypub:** Leave the loopback row out of followers and following ([69c80a650](https://github.com/AlohaSocial/social/commit/69c80a6506b6a2e4c6fef46b181bdbe3a862af4d))
- **activitypub:** Keep a remote display name with a bare less-than sign ([3a50885fb](https://github.com/AlohaSocial/social/commit/3a50885fb30de7c1ddcbc6ac513da822fc4a9c28))
- **activitypub:** Keep the link of a lemmy link post ([61147c0a3](https://github.com/AlohaSocial/social/commit/61147c0a336621a93dbbea703d7f4594f93866ab))
- **activitypub:** Embed the announce in an undo of a boost ([9e219804e](https://github.com/AlohaSocial/social/commit/9e219804ec1ebe1086d40dac38c9991b63bc2152))
- **activitypub:** Keep the largest picture of a peertube avatar or banner list ([67b2abe0e](https://github.com/AlohaSocial/social/commit/67b2abe0e32478027027517a59944c4c96c8ca77))
- **activitypub:** Read a single attachment or tag object as a list of one ([1b447104e](https://github.com/AlohaSocial/social/commit/1b447104ecb2f36f56f320a2ebc5359dc9a3a7a3))
- **activitypub:** Fetch a forwarded post from its origin instead of refusing it ([54fef8e25](https://github.com/AlohaSocial/social/commit/54fef8e25ed7c56635e0d324896fb64d8d19ae63))
- **activitypub:** Answer the profiled ld+json type and keep the status ([88a4e9d12](https://github.com/AlohaSocial/social/commit/88a4e9d127567309027109e734ceb19db1bd508f))
- **profile:** Do not report an account nobody holds as a failed lookup ([ea9a0bffe](https://github.com/AlohaSocial/social/commit/ea9a0bffe9fa928edbc32e4300bb7541bddbcb53))
- **api:** Answer the caller's own mistakes on the session routes with a 4xx ([6ff893e72](https://github.com/AlohaSocial/social/commit/6ff893e72e17692229e65f15f304c5c8ab03e0a1))
- **moderation:** Resolve an incoming Flag from the cache, and cap it ([6dc662bc8](https://github.com/AlohaSocial/social/commit/6dc662bc84fbc2a5719aee4a84aaf741bc452851))
- **follow:** Move the following count only when a follow changes ([57c199f68](https://github.com/AlohaSocial/social/commit/57c199f68cb40759fd582f52ea97e73726e15f47))
- **import:** Forget an imported post when it is deleted ([4d3cd1d06](https://github.com/AlohaSocial/social/commit/4d3cd1d06d9a0046c4ba73cc26f1e8a4d01609e5))
- **posts:** Delete a poll by its own type ([754af4df4](https://github.com/AlohaSocial/social/commit/754af4df41d2d278d55411c6dcc399691227be5b))
- **profile:** Read the Tagged view's handle from the route as a string ([1f5837f78](https://github.com/AlohaSocial/social/commit/1f5837f78153b9732007cef57f2983eac1382c57))
- **media:** Charge a remote picture to the server it came from ([74e05e22f](https://github.com/AlohaSocial/social/commit/74e05e22fb4e8c2c5bc7540894a2cdbcac90b554))
- **federation:** Remove a deleted poll when its Delete arrives ([a943450f9](https://github.com/AlohaSocial/social/commit/a943450f9c946c6fc83b43953c2d7c33d30fb532))
- **media:** Give the caching run time to finish a large remote picture ([6465cc142](https://github.com/AlohaSocial/social/commit/6465cc1427efcd60362cb8121202a0bd623f5568))
- **media:** Keep a post's pictures when the cron fetches one it missed ([8ccf18322](https://github.com/AlohaSocial/social/commit/8ccf1832256497c00d99ddec5035c071b4aff9e2))
- **direct-messages:** The send button ran off the bottom below 1024px ([ea9560d50](https://github.com/AlohaSocial/social/commit/ea9560d5064db4e5b110ecd357409ef98fca9294))
- **direct-messages:** No floor under the chat's height, and two panes down to 801px ([e9fb9ba68](https://github.com/AlohaSocial/social/commit/e9fb9ba68d0b2920c5e85c5f1fbe569b9c95fc5b))
- **direct-messages:** People you follow first, other accounts under their own heading ([b2c0584a5](https://github.com/AlohaSocial/social/commit/b2c0584a50cdefd48436dde1d52d5c23fee4a531))
- **conversations:** Take a conversation id as the nid string it arrived as ([3bed5c659](https://github.com/AlohaSocial/social/commit/3bed5c659e257760d61b27d10d437ff3dffba8eb))
- **notifications:** Compare marker and group ids as nids, not PHP ints ([fdd5aba1a](https://github.com/AlohaSocial/social/commit/fdd5aba1a8ff5c3cb33337bf591bff5d652cd11f))
- **timeline:** Pass a paging cursor on as the nid string it arrived as ([be6980019](https://github.com/AlohaSocial/social/commit/be698001969acd7235b90aafdc8d8681f150ca82))
- **media:** Look a streamed document up by the string its route was given ([ca977d6d7](https://github.com/AlohaSocial/social/commit/ca977d6d780b599988ef50af569841a68cefcbee))
- **collections:** Take a collection item's post id as int|string ([cc384f234](https://github.com/AlohaSocial/social/commit/cc384f234666c5ac5c3c31f97cdb4d400960a901))
- **filters:** Hold a filtered post's nid as a decimal string ([92edaafc1](https://github.com/AlohaSocial/social/commit/92edaafc1b6f91514fa9de5ed728d6b8e003eb1e))
- **media-tags:** Carry a post's nid as a decimal string ([16c4d5219](https://github.com/AlohaSocial/social/commit/16c4d52191742817825a13dfdb1b8d4c24f513b8))
- **hashtags:** Stop treating the first page of followed tags as all of them ([cb6597d8b](https://github.com/AlohaSocial/social/commit/cb6597d8bb263b620785c28a4e2daa4978c57d7a))
- **lists:** Page list members in Settings past the first 500 ([6353ff264](https://github.com/AlohaSocial/social/commit/6353ff264a8f9a54a0c5f0ca48a3baf953570299))
- **profile:** Page the Tagged view past its first twenty posts ([4661e694b](https://github.com/AlohaSocial/social/commit/4661e694b507f2e8edef24a04822f9e156666f2c))
- **moderation:** Page the refused pictures instead of stopping at 500 ([f8cadf20e](https://github.com/AlohaSocial/social/commit/f8cadf20e1d784eed90462e203096494cd0a5d44))
- **follow-requests:** Page the pending requests instead of reading them all ([e3c694f72](https://github.com/AlohaSocial/social/commit/e3c694f72f593e86c90de4338bd74d4e4555fed6))
- **dashboard:** Keep polling notifications after a failed ask ([642a71a00](https://github.com/AlohaSocial/social/commit/642a71a009fdfb760107e4ed3e6387b02d1eab15))
- **lists:** Let only the newest member search answer, on failure too ([96a35a023](https://github.com/AlohaSocial/social/commit/96a35a02320ad2f41ed426e0032876e9303ecaa1))
- **frontend:** Let only the newest load of a route-reused view commit ([7df662a8b](https://github.com/AlohaSocial/social/commit/7df662a8b2ff3f0b4f1aad519b7516bf35c2e0e4))
- **types:** Describe the server data, view counts and report rows the code reads ([cf3209ebb](https://github.com/AlohaSocial/social/commit/cf3209ebb8be11fd22f5cdaf3b41a39b5803eb5b))
- **components:** Declare the fields components assign in their hooks ([1c8e8fa20](https://github.com/AlohaSocial/social/commit/1c8e8fa203f7150931b06b6062851b70027f40fe))
- **types:** Declare the translate helpers and server namespaces on every component ([4ee4f3810](https://github.com/AlohaSocial/social/commit/4ee4f381027ddc435ad6f86b71bd21d28222c03d))
- **deps:** Put back the three dependency ranges a version bump rewrote ([1857cf758](https://github.com/AlohaSocial/social/commit/1857cf758909a324d738de8b5e8305a3d713a564))
- **stories:** Holding a video story stops it ([4a7f93776](https://github.com/AlohaSocial/social/commit/4a7f93776dcdd3e58e6ad13fa1ee172acfd2063d))
- **navigation:** The server answers /reels too ([83ef34c3c](https://github.com/AlohaSocial/social/commit/83ef34c3c1833d14548474e49bc67383778e85e7))
- **app:** A page change no longer drops the new page halfway down the column ([97db3d5fb](https://github.com/AlohaSocial/social/commit/97db3d5fb6c6a58b99350d6f367f209e423a17f8))
- **oauth:** Make the consent page legible ([32ec784a6](https://github.com/AlohaSocial/social/commit/32ec784a6aa905c4e9279923fc2b37c39738ff1d))
- **navigation:** One profile entry, and it is the one with the posts ([7461d8717](https://github.com/AlohaSocial/social/commit/7461d871718ac70272a568c0e81e5b5368d127ac))
- **direct-messages:** Which mention is the routing one, and who a reply reaches ([8801b9686](https://github.com/AlohaSocial/social/commit/8801b9686638770e574d7948ae4ac9103da94087))
- **direct-messages:** The label was drawn on top of the message box ([b399f2f0f](https://github.com/AlohaSocial/social/commit/b399f2f0fa32fd53ec4a20a3ea0f5a12c6000bb8))
- **profile:** Who may open a handle this server has never seen ([937cfbab1](https://github.com/AlohaSocial/social/commit/937cfbab157d3d0af3ac87abdd1ec43a68f1c4f7))
- **composer:** A draft belongs to the account that wrote it ([19868ae8e](https://github.com/AlohaSocial/social/commit/19868ae8e4c088f8f01372234eccd286bb9bd7fc))
- **scheduled:** The waiting posts past the first fifty ([a14270670](https://github.com/AlohaSocial/social/commit/a14270670ce70cc29d8938872106afefb728b0aa))
- **frontend:** An answer belongs to the question that was asked ([a41bc66f1](https://github.com/AlohaSocial/social/commit/a41bc66f19d404b99e8f9467d2f5145577c4ecdd))
- **portfolio:** A public portfolio is no longer answered as a missing post ([c93216efe](https://github.com/AlohaSocial/social/commit/c93216efe47b573a319860f969d3f428455e5d20))
- **fediverse:** What a blocklist CSV is allowed to say ([ed889fea7](https://github.com/AlohaSocial/social/commit/ed889fea7d9532f8945556203922f58f13cce88e))
- **moderation:** A page of accounts belongs to the search that asked for it ([dcdbea228](https://github.com/AlohaSocial/social/commit/dcdbea2288636b5ff6b6ae6430484a7d05320eb0))
- **l10n:** The new inbox label in the German catalogues ([257842c03](https://github.com/AlohaSocial/social/commit/257842c03fccb338fd53a767203f47e0db4bf487))
- **direct-messages:** Three ways the inbox lost a conversation ([503f47797](https://github.com/AlohaSocial/social/commit/503f47797e3c6ecdf53a0fe1756f2d6e0c4dcf4f))
- **media:** The attachment a peer sent on its own ([4dda814e1](https://github.com/AlohaSocial/social/commit/4dda814e144a9b68fcc4d357328571c6b99ed497))
- **history:** A status id that is not a number is a 404 ([f81053b79](https://github.com/AlohaSocial/social/commit/f81053b791a1d4733e51d1c101330a5ae1047b1a))
- **visibility:** Two reads that returned posts their reader may not see ([75da8cbaa](https://github.com/AlohaSocial/social/commit/75da8cbaaad4236d802f318edfbad0a40277447f))
- **documents:** A redelivered attachment writes down what it says ([0778b310b](https://github.com/AlohaSocial/social/commit/0778b310bc034e64f02028f3c9baecb2ad493296))
- **scheduled:** Page the waiting posts by the order they are drawn in ([f104a77e9](https://github.com/AlohaSocial/social/commit/f104a77e93d11eedebebdd7a6dde2b08626ca7ea))
- **filters:** A change that fails leaves the filter as it was ([9ad7d8ca6](https://github.com/AlohaSocial/social/commit/9ad7d8ca6de10d6cb6023b5c32698572f3e680c7))
- **local:** A ceiling on the two public routes that had none ([d2ff9fbc2](https://github.com/AlohaSocial/social/commit/d2ff9fbc28f2d3783f7129f98d78d2f68fec286c))
- **media:** The one byte route that had no ceiling ([6a9755868](https://github.com/AlohaSocial/social/commit/6a97558686cc02aa9242e36f22d81beea62aa2e5))
- **config:** Read the avatar version as the number it is ([1e8528084](https://github.com/AlohaSocial/social/commit/1e852808475ae2bd14d3b1a3f69c510a0ac4c933))
- **l10n:** Two strings that could never have been translated ([877025929](https://github.com/AlohaSocial/social/commit/877025929ad4f15bc63d2b3a991e16ec1ce9d88e))
- **frontend:** Two things the new tests found ([7fff6951c](https://github.com/AlohaSocial/social/commit/7fff6951c22b339904df463365787f6029aeb472))
- **profile:** A picture kept local stays local ([86087ca2c](https://github.com/AlohaSocial/social/commit/86087ca2c1ea2bd308a5ca74623b597ebcd0f6d4))
- **statistics:** Let the chart follow the theme ([a2fa70565](https://github.com/AlohaSocial/social/commit/a2fa7056516829e31fce90eed6301c39c549e44b))
- **checks:** The proxy has to pass the scheme on, and now says when it does not ([615d3f016](https://github.com/AlohaSocial/social/commit/615d3f0167aae29e1f7f003daa4ed10fa395dc68))
- **oauth:** Write the redirect URI as a CSP source, not as a URI ([e971d574f](https://github.com/AlohaSocial/social/commit/e971d574fc5f0e9f210070a9979c9c7f2e5bbfaa))
- **oauth:** Answer the registration in the shape a typed client decodes ([38f0aef12](https://github.com/AlohaSocial/social/commit/38f0aef1252d3839dff32717b00981f5b9901b38))
- **layout:** A page with little on it starts at the top, not halfway down ([6a6ed77d5](https://github.com/AlohaSocial/social/commit/6a6ed77d55210d1521b0a6325130a85048115764))
- **checks:** Write down whether the root answers, rather than only caching it ([905bdbe5f](https://github.com/AlohaSocial/social/commit/905bdbe5ff4fc09178177dd4a7257d6848522cef))
- **oauth:** Make discovery work at the domain root ([a235134c1](https://github.com/AlohaSocial/social/commit/a235134c15f74c4db248b409fbe31170993f7b78))
- **oauth:** Let Authorize finish, and put the dialog on the page ([3ecd36926](https://github.com/AlohaSocial/social/commit/3ecd36926bc9bbb1be8f64d0a62f83cf24a72795))
- **checks:** Say what the client-API probe got, not just that it failed ([5b74fdcb8](https://github.com/AlohaSocial/social/commit/5b74fdcb81ea15333392ef1be3d7adb231ed362a))
- **discover:** Set the directory line as one sentence ([b88963e0c](https://github.com/AlohaSocial/social/commit/b88963e0c4f2fc1b7dce9ec09dc55697aa1ffcb1))
- **statistics:** A reach of "0%" is a true number that says nothing ([9967ede49](https://github.com/AlohaSocial/social/commit/9967ede49616cb3f5de5f02905bb96bbf2be5e5c))
- **statistics:** Do not print a year that measures the survey ([69486382f](https://github.com/AlohaSocial/social/commit/69486382f09837bf350346dbbd6747c3400bd1c1))
- **admin:** Read the stuck-since moment as a date, and show it ([414fd8ed2](https://github.com/AlohaSocial/social/commit/414fd8ed29bf0425e381f262c1822a46796a957a))
- **federation:** Stop repeating a counter nobody could believe ([e0b1b6f15](https://github.com/AlohaSocial/social/commit/e0b1b6f1518623d91b191485c4348bd21c299b11))
- **federation:** Read a url that arrived as a Link object ([953513d74](https://github.com/AlohaSocial/social/commit/953513d74edda0b945829ba34a88f81d9d8dd196))
- **frontend:** Keep a page inside the column it is drawn in ([f302c88c7](https://github.com/AlohaSocial/social/commit/f302c88c7a1ceffe474cebf0c425e3bf12475e23))
- **logging:** Stop writing whole actor documents to the log ([98885219d](https://github.com/AlohaSocial/social/commit/98885219da6078f716d6a7241835c575c401b448))
- **security:** Refuse an address that would run something ([7ac16fcff](https://github.com/AlohaSocial/social/commit/7ac16fcff28f0c89f8f4cce92b48def611faa107))
- **deps:** Fix npm audit ([ccdd582a7](https://github.com/AlohaSocial/social/commit/ccdd582a7354b10cf3b9213c6c3a9eb243070b11))
- **timeline:** Stop the switcher blinking through a skeleton ([29b4496c1](https://github.com/AlohaSocial/social/commit/29b4496c1f1451e17522868ea177c633305809db))
- **federation:** Keep the page address where the row can carry it ([bc1c78b03](https://github.com/AlohaSocial/social/commit/bc1c78b032a837a7824d9efd6247177158a4aae1))
- **federation:** Open the page a post names, and honour who may quote it ([e96631e22](https://github.com/AlohaSocial/social/commit/e96631e22e3ae671da1c6eb3e04f456cd6b831a5))
- **discover:** Render the graph section, and a follow button that works ([cdd2fe9d2](https://github.com/AlohaSocial/social/commit/cdd2fe9d20debbdab3507a7a4cc0b5d82a409f22))
- **directory:** Keep what the lookup found when a server has no directory ([2b17de645](https://github.com/AlohaSocial/social/commit/2b17de645973b387fbd0052cac6e8e05959ce8f3))
- **account:** Let the setup screen be the one that makes the account ([41c269838](https://github.com/AlohaSocial/social/commit/41c269838c656da266d77be5377dcf1984534d55))
- **compat:** Hold on Nextcloud 35 and 36 alike ([dc7e7d730](https://github.com/AlohaSocial/social/commit/dc7e7d730d51b7ce68279b5194479a7358fa55d7))
- **packaging:** Stop denying the browser the app's own JavaScript ([664cf465f](https://github.com/AlohaSocial/social/commit/664cf465ff96f63d6c1b0fbcff5cf11b6de13eec))
- **app:** Animate the page, not the whole window ([6e0e2b876](https://github.com/AlohaSocial/social/commit/6e0e2b8764be4334d0d43678387c7bfd596f8b4d))
- **frontend:** Match a custom emoji shortcode whatever its case ([c99b47c0b](https://github.com/AlohaSocial/social/commit/c99b47c0bfa8e28a730aa1eceb68db0b9001a946))
- **frontend:** Put the overflow menu at the near end of the action row ([a71f64875](https://github.com/AlohaSocial/social/commit/a71f64875ed9deff7614a12d9b776a36e7fb4364))
- **frontend:** Give the private note a way out of an edit ([88748f7bb](https://github.com/AlohaSocial/social/commit/88748f7bbbbc59941c9e3413efc77e03acbd739c))
- **frontend:** Sanitise the edit history before rendering it ([329e8ea1b](https://github.com/AlohaSocial/social/commit/329e8ea1b9e8e177433fd672a6f4137bcbcfd5da))
- **frontend:** Link public account entries to their profile URL ([553ce1549](https://github.com/AlohaSocial/social/commit/553ce1549e3f8efbb469f5fa02fbc960ec26a774))
- **frontend:** Ask for a page size when loading followers ([5dd7506f2](https://github.com/AlohaSocial/social/commit/5dd7506f25708c4b389bdbc100045bd3f69b843f))
- **frontend:** Read the follow state back from the server ([8d1dad64a](https://github.com/AlohaSocial/social/commit/8d1dad64ad2622f9c15e22a76b125296c0db6243))
- **frontend:** Discard search responses the reader has typed past ([0857b3235](https://github.com/AlohaSocial/social/commit/0857b323521b4d1a10556df8b0314a86c8db4d65))
- **timeline:** Announce arriving posts against the scrolling column ([f89a329a6](https://github.com/AlohaSocial/social/commit/f89a329a63c4d370a59bec698a809b6c9f093d50))
- **timeline:** Subscribe to notify_push once for the page ([ec79cd89e](https://github.com/AlohaSocial/social/commit/ec79cd89e3afa26d24bd22d276d3ba021b80d197))
- **timeline:** Page on snowflake ids as strings ([d70a640aa](https://github.com/AlohaSocial/social/commit/d70a640aa92e38c532cf9bd3cd8a84d80f2ffc90))
- **db:** Stop the empty catches around a write from being silent ([9ecc53474](https://github.com/AlohaSocial/social/commit/9ecc53474a121b534517f680ab6326e83c26a453))
- **db:** Give every paged list of follows and actions a tiebreaker ([c3a213f76](https://github.com/AlohaSocial/social/commit/c3a213f76f7289e2d0e14fc0ae39b68507e97f89))
- **db:** Write a hashtag trend as an upsert ([3649e9594](https://github.com/AlohaSocial/social/commit/3649e9594017c9ff884ca0d4990c2499bb139ba5))
- **db:** Stop a content search matching the markup a post is stored in ([33174cf11](https://github.com/AlohaSocial/social/commit/33174cf11d664adbf6f36877db81db411d21e997))
- **timeline:** Read a meta attribute to the quote it opened with ([e3d73bb69](https://github.com/AlohaSocial/social/commit/e3d73bb69e8f0110652967a6a191a88e2bb5bbc8))
- **timeline:** Match a custom emoji shortcode whatever its case ([c8223f1c2](https://github.com/AlohaSocial/social/commit/c8223f1c227aa5f390eb4962d63e5448beb22a59))
- **api:** Do not let an anonymous caller make the instance fetch actors ([2cce7a6bf](https://github.com/AlohaSocial/social/commit/2cce7a6bf86e349298ac6e5296dfb5e42e918fad))
- **federation:** Sync a remote outbox with its audience and its mentions ([d81464b72](https://github.com/AlohaSocial/social/commit/d81464b72c1d473e5f803ab131399b02ebe45286))
- **api:** Keep the next-page link when the page dropped a repeated boost ([2230162fa](https://github.com/AlohaSocial/social/commit/2230162fa2b2cfbe753a495ac64b8543374728bd))
- **timeline:** Re-parse an edited post and keep it inside its audience ([aa6386736](https://github.com/AlohaSocial/social/commit/aa638673617bc7aac3a4c61f399ddc06f80c0585))
- **federation:** Record a remote edit in the status history ([40c01a11d](https://github.com/AlohaSocial/social/commit/40c01a11da482750501c6ac255db5074ed198ba8))
- **db:** Read a profile, a direct thread and the trends as they should be ([9e07a6352](https://github.com/AlohaSocial/social/commit/9e07a63527e30c030f08db0f69233a685d1fff14))
- **db:** Give a poll and an edited post the tag rows their text names ([ee1b99b3c](https://github.com/AlohaSocial/social/commit/ee1b99b3c861f1446cad1eba9012144b31ae9394))
- **timeline:** Look a conversation up as the viewer who asked for it ([c06c3b1b4](https://github.com/AlohaSocial/social/commit/c06c3b1b4057e348e22e501c72a0c1d31f55541c))
- **federation:** Recognise this instance by its social URL as well ([fad584d37](https://github.com/AlohaSocial/social/commit/fad584d371714391dca19f0390710451af77498e))
- **federation:** Stop dropping likes and follows without a word ([4ea1e5e06](https://github.com/AlohaSocial/social/commit/4ea1e5e06682ae3126072044a1c51193615b6d1c))
- **federation:** Stop stranding inbound queue items as running ([fea49dd25](https://github.com/AlohaSocial/social/commit/fea49dd2578a9f12bdf9dbdd8b511cdef38c5833))
- **federation:** Do not report a blocked delivery as delivered ([7b109c9b5](https://github.com/AlohaSocial/social/commit/7b109c9b5f7cf95b9cfdbfa948b8715b87727bd8))
- **federation:** Stop a skipped delivery from spinning and starving the queue ([75c40fccf](https://github.com/AlohaSocial/social/commit/75c40fccfc42a804b6f612bb5addb95bdf4e0c74))
- **federation:** Never undo another account's boost ([9098d61d8](https://github.com/AlohaSocial/social/commit/9098d61d897c53fd6e053be469595f09786b55ae))
- **federation:** Send local posts to the relays, not only boosts ([3444d1815](https://github.com/AlohaSocial/social/commit/3444d18152b385522d65b15862e89a80c97e2f90))
- **media:** Take the picture away with the story and the banner ([06b313e95](https://github.com/AlohaSocial/social/commit/06b313e9544d241831c973a07eca731ac8be791d))
- **media:** One way to take a stored document away ([d4f7395fd](https://github.com/AlohaSocial/social/commit/d4f7395fd2c3124a58192d26f5aec3a8645e4ebc))
- **federation:** Keep a post whose attachment could not be fetched ([5de8a6c2a](https://github.com/AlohaSocial/social/commit/5de8a6c2ae487cacb6ac4b3bc7149218da4ca83a))
- **api:** Never let stored media be sniffed, and download what is not media ([a61cb2a9a](https://github.com/AlohaSocial/social/commit/a61cb2a9aa49bfc4dab44ac7fec7915f19d31ec7))
- **media:** Fetch remote attachments through the upload funnel ([39f34a563](https://github.com/AlohaSocial/social/commit/39f34a563ef9e5fe83b441b61fd8e26a45f9d2b9))
- **media:** Drain ffmpeg's pipes while waiting for a poster ([8e2843c6e](https://github.com/AlohaSocial/social/commit/8e2843c6e891a17d09d9546c189d17b162deeec4))
- **moderation:** Stop serving a suspended local account ([814035f38](https://github.com/AlohaSocial/social/commit/814035f388bbdc1253f7274e499e8c35b764071c))
- **moderation:** Tell every moderator about a report, without a user walk ([b4b7789aa](https://github.com/AlohaSocial/social/commit/b4b7789aae495182cd2853c938cf13e6f303c416))
- **api:** Carry the expiry of a timed mute through an account export ([6dc9f2fd4](https://github.com/AlohaSocial/social/commit/6dc9f2fd46e5e0c8026f4150ebd907cfd89778b4))
- **federation:** Republish the profile of accounts whose handle is derived ([6246dfd10](https://github.com/AlohaSocial/social/commit/6246dfd10c36aca7eb8e223a173169ab2c91b54b))
- **moderation:** Purge an account from one list, and spare what others own ([0c228a8bd](https://github.com/AlohaSocial/social/commit/0c228a8bd2924a9ad75ef86a566bd5fe28a6cf04))
- **api:** Let a timed mute stop hiding notifications when it runs out ([eba133d97](https://github.com/AlohaSocial/social/commit/eba133d9794890955c2dabbcbb5adb7ff5c9eab4))
- **media:** Only restore archived attachments onto the importer's posts ([bd8a6ac5d](https://github.com/AlohaSocial/social/commit/bd8a6ac5d8a15767123a5fb082429d6c183a299f))
- **delivery:** Let the async self-call reach this instance's own address ([c9a1950d7](https://github.com/AlohaSocial/social/commit/c9a1950d7d15d16485d9633dc31e70d4b91aa8ac))
- **federation:** Apply the instance block list to every redirect hop ([67086ece4](https://github.com/AlohaSocial/social/commit/67086ece4b849475c5d709eaabf4bdc4a89af2b3))
- **federation:** Keep a webfinger answer from claiming another host's handle ([dee1b13e2](https://github.com/AlohaSocial/social/commit/dee1b13e293606643a6934f5a6f1a3f8c284148b))
- **federation:** Cache an actor only under the id it was asked for ([553a20b7e](https://github.com/AlohaSocial/social/commit/553a20b7e9b02509774e25bf594b3d400e1966a7))
- **federation:** Authorize the origin an activity finally carries ([d7e24324c](https://github.com/AlohaSocial/social/commit/d7e24324c4bd80f8f542864c7891ea610aea4fc8))
- **federation:** Ignore actor_info on the import path ([093402147](https://github.com/AlohaSocial/social/commit/0934021478efe4b9cd26a948ccaccff860e5882f))
- **federation:** Derive an actor's handle and refuse a key it does not own ([11317b6bf](https://github.com/AlohaSocial/social/commit/11317b6bfd3c66971976f5e963922efd904d4ab3))
- **federation:** Check which actor acts, not only which host ([1853bad0d](https://github.com/AlohaSocial/social/commit/1853bad0dc5c4c92a9103f95da4cd62cd6cbb04c))
- **frontend:** Drop the unused binding on the redirect-target catch ([90f6f194d](https://github.com/AlohaSocial/social/commit/90f6f194d361d7ab34fcaf37f08905b023969e90))
- **api:** Ask each route for the scope Mastodon documents for it ([e9189c4c4](https://github.com/AlohaSocial/social/commit/e9189c4c4ee6de7907326da0cc2e326ae1ae043a))
- **api:** Throttle the write routes for clients without a session ([66531185a](https://github.com/AlohaSocial/social/commit/66531185a4dc26f0a55d8084b0ab73ae434b5a0f))
- **oauth:** Grant tokens against the authorization, not the app row ([8be585f30](https://github.com/AlohaSocial/social/commit/8be585f3094021ce63d5a1bc959e9445bbb266af))
- **federation:** Make the NodeInfo 2.1 document reachable ([f3084d261](https://github.com/AlohaSocial/social/commit/f3084d26156e94efe3b75b2890abdbe4da32a19e))
- **admin:** Settle what the Sections card left failing in CI ([51d68937c](https://github.com/AlohaSocial/social/commit/51d68937c2b4eacb018d00e37360a433a400c5f3))
- **types:** Declare the avatar version the server puts on the page ([71720d458](https://github.com/AlohaSocial/social/commit/71720d45834e77d5ac1aa2d068d0d48a00a958b3))
- **frontend:** Show a newly uploaded avatar without waiting a day ([88da6c516](https://github.com/AlohaSocial/social/commit/88da6c51691ab2545edfb57e4010378d786bf38d))
- **composer:** The poll's three controls sit on one line ([0970b6780](https://github.com/AlohaSocial/social/commit/0970b678055d70951ddbb65a812dc4ba22cae500))
- **upgrade:** A PDF attached before 0.24 made the app impossible to upgrade ([a637412b7](https://github.com/AlohaSocial/social/commit/a637412b79b06d83b52ab80b29782e5069e64f74))
- **news:** The link timeline's url belongs in the params type ([b1e1fdd4d](https://github.com/AlohaSocial/social/commit/b1e1fdd4d14bf3f28d22851c6b4f3e61b15241ea))
- **api:** A status with no stored visibility told clients nothing ([716737422](https://github.com/AlohaSocial/social/commit/716737422bc493aee6aca3510de658e793473216))
- **photos:** A page called Photos asked whether a post had an attachment ([ae5ffcf2e](https://github.com/AlohaSocial/social/commit/ae5ffcf2e90d284fdddade535f73511e4db5be64))
- **discover:** A starter pack said "could not be reached: 2", and claimed to have followed nobody ([ae42d2b4a](https://github.com/AlohaSocial/social/commit/ae42d2b4aaf99784106da7a378e75656347ad0a6))
- **activities:** The badge could not clear, and the page never marked what was new ([ed7a96ed7](https://github.com/AlohaSocial/social/commit/ed7a96ed70a441862f68c52f26c148ebaab970ae))

### Performance

- **timeline:** Probe the viewer's follows instead of joining them ([460c0b180](https://github.com/AlohaSocial/social/commit/460c0b1807b2a50b35cd0ded07ef093b9a1477df))
- **stream:** Store a boost's cached post once and no copies in notifications ([47661f389](https://github.com/AlohaSocial/social/commit/47661f389007afc6ddcc5682f3f1cbe56a8ab41e))
- **migration:** Refresh the local actor caches a page at a time ([64a83abf6](https://github.com/AlohaSocial/social/commit/64a83abf6715bd789a83ae9e2750ff3ed8fe8a66))
- **account:** Rotate the key pairs a page of accounts at a time ([583dcab42](https://github.com/AlohaSocial/social/commit/583dcab42549e9c08786fef455fef6c87b85f44a))
- **search:** Choose the visible posts over the nid, then read them ([758c6d2a5](https://github.com/AlohaSocial/social/commit/758c6d2a5b6a0db7bcebbc496bd01907ee0ba1d8))
- **check:** Walk the follow and note repairs a page at a time ([15ad2b818](https://github.com/AlohaSocial/social/commit/15ad2b81897eaf2d96a60d9db68e9585c1f254e9))
- **moderation:** Match blocked and silenced instances on an author host column ([b59f8fe13](https://github.com/AlohaSocial/social/commit/b59f8fe1352da8ba41276253fae6d6f88093cfba))
- **media:** Find a transcoded video's posts through a link table ([fd56ade3e](https://github.com/AlohaSocial/social/commit/fd56ade3ec53312c2932873554d14014d950ecf7))
- **polls:** Find a poll's voters by an indexed poll key ([23115e0f6](https://github.com/AlohaSocial/social/commit/23115e0f60eb161b5a34fce87543ef05eb7fff53))
- **db:** Keep post counters in columns recounted in one statement ([eef00a6c9](https://github.com/AlohaSocial/social/commit/eef00a6c99a3f1f61d9c9e555f2a7767e1ecccc1))
- **search:** Look content searches up in a word index ([acdd31293](https://github.com/AlohaSocial/social/commit/acdd31293b56fa140b86d133847fbe4df046f6ef))
- **follows:** Walk follower lists after a cursor, not an offset ([3d352aca7](https://github.com/AlohaSocial/social/commit/3d352aca7a4b7120699bc5b7b431a7630d089149))
- **db:** Write a post's recipient rows in one statement ([a1633e60e](https://github.com/AlohaSocial/social/commit/a1633e60e6d2b80ef00c05182d77c6d1112d74c8))
- **db:** Index social_stream on (attributed_to_prim, nid) ([240e912e0](https://github.com/AlohaSocial/social/commit/240e912e0346dd8f73d6036607de83906cb7bf54))
- **db:** Compare For you candidate tags without LOWER() ([0ec99c180](https://github.com/AlohaSocial/social/commit/0ec99c18028ac0405757a94d1254e86ee0c827da))
- **api:** Sync a remote profile's outbox in the background, first page only ([7e0c9fc94](https://github.com/AlohaSocial/social/commit/7e0c9fc94fc47c182eb363035738d99f4e289d97))
- **api:** Answer remote followers/following from the cache and queue the rest ([66c0c5414](https://github.com/AlohaSocial/social/commit/66c0c54147020c049a031d963fdce05412356259))
- **timeline:** Name tagged people from the cache and fetch misses in the background ([60ba86dcc](https://github.com/AlohaSocial/social/commit/60ba86dcc35eb42086c78055aaf3f86d5460f5f8))
- **federation:** Send an account deletion only to servers heard from lately ([6bdddd6b1](https://github.com/AlohaSocial/social/commit/6bdddd6b1fc2f17b8060471a423171f3a40a85d8))
- **federation:** Give reaching a peer its own short connect timeout ([024dfc537](https://github.com/AlohaSocial/social/commit/024dfc53768d3627035b63976020d8e3c9541caa))
- **queue:** Route inbound stream-queue fetches through the host breaker ([57699766d](https://github.com/AlohaSocial/social/commit/57699766ddcd42c120db23ea579e3193f1baec9a))
- **queue:** Drop the signed body of a delivered request ([f90916b30](https://github.com/AlohaSocial/social/commit/f90916b30e85b89fc225c642bf3e66190635a2d8))
- **index:** Make the side-index repair a one-shot walk that ends ([37f43e260](https://github.com/AlohaSocial/social/commit/37f43e260afc140d9ef473ab8e0fda336ad605f6))
- **cron:** Give the inbound stream queue a budget of its own ([21341b37c](https://github.com/AlohaSocial/social/commit/21341b37ce93d855c1ef78e66a1b49f47ced91f8))
- **atproto:** Cache viewer record scans per request ([266641f52](https://github.com/AlohaSocial/social/commit/266641f52a88d18041eedf3257324f7525df1e43))
- **timeline:** Drop the entity decoder and lazy-load the pictures ([38e9918d8](https://github.com/AlohaSocial/social/commit/38e9918d8149a8dfa7e6dfd08394555167d810b3))
- **cache:** Sync the outbox of followed remote actors only ([d562f788e](https://github.com/AlohaSocial/social/commit/d562f788ee60e99467ed7006dd56fc171b5e4e9a))
- **profile:** Load the post renderer lazily on the profile page ([273cd7cb9](https://github.com/AlohaSocial/social/commit/273cd7cb9930ab21b0bbc5a8c933beec97d40d51))
- **frontend:** Stop sending the statistics engine to everyone who opens a profile ([5d80d9adf](https://github.com/AlohaSocial/social/commit/5d80d9adf95b54ce9e3c1d563856d818d7b8ca09))
- **media:** Let a browser keep a public picture for a day ([d1b260bcd](https://github.com/AlohaSocial/social/commit/d1b260bcd6e9f11e108ed451a7423dcf5e0278ce))
- **federation:** Ask the follow graph's twenty servers at once ([f0e23703d](https://github.com/AlohaSocial/social/commit/f0e23703d798953a5064ab4b55b3ce0f33589ccd))
- **db:** Group the known servers on a column instead of reading every row ([7c2e13333](https://github.com/AlohaSocial/social/commit/7c2e133334877003d098f1d5c6b69856cf8ea399))
- **timeline:** Bound the collection list a home timeline is read with ([369421fdf](https://github.com/AlohaSocial/social/commit/369421fdf42d1bad2946dbd79205f5add6c4e72e))
- **db:** Read a hashtag's counts by key rather than by search ([e50ce0e8b](https://github.com/AlohaSocial/social/commit/e50ce0e8b1a6225063e64892e1fb6fd19b3c6672))
- **federation:** Write a post's fan-out in batches, not one row at a time ([fab429e1d](https://github.com/AlohaSocial/social/commit/fab429e1d6d16832caeacdd5e2b8bf262595adf2))

### Documentation

- **api:** Write down which credentials a route takes ([e8d0a44cb](https://github.com/AlohaSocial/social/commit/e8d0a44cb62233a1c8927ab8788e2d1fd9a29e6f))
- Add AGENTS.md for AI coding agents ([db2d3f12d](https://github.com/AlohaSocial/social/commit/db2d3f12d6e421ee67cd2b38345ac024762a5120))
- **readme:** What the browser tests cover and what they need ([6d062963f](https://github.com/AlohaSocial/social/commit/6d062963f1042d9b5d04cdbc4849d1f802280375))
- **atproto:** A live-host test for Bluesky, with a probe from outside ([88129cb65](https://github.com/AlohaSocial/social/commit/88129cb65792150eccae197fe92de35c2551853a))
- **atproto:** Profile pictures from a Bluesky app ([0d559fce6](https://github.com/AlohaSocial/social/commit/0d559fce6b69f5ee409ca7731ee8f33bde01ac74))
- **atproto:** Link cards carry the page's picture ([575bb2c64](https://github.com/AlohaSocial/social/commit/575bb2c640736da8942e24a6778bcf7c544fa7d8))
- The specification says what was built ([a95d2da64](https://github.com/AlohaSocial/social/commit/a95d2da64adc238aa0b2dcfbf6651be493c55db1))
- Who may reach you, requests and muting a conversation ([15271e516](https://github.com/AlohaSocial/social/commit/15271e5168ad9cfb4e631f5bbafa04c88741146c))
- Who may reach you, held senders and muted conversations on the server ([dd9fc0cf5](https://github.com/AlohaSocial/social/commit/dd9fc0cf5416082633e668a530ed92ca1d0ad736))
- Specify who may reach you and muting a conversation ([473d72db8](https://github.com/AlohaSocial/social/commit/473d72db8de00ac5732ad520bfd62d2151e9752d))
- **api:** The Sections route takes three switches, not four ([4533d0776](https://github.com/AlohaSocial/social/commit/4533d0776fb3070809ad232a02db0bce26da758c))
- **shorts:** One feature, two lifetimes — stories are 24-hour shorts ([8482fd127](https://github.com/AlohaSocial/social/commit/8482fd1276b732ff7562392dbbba18fec0124193))
- **interop:** What the inbound and client-API runs found ([70e558981](https://github.com/AlohaSocial/social/commit/70e55898148e5a55a0b43ce70a6ba5667e780088))
- **atproto:** The specification for native Bluesky identities ([58c41b1ef](https://github.com/AlohaSocial/social/commit/58c41b1efea0204aa13f9763cc8d32f51d05656d))
- **atproto:** Document broken-session action responses ([d8136a16c](https://github.com/AlohaSocial/social/commit/d8136a16c6ef9b7a6bef05a4fe3b8bab9a124816))
- **atproto:** Document native reply references ([f43248bef](https://github.com/AlohaSocial/social/commit/f43248bef1a41ed421132e558481893e01df89fb))
- **atproto:** Describe native profile follow state ([9c9cae2f9](https://github.com/AlohaSocial/social/commit/9c9cae2f9c0926eaf6a514a1a418c7b3037704a3))
- **atproto:** Document profile editing route ([e21e3c544](https://github.com/AlohaSocial/social/commit/e21e3c544bc88b835d569d48ca36a2852da6c97a))
- **atproto:** Document account routes ([507852472](https://github.com/AlohaSocial/social/commit/507852472a483deb26d459e37f0b56330415e12f))
- Moving to Aloha Social and away, in the order it happens ([cc95bb3aa](https://github.com/AlohaSocial/social/commit/cc95bb3aa9ba5ba18759a3a4a1a1cf8886b3154f))
- **api:** List every registered route in the table ([8e572224c](https://github.com/AlohaSocial/social/commit/8e572224ce0fbe0cbb44661cd20e696db16803ff))
- Finish the Aloha Social rename in API.md and the systemd unit ([fc9bf70a0](https://github.com/AlohaSocial/social/commit/fc9bf70a03dd84a54d9e3b8213ca7ae7da95b5d5))
- **tests:** List the new request tests in the integration README ([adf039387](https://github.com/AlohaSocial/social/commit/adf03938706e99b2ceb373c85506bf797746b06a))
- **api:** Describe the client API as the controllers it now is ([025a5f351](https://github.com/AlohaSocial/social/commit/025a5f35115c797b7cf7ddeae3a733918de3fdb3))
- **interests:** My interests in the README, the guides and its own spec ([1fe497969](https://github.com/AlohaSocial/social/commit/1fe497969a7b65a6824cc844e3ce045012035ab3))
- The My Interests specification ([1377b4a06](https://github.com/AlohaSocial/social/commit/1377b4a06ca91b3714c7c3a8ccd7e50cf4bf3500))
- **admin:** Say what the three stream-rewriting upgrade steps cost ([a51bd6cb6](https://github.com/AlohaSocial/social/commit/a51bd6cb641240a994ad57ab9197e0733fc486b6))
- The network section, and the app value that turns it off ([ba4967ab3](https://github.com/AlohaSocial/social/commit/ba4967ab32ac190a8c3b7e612401d2a56a36b654))
- **api:** Say what uri and url mean, and which policy is read ([75e85a068](https://github.com/AlohaSocial/social/commit/75e85a068d9c72e3c5c2ce1daa40586ef36dfd70))

## 0.6.1

- fix missing avatar on notifications
- fix missing visibility on remote post
- fix random reset of account display name 
- avoid race condition on stream action
- fix stream details update
- fix action on stream unreblog


## 0.6.0

- full rewrite of the API


## 0.4.2

- using IClient, to use local nextcloud network configuration.
- rework on the API.
- some compat with ActivityPub,
- and small bugfixes.


## 0.4.1

- fixing an issue with primary key.


## 0.4.0 (alpha 3.2)

- [global] compat nc20


## 0.3.1 (alpha 3.1)

- [setup] notification during update.
- [setup] fixing some requests. 


## 0.3.0 (alpha 3.0)

- [setup] fully indexed database structure.
- [global] fixing issue with hashtags.
- [global] compat nc19.


## 0.2.101

- [setup] fixing an issue with migration on pgsql


## 0.2.100 (alpha 2.1)

- [setup] it is now possible to create an account using ./occ
- [setup] it is now possible to follow an account using ./occ
- [setup] new command to completely uninstall the app: ./occ social:reset --uninstall 
- [federated] better management of the ID generator.
- [federated] Caching now generate also a thumb version of remote Image document.
- [federated] fixing an issue with hashtags importation
- [global] better parsing on non-latin hashtags
- [global] Attachments (Image) should now be displayed.
- [global] like/unlike on Post.
- [global] new Timeline: Liked post.
- [global] better compatibility with Pleroma.
- [global] fixing an issue on exact result during search. 
- [global] cleaning code.



## 0.2.6

- [global] Improving and fixing streams.


## 0.2.5

- [setup] Fixing migrations (again)


## 0.2.4

- [setup] Rewrite of the announce system
- [setup] Fixing migrations
- [global] Fixing caching from 3rd party instance 
- [global] Fixing signature check
- [global] Fixing local and federated timeline
- [global] More debug logging
- [federated] Managing local and remote host-meta


## 0.2.3

- [global] reverting nextcloud to 0.9.x
- [global] reverting routes


## 0.2.2

- [setup] fixing an issue with empty boolean field during postgresql migration.
- [setup] enlarging some database field.


## 0.2.1

- [setup] fixing an issue with empty creation field during migration.


## 0.2.0 - alpha2


features:

- [global] Boosting Post.
- [global] Delete a Post.
- [UI] Following an account from an external website.
- [federated] Async on incoming request.
- [federated] Caching on incoming request.
- [federated] Caching incoming attachments.
- [federated] limit rights and access to/from the fediverse.


enhancements:

- [global] Complete SQL migration.
- [global] Timeline can now manage multiple type of Stream object.
- [global] More logs.
- [UI] Dark theme.
- [UI] Searching now send only limited request.
- [federated] Caching context content.
- [federated] Outgoing request accepts redirection.
- [federated] Removing an actor should deletes his posts.
- [setup] The app can now works on local address, with no SSL support.
- [setup] The app can be installed in custom apps folder.


bugfixes:

- [bugfix] public post counter now count only public post.



## 0.1.3

- [global] fixing an issue displaying timeline when opening details for a local account.
- [global] provide more details in logs on async failure.
- [global] Cache refreshing on some events (new post, new follow)
- [federated] adding security @context on returned Actor.
- [federated] signature can be 5min old (instead of 30s) to avoid issue on badly configured instance of Nextcloud.
- [federated] webfinger will also check the host of the account.
- [UI] do not clear post field on fail post creation. 


## 0.1.2

- [global] Fix Host Header on proxy setup
- [global] Delete object by objectId, not just by its Type+Id
- [global] Remove the ending slash from Id on webfinger
- [global] rewrite the exception handling during upstream request
- [global] Blind key rotation
- [request] Manage Update/Person and use signature date as creation on Person creation/update
- [request] check the status of queued request before forking process
- [request] fixing Accept header for Diaspora
- [UI] Improve account data handling
- [UI] Fix follow button on profile pages
- [UI] Add profile page for remote users
- [UI] Fix feedback on following the Nextcloud mastodon account


## 0.1.1

- [setup] the app is now displaying an issue with the setup of the httpd only if there is issue.
- [setup] fixing 'not-big-enough' fields in database.
- [setup] it is now possible, using ./occ social:reset, to reset all data from the app and change the base url of the cloud.
- [global] rework on some SQL request
- [global] complete rework on the ActivityPub Parser and ActivityPub Generator.
- [global] The app will now sign every ld-json object. 
- [global] The app will now verify every signed ld-json object. This should fix forwarded Note not being displayed in the streams.
- [global] Unfollowing a user will keep remote instance up-to-date.
- [UI] Some strange behavior while typing message have been fixed.
- [UI] Better display of the Public/Account page.
- [UI] Better UX when using the searchbar to search for a remote account. 
- [bugfix] Home Stream is working even when user is following no one.
- [bugfix] fixing quotes encoding.
- [bugfix] the cache refresher will now avoid ghost account.
- [bugfix] fixing minor issues.



## 0.1.0

- first alpha release

