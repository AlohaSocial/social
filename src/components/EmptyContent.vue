<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timeline-empty" :class="{ 'timeline-empty--bare': !scene }">
		<NcEmptyContent :name="item.title" :description="item.description">
			<template v-if="scene || illustration" #icon>
				<AlohaScene v-if="scene" :scene="scene" class="timeline-empty__scene" />
				<component :is="illustration" v-else class="timeline-empty__illustration" />
			</template>
			<!-- An empty page that only says it is empty leaves the reader to
			     work out what to do about it. Where there is an obvious next
			     step it is offered here; where there is not — a tag with no
			     posts, a search that found nothing — there is no button, and
			     one invented for the sake of having one would be worse. -->
			<template v-if="item.action" #action>
				<NcButton variant="primary" :to="item.action.to" @click="item.action.to ? undefined : $emit('action')">
					{{ item.action.label }}
				</NcButton>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>

import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlohaScene, { SCENES } from './illustrations/AlohaScene.vue'
import NoMessages from './illustrations/NoMessages.vue'
import NobodyYet from './illustrations/NobodyYet.vue'
import NoReplies from './illustrations/NoReplies.vue'
import QuietTimeline from './illustrations/QuietTimeline.vue'

/**
 * The small drawings, by the name an empty state asks for them under.
 */
const ILLUSTRATIONS = {
	'no-messages': NoMessages,
	'no-replies': NoReplies,
	'quiet-timeline': QuietTimeline,
	'nobody-yet': NobodyYet,
}

export default {
	name: 'EmptyContent',
	components: {
		AlohaScene,
		NcButton,
		NcEmptyContent,
		NoMessages,
		NobodyYet,
		NoReplies,
		QuietTimeline,
	},

	props: {
		item: {
			type: Object,
			default: () => {},
		},
	},

	/** `action`: the button was pressed, for an action with no route of its own */
	emits: ['action'],

	computed: {
		/**
		 * The full-size scene this state asks for, if it names one that
		 * exists. See illustrations/AlohaScene.vue.
		 *
		 * @return {string|null}
		 */
		scene() {
			return SCENES.includes(this.item.scene) ? this.item.scene : null
		},

		/**
		 * The small drawing this state asks for, if it asks for one. A state
		 * has either this or a full-size scene, never both: the scenes hold
		 * 60% of the window open for themselves.
		 *
		 * @return {object|null}
		 */
		illustration() {
			return ILLUSTRATIONS[this.item.illustration] ?? null
		},
	},
}
</script>

<style scoped>
/*
 * `timeline-empty` rather than `empty-content`: NcEmptyContent's own root
 * carries that class, and a child component's root inherits the parent's scope
 * id — so a scoped rule on `.empty-content` here landed on both this wrapper
 * and the component inside it. Two elements were 60vh tall, one nested in the
 * other, and turning the outer one off left the inner one holding the page open.
 */
.timeline-empty {
	min-height: 60vh;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

/*
 * Most of the 60vh is room for the full-size scene. A state without one
 * is a small drawing over a line of text, and holding 60% of the window open
 * under it — which is what "No replies found" did under every post with no
 * replies — says the page is still loading something.
 */
.timeline-empty--bare {
	min-height: 0;
	padding: 20px 0 28px;
}

/*
 * And its words are an aside, not a heading: this sits under a post somebody
 * came to read, and at the h2 size NcEmptyContent gives it, "No replies yet"
 * was the loudest thing on the page it is the smallest part of.
 */
.timeline-empty--bare :deep(.empty-content__name) {
	font-size: 1rem;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.timeline-empty__illustration {
	display: block;
	/* it arrives with the same movement the big ones do */
	animation: empty-content-settle .45s cubic-bezier(.22, 1, .36, 1) both;
}

@media (prefers-reduced-motion: reduce) {
	.timeline-empty__illustration {
		animation: none;
	}
}

/* NcEmptyContent pins any svg in its icon slot to 64px, with !important and
   `.empty-content__icon svg`; this has to outrank that selector as well */
.timeline-empty .timeline-empty__scene {
	width: 256px !important;
	height: auto !important;
	max-width: 100% !important;
	max-height: none !important;
	/* it arrives at its own size and settles in */
	animation: empty-content-settle .45s cubic-bezier(.22, 1, .36, 1) both;
}

@keyframes empty-content-settle {
	from {
		opacity: 0;
		transform: scale(.94);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.timeline-empty__scene {
		animation: none;
	}
}

:deep(.empty-content__icon) {
	opacity: 1;
	/* NcEmptyContent sizes this for a 64px icon; the drawings set their own */
	width: auto;
	height: auto;
	margin-bottom: 20px;
}

/* a small drawing needs a fraction of that gap, and a state with no drawing
   at all needs none */
.timeline-empty--bare :deep(.empty-content__icon) {
	margin-bottom: 0;
}

.timeline-empty--bare:has(.timeline-empty__illustration) :deep(.empty-content__icon) {
	margin-bottom: 12px;
}
</style>
