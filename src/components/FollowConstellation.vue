<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		ref="sky"
		class="constellation"
		role="group"
		:aria-label="t('social', 'People you might know, drawn around you')">
		<!-- the ties: who each suggestion came through -->
		<svg
			class="constellation__lines"
			viewBox="0 0 100 100"
			preserveAspectRatio="none"
			aria-hidden="true">
			<line
				v-for="line in lines"
				:key="line.key"
				class="constellation__line"
				:class="[`constellation__line--${line.kind}`]"
				:x1="line.x1"
				:y1="line.y1"
				:x2="line.x2"
				:y2="line.y2" />
		</svg>

		<span class="constellation__you" :style="at(you)" aria-hidden="true">
			<img v-if="youAvatar" :src="youAvatar" alt="">
		</span>

		<span
			v-for="node in vias"
			:key="node.id"
			class="constellation__via"
			:style="at(node)"
			aria-hidden="true">
			@{{ shortHandle(node.handle) }}
		</span>

		<!-- the suggestions: a press follows, a drag moves the star and
		     leaves it where it was put -->
		<button
			v-for="(node, index) in stars"
			:key="node.id"
			type="button"
			class="constellation__star"
			:class="{
				'constellation__star--followed': isFollowed(node.account),
				'constellation__star--pending': isPending(node.account),
			}"
			:style="{ ...at(node), '--twinkle': (index % 5) * 0.6 }"
			:aria-label="labelFor(node)"
			:title="labelFor(node)"
			@pointerdown="grab(node, $event)"
			@click="press(node)">
			<img
				class="constellation__avatar"
				:src="node.account.avatar"
				alt=""
				draggable="false">
			<span class="constellation__name">{{ nameOf(node.account) }}</span>
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { markRaw } from 'vue'
import { buildGraph, settle, step } from '../utils/constellation.js'

/** the most frames the sky is animated for before it is left where it is */
const MAX_FRAMES = 240

/** how far a press may move before it counts as a drag, in pixels */
const DRAG_SLOP = 5

/**
 * "People you might know" as a sky rather than a list.
 *
 * The reader sits in the middle; the people they follow who led somewhere
 * sit around them; the suggestions float at the edge, tied to whoever they
 * came through. It settles by itself in a second or two, every star can be
 * dragged somewhere else, and pressing one follows it.
 *
 * Every star is a real button with a label that says who it is and why, so a
 * keyboard and a screen reader get the same list the list view gives them --
 * only laid out differently for everybody else. A reader who asked for less
 * motion gets the settled sky straight away.
 */
export default {
	name: 'FollowConstellation',

	props: {
		/** the suggestions as the follow-graph route answers them */
		suggestions: {
			type: /** @type {import('vue').PropType<Array<{ account: object, via?: string[] }>>} */ (Array),
			default: () => [],
		},

		/** the reader's avatar, for the middle */
		youAvatar: {
			type: String,
			default: '',
		},

		/** whether this visit has followed an account */
		isFollowed: {
			type: Function,
			default: () => false,
		},

		/** whether a follow of an account is in flight */
		isPending: {
			type: Function,
			default: () => false,
		},

		/** why a suggestion is here, as the list says it */
		reasonFor: {
			type: Function,
			default: () => '',
		},
	},

	emits: ['follow'],

	data() {
		return {
			/** bumped each frame, which is what redraws the non-reactive graph */
			frame: 0,
			graph: markRaw({ nodes: [], links: [] }),
			/** the animation frame waiting to run, null when the sky is still */
			loop: null,
			/** the star being pressed, while it is */
			held: null,
			/** the window listeners of a press in progress */
			onMove: null,
			onUp: null,
			/** set by a drag, so the click that ends it does not also follow */
			swallowClick: false,
		}
	},

	computed: {
		/** @return {object} the reader's node */
		you() {
			return this.graph.nodes[0] ?? { x: 0.5, y: 0.5 }
		},

		/** @return {object[]} the people the suggestions came through */
		vias() {
			return this.frame >= 0 ? this.graph.nodes.filter((node) => node.kind === 'via') : []
		},

		/** @return {object[]} the suggestions */
		stars() {
			return this.frame >= 0 ? this.graph.nodes.filter((node) => node.kind === 'suggestion') : []
		},

		/** @return {object[]} the ties, as SVG lines in a 100 by 100 box */
		lines() {
			if (this.frame < 0) {
				return []
			}

			const byId = new Map(this.graph.nodes.map((node) => [node.id, node]))

			return this.graph.links
				.map((link) => ({ link, a: byId.get(link.source), b: byId.get(link.target) }))
				.filter(({ a, b }) => a && b)
				.map(({ link, a, b }) => ({
					key: link.source + '>' + link.target,
					kind: b.kind,
					x1: a.x * 100,
					y1: a.y * 100,
					x2: b.x * 100,
					y2: b.y * 100,
				}))
		},
	},

	watch: {
		suggestions: {
			handler() {
				this.rebuild()
			},

			deep: false,
		},
	},

	mounted() {
		this.rebuild()
	},

	beforeUnmount() {
		this.stopLoop()
		this.release()
	},

	methods: {
		t,

		/** Lays the sky out afresh from the suggestions. */
		rebuild() {
			this.stopLoop()
			this.graph = markRaw(buildGraph(this.suggestions))
			if (this.stillness()) {
				settle(this.graph.nodes, this.graph.links)
				this.frame++

				return
			}

			this.startLoop()
		},

		/** @return {boolean} whether the reader asked for less motion */
		stillness() {
			return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
		},

		startLoop() {
			this.stopLoop()
			let frames = 0
			const tick = () => {
				const moved = step(this.graph.nodes, this.graph.links) + step(this.graph.nodes, this.graph.links)
				this.frame++
				frames++
				if (moved < 0.0005 || frames >= MAX_FRAMES) {
					this.loop = null

					return
				}
				this.loop = window.requestAnimationFrame(tick)
			}
			this.loop = window.requestAnimationFrame(tick)
		},

		stopLoop() {
			if (this.loop) {
				window.cancelAnimationFrame(this.loop)
				this.loop = null
			}
		},

		/**
		 * @param {object} node a node
		 * @return {object} where it is drawn, as a style binding
		 */
		at(node) {
			// read so that a new frame redraws, although the value is not used
			return this.frame >= 0
				? { left: (node.x * 100) + '%', top: (node.y * 100) + '%' }
				: {}
		},

		/**
		 * @param {string} handle a handle
		 * @return {string} its name part, which is what fits under a dot
		 */
		shortHandle(handle) {
			return String(handle).split('@')[0]
		},

		/**
		 * @param {object} account an account
		 * @return {string} what to call it
		 */
		nameOf(account) {
			return account.display_name || account.username || account.acct
		},

		/**
		 * @param {object} node a suggestion's node
		 * @return {string} who it is, why it is here, and what pressing does
		 */
		labelFor(node) {
			const suggestion = this.suggestions.find((one) => one.account?.acct === node.account.acct)
			const reason = suggestion ? this.reasonFor(suggestion) : ''
			if (this.isFollowed(node.account)) {
				return t('social', '{name}, followed. {reason}', { name: this.nameOf(node.account), reason })
			}

			return t('social', 'Follow {name}. {reason}', { name: this.nameOf(node.account), reason })
		},

		/**
		 * @param {object} node the star pressed
		 * @param {PointerEvent} event the press
		 */
		grab(node, event) {
			this.release()
			const box = /** @type {HTMLElement|undefined} */ (this.$refs.sky)?.getBoundingClientRect?.()
			if (!box || box.width === 0) {
				return
			}

			this.held = { node, startX: event.clientX, startY: event.clientY, box, dragged: false }
			this.onMove = (move) => this.drag(move)
			this.onUp = () => this.release()
			window.addEventListener('pointermove', this.onMove)
			window.addEventListener('pointerup', this.onUp)
		},

		/** @param {PointerEvent} event where the pointer is */
		drag(event) {
			const held = this.held
			if (!held) {
				return
			}

			if (!held.dragged && Math.hypot(event.clientX - held.startX, event.clientY - held.startY) < DRAG_SLOP) {
				return
			}

			held.dragged = true
			held.node.fixed = true
			held.node.x = Math.min(0.95, Math.max(0.05, (event.clientX - held.box.left) / held.box.width))
			held.node.y = Math.min(0.95, Math.max(0.05, (event.clientY - held.box.top) / held.box.height))
			if (!this.loop && !this.stillness()) {
				this.startLoop()
			} else {
				this.frame++
			}
		},

		/** The pointer let go: a star that was dragged stays where it was put. */
		release() {
			if (this.held?.dragged) {
				// the click that follows the pointerup belongs to the drag
				this.swallowClick = true
			}
			this.held = null
			if (this.onMove) {
				window.removeEventListener('pointermove', this.onMove)
				window.removeEventListener('pointerup', this.onUp)
				this.onMove = null
				this.onUp = null
			}
		},

		/** @param {object} node the star pressed */
		press(node) {
			if (this.swallowClick) {
				this.swallowClick = false

				return
			}

			if (this.isFollowed(node.account) || this.isPending(node.account)) {
				return
			}

			this.$emit('follow', node.account)
		},
	},
}
</script>

<style scoped lang="scss">
.constellation {
	position: relative;
	inline-size: 100%;
	block-size: min(70vh, 520px);
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	background:
		radial-gradient(circle at 50% 50%, var(--color-primary-element-light) 0, transparent 60%),
		var(--color-background-hover);
	touch-action: none;
}

.constellation__lines {
	position: absolute;
	inset: 0;
	inline-size: 100%;
	block-size: 100%;
}

.constellation__line {
	stroke: var(--color-border-maxcontrast);
	stroke-width: 1;
	vector-effect: non-scaling-stroke;
	opacity: .55;

	&--via {
		stroke: var(--color-primary-element);
		opacity: .7;
	}
}

.constellation__you,
.constellation__via,
.constellation__star {
	position: absolute;
	transform: translate(-50%, -50%);
}

.constellation__you {
	inline-size: 56px;
	block-size: 56px;
	border: 3px solid var(--color-primary-element);
	border-radius: 50%;
	background: var(--color-main-background);
	overflow: hidden;

	img {
		inline-size: 100%;
		block-size: 100%;
		object-fit: cover;
	}
}

.constellation__via {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 11px;
	font-weight: 600;
	white-space: nowrap;
	pointer-events: none;
}

.constellation__star {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	padding: 4px;
	border: none;
	border-radius: 12px;
	background: none;
	color: var(--color-main-text);
	cursor: grab;
	user-select: none;
	animation: constellation-twinkle 3.2s ease-in-out infinite;
	animation-delay: calc(var(--twinkle, 0) * 1s);

	&:active {
		cursor: grabbing;
	}

	&:hover .constellation__avatar {
		transform: scale(1.12);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&--followed .constellation__avatar {
		border-color: var(--color-success, #2d7b41);
	}

	&--pending .constellation__avatar {
		opacity: .6;
	}
}

.constellation__avatar {
	inline-size: 40px;
	block-size: 40px;
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
	background: var(--color-main-background);
	object-fit: cover;
	transition: transform .15s ease;
}

.constellation__name {
	max-inline-size: 9em;
	padding: 0 6px;
	border-radius: 6px;
	background: color-mix(in srgb, var(--color-main-background) 80%, transparent);
	font-size: 11px;
	line-height: 1.5;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* a slow breath, each star a little out of step with the next */
@keyframes constellation-twinkle {
	0%, 100% { filter: brightness(1); }
	50% { filter: brightness(1.12); }
}

@media (prefers-reduced-motion: reduce) {
	.constellation__star {
		animation: none;
	}

	.constellation__avatar {
		transition: none;
	}
}
</style>
