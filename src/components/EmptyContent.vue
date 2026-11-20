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
			<!-- an obvious next step is offered here; where there is none, there is
			     no button -->
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
 * carries that class and inherits this scope id, so a scoped rule on
 * `.empty-content` would land on both elements.
 */
.timeline-empty {
	min-height: 60vh;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

/*
 * Most of the 60vh is room for the full-size scene; a state without one
 * holding the window open would look as if something were still loading.
 */
.timeline-empty--bare {
	min-height: 0;
	padding: 20px 0 28px;
}

/* and its words are an aside under a post, not a heading */
.timeline-empty--bare :deep(.empty-content__name) {
	font-size: 1rem;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.timeline-empty__illustration {
	display: block;
	/* it arrives with the same movement the big ones do */
	animation: empty-content-settle var(--social-motion-slow) var(--social-ease) both;
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
	animation: empty-content-settle var(--social-motion-slow) var(--social-ease) both;
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
