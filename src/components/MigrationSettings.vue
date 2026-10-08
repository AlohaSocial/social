<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="migration">
		<!--
			First, because it is what somebody arriving is looking for and the
			rest of this page is written for people who already know what an
			export is.
		-->
		<section class="migration__card migration__card--lead">
			<h4>
				<IconAccountArrowRight :size="20" />
				{{ t('social', 'Moving in') }}
			</h4>
			<p>
				{{ t('social', 'Coming from X, Instagram, TikTok or YouTube? The step-by-step way in: your posts, the accounts you followed where they can be found again, and a card to post where you used to be.') }}
			</p>
			<NcButton variant="primary" :to="{ name: 'switch' }">
				<template #icon>
					<IconAccountArrowRight :size="20" />
				</template>
				{{ t('social', 'Move in from another network') }}
			</NcButton>
		</section>

		<!-- out -->
		<section class="migration__card">
			<h4>
				<IconDownload :size="20" />
				{{ t('social', 'Export your data') }}
			</h4>
			<p>
				{{ t('social', 'A zip file holding your profile, the people you follow, your followers, the accounts you block and mute, your bookmarks and likes, and every post you have written — with the pictures and videos on those posts, and your profile banner, as files inside the archive rather than as links back to this server.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Your private key is deliberately not in it. An archive is an ordinary file that can be copied anywhere, and a key that could sign as you cannot be taken back once it has been seen.') }}
			</p>
			<NcButton
				variant="primary"
				:disabled="exporting"
				@click="exportArchive">
				<template #icon>
					<NcLoadingIcon v-if="exporting" :size="20" />
					<IconDownload v-else :size="20" />
				</template>
				{{ exporting ? t('social', 'Preparing the archive …') : t('social', 'Export') }}
			</NcButton>

			<h5>{{ t('social', 'Or one list at a time') }}</h5>
			<p>
				{{ t('social', 'The same lists of accounts as single CSV files, each written the way Mastodon writes it — so another server\'s importer reads them without being asked to understand a whole archive. The followers file is a record rather than something an import can re-create: a follower follows again, or their server is told by the move.') }}
			</p>
			<div class="migration__csv-buttons">
				<NcButton
					v-for="kind in csvKinds"
					:key="kind.name"
					:disabled="csvBusy !== ''"
					@click="downloadCsv(kind.name)">
					<template #icon>
						<NcLoadingIcon v-if="csvBusy === kind.name" :size="20" />
						<IconDownload v-else :size="20" />
					</template>
					{{ kind.label }}
				</NcButton>
			</div>
		</section>

		<!-- in -->
		<section class="migration__card">
			<h4>
				<IconUpload :size="20" />
				{{ t('social', 'Import an archive') }}
			</h4>
			<p>
				{{ t('social', 'Reads an archive from the Export button — or from a Nextcloud account export — back into this account. Nothing is deleted: your profile, follows, blocks, mutes, bookmarks and likes are restored alongside what is already here.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Posts in the archive are listed rather than published again, so importing cannot flood the timelines of people who follow you. Their pictures are put back on the posts this server still has, and your banner is restored.') }}
			</p>
			<!-- each file input here is opened by the button after it, which is
			     the control a keyboard and a screen reader reach; the input
			     itself is kept out of both, or it is an unnamed "Browse…" -->
			<input
				ref="archive"
				type="file"
				accept=".zip,application/zip"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				@change="importArchive">
			<NcButton :disabled="importing" @click="pick('archive')">
				<template #icon>
					<NcLoadingIcon v-if="importing" :size="20" />
					<IconUpload v-else :size="20" />
				</template>
				{{ importing ? t('social', 'Importing …') : t('social', 'Import') }}
			</NcButton>

			<ul v-if="importLog.length" class="migration__log">
				<li v-for="(line, index) in importLog" :key="index">
					{{ line }}
				</li>
			</ul>
		</section>

		<!-- from another server, starting from the old handle -->
		<section class="migration__card migration__card--move-in">
			<h4>
				<IconAccountArrowLeft :size="20" />
				{{ t('social', 'Move here from another server') }}
			</h4>
			<p>
				{{ t('social', 'Type the handle of your old account. This account is marked as also being that one, everyone it follows is followed from here, and its public posts are written here as yours, dated when you wrote them — all in the background. Your followers stay where they are until the last step, which is done on the old server.') }}
			</p>
			<div class="migration__move-in-form">
				<NcTextField
					v-model="moveHandle"
					class="migration__move-in-field"
					:label="t('social', 'Your old account')"
					placeholder="@you@mastodon.example"
					:disabled="moveInspecting || moveStarting"
					@keydown.enter="inspectMoveIn" />
				<NcButton :disabled="moveInspecting || moveHandle.trim() === ''" @click="inspectMoveIn">
					<template v-if="moveInspecting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Look it up') }}
				</NcButton>
			</div>
			<div v-if="moveAccount" class="migration__move-in-account">
				<img
					v-if="moveAccount.avatar"
					:src="moveAccount.avatar"
					alt=""
					class="migration__move-in-avatar">
				<div class="migration__move-in-who">
					<strong>{{ moveAccount.name }}</strong>
					<span class="migration__move-in-acct">@{{ moveAccount.acct }}</span>
					<p class="migration__note">
						{{ moveAccount.following.readable
							? n('social', 'It follows %n account.', 'It follows %n accounts.', moveAccount.following.total)
							: t('social', 'Its follows are hidden by its server; bring them with the file below.') }}
						{{ moveAccount.posts.readable
							? n('social', '%n public post can be brought over.', '%n public posts can be brought over.', moveAccount.posts.total)
							: t('social', 'Its posts cannot be read from here; bring them with the export below.') }}
						<template v-if="moveAccount.finishable">
							{{ t('social', 'Its server is Aloha Social too, so the last step — moving your followers — can be done from here once the run is through.') }}
						</template>
					</p>
				</div>
			</div>
			<template v-if="moveAccount">
				<NcCheckboxRadioSwitch
					v-model="moveFollows"
					class="migration__move-in-switch"
					:disabled="!moveAccount.following.readable || moveStarting">
					{{ t('social', 'Follow everyone it follows') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="movePosts"
					class="migration__move-in-switch"
					:disabled="!moveAccount.posts.readable || moveStarting">
					{{ t('social', 'Bring its public posts over, with their pictures') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="primary"
					:disabled="moveStarting || (!moveFollows && !movePosts)"
					@click="startMoveIn">
					<template v-if="moveStarting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Move here') }}
				</NcButton>
			</template>
			<p v-if="finish.status === 'done'" class="migration__move-in-finish migration__move-in-finish--done">
				{{ t('social', 'Done: {acct} told every server that knows you to follow this account instead. Your followers are on their way.', { acct: '@' + finish.acct }) }}
			</p>
			<template v-else-if="finishedMoveIn && finishedMoveIn.options?.finishable">
				<p class="migration__move-in-finish">
					{{ t('social', 'One step is left: your old server has to move your followers here. It is Aloha Social too, so you can do that from here — you will be asked to log in there and to agree to it.') }}
				</p>
				<p v-if="finish.status === 'failed'" class="migration__finish-error" role="alert">
					{{ t('social', 'That did not work: {reason}', { reason: finish.error }) }}
				</p>
				<NcButton variant="primary" :disabled="finishing" @click="finishFromHere">
					<template #icon>
						<NcLoadingIcon v-if="finishing" :size="20" />
						<IconAccountArrowLeft v-else :size="20" />
					</template>
					{{ t('social', 'Finish the move from here') }}
				</NcButton>
			</template>
			<p v-else-if="finishedMoveIn" class="migration__move-in-finish">
				{{ t('social', 'One step is left, on your old server: tell it to move your followers here. On Mastodon that is Preferences → Account → Move to a different account; enter {handle} there.', { handle: ownHandle || t('social', 'your handle here') }) }}
			</p>
			<div v-if="finishedMoveIn && finishedMoveIn.options?.posts" class="migration__move-in-again">
				<p class="migration__note">
					{{ t('social', 'Posts written on the old account since the copy are not picked up by anything. Running the copy again brings over only what is new; nothing already here is written twice.') }}
				</p>
				<NcButton :disabled="moveStarting || moveInRunning" @click="copyNewerPosts">
					<template #icon>
						<NcLoadingIcon v-if="moveStarting" :size="20" />
						<IconPostOutline v-else :size="20" />
					</template>
					{{ t('social', 'Copy newer posts from {acct}', { acct: '@' + finishedMoveIn.options.acct }) }}
				</NcButton>
			</div>
		</section>

		<!-- from elsewhere -->
		<section class="migration__card">
			<h4>
				<IconAccountArrowRight :size="20" />
				{{ t('social', 'Coming from another network') }}
			</h4>
			<p>
				{{ t('social', 'The fediverse is one network with many doors. Mastodon, Pixelfed, GoToSocial, Akkoma, Misskey and this app all speak ActivityPub, so an account here can follow and be followed by any of them — and what you bring with you is mostly the list of people you had found.') }}
			</p>

			<ul v-if="imports.length > 0" class="migration__imports" aria-live="polite">
				<li
					v-for="job in imports"
					:key="job.id"
					class="migration__import"
					:class="'migration__import--' + job.status">
					<span class="migration__import-kind">{{ kindLabel(job.kind) }}</span>
					<span class="migration__result">{{ importSummary(job) }}</span>
					<ul v-if="failuresOf(job).length > 0" class="migration__failures">
						<li v-for="[what, why] in failuresOf(job)" :key="what">
							<span class="migration__failure-what">{{ what }}</span> — {{ why }}
						</li>
						<li v-if="job.failed > failuresOf(job).length">
							{{ n('social', 'and %n more', 'and %n more', job.failed - failuresOf(job).length) }}
						</li>
					</ul>
					<NcButton
						v-if="job.status === 'done' || job.status === 'failed'"
						variant="tertiary"
						:aria-label="t('social', 'Dismiss')"
						:title="t('social', 'Dismiss')"
						@click="dismissImport(job.id)">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>

			<h5>{{ t('social', 'Bring your follows with you') }}</h5>
			<p>
				{{ t('social', 'Every one of those servers exports the people you follow — most as a following_accounts.csv, Pixelfed as pixelfed-following.json. Upload that file and each account is followed again from here. A follow is an agreement between two servers, so it has to be asked for again — it cannot be copied out of a file.') }}
			</p>
			<input
				ref="follows"
				type="file"
				accept=".csv,text/csv,.json,application/json"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				@change="importFollows">
			<NcButton :disabled="followsBusy" @click="pick('follows')">
				<template #icon>
					<NcLoadingIcon v-if="followsBusy" :size="20" />
					<IconAccountMultiplePlus v-else :size="20" />
				</template>
				{{ followsBusy ? t('social', 'Following …') : t('social', 'Import follows from a file') }}
			</NcButton>

			<h5>{{ t('social', 'Bring your blocks, mutes, lists, bookmarks and blocked domains') }}</h5>
			<p>
				{{ t('social', 'The rest of what the same export holds. Blocks and mutes are decisions this account makes on its own, so they apply the moment the file is read — and a block federates, exactly as blocking somebody from here does. Bookmarks are the addresses of posts: each is fetched from where it lives and marked here, for you alone. A blocked domain hides a whole server from you, as it did there.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Import your follows first and your lists after. A list here can only hold accounts you follow, as on Mastodon, so anybody you have not followed again yet is counted as skipped rather than followed by a button that says lists. A list you already have is filled rather than made twice.') }}
			</p>
			<div class="migration__csv-buttons">
				<input
					ref="blocks"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'blocks')">
				<NcButton :disabled="csvImport !== ''" @click="pick('blocks')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'blocks'" :size="20" />
						<IconCancel v-else :size="20" />
					</template>
					{{ t('social', 'Import blocks') }}
				</NcButton>
				<input
					ref="mutes"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'mutes')">
				<NcButton :disabled="csvImport !== ''" @click="pick('mutes')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'mutes'" :size="20" />
						<IconVolumeOff v-else :size="20" />
					</template>
					{{ t('social', 'Import mutes') }}
				</NcButton>
				<input
					ref="lists"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'lists')">
				<NcButton :disabled="csvImport !== ''" @click="pick('lists')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'lists'" :size="20" />
						<IconFormatListBulleted v-else :size="20" />
					</template>
					{{ t('social', 'Import lists') }}
				</NcButton>
				<input
					ref="bookmarks"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'bookmarks')">
				<NcButton :disabled="csvImport !== ''" @click="pick('bookmarks')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'bookmarks'" :size="20" />
						<IconBookmarkOutline v-else :size="20" />
					</template>
					{{ t('social', 'Import bookmarks') }}
				</NcButton>
				<input
					ref="domain_blocks"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'domain_blocks')">
				<NcButton :disabled="csvImport !== ''" @click="pick('domain_blocks')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'domain_blocks'" :size="20" />
						<IconDomainOff v-else :size="20" />
					</template>
					{{ t('social', 'Import blocked domains') }}
				</NcButton>
			</div>

			<h5>{{ t('social', 'Bring your posts with you') }}</h5>
			<p>
				{{ t('social', 'The one thing moving has never carried. Upload the export from your old server and the posts in it are written here as yours, dated when you wrote them, with their pictures.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Nothing is sent to anybody: your followers do not get years of posts in one afternoon, because nothing here is published again. Boosts and direct messages are left out, and a reply keeps the post it answers where the file holds both. Importing the same file twice changes nothing the second time.') }}
			</p>
			<NcCheckboxRadioSwitch v-model="fetchMedia" type="switch" class="migration__media-switch">
				{{ t('social', 'Fetch the pictures from the old server') }}
			</NcCheckboxRadioSwitch>
			<p class="migration__note">
				{{ t('social', 'An archive usually holds the files themselves and they are used as they are. A file that only lists where its pictures are — Pixelfed writes one — needs them fetched, which tells that server the import is happening and only works while it is still running.') }}
			</p>
			<input
				ref="posts"
				type="file"
				accept=".zip,application/zip,.json,application/json"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				@change="importPosts">
			<NcButton :disabled="postsBusy" @click="pick('posts')">
				<template #icon>
					<NcLoadingIcon v-if="postsBusy" :size="20" />
					<IconPostOutline v-else :size="20" />
				</template>
				{{ postsBusy ? t('social', 'Writing your posts …') : t('social', 'Import posts from an export') }}
			</NcButton>
			<p class="migration__note">
				{{ t('social', 'At most 2000 posts at a time; run it again to carry on. An archive too large for a browser to upload can be imported by an administrator with occ social:account:import-posts.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Coming from Instagram? The same button reads its archive — ask Instagram for your information in JSON, not HTML. Your posts and reels arrive with their pictures and captions. An Instagram post does not record who could see it, so each one is posted with your own default visibility; what you shared for 24 hours, your archived posts and deleted ones are left where they are.') }}
			</p>

			<h5>{{ t('social', 'Where to find that file') }}</h5>
			<ul class="migration__list">
				<li>
					<strong>{{ t('social', 'Mastodon') }}</strong>
					{{ t('social', '— Preferences → Import and export → Data export → Follows (CSV). The archive there also holds your posts and media.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Pixelfed') }}</strong>
					{{ t('social', '— Settings → Data export → Following (JSON), which writes pixelfed-following.json: a list of account addresses rather than a CSV, and read here all the same. Photos come across as posts once you follow the accounts again.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Instagram') }}</strong>
					{{ t('social', '— Settings → Accounts Centre → Your information and permissions → Download your information, and choose JSON. The HTML download holds the pages and not the posts. The posts and reels in it come over with the button above, and the accounts it says you follow can be looked up on Threads — which does federate — in Moving in.') }}
				</li>
				<li>
					<strong>{{ t('social', 'GoToSocial and Akkoma') }}</strong>
					{{ t('social', '— Settings → Export, which writes the same Mastodon-shaped CSV.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Threads') }}</strong>
					{{ t('social', '— Threads accounts that have turned fediverse sharing on can be followed from here directly, and a Threads name is the same as the Instagram one. There is no follow list to export, so Moving in looks them up from the Instagram archive instead.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Bluesky and X') }}</strong>
					{{ t('social', '— neither carries a follow list this can read: X exports the accounts it follows as numbers rather than names, and nothing on either side federates a follow. X posts can still be imported with the button above. Bluesky accounts can be followed through a bridge if the other side has opted in.') }}
				</li>
			</ul>

			<h5>{{ t('social', 'Accounts you also answer to') }}</h5>
			<p>
				{{ t('social', 'Before your old server will send your followers here, it wants this account to say it is also you. Add the old account\'s address and it does. Nothing is sent to anybody by this — it is a note this server keeps about an account it owns — and you can take it off again at any time.') }}
			</p>
			<ul v-if="aliases.length > 0" class="migration__aliases">
				<li v-for="alias in aliases" :key="alias" class="migration__alias">
					<span class="migration__alias-id">{{ alias }}</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('social', 'Remove this alias')"
						:title="t('social', 'Remove this alias')"
						:disabled="aliasBusy"
						@click="removeAlias(alias)">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<div class="migration__alias-add">
				<NcTextField
					v-model="aliasInput"
					class="migration__alias-field"
					:label="t('social', 'The old account')"
					placeholder="@you@pixelfed.social"
					:disabled="aliasBusy"
					@keydown.enter="addAlias" />
				<NcButton :disabled="aliasBusy || aliasInput.trim() === ''" @click="addAlias">
					<template v-if="aliasBusy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Add') }}
				</NcButton>
			</div>
			<p class="migration__note">
				{{ t('social', 'The handle as you would give it to somebody — or, if you have it, the address its server publishes, like https://pixelfed.social/users/you.') }}
			</p>
		</section>

		<!-- away -->
		<section class="migration__card migration__card--move-out">
			<h4>
				<IconAccountArrowRight :size="20" />
				{{ t('social', 'Move your account away') }}
			</h4>
			<template v-if="moveStatus && moveStatus.moved_to">
				<p>
					{{ t('social', 'This account moved to {target} on {date}. Its followers were told to follow the new account; nothing is posted from here while it stays moved.', { target: moveStatus.moved_to, date: dateOf(moveStatus.moved_at) }) }}
				</p>
				<p class="migration__note">
					{{ t('social', 'Undoing the move lets you post and follow from here again. Your followers do not come back by themselves: their servers acted on the move when it arrived.') }}
				</p>
				<NcButton :disabled="moveOutBusy" @click="undoMove">
					<template v-if="moveOutBusy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Undo the move') }}
				</NcButton>
			</template>
			<template v-else>
				<p>
					{{ t('social', 'Tell every server that knows you to follow your new account instead of this one. First, on the new account, name this one as an account you also answer to — on Mastodon that is Preferences → Account → Moving from a different account — and then type it here. Your posts stay where they are: a move carries the followers, never the content.') }}
				</p>
				<p v-if="moveStatus && moveStatus.can_move_at * 1000 > Date.now()" class="migration__note">
					{{ t('social', 'This account moved on {date} and can move again on {next}.', { date: dateOf(moveStatus.moved_at), next: dateOf(moveStatus.can_move_at) }) }}
				</p>
				<template v-else>
					<div class="migration__move-out-form">
						<NcTextField
							v-model="moveTarget"
							class="migration__move-out-field"
							:label="t('social', 'Your new account')"
							placeholder="@you@new.example"
							:disabled="moveOutBusy" />
						<NcTextField
							v-model="moveConfirm"
							class="migration__move-out-field"
							:label="t('social', 'Type your handle here to confirm')"
							:placeholder="ownHandle || '@you@this.server'"
							:disabled="moveOutBusy" />
					</div>
					<p class="migration__note">
						{{ t('social', 'This cannot be taken back: it federates to every server that knows you. You will be asked for your password.') }}
					</p>
					<NcButton
						variant="error"
						:disabled="moveOutBusy || moveTarget.trim() === '' || moveConfirm.trim() === ''"
						@click="moveOut">
						<template v-if="moveOutBusy" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('social', 'Move my followers to the new account') }}
					</NcButton>
				</template>
			</template>
		</section>

		<BlueskyMoveAway />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '../services/toast.js'
import { confirmPassword } from '../services/externalApi.js'
import BlueskyMoveAway from './BlueskyMoveAway.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccountArrowLeft from 'vue-material-design-icons/AccountArrowLeft.vue'
import IconAccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconCancel from 'vue-material-design-icons/Cancel.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconBookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import IconDomainOff from 'vue-material-design-icons/DomainOff.vue'
import IconDownload from 'vue-material-design-icons/Download.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconPostOutline from 'vue-material-design-icons/PostOutline.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { n, t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

/**
 * Taking your account out, and bringing one in: the body of the Migration
 * page. The heading and the sentence under it belong to the page, so they
 * live in `views/Migration.vue` rather than being repeated here.
 */
export default {
	name: 'MigrationSettings',

	components: {
		BlueskyMoveAway,
		IconAccountArrowLeft,
		IconAccountArrowRight,
		IconAccountMultiplePlus,
		IconCancel,
		IconClose,
		IconBookmarkOutline,
		IconDomainOff,
		IconDownload,
		IconFormatListBulleted,
		IconPostOutline,
		IconUpload,
		IconVolumeOff,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			exporting: false,
			importing: false,
			followsBusy: false,
			postsBusy: false,
			/** whether a picture named only by its address may be fetched from the old server */
			fetchMedia: true,
			/** @type {string[]} what the last import reported */
			importLog: [],
			/** @type {string} which single-file export is being prepared */
			csvBusy: '',
			/** @type {string} which CSV is being read in */
			csvImport: '',
			/** @type {string} the old account, as typed */
			moveHandle: '',
			moveInspecting: false,
			/** @type {object|null} the old account as the server described it */
			moveAccount: null,
			moveFollows: true,
			movePosts: true,
			moveStarting: false,
			/** @type {string} this account's handle, for the last step on the old server */
			ownHandle: '',
			/** @type {object|null} whether this account moved, where and when, and when it may move */
			moveStatus: null,
			moveTarget: '',
			moveConfirm: '',
			moveOutBusy: false,
			/** @type {Array<object>} the imports this account asked for, newest first, as the server lists them */
			imports: [],
			/** @type {number|null} the timer behind the next poll of the imports, while one runs */
			pollTimer: null,
			/** @type {string[]} the accounts this one also answers to */
			aliases: [],
			/** @type {string} the address being added */
			aliasInput: '',
			aliasBusy: false,
			/**
			 * Where a move finished from here stands, as the server keeps it:
			 * `none`, `pending`, `done` or `failed`, with the old account.
			 */
			finish: { status: 'none', acct: '', at: 0, error: '' },
			finishing: false,
		}
	},

	computed: {
		/** @return {object|undefined} a move here that has run */
		finishedMoveIn() {
			return this.imports.find((job) => job.kind === 'move_in' && job.status === 'done')
		},

		/** @return {boolean} whether a move here is queued or running, which the server allows one of at a time */
		moveInRunning() {
			return this.imports.some((job) => job.kind === 'move_in' && (job.status === 'queued' || job.status === 'running'))
		},

		/**
		 * The lists of accounts that can be downloaded one at a time.
		 *
		 * Named here rather than in the loop so the labels are translated
		 * strings in the source and not something built out of a route name.
		 *
		 * @return {Array<{name: string, label: string}>}
		 */
		csvKinds() {
			return [
				{ name: 'following', label: t('social', 'Follows') },
				{ name: 'followers', label: t('social', 'Followers') },
				{ name: 'blocks', label: t('social', 'Blocks') },
				{ name: 'mutes', label: t('social', 'Mutes') },
				{ name: 'lists', label: t('social', 'Lists') },
				{ name: 'bookmarks', label: t('social', 'Bookmarks') },
				{ name: 'domain_blocks', label: t('social', 'Blocked domains') },
			]
		},
	},

	mounted() {
		this.loadAliases()
		this.loadImports()
		this.loadMoveStatus()
		this.loadOwnHandle()
		this.loadFinish()
	},

	beforeUnmount() {
		window.clearTimeout(this.pollTimer)
	},

	methods: {
		/** @param {string} name the ref of the file input behind a button */
		pick(name) {
			/** @type {HTMLInputElement} */ (this.$refs[name]).click()
		},

		t,

		n,

		/**
		 * Who the old account is, and what its server lets this one read.
		 *
		 * @return {Promise<void>}
		 */
		async inspectMoveIn() {
			const handle = this.moveHandle.trim()
			if (handle === '' || this.moveInspecting) {
				return
			}
			this.moveInspecting = true
			this.moveAccount = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/move-in/inspect'), { handle })
				this.moveAccount = data.account
				this.moveFollows = Boolean(data.account?.following?.readable)
				this.movePosts = Boolean(data.account?.posts?.readable)
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not find that account'))
			} finally {
				this.moveInspecting = false
			}
		},

		/**
		 * Sets the alias and queues the follows and the posts.
		 *
		 * @return {Promise<void>}
		 */
		async startMoveIn() {
			if (!this.moveAccount || this.moveStarting) {
				return
			}
			const queued = await this.queueMoveIn(this.moveHandle.trim(), this.moveFollows, this.movePosts)
			if (queued) {
				this.moveAccount = null
				this.moveHandle = ''
				this.loadAliases()
			}
		},

		/**
		 * Where a move finished from here stands — asked when the page opens,
		 * which is where the old server sends the person back to.
		 *
		 * @return {Promise<void>}
		 */
		async loadFinish() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/move-in/finish'))
				this.finish = { status: 'none', acct: '', at: 0, error: '', ...data }
			} catch (error) {
				logger.debug('Could not read where the move stands', { error })
			}
		},

		/**
		 * Has the old server — this app too — move the followers here: the
		 * person is sent there to log in and agree, and comes back to this page.
		 *
		 * @return {Promise<void>}
		 */
		async finishFromHere() {
			const from = this.finishedMoveIn?.options?.acct
			if (!from || this.finishing) {
				return
			}
			this.finishing = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/move-in/finish'), { handle: '@' + from })
				this.leaveFor(data.authorize_url)
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not start the move'))
				this.finishing = false
			}
		},

		/**
		 * @param {string} url where the browser goes next
		 */
		leaveFor(url) {
			window.location.assign(url)
		},

		/**
		 * Runs the copy again for the account a finished move came from, posts
		 * only: what was written there since comes over, what is here already
		 * is left alone by the import itself.
		 *
		 * @return {Promise<void>}
		 */
		async copyNewerPosts() {
			const from = this.finishedMoveIn?.options?.acct
			if (!from || this.moveStarting) {
				return
			}
			await this.queueMoveIn('@' + from, false, true)
		},

		/**
		 * Queues a move here from `handle` and starts watching the list.
		 *
		 * @param {string} handle the old account
		 * @param {boolean} follows whether to follow who it follows
		 * @param {boolean} posts whether to copy its posts
		 * @return {Promise<boolean>} whether the server took it
		 */
		async queueMoveIn(handle, follows, posts) {
			this.moveStarting = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/move-in'), {
					handle,
					follows: follows ? '1' : '0',
					posts: posts ? '1' : '0',
					fetch_media: this.fetchMedia ? '1' : '0',
				})
				if (data?.import) {
					this.imports = [data.import, ...this.imports.filter((job) => job.id !== data.import.id)]
				}
				showSuccess(t('social', 'Moving — it runs in the background, and this page shows where it gets to'))
				this.schedulePoll()

				return true
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not start the move'))

				return false
			} finally {
				this.moveStarting = false
			}
		},

		/**
		 * Whether this account moved, where and when, and when it may move.
		 *
		 * @return {Promise<void>}
		 */
		async loadMoveStatus() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/move'))
				this.moveStatus = data
			} catch (error) {
				logger.error('Failed to load the move status', { error })
			}
		},

		/**
		 * Moves the account away, after the password and the typed handle.
		 *
		 * @return {Promise<void>}
		 */
		async moveOut() {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.moveOutBusy = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/move'), {
					target: this.moveTarget.trim(),
					confirm: this.moveConfirm.trim(),
				})
				this.moveStatus = data
				this.moveTarget = ''
				this.moveConfirm = ''
				showSuccess(t('social', 'Your followers are being told to follow {target}', { target: data?.target?.acct ?? data?.moved_to ?? '' }))
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not move the account'))
			} finally {
				this.moveOutBusy = false
			}
		},

		/**
		 * Takes the redirect off again.
		 *
		 * @return {Promise<void>}
		 */
		async undoMove() {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.moveOutBusy = true
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/migration/move'))
				this.moveStatus = data
				showSuccess(t('social', 'This account is no longer marked as moved'))
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not undo the move'))
			} finally {
				this.moveOutBusy = false
			}
		},

		/**
		 * @param {number|null} timestamp seconds since the epoch
		 * @return {string} the day, in the reader's locale
		 */
		dateOf(timestamp) {
			return timestamp ? new Date(timestamp * 1000).toLocaleDateString() : ''
		},

		/**
		 * This account's handle, for the sentence that names the last step.
		 *
		 * @return {Promise<void>}
		 */
		async loadOwnHandle() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/announcement'))
				this.ownHandle = data?.handle ?? ''
			} catch (error) {
				logger.debug('No announcement for the own handle', { error })
			}
		},

		/**
		 * The accounts this one also answers to.
		 *
		 * Read on mount rather than handed over with the page: the section is
		 * below the fold of a settings page most people never open, and a
		 * request that costs nothing until then is cheaper than state on every
		 * page load.
		 *
		 * @return {Promise<void>}
		 */
		async loadAliases() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/aliases'))
				this.aliases = Array.isArray(data.aliases) ? data.aliases : []
			} catch (error) {
				logger.error('Failed to load the aliases', { error })
			}
		},

		/** @return {Promise<void>} */
		async addAlias() {
			const alias = this.aliasInput.trim()
			if (alias === '' || this.aliasBusy) {
				return
			}

			this.aliasBusy = true
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/aliases'),
					{ alias },
				)
				this.aliases = data.aliases
				this.aliasInput = ''
				showSuccess(t('social', 'This account now says it is also that one'))
			} catch (error) {
				// the server refuses an address that is not an account's own,
				// and says which; that sentence is the whole of the help there is
				showError(error.response?.data?.error ?? t('social', 'Could not add the alias'))
			} finally {
				this.aliasBusy = false
			}
		},

		/**
		 * @param {string} alias the address to stop answering to
		 * @return {Promise<void>}
		 */
		async removeAlias(alias) {
			this.aliasBusy = true
			try {
				const { data } = await axios.delete(
					generateUrl('apps/social/api/v1/migration/aliases'),
					{ data: { alias } },
				)
				this.aliases = data.aliases
			} catch {
				showError(t('social', 'Could not remove the alias'))
			} finally {
				this.aliasBusy = false
			}
		},

		/**
		 * The archive is built on the server and handed over as a blob, then
		 * saved through a link this code makes and clicks: the route needs the
		 * session, so a plain `window.open` of it would work, but an error
		 * would then replace the page with a JSON body instead of being caught
		 * here and said out loud.
		 */
		async exportArchive() {
			this.exporting = true
			try {
				const response = await axios.get(
					generateUrl('apps/social/api/v1/migration/export'),
					{ responseType: 'blob' },
				)
				const url = URL.createObjectURL(response.data)
				const link = document.createElement('a')
				link.href = url
				link.download = this.filenameOf(response) || 'social-export.zip'
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
				showSuccess(t('social', 'Your archive is downloading'))
			} catch (error) {
				logger.error('The export failed', { error })
				showError(t('social', 'Could not export your data'))
			} finally {
				this.exporting = false
			}
		},

		/**
		 * @param {object} response what the server answered
		 * @return {string} the name the server gave the file, or ''
		 */
		filenameOf(response) {
			const disposition = response?.headers?.['content-disposition'] ?? ''
			const match = /filename="?([^";]+)"?/.exec(disposition)

			return match ? match[1] : ''
		},

		/**
		 * @param {Event} event the file input's change
		 */
		async importArchive(event) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.importing = true
			this.importLog = []
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/import'),
					form,
				)
				this.importLog = Array.isArray(data?.log) ? data.log : []
				showSuccess(t('social', 'Your archive has been imported'))
			} catch (error) {
				logger.error('The import failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import that archive'))
			} finally {
				this.importing = false
				// cleared, or choosing the same file twice fires no change
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * @param {Event} event the file input's change
		 */
		/**
		 * Queues the export: the server keeps the file and writes the posts in
		 * the background, where a long history is hours of work rather than a
		 * request against the web server's timeout. The list above shows where
		 * it has got to.
		 *
		 * @param {Event} event the file input's change
		 * @return {Promise<void>}
		 */
		async importPosts(event) {
			this.postsBusy = true
			try {
				await this.queueUpload(event, 'posts', { fetch_media: this.fetchMedia ? '1' : '0' })
			} finally {
				this.postsBusy = false
			}
		},

		/**
		 * One list of accounts, saved as the CSV the server writes.
		 *
		 * Fetched as a blob and saved through a link this code makes, the same
		 * way the archive is: the route needs the session, so opening it in a
		 * tab would work — and an error would then replace the page with a
		 * JSON body instead of being caught here and said out loud.
		 *
		 * @param {string} kind which list: following, followers, blocks, mutes or lists
		 * @return {Promise<void>}
		 */
		async downloadCsv(kind) {
			this.csvBusy = kind
			try {
				const response = await axios.get(
					generateUrl('apps/social/api/v1/migration/export/{kind}', { kind }),
					{ responseType: 'blob' },
				)
				const url = URL.createObjectURL(response.data)
				const link = document.createElement('a')
				link.href = url
				link.download = this.filenameOf(response) || kind + '.csv'
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
			} catch (error) {
				logger.error('A CSV export failed', { error, kind })
				showError(t('social', 'Could not export that list'))
			} finally {
				this.csvBusy = ''
			}
		},

		/**
		 * Queues a blocks, mutes, lists, bookmarks or blocked-domains CSV.
		 *
		 * @param {Event} event the file input's change
		 * @param {string} kind blocks, mutes, lists, bookmarks or domain_blocks
		 * @return {Promise<void>}
		 */
		async importCsv(event, kind) {
			this.csvImport = kind
			try {
				await this.queueUpload(event, kind)
			} finally {
				this.csvImport = ''
			}
		},

		/**
		 * @param {Event} event the file input's change
		 * @return {Promise<void>}
		 */
		async importFollows(event) {
			this.followsBusy = true
			try {
				await this.queueUpload(event, 'follows')
			} finally {
				this.followsBusy = false
			}
		},

		/**
		 * Hands an upload to the server to queue, and starts watching the list.
		 *
		 * @param {Event} event the file input's change
		 * @param {string} kind follows, blocks, mutes, lists or posts: the route and the import's kind
		 * @param {Object<string, string>} fields anything to send beside the file
		 * @return {Promise<void>}
		 */
		async queueUpload(event, kind, fields = {}) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}
			try {
				const form = new FormData()
				form.append('file', file)
				for (const [name, value] of Object.entries(fields)) {
					form.append(name, value)
				}
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/{kind}', { kind }),
					form,
				)
				if (data?.import) {
					this.imports = [data.import, ...this.imports.filter((job) => job.id !== data.import.id)]
				}
				showSuccess(t('social', 'Queued — it runs in the background, and this page shows where it gets to'))
				this.schedulePoll()
			} catch (error) {
				logger.error('Queueing an import failed', { error, kind })
				showError(error?.response?.data?.error || t('social', 'Could not start that import'))
			} finally {
				// cleared, or choosing the same file twice fires no change
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * The imports this account asked for, and where each has got to.
		 * Polled while any is still queued or running.
		 *
		 * @return {Promise<void>}
		 */
		async loadImports() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/imports'))
				this.imports = Array.isArray(data?.imports) ? data.imports : []
			} catch (error) {
				logger.error('Failed to load the imports', { error })
			}
			if (this.imports.some((job) => job.status === 'queued' || job.status === 'running')) {
				this.schedulePoll()
			}
		},

		/** Asks again in a few seconds; one timer at a time. */
		schedulePoll() {
			window.clearTimeout(this.pollTimer)
			this.pollTimer = window.setTimeout(() => this.loadImports(), 3000)
		},

		/**
		 * @param {number} id the import to take off the list
		 * @return {Promise<void>}
		 */
		async dismissImport(id) {
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/migration/imports/{id}', { id }))
				this.imports = Array.isArray(data?.imports) ? data.imports : this.imports.filter((job) => job.id !== id)
			} catch {
				showError(t('social', 'Could not dismiss that import'))
			}
		},

		/**
		 * @param {string} kind an import's kind
		 * @return {string} what to call it
		 */
		kindLabel(kind) {
			return {
				follows: t('social', 'Follows'),
				blocks: t('social', 'Blocks'),
				mutes: t('social', 'Mutes'),
				lists: t('social', 'Lists'),
				posts: t('social', 'Posts'),
				move_in: t('social', 'Move here'),
				bookmarks: t('social', 'Bookmarks'),
				domain_blocks: t('social', 'Blocked domains'),
			}[kind] ?? kind
		},

		/**
		 * What could not be done, by name, with the reason — the first few.
		 *
		 * The server keeps them under three keys, one per importer: `failed`
		 * for the account lists, `failures` for the follows of a move-in and
		 * `post_failures` for its posts.
		 *
		 * @param {object} job the import as the server lists it
		 * @return {Array<[string, string]>} what, and why
		 */
		failuresOf(job) {
			if (job.status !== 'done' || !job.report) {
				return []
			}
			const named = { ...(job.report.failed ?? {}), ...(job.report.failures ?? {}), ...(job.report.post_failures ?? {}) }

			return Object.entries(named).slice(0, 5)
		},

		/**
		 * Where an import has got to, or what it came to, in one sentence.
		 *
		 * @param {object} job the import as the server lists it
		 * @return {string}
		 */
		importSummary(job) {
			if (job.status === 'queued') {
				return t('social', 'Waiting to start — it runs in the background on the next cron run.')
			}
			if (job.status === 'running') {
				return job.total > 0
					? t('social', '{done} of {total} …', { done: job.done, total: job.total })
					: t('social', 'Starting …')
			}
			if (job.status === 'failed') {
				return t('social', 'Failed: {reason}', { reason: job.report?.error ?? t('social', 'unknown reason') })
			}
			if (job.kind === 'move_in') {
				return t(
					'social',
					'{followed} accounts followed and {imported} posts brought over; {skipped} were already here or not for bringing, {failed} could not be done',
					{
						followed: job.report?.followed ?? 0,
						imported: job.report?.imported ?? 0,
						skipped: job.skipped,
						failed: job.failed,
					},
				)
			}
			if (job.kind === 'posts') {
				return t(
					'social',
					'{imported} posts written with {media} of their pictures; {skipped} were already here or not posts to bring over, {failed} could not be written',
					{ imported: job.done, media: job.report?.media ?? 0, skipped: job.skipped, failed: job.failed },
				)
			}
			if (job.kind === 'lists') {
				return t(
					'social',
					'{lists} lists made, {added} accounts added, {skipped} skipped because you do not follow them, {failed} could not be reached',
					{ lists: job.report?.lists ?? 0, added: job.done, skipped: job.skipped, failed: job.failed },
				)
			}
			if (job.kind === 'follows') {
				return t(
					'social',
					'{followed} followed, {skipped} skipped, {failed} could not be reached',
					{ followed: job.done, skipped: job.skipped, failed: job.failed },
				)
			}
			if (job.kind === 'bookmarks') {
				return t(
					'social',
					'{done} posts bookmarked, {skipped} lines were not the address of a post, {failed} could not be fetched',
					{ done: job.done, skipped: job.skipped, failed: job.failed },
				)
			}
			if (job.kind === 'domain_blocks') {
				return t(
					'social',
					'{done} servers blocked, {skipped} lines were not a domain, {failed} could not be blocked',
					{ done: job.done, skipped: job.skipped, failed: job.failed },
				)
			}
			return t(
				'social',
				'{done} applied, {skipped} skipped, {failed} could not be reached',
				{ done: job.done, skipped: job.skipped, failed: job.failed },
			)
		},

	},
}
</script>

<style scoped lang="scss">
.migration {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

	h2 {
		margin-bottom: 8px;
	}
}

.migration__hint {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.migration__csv-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

/* the way in, told apart from the four cards of machinery below it */
.migration__card--lead {
	border-inline-start: 4px solid var(--color-primary-element);
}

.migration__card {
	margin-bottom: 16px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-resting);

	/* the card's own heading, one level below the section's */
	h4 {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		font-size: 17px;
		font-weight: bold;
	}

	h5 {
		margin: 20px 0 6px;
		font-size: inherit;
		font-weight: bold;
	}

	p {
		margin-bottom: 12px;
	}
}

.migration__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.migration__aliases {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block: 8px;
}

.migration__alias {
	display: flex;
	align-items: center;
	gap: 8px;
}

.migration__alias-id {
	// an actor id is a URL and will not break on its own
	overflow-wrap: anywhere;
}

.migration__alias-add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 4px;
}

.migration__alias-field {
	max-width: 420px;
}

.migration__list {
	margin: 0 0 12px;
	padding: 0;
	list-style: none;

	li {
		margin-bottom: 6px;
		padding-inline-start: 14px;
		position: relative;

		&::before {
			content: '·';
			position: absolute;
			inset-inline-start: 2px;
		}
	}
}

.migration__log {
	margin-top: 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	font-size: 13px;
	list-style: none;
	max-height: 240px;
	overflow-y: auto;
}

.migration__media-switch {
	margin: 4px 0 2px;
}

.migration__move-in-form {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.migration__move-in-field {
	flex: 1;
	min-width: 220px;
}

.migration__move-in-account {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	margin: 12px 0;
}

.migration__move-in-avatar {
	width: 48px;
	height: 48px;
	border-radius: 50%;
	flex-shrink: 0;
}

.migration__move-in-who strong {
	display: block;
}

.migration__move-in-acct {
	color: var(--color-text-maxcontrast);
}

.migration__move-in-again {
	margin-top: 12px;

	.migration__note {
		margin-bottom: 8px;
	}
}

.migration__move-in-finish--done {
	font-weight: 600;
}

.migration__finish-error {
	color: var(--color-error-text, var(--color-error));
}

.migration__move-in-finish {
	margin-top: 12px;
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.migration__move-out-form {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.migration__move-out-field {
	flex: 1;
	min-width: 220px;
}

.migration__imports {
	list-style: none;
	margin: 0 0 12px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.migration__import {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-hover);
}

.migration__import-kind {
	font-weight: bold;
	min-width: 5em;
}

.migration__import .migration__result {
	flex: 1;
	margin: 0;
}

.migration__import--failed .migration__result {
	color: var(--color-error-text, var(--color-error));
}

.migration__failures {
	flex-basis: 100%;
	margin: 0 0 4px 0;
	padding-inline-start: 20px;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);

	li {
		overflow-wrap: anywhere;
	}
}

.migration__failure-what {
	font-family: var(--font-face-mono, monospace);
}

.migration__result {
	margin-top: 10px;
	font-weight: 500;
}
</style>
