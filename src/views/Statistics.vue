<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="stats">
		<h2>{{ t('social', 'Statistics') }}</h2>
		<p class="stats__hint">
			{{ t('social', 'What you have posted here, and what came back. Counted from this server when you opened the page, so it is never a stale number.') }}
		</p>

		<!-- the window these numbers were counted over, and what to do with
		     them once they are on screen -->
		<div class="stats__toolbar">
			<!-- the same switcher as the timelines, Discover and a profile's
			     tabs: the window is a choice this page holds, so no option
			     carries a route and the pick comes back as update:value -->
			<TimelineSwitcher
				class="stats__windows"
				:options="windowChoices"
				:value="String(days)"
				:label="t('social', 'Counted over')"
				@update:value="pick(Number($event))" />
			<div class="stats__actions">
				<NcButton
					variant="tertiary"
					:disabled="loading"
					:title="t('social', 'Count these again now')"
					:aria-label="t('social', 'Count these again now')"
					@click="load(true)">
					<template #icon>
						<IconRefresh :size="20" />
					</template>
				</NcButton>
				<NcButton variant="tertiary" :href="exportUrl" :download="exportName">
					<template #icon>
						<IconDownload :size="20" />
					</template>
					{{ t('social', 'Download') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="stats__loading" :size="44" />

		<div v-else-if="error" class="stats__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="stats">
			<!-- who -->
			<section class="stats__card stats__hero">
				<div class="stats__hero-who">
					<NcAvatar
						:user="uid"
						:displayName="displayName"
						:size="64"
						:disableMenu="true"
						:disableTooltip="true" />
					<div class="stats__hero-names">
						<p class="stats__eyebrow">
							{{ t('social', 'Analysed account') }}
						</p>
						<h3 class="stats__hero-name">
							{{ displayName }}
						</h3>
						<p class="stats__hero-handle">
							{{ '@' + stats.account.acct }}
						</p>
					</div>
					<p class="stats__hero-followers">
						<strong>{{ number(stats.account.followers) }}</strong>
						<span>{{ t('social', 'current followers') }}</span>
					</p>
				</div>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.posts.total) }}</strong>
						<span>{{ n('social', 'post', 'posts', stats.posts.total) }}</span>
					</li>
					<li>
						<strong>{{ number(stats.account.following) }}</strong>
						<span>{{ t('social', 'following') }}</span>
					</li>
				</ul>
				<p v-if="joined" class="stats__note">
					{{ joined }}
				</p>
				<div class="stats__coverage">
					<span class="stats__eyebrow">{{ t('social', 'Posts analysed') }}</span>
					<span class="stats__coverage-track">
						<span
							class="stats__coverage-fill"
							:class="{ 'stats__coverage-fill--capped': coverage.capped }" />
					</span>
					<span class="stats__coverage-count">{{ coverage.label }}</span>
				</div>
			</section>

			<!-- the two windows, side by side -->
			<section v-if="periods" class="stats__card stats__periods">
				<div class="stats__periods-head">
					<div>
						<p class="stats__eyebrow">
							{{ t('social', 'Posting insights') }}
						</p>
						<h3 class="stats__periods-title">
							{{ n('social', '{days} day. Directly comparable.', '{days} days. Directly comparable.', periods.days, { days: periods.days }) }}
						</h3>
					</div>
					<dl class="stats__legend">
						<div>
							<dt><span class="stats__legend-key stats__legend-key--current" />{{ t('social', 'Now') }}</dt>
							<dd>{{ range(periods.current) }}</dd>
						</div>
						<div>
							<dt><span class="stats__legend-key stats__legend-key--previous" />{{ t('social', 'Before') }}</dt>
							<dd>{{ range(periods.previous) }}</dd>
						</div>
					</dl>
				</div>
				<p class="stats__note">
					{{ t('social', 'Both windows are the same length, so the pair is worth reading. A post counts on the day it went out, together with everything it has collected since.') }}
				</p>
				<ul class="stats__kpis">
					<li v-for="card in kpis" :key="card.key" class="stats__kpi">
						<p class="stats__kpi-head">
							<span class="stats__kpi-label">{{ card.label }}</span>
							<span class="stats__kpi-index">{{ card.index }}</span>
						</p>
						<p class="stats__eyebrow">
							{{ card.hint }}
						</p>
						<p class="stats__kpi-value">
							<strong>{{ number(card.value) }}</strong>
							<span class="stats__delta" :class="card.deltaClass">{{ card.delta }}</span>
						</p>
						<svg
							class="stats__spark"
							viewBox="0 0 100 32"
							preserveAspectRatio="none"
							role="img"
							:aria-label="card.description">
							<polyline
								class="stats__spark-line stats__spark-line--previous"
								:points="card.previousPoints"
								vector-effect="non-scaling-stroke" />
							<polyline
								class="stats__spark-line stats__spark-line--current"
								:points="card.currentPoints"
								vector-effect="non-scaling-stroke" />
						</svg>
						<p class="stats__kpi-foot">
							<span>{{ t('social', 'Previous total') }}</span>
							<span>{{ number(card.previousValue) }}</span>
						</p>
					</li>
				</ul>
				<p class="stats__note">
					{{ reachNote }}
				</p>
			</section>

			<!-- every post of the window, one by one -->
			<section v-if="timeline.length" class="stats__card">
				<div class="stats__periods-head">
					<div>
						<p class="stats__eyebrow">
							{{ t('social', 'Post breakdown') }}
						</p>
						<h3 class="stats__periods-title">
							{{ t('social', 'Individual posts') }}
						</h3>
					</div>
					<label class="stats__sort">
						{{ t('social', 'Sort by') }}
						<select v-model="sort">
							<option value="date">{{ t('social', 'Date') }}</option>
							<option value="reach">{{ t('social', 'Estimated reach') }}</option>
							<option value="engagement">{{ t('social', 'Engagement') }}</option>
						</select>
					</label>
				</div>
				<ol class="stats__posts">
					<li v-for="post in timeline" :key="post.id" class="stats__post">
						<router-link
							class="stats__post-text"
							:to="{ name: 'single-post', params: { account: stats.account.acct, id: post.id } }">
							<span class="stats__post-date">{{ posted(post) }}</span>
							<span>{{ post.excerpt || t('social', '(no text)') }}</span>
						</router-link>
						<div class="stats__post-figures">
							<p class="stats__post-reach">
								<span class="stats__eyebrow">{{ t('social', 'Estimated reach') }}</span>
								<strong>{{ number(post.reach) }}</strong>
								<span class="stats__post-track">
									<span class="stats__post-fill" :style="{ '--share': post.share }" />
								</span>
							</p>
							<p class="stats__post-counts">
								<span><IconHeart :size="14" /> {{ number(post.likes) }}</span>
								<span><IconRepeat :size="14" /> {{ number(post.boosts) }}</span>
								<span><IconReply :size="14" /> {{ number(post.replies) }}</span>
							</p>
						</div>
					</li>
				</ol>
				<p class="stats__note">
					{{ listNote }}
				</p>
			</section>

			<!-- how the posts are doing -->
			<section class="stats__card">
				<h3>
					<IconHeart :size="20" />
					{{ t('social', 'How your posts are doing') }}
				</h3>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.engagement.likes) }}</strong>
						<span>{{ t('social', 'likes received') }}</span>
						<em>{{ t('social', '{n} per post', { n: decimal(stats.engagement.likes_per_post) }) }}</em>
					</li>
					<li>
						<strong>{{ number(stats.engagement.boosts) }}</strong>
						<span>{{ t('social', 'boosts received') }}</span>
						<em>{{ t('social', '{n} per post', { n: decimal(stats.engagement.boosts_per_post) }) }}</em>
					</li>
					<li>
						<strong>{{ number(stats.engagement.replies) }}</strong>
						<span>{{ t('social', 'replies received') }}</span>
						<em>{{ t('social', '{n} per post', { n: decimal(stats.engagement.replies_per_post) }) }}</em>
					</li>
				</ul>
				<ul v-if="stats.rates" class="stats__figures stats__figures--small">
					<li>
						<strong>{{ decimal(stats.rates.per_post) }}</strong>
						<span>{{ t('social', 'engagement per post') }}</span>
						<em>{{ t('social', 'median {n}', { n: decimal(stats.rates.median) }) }}</em>
					</li>
					<li>
						<strong>{{ decimal(stats.rates.per_follower) }}%</strong>
						<span>{{ t('social', 'engagement rate') }}</span>
						<em>{{ t('social', 'against your followers') }}</em>
					</li>
					<li>
						<strong>{{ number(stats.rates.best) }}</strong>
						<span>{{ t('social', 'best post') }}</span>
						<em>{{ t('social', '{n}× the median', { n: decimal(viralMultiple) }) }}</em>
					</li>
					<li>
						<strong>{{ decimal(stats.rates.silent_share) }}%</strong>
						<span>{{ t('social', 'got no answer') }}</span>
						<em>{{ n('social', '%n post', '%n posts', stats.rates.silent) }}</em>
					</li>
				</ul>
				<p class="stats__note">
					{{ t('social', 'A floor rather than a total: a like on a server that never told this one about it cannot be counted anywhere. There are no impressions to divide by either, so the rate is against your follower count.') }}
				</p>
			</section>

			<!-- what works -->
			<section v-if="content.length" class="stats__card">
				<h3>
					<IconTarget :size="20" />
					{{ t('social', 'What works') }}
				</h3>
				<p class="stats__note">
					{{ t('social', 'Average engagement per post, by what the post was.') }}
				</p>
				<dl class="stats__rows">
					<div v-for="row in content" :key="row.key" class="stats__row">
						<dt>{{ row.label }}</dt>
						<dd>
							<span class="stats__meter" :style="{ '--share': row.share }" />
							<span class="stats__row-value">{{ decimal(row.average) }}</span>
							<span class="stats__row-note">{{ n('social', '%n post', '%n posts', row.posts) }}</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- when they do best -->
			<section v-if="weekdays.length" class="stats__card">
				<h3>
					<IconClock :size="20" />
					{{ t('social', 'When your posts do best') }}
				</h3>
				<p v-if="bestHour" class="stats__lead">
					{{ bestHour }}
				</p>
				<p v-else class="stats__note">
					{{ t('social', 'Not enough posts at any one hour to say yet — it takes three in the same hour before this is more than luck.') }}
				</p>
				<dl class="stats__rows">
					<div v-for="row in weekdays" :key="row.day" class="stats__row">
						<dt>{{ row.label }}</dt>
						<dd>
							<span class="stats__meter" :style="{ '--share': row.share }" />
							<span class="stats__row-value">{{ decimal(row.average) }}</span>
							<span class="stats__row-note">{{ n('social', '%n post', '%n posts', row.posts) }}</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- the best of them -->
			<section v-if="stats.best.length" class="stats__card">
				<h3>
					<IconTrophy :size="20" />
					{{ t('social', 'Your best posts') }}
				</h3>
				<ol class="stats__best">
					<li v-for="post in stats.best" :key="post.id">
						<router-link :to="{ name: 'single-post', params: { account: stats.account.acct, id: post.id } }">
							<span class="stats__best-text">{{ post.excerpt || t('social', '(no text)') }}</span>
							<span class="stats__best-counts">
								<span><IconHeart :size="14" /> {{ number(post.likes) }}</span>
								<span><IconRepeat :size="14" /> {{ number(post.boosts) }}</span>
								<span><IconReply :size="14" /> {{ number(post.replies) }}</span>
							</span>
						</router-link>
					</li>
				</ol>
			</section>

			<!-- when -->
			<section class="stats__card">
				<h3>
					<IconCalendar :size="20" />
					{{ t('social', 'When you post') }}
				</h3>
				<h4>{{ t('social', 'Over the last twelve months') }}</h4>
				<ul class="stats__bars stats__bars--months stats__bars--posts">
					<li v-for="month in months" :key="month.key" :title="monthTitle(month)">
						<span class="stats__bar" :style="{ '--height': month.height }" />
						<span class="stats__bar-label">{{ month.label }}</span>
					</li>
				</ul>
				<h4 v-if="engagementMonths.length">
					{{ t('social', 'Engagement over the same months') }}
				</h4>
				<ul class="stats__bars stats__bars--months stats__bars--engagement">
					<li v-for="month in engagementMonths" :key="month.key" :title="engagementTitle(month)">
						<span class="stats__bar stats__bar--alt" :style="{ '--height': month.height }" />
						<span class="stats__bar-label">{{ month.label }}</span>
					</li>
				</ul>
				<h4>{{ t('social', 'By hour of the day (UTC)') }}</h4>
				<ul class="stats__bars stats__bars--hours">
					<li v-for="hour in hours" :key="hour.key" :title="hourTitle(hour)">
						<span class="stats__bar" :style="{ '--height': hour.height }" />
						<span class="stats__bar-label">{{ hour.label }}</span>
					</li>
				</ul>
			</section>

			<!-- what -->
			<section class="stats__card">
				<h3>
					<IconShape :size="20" />
					{{ t('social', 'What you post') }}
				</h3>
				<dl class="stats__rows">
					<div v-for="row in composition" :key="row.key" class="stats__row">
						<dt>{{ row.label }}</dt>
						<dd>
							<span class="stats__meter" :style="{ '--share': row.share }" />
							<span class="stats__row-value">{{ number(row.count) }}</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- hashtags -->
			<section v-if="stats.hashtags.length" class="stats__card">
				<h3>
					<IconPound :size="20" />
					{{ t('social', 'What you write about') }}
				</h3>
				<ul class="stats__tags">
					<li v-for="tag in stats.hashtags" :key="tag.name">
						<router-link :to="{ name: 'tags', params: { tag: tag.name } }">
							#{{ tag.name }}
							<span class="stats__tag-count">{{ number(tag.count) }}</span>
						</router-link>
					</li>
				</ul>
				<template v-if="hashtagPerformance.length">
					<h4>{{ t('social', 'Which of them works') }}</h4>
					<dl class="stats__rows">
						<div v-for="tag in hashtagPerformance" :key="tag.name" class="stats__row">
							<dt>#{{ tag.name }}</dt>
							<dd>
								<span class="stats__meter" :style="{ '--share': tag.share }" />
								<span class="stats__row-value">{{ decimal(tag.average) }}</span>
								<span class="stats__row-note">{{ n('social', '%n post', '%n posts', tag.posts) }}</span>
							</dd>
						</div>
					</dl>
					<p class="stats__note">
						{{ t('social', 'Average engagement of a post carrying the tag. Tags used once are left out: one post that did well is a post that did well.') }}
					</p>
				</template>
			</section>

			<!-- who is listening -->
			<section v-if="stats.audience" class="stats__card">
				<h3>
					<IconAccountGroup :size="20" />
					{{ t('social', 'Who is listening') }}
				</h3>
				<h4>{{ t('social', 'Followers gained, by month') }}</h4>
				<ul class="stats__bars stats__bars--months stats__bars--followers">
					<li v-for="month in followerMonths" :key="month.key" :title="followerTitle(month)">
						<span class="stats__bar stats__bar--alt" :style="{ '--height': month.height }" />
						<span class="stats__bar-label">{{ month.label }}</span>
					</li>
				</ul>
				<template v-if="instances.length">
					<h4>{{ t('social', 'Where they are') }}</h4>
					<dl class="stats__rows">
						<div v-for="instance in instances" :key="instance.host" class="stats__row">
							<dt>{{ instance.host }}</dt>
							<dd>
								<span class="stats__meter" :style="{ '--share': instance.share }" />
								<span class="stats__row-value">{{ number(instance.count) }}</span>
							</dd>
						</div>
					</dl>
					<p class="stats__note">
						{{ t('social', '{n}% of your followers are on this server.', { n: decimal(stats.audience.local_share) }) }}
					</p>
				</template>
			</section>

			<!--
				How big the place is that all of the above went out to. Every
				other number here is counted from this server's own rows, which
				is what makes them trustworthy and also what makes them small: a
				reader with four followers cannot tell from them whether they
				are posting into a village or a city.
			-->
			<section v-if="stats.network" class="stats__card stats__network">
				<h3>{{ t('social', 'The network you are posting into') }}</h3>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.network.peers) }}</strong>
						<span>{{ t('social', 'servers this one talks to') }}</span>
					</li>
					<li>
						<strong>{{ number(stats.network.servers) }}</strong>
						<span>{{ t('social', 'servers in the fediverse') }}</span>
					</li>
					<li>
						<strong>{{ number(stats.network.accounts) }}</strong>
						<span>{{ t('social', 'accounts') }}</span>
					</li>
					<li>
						<strong>{{ number(stats.network.active) }}</strong>
						<span>{{ t('social', 'posted in the last month') }}</span>
					</li>
				</ul>
				<!-- quoted, not claimed: a figure about the whole fediverse is
				     somebody's survey, and the page says whose and when -->
				<p class="stats__note">
					{{ networkNote }}
					<a :href="stats.network.source_url" target="_blank" rel="noreferrer noopener">
						{{ stats.network.source }} ↗
					</a>
				</p>
			</section>

			<!--
				What it is made of. "Forty thousand servers" is an abstraction;
				the list of platforms is a picture of a place — and for
				somebody reading this from inside a Nextcloud, that the network
				is many kinds of software talking to each other is the whole
				point of it.
			-->
			<section v-if="platforms.length" class="stats__card stats__platforms">
				<h3>{{ t('social', 'What the fediverse runs on') }}</h3>

				<div
					class="stats__composition"
					role="img"
					:aria-label="compositionDescription">
					<span
						v-for="platform in platforms"
						:key="platform.key"
						class="stats__composition-band"
						:class="{ 'stats__composition-band--rest': platform.rest }"
						:style="{ width: platform.width, ...platform.colour }"
						:title="platform.title" />
				</div>

				<ul class="stats__platform-list">
					<li v-for="platform in platforms" :key="platform.key">
						<span class="stats__platform-swatch" :style="platform.colour" aria-hidden="true" />
						<span class="stats__platform-name">{{ platform.name }}</span>
						<span class="stats__platform-share">{{ platform.shareLabel }}</span>
						<span class="stats__platform-detail">{{ platform.detail }}</span>
					</li>
				</ul>

				<p class="stats__note">
					{{ platformsNote }}
					<a :href="stats.software.source_url" target="_blank" rel="noreferrer noopener">
						{{ stats.software.source }} ↗
					</a>
				</p>
			</section>

			<!--
				And whether that network is growing, which is the question the
				figures above cannot answer: a reader deciding whether to write
				here wants to know if this is somewhere more people are
				arriving or somewhere they are leaving, and no amount of
				precision about today says.
			-->
			<section v-if="growth" class="stats__card stats__growth">
				<h3>{{ t('social', 'How the fediverse has grown') }}</h3>

				<ul class="stats__figures stats__figures--change">
					<li v-for="change in growthChanges" :key="change.key">
						<strong :class="change.className">{{ change.month }}</strong>
						<span>{{ change.label }}</span>
						<span class="stats__growth-year">{{ change.year }}</span>
					</li>
				</ul>

				<figure class="stats__growth-chart">
					<figcaption>
						{{ t('social', 'Accounts, month by month') }}
						<span class="stats__growth-latest">{{ growthLatest }}</span>
					</figcaption>
					<svg
						class="stats__area"
						viewBox="0 0 100 40"
						preserveAspectRatio="none"
						role="img"
						:aria-label="growthDescription">
						<defs>
							<linearGradient
								:id="gradientId"
								x1="0"
								y1="0"
								x2="0"
								y2="1">
								<stop offset="0%" stop-color="var(--color-primary-element)" stop-opacity=".35" />
								<stop offset="100%" stop-color="var(--color-primary-element)" stop-opacity="0" />
							</linearGradient>
						</defs>
						<!-- the years behind it, so a rise has something to be
						     a rise against -->
						<line
							v-for="tick in growthTicks"
							:key="tick.x"
							class="stats__area-tick"
							:x1="tick.x"
							:x2="tick.x"
							y1="0"
							y2="40"
							vector-effect="non-scaling-stroke" />
						<polygon class="stats__area-fill" :points="growthArea" :fill="`url(#${gradientId})`" />
						<polyline
							class="stats__area-line"
							:points="growthPoints"
							vector-effect="non-scaling-stroke" />
					</svg>
					<p class="stats__growth-span">
						<span>{{ growthFrom }}</span>
						<span
							v-for="tick in growthTicks"
							:key="tick.x"
							class="stats__growth-tick"
							:style="{ left: tick.x + '%' }">
							{{ tick.label }}
						</span>
						<span>{{ growthTo }}</span>
					</p>
				</figure>

				<!-- the numbers nobody has to work out for themselves: what
				     they are actually looking at, in sentences -->
				<ul class="stats__derived">
					<li v-for="fact in growthFacts" :key="fact.key">
						<strong>{{ fact.value }}</strong>
						<span>{{ fact.label }}</span>
					</li>
				</ul>

				<!-- a step in this series is usually the crawler reaching more
				     servers, and a reader who is not told that reads it as the
				     network doubling -->
				<p v-if="growth.coverage_changed" class="stats__note">
					{{ t('social', 'A step in this line is usually the survey reaching servers it had not reached before, rather than the network changing size — so no year-on-year figure is shown for a year that has one in it.') }}
				</p>

				<!-- a second source, named as such: the figures above publish
				     no history, and the two do not agree about the totals -->
				<p class="stats__note">
					{{ growthNote }}
					<a :href="growth.source_url" target="_blank" rel="noreferrer noopener">
						{{ growth.source }} ↗
					</a>
				</p>
			</section>

			<!-- what the account did, rather than what came back -->
			<section v-if="activity.length" class="stats__card">
				<h3>
					<IconPencil :size="20" />
					{{ t('social', 'What you did') }}
				</h3>
				<p class="stats__note">
					{{ t('social', 'Everything above counts what came back to you. This counts what you did: a quiet month here is a month you did not post, not a month nobody answered.') }}
				</p>
				<ul class="stats__stack">
					<li v-for="month in activity" :key="month.key">
						<span class="stats__stack-bars" :title="month.title">
							<span
								v-for="part in month.parts"
								:key="part.key"
								class="stats__stack-part"
								:class="'stats__stack-part--' + part.key"
								:style="{ height: part.height + '%' }" />
						</span>
						<span class="stats__stack-label">{{ month.label }}</span>
					</li>
				</ul>
				<ul class="stats__legend">
					<li v-for="part in activityLegend" :key="part.key">
						<span class="stats__swatch" :class="'stats__stack-part--' + part.key" />
						{{ part.label }}
					</li>
				</ul>
			</section>

			<!-- how steadily -->
			<section v-if="stats.consistency && stats.consistency.span_days > 0" class="stats__card">
				<h3>
					<IconCalendar :size="20" />
					{{ t('social', 'How steadily you post') }}
				</h3>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.consistency.active_days) }}</strong>
						<span>{{ t('social', 'days you posted on') }}</span>
						<em>{{ t('social', '{n}% of the days counted', { n: decimal(stats.consistency.share) }) }}</em>
					</li>
					<li>
						<strong>{{ number(stats.consistency.streak) }}</strong>
						<span>{{ t('social', 'longest run of days') }}</span>
					</li>
					<li>
						<strong>{{ number(stats.consistency.longest_gap) }}</strong>
						<span>{{ t('social', 'longest quiet spell') }}</span>
						<em>{{ n('social', '%n day', '%n days', stats.consistency.longest_gap) }}</em>
					</li>
				</ul>
			</section>

			<!-- who you talk with -->
			<section v-if="partners.length" class="stats__card">
				<h3>
					<IconForum :size="20" />
					{{ t('social', 'Who you talk with') }}
				</h3>
				<p class="stats__note">
					{{ t('social', 'From the replies rather than from who you follow. The two are rarely the same list.') }}
				</p>
				<div class="stats__columns">
					<div v-for="side in partners" :key="side.key">
						<h4>{{ side.label }}</h4>
						<dl class="stats__rows">
							<div v-for="person in side.people" :key="person.id" class="stats__row">
								<dt>
									{{ '@' + person.account }}
									<span v-if="!person.followed" class="stats__badge">{{ t('social', 'not followed') }}</span>
								</dt>
								<dd>
									<span class="stats__meter" :style="{ '--share': person.share }" />
									<span class="stats__row-value">{{ number(person.replies) }}</span>
								</dd>
							</div>
						</dl>
					</div>
				</div>
				<p v-if="stats.partners.inbound.length" class="stats__note">
					{{ t('social', '{n}% of the people who replied to you are people you do not follow.', { n: decimal(stats.partners.not_followed_share) }) }}
				</p>
			</section>

			<!-- pictures and whether they describe themselves -->
			<section v-if="stats.media && stats.media.images > 0" class="stats__card">
				<h3>
					<IconImage :size="20" />
					{{ t('social', 'Your pictures') }}
				</h3>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.media.images) }}</strong>
						<span>{{ n('social', 'picture posted', 'pictures posted', stats.media.images) }}</span>
					</li>
					<li>
						<strong>{{ decimal(stats.media.described_share) }}%</strong>
						<span>{{ t('social', 'carry a description') }}</span>
						<em>{{ t('social', '{done} of {all}', { done: number(stats.media.described), all: number(stats.media.images) }) }}</em>
					</li>
				</ul>
				<p v-if="stats.media.described_share < 100" class="stats__note">
					{{ t('social', 'A picture without a description is a picture nobody using a screen reader can read. This is the one number on the page you can move on your own.') }}
				</p>
			</section>

			<!-- what you write in, where you link to -->
			<section v-if="stats.languages.length || stats.domains.length" class="stats__card">
				<h3>
					<IconLink :size="20" />
					{{ t('social', 'What you write, and what you point at') }}
				</h3>
				<div class="stats__columns">
					<div v-if="stats.languages.length">
						<h4>{{ t('social', 'Languages you post in') }}</h4>
						<ul class="stats__list">
							<li v-for="language in stats.languages" :key="language.name">
								<span>
									{{ languageName(language.name) }}
									<span class="stats__tag-count">{{ number(language.count) }}</span>
								</span>
							</li>
						</ul>
					</div>
					<div v-if="stats.domains.length">
						<h4>{{ t('social', 'Where your links go') }}</h4>
						<ul class="stats__list">
							<li v-for="domain in stats.domains" :key="domain.name">
								<a :href="'https://' + domain.name" target="_blank" rel="noreferrer noopener">
									{{ domain.name }}
									<span class="stats__tag-count">{{ number(domain.count) }}</span>
								</a>
							</li>
						</ul>
					</div>
				</div>
			</section>

			<p class="stats__window">
				{{ window }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import IconClock from 'vue-material-design-icons/ClockOutline.vue'
import IconDownload from 'vue-material-design-icons/Download.vue'
import IconForum from 'vue-material-design-icons/ForumOutline.vue'
import IconImage from 'vue-material-design-icons/ImageOutline.vue'
import IconLink from 'vue-material-design-icons/LinkVariant.vue'
import IconPencil from 'vue-material-design-icons/PencilOutline.vue'
import IconCalendar from 'vue-material-design-icons/CalendarBlank.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconReply from 'vue-material-design-icons/Reply.vue'
import IconRepeat from 'vue-material-design-icons/Repeat.vue'
import IconShape from 'vue-material-design-icons/ShapeOutline.vue'
import IconTarget from 'vue-material-design-icons/Target.vue'
import IconTrophy from 'vue-material-design-icons/Trophy.vue'
import IconAllTime from 'vue-material-design-icons/Infinity.vue'
import IconMonth from 'vue-material-design-icons/CalendarMonthOutline.vue'
import IconQuarter from 'vue-material-design-icons/CalendarRangeOutline.vue'
import IconYear from 'vue-material-design-icons/CalendarBlankOutline.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import { seriesStyle } from '../utils/tagColour.js'
import logger from '../services/logger.js'

/**
 * A `YYYY-MM` key from the server, named as a month.
 *
 * The server counts in UTC, so the key's first midnight is a UTC one; read in
 * a zone west of it, that midnight is still the last evening of the month
 * before, and September's column would be labelled August.
 *
 * @param {string} key `YYYY-MM`
 * @param {object} options `toLocaleDateString()` options
 * @param {string|undefined} locale as `toLocaleDateString()` takes it
 * @return {string}
 */
function utcMonth(key, options, locale = undefined) {
	return new Date(key + '-01T00:00:00Z').toLocaleDateString(locale, { ...options, timeZone: 'UTC' })
}

/**
 * The icon beside a window in the switcher, which every option of that
 * control carries.
 *
 * @param {number} days the window, 0 for everything
 * @return {object} an icon component
 */
function windowIcon(days) {
	if (days === 0) {
		return IconAllTime
	}
	if (days <= 31) {
		return IconMonth
	}
	if (days <= 120) {
		return IconQuarter
	}

	return IconYear
}

/**
 * The reader's own numbers.
 *
 * Everything here is drawn with CSS rather than a charting library: two bar
 * charts and a set of meters do not justify the weight of one, and this app
 * has just spent a release getting that weight down.
 *
 * The bars are scaled to the tallest column rather than to an absolute, which
 * is what makes a quiet month visible next to a busy one. Each carries its own
 * figure in a `title` and in the accessible name, because a bar whose only
 * value is its height says nothing to somebody who cannot see it.
 */
export default {
	name: 'Statistics',

	components: {
		IconAccountGroup,
		IconCalendar,
		IconClock,
		IconDownload,
		IconForum,
		IconHeart,
		IconImage,
		IconLink,
		IconPencil,
		IconPound,
		IconRefresh,
		IconReply,
		IconRepeat,
		IconShape,
		IconTarget,
		IconTrophy,
		TimelineSwitcher,
		NcAvatar,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			error: '',
			/** what the per-post list is ordered by */
			sort: 'date',
			/** the window the reader has asked for, in days; 0 is everything */
			days: 0,
			/** @type {object|null} */
			stats: null,
		}
	},

	computed: {
		/**
		 * Who counted the network and when. The date matters: these are the
		 * one set of figures on this page that this server did not count
		 * itself, and a survey nobody has run for a month is worth reading as
		 * one.
		 *
		 * @return {string} the sentence before the link to the source
		 */
		/**
		 * The windows on offer, named rather than numbered.
		 *
		 * The server decides which ones exist — each is a walk and a cache
		 * entry of its own — so the list is read from the answer rather than
		 * written down twice.
		 *
		 * @return {object[]} each with the days it covers as its `value`, its
		 *                    label and its icon, as TimelineSwitcher takes them
		 */
		windowChoices() {
			const choices = this.stats?.window?.choices ?? [0, 30, 90, 365]

			return choices.map((days) => ({
				value: String(days),
				label: days === 0
					? t('social', 'All time')
					: n('social', 'Last %n day', 'Last %n days', days),
				icon: windowIcon(days),
				// on a phone the switcher drops the labels for the icons, and
				// four calendars say nothing; these are what it shows instead
				short: days === 0
					? t('social', 'All')
					: n('social', '%n day', '%n days', days),
			}))
		},

		/** @return {string} where the download comes from, with the window on it */
		exportUrl() {
			return generateUrl('apps/social/api/v1/statistics/export?days={days}', { days: this.days })
		},

		/** @return {string} what the downloaded file is called */
		exportName() {
			const acct = this.stats?.account?.acct ?? 'social'

			return `${acct}-statistics.csv`
		},

		/**
		 * What the account did, month by month, as one stacked bar each.
		 *
		 * Stacked rather than three lines because the question it answers is
		 * "what was I doing that month", and the parts of an answer belong in
		 * one column. Scaled to the busiest month, like every other chart here.
		 *
		 * @return {object[]} one entry per month
		 */
		activity() {
			const activity = this.stats?.activity
			if (!activity) {
				return []
			}

			const months = Object.keys(activity.originals ?? {})
			const total = (month) => this.activityKinds
				.reduce((sum, kind) => sum + (activity[kind.key]?.[month] ?? 0), 0)
			const tallest = Math.max(1, ...months.map(total))

			return months.map((month) => ({
				key: month,
				label: utcMonth(month, { month: 'narrow' }),
				title: [utcMonth(month, { year: 'numeric', month: 'long' })]
					.concat(this.activityKinds.map((kind) => `${kind.label}: ${activity[kind.key]?.[month] ?? 0}`))
					.join(' · '),
				parts: this.activityKinds.map((kind) => ({
					key: kind.key,
					height: Math.round((activity[kind.key]?.[month] ?? 0) / tallest * 100),
				})),
			}))
		},

		/** @return {object[]} the three kinds of thing an account does, named once */
		activityKinds() {
			return [
				{ key: 'originals', label: t('social', 'Posts of your own') },
				{ key: 'replies', label: t('social', 'Replies you wrote') },
				{ key: 'boosts', label: t('social', 'Boosts you gave') },
			]
		},

		/** @return {object[]} the same three, for the key under the chart */
		activityLegend() {
			return this.activityKinds
		},

		/**
		 * The people on either end of a conversation, as two lists.
		 *
		 * Each side is scaled to its own busiest partner: the two directions
		 * are different questions and a shared scale would flatten whichever
		 * of them the account does less of.
		 *
		 * @return {object[]} the two sides, each with its people
		 */
		partners() {
			const partners = this.stats?.partners
			if (!partners) {
				return []
			}

			const sides = [
				{ key: 'inbound', label: t('social', 'Who answers you') },
				{ key: 'outbound', label: t('social', 'Who you answer') },
			]

			return sides
				.map((side) => {
					const people = partners[side.key] ?? []
					const most = Math.max(1, ...people.map((person) => person.replies))

					return {
						...side,
						people: people.map((person) => ({ ...person, share: person.replies / most })),
					}
				})
				.filter((side) => side.people.length > 0)
		},

		networkNote() {
			const measured = this.stats?.network?.measured
			const on = measured ? new Date(measured) : null

			if (!on || Number.isNaN(on.getTime())) {
				return t('social', 'Counted across the fediverse by')
			}

			return t('social', 'Counted across the fediverse on {date} by', {
				date: on.toLocaleDateString(getCanonicalLocale(), { year: 'numeric', month: 'long', day: 'numeric' }),
			})
		},

		/**
		 * The platforms, as bands of a bar and rows of a list.
		 *
		 * Coloured from a fixed palette rather than from the theme's accent:
		 * six bands of one hue is a gradient, and this is a legend. The last
		 * row is everything not named, which keeps the shares summing to the
		 * whole.
		 *
		 * @return {Array<object>}
		 */
		platforms() {
			const platforms = this.stats?.software?.platforms
			if (!Array.isArray(platforms) || platforms.length === 0) {
				return []
			}

			return platforms.map((platform, index) => {
				const rest = !platform.name
				const name = rest ? t('social', 'everything else') : platform.name

				return {
					key: name + index,
					name,
					rest,
					// the remainder band is the app's own "there is more here"
					// grey; the named ones are hues the stylesheet resolves
					// per theme -- see seriesStyle()
					colour: rest
						? { '--series-colour': 'var(--color-border-dark)', '--series-colour-dark': 'var(--color-border-dark)' }
						: seriesStyle(index),
					width: Math.max(0.5, platform.share) + '%',
					shareLabel: this.decimal(platform.share) + '%',
					detail: t('social', '{servers} servers · {accounts} accounts', {
						servers: this.number(platform.servers),
						accounts: this.number(platform.accounts),
					}),
					title: name + ' — ' + this.decimal(platform.share) + '%',
				}
			})
		},

		/** @return {string} the bar, for somebody who cannot see it */
		compositionDescription() {
			return this.platforms
				.map((platform) => platform.name + ' ' + platform.shareLabel)
				.join(', ')
		},

		/** @return {string} why these shares are of accounts and not servers */
		platformsNote() {
			return t('social', 'Share of accounts, largest first — a platform can be many small servers or one big one. Counted by')
		},

		/** @return {object|null} the monthly series, or null where there is none */
		growth() {
			const growth = this.stats?.growth
			return Array.isArray(growth?.months) && growth.months.length > 1 ? growth : null
		},

		/**
		 * The four figures, each with what it did last month and last year.
		 *
		 * A percentage rather than a difference: the four are orders of
		 * magnitude apart, and "up 3%" is the sentence a reader wants from all
		 * of them.
		 *
		 * @return {Array<{key: string, label: string, month: string, year: string, className: string}>}
		 */
		growthChanges() {
			const labels = {
				servers: t('social', 'servers'),
				accounts: t('social', 'accounts'),
				active: t('social', 'posted last month'),
				posts: t('social', 'posts'),
			}
			const month = this.growth?.change?.month ?? {}
			const year = this.growth?.change?.year ?? {}

			return Object.keys(labels)
				.filter((key) => month[key] !== undefined)
				.map((key) => ({
					key,
					label: labels[key],
					month: this.percentage(month[key]),
					year: year[key] === undefined
						? ''
						: t('social', '{change} over a year', { change: this.percentage(year[key]) }),
					className: month[key] >= 0 ? 'stats__delta--up' : 'stats__delta--down',
				}))
		},

		/** @return {string} the accounts series, as a polyline */
		growthPoints() {
			return this.series(38)
		},

		/**
		 * @return {string} the same, closed along the bottom so it can be
		 * filled: a line says "it went up", a filled area says "this much of
		 * it is there"
		 */
		growthArea() {
			const line = this.series(38)

			return line === '' ? '' : '0,40 ' + line + ' 100,40'
		},

		/** @return {string} a unique id, so two charts on a page do not share a gradient */
		gradientId() {
			return 'social-growth-' + (this.growth?.months?.length ?? 0)
		},

		/**
		 * @return {Array<{x: number, label: string}>} a mark at each January
		 * the series covers, so a rise has something to be a rise against
		 */
		growthTicks() {
			const months = this.growth?.months ?? []

			return months
				.map((month, index) => ({ month, index }))
				.filter(({ month }) => month.month.endsWith('-01'))
				.map(({ month, index }) => ({
					x: Math.round((index / Math.max(1, months.length - 1)) * 1000) / 10,
					label: month.month.slice(0, 4),
				}))
		},

		/** @return {string} where the line ends, said in words beside it */
		growthLatest() {
			const months = this.growth?.months ?? []
			const last = months[months.length - 1]

			return last ? t('social', '{count} accounts', { count: this.number(last.accounts) }) : ''
		},

		/**
		 * The things a reader would otherwise work out for themselves, in the
		 * one place they have all the numbers to hand.
		 *
		 * @return {Array<{key: string, value: string, label: string}>}
		 */
		growthFacts() {
			const months = this.growth?.months ?? []
			const last = months[months.length - 1]
			const facts = []
			if (!last) {
				return facts
			}

			const yearAgo = months[months.length - 13]
			if (yearAgo && last.accounts > yearAgo.accounts) {
				facts.push({
					key: 'joined',
					value: this.number(last.accounts - yearAgo.accounts),
					label: t('social', 'accounts more than a year ago'),
				})
			}

			if (last.accounts > 0) {
				facts.push({
					key: 'active',
					value: this.decimal((last.active / last.accounts) * 100) + '%',
					label: t('social', 'of them posted last month'),
				})
				facts.push({
					key: 'perAccount',
					value: this.number(Math.round(last.posts / last.accounts)),
					label: t('social', 'posts per account, all time'),
				})
			}

			const peers = this.stats?.network?.peers ?? 0
			const servers = this.stats?.network?.servers ?? 0
			if (peers > 0 && servers > 0) {
				// a young instance talks to two servers out of forty thousand,
				// which is 0.0% — a true number that tells its reader nothing.
				// Below a tenth of a per cent the count is the honest figure
				const share = (peers / servers) * 100

				facts.push(share < 0.1
					? {
							key: 'reach',
							value: this.number(peers),
							label: t('social', 'of {total} servers are ones this one talks to', {
								total: this.number(servers),
							}),
						}
					: {
							key: 'reach',
							value: this.decimal(share) + '%',
							label: t('social', 'of all servers are ones this one talks to'),
						})
			}

			return facts
		},

		/** @return {string} the first month drawn */
		growthFrom() {
			return this.monthName(this.growth?.months?.[0]?.month)
		},

		/** @return {string} and the last */
		growthTo() {
			return this.monthName(this.growth?.months?.[this.growth.months.length - 1]?.month)
		},

		/** @return {string} what the chart says, for somebody who cannot see it */
		growthDescription() {
			return t('social', 'Accounts in the fediverse from {from} to {to}', {
				from: this.growthFrom,
				to: this.growthTo,
			})
		},

		/**
		 * @return {string} why this says different numbers than the card above
		 * it, which is the first thing anybody comparing them will ask
		 */
		growthNote() {
			return t('social', 'Counted separately from the figures above — the two surveys crawl different servers and count dormant accounts differently, so their totals do not match. Month by month, by')
		},

		/** @return {string} the login name, which is what the avatar endpoint answers for */
		uid() {
			return getCurrentUser()?.uid ?? ''
		},

		/**
		 * The name the account publishes under.
		 *
		 * The account's own display name first: the page is about the account,
		 * and that name is not always the Nextcloud one. But an account that
		 * has never been given a name of its own carries its login name as one
		 * — `admin` sits in that column for anybody who never opened the
		 * profile editor — and a page headed "admin" is headed nobody. Where
		 * the published name is only the handle again, the name the rest of
		 * this server calls the reader by is the better answer.
		 *
		 * @return {string}
		 */
		displayName() {
			const published = this.stats?.account?.display_name ?? ''
			const handle = this.stats?.account?.acct ?? ''

			if (published !== '' && published !== handle) {
				return published
			}

			return getCurrentUser()?.displayName || published || handle
		},

		/** @return {string} */
		joined() {
			const at = this.stats?.account?.created_at
			if (!at) {
				return ''
			}

			const date = new Date(at)

			return Number.isNaN(date.getTime())
				? ''
				: t('social', 'Here since {date}', {
						date: date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
					})
		},

		/**
		 * How much of the account's history the figures were counted over.
		 *
		 * The bar is full either way — the walk always finished — and it is
		 * the colour and the words beside it that say whether it finished
		 * because it ran out of posts or because it hit its ceiling. A bar
		 * drawn at the share of the ceiling used would say 2% of an account
		 * that has been counted down to its very first post.
		 *
		 * @return {{capped: boolean, label: string}}
		 */
		coverage() {
			const w = this.stats?.window
			const counted = w?.counted ?? 0
			const max = Math.max(1, w?.max ?? 1)

			return {
				capped: Boolean(w?.capped),
				label: w?.capped
					? t('social', '{counted} of {max} — as far back as this page goes', {
							counted: this.number(counted),
							max: this.number(max),
						})
					: n('social', '{counted} post, all of them', '{counted} posts, all of them', counted, {
							counted: this.number(counted),
						}),
			}
		},

		/** @return {object|null} the two windows, as the server counted them */
		periods() {
			return this.stats?.periods ?? null
		},

		/**
		 * The four figures the two windows are compared on.
		 *
		 * Each carries both its own line and the line of the window before it,
		 * drawn against the taller of the two so that the pair can be read as
		 * one picture rather than two charts that happen to sit side by side.
		 *
		 * @return {Array<object>}
		 */
		kpis() {
			const periods = this.periods
			if (!periods) {
				return []
			}

			const cards = [
				{ key: 'reach', label: t('social', 'Estimated reach'), hint: t('social', 'Sum of the per-post estimates') },
				{ key: 'interactions', label: t('social', 'Interactions'), hint: t('social', 'Likes, boosts and replies together') },
				{ key: 'likes', label: t('social', 'Likes'), hint: t('social', 'As far as this server was told') },
				{ key: 'boosts', label: t('social', 'Boosts'), hint: t('social', 'As far as this server was told') },
			]

			return cards.map((card, index) => {
				const current = periods.current?.series?.[card.key] ?? []
				const previous = periods.previous?.series?.[card.key] ?? []
				const tallest = Math.max(1, ...current, ...previous)
				const change = periods.change?.[card.key] ?? null

				return {
					...card,
					index: String(index + 1).padStart(2, '0'),
					value: periods.current?.[card.key] ?? 0,
					previousValue: periods.previous?.[card.key] ?? 0,
					delta: this.delta(change),
					deltaClass: {
						'stats__delta--up': change !== null && change > 0,
						'stats__delta--down': change !== null && change < 0,
					},
					currentPoints: this.points(current, tallest),
					previousPoints: this.points(previous, tallest),
					description: t('social', '{label}: {now} over the last {days} days, against {before} over the {days} before them.', {
						label: card.label,
						now: this.number(periods.current?.[card.key] ?? 0),
						before: this.number(periods.previous?.[card.key] ?? 0),
						days: periods.days,
					}),
				}
			})
		},

		/**
		 * The window's posts in the order the reader asked for, each with its
		 * reach against the best of them.
		 *
		 * Sorted here rather than on the server: it is a hundred rows that are
		 * already in the browser, and a round trip to reorder them would be a
		 * round trip to reorder them.
		 *
		 * @return {Array<object>}
		 */
		timeline() {
			const rows = this.stats?.timeline ?? []
			const furthest = Math.max(1, ...rows.map((row) => row.reach))
			const sorted = [...rows]

			if (this.sort === 'reach') {
				sorted.sort((a, b) => b.reach - a.reach)
			} else if (this.sort === 'engagement') {
				sorted.sort((a, b) => b.score - a.score)
			}

			return sorted.map((row) => ({
				...row,
				share: Math.round((row.reach / furthest) * 100) + '%',
			}))
		},

		/** @return {string} what the reach estimate is, and what it cannot know */
		reachNote() {
			const unknown = this.stats?.reach?.unknown_boosters ?? 0
			const note = t('social', 'Reach is an estimate: your followers today, plus the followers of everybody who boosted the post. Audiences that overlap are counted twice, and nobody can count the people who saw a post without touching it.')

			return (unknown < 1)
				? note
				: note + ' ' + n(
					'social',
					'The audience of one account that boosted you is not known here, and counts as nobody.',
					'The audiences of {count} accounts that boosted you are not known here, and count as nobody.',
					unknown,
					{ count: this.number(unknown) },
				)
		},

		/** @return {string} how much of the window the list holds */
		listNote() {
			const listed = this.stats?.timeline?.length ?? 0
			const posts = this.periods?.current?.posts ?? 0

			return (posts > listed)
				? t('social', 'The {listed} most recent of the {posts} posts in the window.', { listed: this.number(listed), posts: this.number(posts) })
				: n('social', 'The one post of the window.', 'All {posts} posts of the window.', posts, { posts: this.number(posts) })
		},

		/** @return {Array<{key: string, label: string, count: number, height: string}>} */
		months() {
			return this.bars(this.stats?.by_month ?? {})
		},

		/**
		 * How far above the middle post the best one was.
		 *
		 * The number an agency looks for after a mean and a median disagree:
		 * one post carrying a month is a different account from one whose
		 * posts all do about the same.
		 *
		 * @return {number}
		 */
		viralMultiple() {
			const median = this.stats?.rates?.median ?? 0
			const best = this.stats?.rates?.best ?? 0

			return (median > 0) ? Math.round((best / median) * 10) / 10 : best
		},

		/** @return {Array<{key: string, label: string, count: number, height: string}>} */
		engagementMonths() {
			return this.bars(this.stats?.engagement_by_month ?? {})
		},

		/** @return {Array<{key: string, label: string, count: number, height: string}>} */
		followerMonths() {
			return this.bars(this.stats?.audience?.by_month ?? {})
		},

		/**
		 * What each kind of post averages, best first, each meter against the
		 * best of them rather than against an absolute — the question is which
		 * kind does better, not how big the number is.
		 *
		 * @return {Array<{key: string, label: string, posts: number, average: number, share: string}>}
		 */
		content() {
			const rows = this.stats?.content ?? []
			const labels = {
				with_media: t('social', 'With a picture or a video'),
				text_only: t('social', 'Text only'),
				with_hashtag: t('social', 'With a hashtag'),
				no_hashtag: t('social', 'Without one'),
				original: t('social', 'Posts of your own'),
				reply: t('social', 'Replies'),
				visibility_public: t('social', 'Public'),
				visibility_unlisted: t('social', 'Unlisted'),
				visibility_followers: t('social', 'Followers only'),
				visibility_direct: t('social', 'Direct'),
			}
			const best = Math.max(1, ...rows.map((row) => row.average))

			return rows
				.filter((row) => labels[row.key] !== undefined)
				.map((row) => ({ ...row, label: labels[row.key], share: Math.round((row.average / best) * 100) + '%' }))
				.sort((a, b) => b.average - a.average)
		},

		/**
		 * The seven days by what a post on each one averages.
		 *
		 * @return {Array<{day: number, label: string, posts: number, average: number, share: string}>}
		 */
		weekdays() {
			const rows = this.stats?.by_weekday ?? []
			const best = Math.max(1, ...rows.map((row) => row.average))

			return rows.map((row) => ({
				...row,
				// 2024-01-07 was a Sunday, which is how PHP's `w` numbers them;
				// named in UTC for the reason utcMonth() gives
				label: new Date(Date.UTC(2024, 0, 7 + row.day)).toLocaleDateString(undefined, { weekday: 'long', timeZone: 'UTC' }),
				share: Math.round((row.average / best) * 100) + '%',
			}))
		},

		/** @return {string} the hour that works, in words, or '' when there is not enough to say */
		bestHour() {
			const best = this.stats?.best_hour
			if (!best || best.hour === null) {
				return ''
			}

			return t('social', 'Your posts do best around {hour}:00 UTC — {n} engagement each, over {posts} posts.', {
				hour: best.hour,
				n: this.decimal(best.average),
				posts: best.posts,
			})
		},

		/** @return {Array<{name: string, posts: number, average: number, share: string}>} */
		hashtagPerformance() {
			const rows = this.stats?.hashtag_performance ?? []
			const best = Math.max(1, ...rows.map((row) => row.average))

			return rows.map((row) => ({ ...row, share: Math.round((row.average / best) * 100) + '%' }))
		},

		/** @return {Array<{host: string, count: number, share: string}>} */
		instances() {
			const rows = this.stats?.audience?.instances ?? []
			const biggest = Math.max(1, ...rows.map((row) => row.count))

			return rows.map((row) => ({ ...row, share: Math.round((row.count / biggest) * 100) + '%' }))
		},

		/** @return {Array<{key: number, label: string, count: number, height: string}>} */
		hours() {
			const counts = this.stats?.by_hour ?? []
			const tallest = Math.max(1, ...counts)

			return counts.map((count, hour) => ({
				key: hour,
				count,
				// every third hour is labelled; the rest would be a smear
				label: (hour % 3 === 0) ? String(hour) : '',
				hour,
				height: Math.round((count / tallest) * 100) + '%',
			}))
		},

		/**
		 * What the posts are made of, as counts against the total.
		 *
		 * @return {Array<{key: string, label: string, count: number, share: string}>}
		 */
		composition() {
			const posts = this.stats?.posts ?? {}
			const visibility = this.stats?.visibility ?? {}
			const total = Math.max(1, posts.total ?? 0)
			const rows = [
				{ key: 'originals', label: t('social', 'Posts of your own'), count: posts.originals ?? 0 },
				{ key: 'replies', label: t('social', 'Replies'), count: posts.replies ?? 0 },
				{ key: 'boosts', label: t('social', 'Boosts of other people'), count: posts.boosts ?? 0 },
				{ key: 'media', label: t('social', 'With a picture or a video'), count: posts.with_media ?? 0 },
				{ key: 'public', label: t('social', 'Public'), count: visibility.public ?? 0 },
				{ key: 'unlisted', label: t('social', 'Unlisted'), count: visibility.unlisted ?? 0 },
				{ key: 'followers', label: t('social', 'Followers only'), count: visibility.followers ?? 0 },
				{ key: 'direct', label: t('social', 'Direct'), count: visibility.direct ?? 0 },
			]

			return rows.map((row) => ({ ...row, share: Math.round((row.count / total) * 100) + '%' }))
		},

		/** @return {string} what the numbers above were counted over */
		window() {
			const w = this.stats?.window
			if (!w) {
				return ''
			}

			if (w.capped) {
				return t('social', 'Counted over your {count} most recent posts, which is as far back as this page goes.', { count: w.max })
			}

			return n('social', 'Counted over your one post.', 'Counted over all {count} of your posts.', w.counted, { count: w.counted })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Ask for a different window.
		 *
		 * @param {number} days how far back, or 0 for everything
		 * @return {Promise<void>}
		 */
		async pick(days) {
			if (days === this.days) {
				return
			}
			this.days = days
			await this.load()
		},

		/**
		 * @param {boolean} fresh count them again rather than reading the cache
		 * @return {Promise<void>}
		 */
		async load(fresh = false) {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl('apps/social/api/v1/statistics'), {
					params: { days: this.days, fresh },
				})
				this.stats = response.data
			} catch (error) {
				logger.error('could not load the statistics', { error })
				this.error = t('social', 'Could not work out your statistics.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A language tag as the reader's own word for that language.
		 *
		 * `Intl.DisplayNames` is in every browser this app supports; where a
		 * tag is not one it knows, the tag itself is a better answer than a
		 * blank.
		 *
		 * @param {string} tag a BCP 47 language tag
		 * @return {string} its name, or the tag
		 */
		languageName(tag) {
			try {
				return new Intl.DisplayNames([getCanonicalLocale()], { type: 'language' }).of(tag) || tag
			} catch {
				return tag
			}
		},

		/**
		 * @param {number} value a count
		 * @return {string} it, in the reader's own digits and grouping
		 */
		number(value) {
			return Number(value ?? 0).toLocaleString()
		},

		/**
		 * @param {number} value an average
		 * @return {string} it, to one decimal
		 */
		decimal(value) {
			return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		/**
		 * One window, as the dates it covers.
		 *
		 * @param {object} period one of the two windows
		 * @return {string} the range, in the reader's own date format
		 */
		range(period) {
			const from = new Date(period?.from ?? '')
			const until = new Date(period?.until ?? '')
			if (Number.isNaN(from.getTime()) || Number.isNaN(until.getTime())) {
				return ''
			}

			// counted in UTC and printed in UTC: formatted in a zone ahead of it,
			// the last second of the window becomes the small hours of the day
			// after it and the page names a day it does not cover
			/** @type {Intl.DateTimeFormatOptions} */
			const format = { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' }

			return from.toLocaleDateString(undefined, format) + ' – ' + until.toLocaleDateString(undefined, format)
		},

		/**
		 * How much bigger than last time, in words.
		 *
		 * A window with nothing before it is new rather than infinitely up:
		 * every percentage against nothing is the same percentage.
		 *
		 * @param {number|null} change the percentage the server worked out
		 * @return {string}
		 */
		delta(change) {
			if (change === null || change === undefined) {
				return t('social', 'new')
			}

			return (change >= 0)
				? '↑ +' + this.decimal(change) + '%'
				: '↓ ' + this.decimal(change) + '%'
		},

		/**
		 * The accounts series as SVG points, scaled to its own range rather
		 * than to zero.
		 *
		 * A series that runs from thirty million to thirty-eight drawn from
		 * zero is a flat line with a lot of empty chart under it; drawn from
		 * its own floor it is the shape the numbers actually have. The floor
		 * is a tenth below the smallest value so the line never touches the
		 * bottom edge.
		 *
		 * @param {number} height the box to draw into
		 * @return {string} the points
		 */
		series(height) {
			const values = (this.growth?.months ?? []).map((month) => month.accounts)
			if (values.length < 2) {
				return ''
			}

			const top = Math.max(...values)
			const floor = Math.min(...values) * 0.9
			const span = Math.max(1, top - floor)

			return values
				.map((value, index) => {
					const x = (index / (values.length - 1)) * 100
					const y = height - 2 - ((value - floor) / span) * (height - 4)

					return Math.round(x * 100) / 100 + ',' + Math.round(y * 100) / 100
				})
				.join(' ')
		},

		/**
		 * @param {number} change a percentage
		 * @return {string} it with a sign and an arrow, as the KPI cards do
		 */
		percentage(change) {
			return (change >= 0 ? '↑ +' : '↓ ') + this.decimal(change) + '%'
		},

		/**
		 * @param {string} month `YYYY-MM`
		 * @return {string} it as a month and a year a reader knows
		 */
		monthName(month) {
			if (typeof month !== 'string' || !/^\d{4}-\d{2}$/.test(month)) {
				return ''
			}

			return utcMonth(month, { year: 'numeric', month: 'short' }, getCanonicalLocale())
		},

		/**
		 * One sparkline, as SVG points.
		 *
		 * Drawn into a fixed 100 × 32 box that the CSS stretches, with the
		 * stroke left un-stretched: a line whose thickness changes with the
		 * width of the card reads as a different line.
		 *
		 * @param {number[]} values one day each
		 * @param {number} tallest what to scale against
		 * @return {string} the polyline's points
		 */
		points(values, tallest) {
			if (values.length < 2) {
				return ''
			}

			return values
				.map((value, index) => {
					const x = (index / (values.length - 1)) * 100
					const y = 30 - (Math.max(0, value) / tallest) * 28

					return Math.round(x * 100) / 100 + ',' + Math.round(y * 100) / 100
				})
				.join(' ')
		},

		/**
		 * @param {object} post one row of the list
		 * @return {string} when it went out, in the reader's own format
		 */
		posted(post) {
			const at = new Date(post?.published_at ?? '')

			return Number.isNaN(at.getTime())
				? ''
				: at.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
		},

		/**
		 * One month-by-month chart: the columns, each scaled to the tallest.
		 *
		 * @param {object} counts month key to number
		 * @return {Array<{key: string, label: string, full: string, count: number, height: string}>}
		 */
		bars(counts) {
			const tallest = Math.max(1, ...Object.values(counts))

			return Object.entries(counts).map(([key, count]) => ({
				key,
				count,
				label: utcMonth(key, { month: 'narrow' }),
				full: utcMonth(key, { year: 'numeric', month: 'long' }),
				height: Math.round((count / tallest) * 100) + '%',
			}))
		},

		/**
		 * @param {object} month one column
		 * @return {string} what the column says
		 */
		engagementTitle(month) {
			return n('social', '%n engagement in {month}', '%n engagement in {month}', month.count, { month: month.full })
		},

		/**
		 * @param {object} month one column
		 * @return {string} what the column says
		 */
		followerTitle(month) {
			return n('social', '%n follower in {month}', '%n followers in {month}', month.count, { month: month.full })
		},

		/**
		 * @param {object} month one column
		 * @return {string} what the column says, for a pointer and a reader
		 */
		monthTitle(month) {
			return n('social', '%n post in {month}', '%n posts in {month}', month.count, { month: month.full })
		},

		/**
		 * @param {object} hour one column
		 * @return {string} what the column says
		 */
		hourTitle(hour) {
			return n('social', '%n post at {hour}:00 UTC', '%n posts at {hour}:00 UTC', hour.count, { hour: hour.hour })
		},
	},
}
</script>

<style scoped lang="scss">
.stats__network {
	h3 {
		margin-block-end: calc(var(--default-grid-baseline) * 2);
		font-weight: bold;
	}

	a {
		text-decoration: underline;
	}
}

/* Six bands of one hue is a gradient; this is a legend, so the hues are spread
   and the bar is read against the list beside it. The hue comes from
   `seriesStyle()` as a pair of custom properties, and the choice between them
   is made here, where the theme is known -- a fixed palette is a set of
   colours chosen against one background, and this app has two. */
.stats__composition {
	display: flex;
	height: 14px;
	border-radius: 7px;
	overflow: hidden;
	margin-block: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-dark);
}

.stats__composition-band {
	height: 100%;
	background: var(--series-colour);
	transition: flex-basis .2s ease;
}

.stats__platform-list {
	list-style: none;
	display: grid;
	grid-template-columns: auto 1fr auto;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	align-items: baseline;

	li {
		display: contents;
	}
}

.stats__platform-swatch {
	inline-size: 10px;
	block-size: 10px;
	border-radius: 3px;
	align-self: center;
	background: var(--series-colour);
}

/* The dark value, chosen here rather than in the component: a hue picked
   against a light background does not carry to a dark one, and only the
   stylesheet knows which it is in. */
@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		.stats__composition-band,
		.stats__platform-swatch {
			background: var(--series-colour-dark);
		}
	}
}

:root[data-theme="dark"] {
	.stats__composition-band,
	.stats__platform-swatch {
		background: var(--series-colour-dark);
	}
}

.stats__platform-name {
	font-weight: bold;
}

.stats__platform-share {
	font-variant-numeric: tabular-nums;
	text-align: end;
}

/* the third column wraps under on a phone, where three columns of numbers do
   not fit and the detail is the one that can wait */
.stats__platform-detail {
	grid-column: 2 / -1;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
}

.stats__area {
	width: 100%;
	height: 120px;
	display: block;
}

.stats__area-line {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 2;
	stroke-linejoin: round;
}

.stats__area-tick {
	stroke: var(--color-border);
	stroke-width: 1;
	stroke-dasharray: 2 3;
}

.stats__growth-latest {
	float: inline-end;
	color: var(--color-main-text);
	font-weight: bold;
}

.stats__growth-tick {
	position: absolute;
	transform: translateX(-50%);
}

.stats__derived {
	list-style: none;
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
	margin-block-start: calc(var(--default-grid-baseline) * 3);

	li {
		display: flex;
		flex-direction: column;
	}

	strong {
		font-size: 1.3em;
	}

	span {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);
	}
}

.stats__platforms,
.stats__growth {
	h3 {
		margin-block-end: calc(var(--default-grid-baseline) * 2);
		font-weight: bold;
	}

	a {
		text-decoration: underline;
	}
}

/* the change figures read as a row of verdicts rather than of totals, so the
   number is the loud part and the period is the quiet one */
.stats__figures--change {
	strong {
		font-variant-numeric: tabular-nums;
	}
}

.stats__growth-year {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
}

.stats__growth-chart {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;

	figcaption {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);
		margin-block-end: var(--default-grid-baseline);
	}
}

.stats__spark--growth {
	width: 100%;
	height: 64px;
}

.stats__growth-span {
	position: relative;
	display: flex;
	justify-content: space-between;
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
}

.stats {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

	h2 {
		margin-bottom: 8px;
	}
}

.stats__hint {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.stats__loading {
	margin: 60px auto;
}

.stats__error {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 10px;
	padding: 16px;
}

.stats__card {
	margin-bottom: 16px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-resting);

	h3 {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		font-size: 17px;
		font-weight: bold;
	}

	h4 {
		margin: 14px 0 6px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		font-weight: normal;
	}
}

.stats__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.stats__eyebrow {
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.stats__hero-who {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	align-items: center;
}

.stats__hero-names {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.stats__hero-name {
	margin: 0;
	font-size: 26px;
	font-weight: bold;
	line-height: 1.1;
	overflow-wrap: anywhere;
}

.stats__hero-handle {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.stats__hero-followers {
	display: flex;
	flex-direction: column;
	/* pushed to the far end where there is room for it, and simply the next
	   thing down where there is not */
	margin-inline-start: auto;
	text-align: end;

	strong {
		font-size: 34px;
		line-height: 1.05;
	}

	span {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
	}
}

.stats__coverage {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 12px;
	align-items: center;
	margin-top: 14px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
	font-size: 12px;
}

.stats__coverage-track {
	flex: 1 1 120px;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
}

.stats__coverage-fill {
	display: block;
	width: 100%;
	height: 100%;
	border-radius: 3px;
	background: var(--color-primary-element);
}

.stats__coverage-fill--capped {
	background: var(--color-warning, var(--color-text-maxcontrast));
}

.stats__coverage-count {
	color: var(--color-text-maxcontrast);
}

.stats__periods-head {
	display: flex;
	flex-wrap: wrap;
	gap: 10px 16px;
	align-items: flex-end;
	justify-content: space-between;
	margin-bottom: 10px;
}

.stats__periods-title {
	margin: 2px 0 0;
	font-size: 24px;
	font-weight: bold;
	line-height: 1.15;
}

.stats__legend {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	font-size: 12px;

	div {
		display: flex;
		gap: 10px;
		align-items: center;
	}

	dt {
		display: flex;
		gap: 6px;
		align-items: center;
		min-width: 72px;
		text-transform: uppercase;
	}

	dd {
		color: var(--color-text-maxcontrast);
	}
}

.stats__legend-key {
	width: 16px;
	height: 3px;
	border-radius: 2px;
}

.stats__legend-key--current {
	background: var(--color-primary-element);
}

.stats__legend-key--previous {
	background: var(--color-text-maxcontrast);
}

.stats__kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
	margin: 12px 0;
}

.stats__kpi {
	display: flex;
	flex-direction: column;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
}

.stats__kpi-head {
	display: flex;
	gap: 8px;
	align-items: center;
	justify-content: space-between;
}

.stats__kpi-label {
	font-weight: bold;
}

.stats__kpi-index {
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.stats__kpi-value {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
	margin-top: 8px;

	strong {
		font-size: 30px;
		line-height: 1.05;
	}
}

.stats__delta {
	padding: 1px 7px;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
}

.stats__delta--up {
	color: var(--color-success-text, var(--color-success));
}

.stats__delta--down {
	color: var(--color-error-text, var(--color-error));
}

.stats__spark {
	display: block;
	width: 100%;
	height: 46px;
	margin: 10px 0 8px;
}

.stats__spark-line {
	fill: none;
	stroke-linecap: round;
	stroke-linejoin: round;
	stroke-width: 2;
}

.stats__spark-line--previous {
	opacity: 0.5;
	stroke: var(--color-text-maxcontrast);
}

.stats__spark-line--current {
	stroke: var(--color-primary-element);
}

.stats__kpi-foot {
	display: flex;
	gap: 8px;
	justify-content: space-between;
	margin-top: auto;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 12px;

	span:last-child {
		color: var(--color-main-text);
		font-weight: bold;
	}
}

.stats__sort {
	display: flex;
	gap: 8px;
	align-items: center;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
}

.stats__posts {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 10px 0;
}

.stats__post {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	align-items: center;
	justify-content: space-between;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.stats__post-text {
	display: flex;
	flex: 1 1 220px;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	color: var(--color-main-text);

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

.stats__post-date {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.stats__post-figures {
	display: flex;
	flex: 1 1 220px;
	flex-direction: column;
	gap: 4px;
	padding: 8px 10px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
}

.stats__post-reach {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 10px;
	align-items: center;

	strong {
		font-size: 20px;
	}
}

.stats__post-track {
	flex: 1 1 60px;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
}

.stats__post-fill {
	display: block;
	width: var(--share);
	height: 100%;
	border-radius: 3px;
	background: var(--color-primary-element);
}

.stats__post-counts {
	display: flex;
	gap: 14px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;

	span {
		display: flex;
		gap: 4px;
		align-items: center;
	}
}

.stats__figures {
	display: flex;
	flex-wrap: wrap;
	gap: 12px 28px;
	margin: 10px 0 4px;

	li {
		display: flex;
		flex-direction: column;
	}

	strong {
		font-size: 26px;
		line-height: 1.1;
	}

	span {
		color: var(--color-text-maxcontrast);
	}

	em {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
		font-style: normal;
	}
}

.stats__best {
	display: flex;
	flex-direction: column;
	gap: 2px;

	a {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 14px;
		align-items: baseline;
		justify-content: space-between;
		padding: 8px;
		border-radius: var(--border-radius, 8px);

		&:hover {
			background: var(--color-background-hover);
		}
	}
}

.stats__best-text {
	flex: 1 1 260px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.stats__best-counts {
	display: flex;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	white-space: nowrap;

	span {
		display: flex;
		gap: 3px;
		align-items: center;
	}
}

.stats__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	justify-content: space-between;
	margin-block-end: 18px;
}

/* the switcher centres itself above a timeline; in a toolbar it sits at the
   start of the row, with the actions at the other end */
.stats__toolbar .stats__windows {
	margin: 0;
}

.stats__actions {
	display: flex;
	gap: 4px;
	align-items: center;
}

.stats__columns {
	display: grid;
	gap: 12px 24px;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));

	h4 {
		margin-block: 4px;
	}
}

.stats__badge {
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 100px);
	margin-inline-start: 6px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: .8em;
	font-weight: normal;
}

// the stacked months: one column per month, each column a pile of the three
// things an account does
.stats__stack {
	display: flex;
	gap: 3px;
	align-items: flex-end;
	height: 90px;

	li {
		display: flex;
		flex: 1 1 0;
		flex-direction: column;
		justify-content: flex-end;
		height: 100%;
		min-width: 0;
	}
}

.stats__stack-bars {
	display: flex;
	flex-direction: column-reverse;
	justify-content: flex-start;
	height: 100%;
	border-radius: 3px;
	overflow: hidden;
}

.stats__stack-part {
	display: block;
	width: 100%;
	min-height: 0;

	&--originals {
		background: var(--color-primary-element);
	}

	&--replies {
		background: var(--color-primary-element-light);
	}

	&--boosts {
		background: var(--color-border-dark);
	}
}

.stats__stack-label {
	padding-block-start: 4px;
	color: var(--color-text-maxcontrast);
	font-size: .75em;
	text-align: center;
}

.stats__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	padding-block-start: 10px;
	color: var(--color-text-maxcontrast);
	font-size: .85em;

	li {
		display: flex;
		gap: 6px;
		align-items: center;
	}
}

.stats__swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 3px;
}

.stats__bars {
	display: flex;
	gap: 3px;
	align-items: flex-end;
	height: 90px;

	li {
		display: flex;
		flex: 1 1 0;
		flex-direction: column;
		justify-content: flex-end;
		height: 100%;
		min-width: 0;
	}
}

.stats__bar {
	/* a column with nothing in it is still a column: the 2px keeps the
	   baseline readable instead of leaving a hole in the chart */
	height: max(2px, var(--height));
	border-radius: 3px 3px 0 0;
	background: var(--color-primary-element);
	transition: height .3s cubic-bezier(.22, 1, .36, 1);
}

.stats__bar--alt {
	background: var(--color-success, var(--color-primary-element));
}

.stats__bar-label {
	overflow: hidden;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 10px;
	text-align: center;
	white-space: nowrap;
}

.stats__rows {
	margin-top: 8px;
}

.stats__row {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 3px 0;

	dt {
		flex: 0 0 42%;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	dd {
		display: flex;
		flex: 1;
		gap: 8px;
		align-items: center;
		min-width: 0;
		margin: 0;
	}
}

.stats__meter {
	height: 8px;
	/* the share of the total, never narrower than a sliver that is visibly
	   not zero */
	width: max(3px, var(--share));
	border-radius: 4px;
	background: var(--color-primary-element-light, var(--color-primary-element));
}

.stats__row-value {
	font-size: 13px;
	font-variant-numeric: tabular-nums;
}

.stats__row-note {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
}

.stats__lead {
	margin-bottom: 6px;
}

.stats__figures--small {
	margin-top: 4px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);

	strong {
		font-size: 20px;
	}
}

// the same pill as a hashtag, for the things that are not hashtags: a
// language or a link target is a name and a count, not somewhere to go
.stats__list {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;

	a,
	> li > span {
		display: inline-flex;
		gap: 6px;
		align-items: baseline;
		padding: 3px 10px;
		border-radius: var(--border-radius-pill, 100px);
		background: var(--color-background-dark);
	}

	a:hover {
		background: var(--color-background-hover);
	}
}

.stats__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;

	a {
		display: inline-flex;
		gap: 6px;
		align-items: baseline;
		padding: 3px 10px;
		border-radius: var(--border-radius-pill, 100px);
		background: var(--color-background-dark);

		&:hover {
			background: var(--color-background-hover);
		}
	}
}

.stats__tag-count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.stats__window {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-align: center;
}

@media (prefers-reduced-motion: reduce) {
	.stats__bar {
		transition: none;
	}
}
</style>
