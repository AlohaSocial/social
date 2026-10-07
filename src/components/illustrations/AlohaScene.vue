<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		The full-size drawings above an empty page: eight scenes from the
		shore, each in the same round window so they read as one set. Markup
		rather than files, so the colours are mixed with the page's own
		background and text and follow the theme without a filter.
	-->
	<svg
		class="aloha-scene"
		:class="`aloha-scene--${scene}`"
		viewBox="0 0 240 160"
		width="210"
		height="140"
		aria-hidden="true"
		focusable="false">
		<defs>
			<clipPath :id="clipId">
				<circle cx="120" cy="80" r="70" />
			</clipPath>
		</defs>
		<g :clip-path="`url(#${clipId})`">
			<template v-if="scene === 'tide'">
				<rect class="sky" width="240" height="160" />
				<path class="sun" d="M94 98A26 26 0 0 1 146 98Z" />
				<rect
					class="sea"
					x="40"
					y="98"
					width="160"
					height="70" />
				<path class="ink" d="M40 98H200" />
				<path class="glint" d="M106 108H134M112 117H128M117 126H123" />
				<path class="ink ink--soft drift" d="M68 138q7-5 14 0t14 0t14 0t14 0t14 0t14 0t14 0" />
				<path class="ink ink--soft" d="M150 52q5-5 10 0q5-5 10 0M168 40q4-4 8 0q4-4 8 0" />
			</template>

			<template v-else-if="scene === 'islands'">
				<rect class="sky" width="240" height="60" />
				<rect
					class="sea"
					y="56"
					width="240"
					height="120" />
				<path class="ink" d="M40 56H200" />
				<path class="route" d="M106 92Q124 80 140 78M98 104Q106 116 116 124M156 86Q160 104 146 118" />
				<path class="sand" d="M64 100q9-15 21-15t21 15Z" />
				<path class="sand" d="M136 84q7-11 15-11t15 11Z" />
				<path class="sand" d="M112 130q9-12 18-12t18 12Z" />
				<path class="ink" d="M84 86q-3-12 3-24" />
				<path class="frond" d="M87 62q-11-4-17 4M87 62q11-6 17 2M87 62q-5-9-13-10M87 62q6-9 14-8" />
				<path class="coral bob" d="M122 101h14q-2 5-7 5t-7-5Z" />
			</template>

			<template v-else-if="scene === 'hammock'">
				<rect class="sky" width="240" height="160" />
				<circle
					class="sun"
					cx="120"
					cy="46"
					r="11" />
				<rect
					class="sea"
					x="40"
					y="96"
					width="160"
					height="20" />
				<rect
					class="sand"
					x="40"
					y="112"
					width="160"
					height="60" />
				<path class="ink" d="M40 96H200M82 124Q70 92 72 54M158 124Q172 92 168 56" />
				<path class="frond" d="M72 54q-14-2-20 8M72 54q-10-10-22-8M72 54q4-12 16-12M72 54q14-2 18 10M168 56q14-2 20 8M168 56q10-10 22-8M168 56q-4-12-16-12M168 56q-14-2-18 10" />
				<path class="coral bob" d="M76 80Q120 118 164 82Q120 106 76 80Z" />
				<path class="ink ink--soft" d="M110 136l4-2M126 138l5 1" />
			</template>

			<template v-else-if="scene === 'plumeria'">
				<rect class="sky" width="240" height="160" />
				<path class="leaf" d="M122 84C150 96 166 112 170 130C150 124 132 108 122 84Z" />
				<path class="ink ink--soft" d="M126 90Q148 106 166 126" />
				<g transform="translate(118 80)">
					<path
						v-for="angle in PETALS"
						:key="angle"
						class="petal"
						:transform="`rotate(${angle})`"
						d="M0 0C-16-8-20-40-2-46C14-46 14-14 0 0Z" />
					<circle class="sun" r="8" />
				</g>
			</template>

			<template v-else-if="scene === 'bottle'">
				<rect class="sky" width="240" height="160" />
				<rect
					class="sea"
					y="80"
					width="240"
					height="90" />
				<path class="ink" d="M40 80H200" />
				<g class="bob">
					<g transform="translate(120 90) rotate(-22)">
						<rect
							class="glass"
							x="-32"
							y="-15"
							width="48"
							height="30"
							rx="11" />
						<path class="glass" d="M16-7h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H16Z" />
						<rect
							class="sand"
							x="31"
							y="-5"
							width="7"
							height="10"
							rx="2" />
						<rect
							class="petal"
							x="-24"
							y="-7"
							width="32"
							height="14"
							rx="3" />
						<path class="ink ink--soft" d="M-18-1H2M-18 3H-6" />
					</g>
				</g>
				<path class="sea sea--flat" d="M30 104q10-6 20 0t20 0t20 0t20 0t20 0t20 0t20 0t20 0t20 0V170H30Z" />
				<path class="ink drift" d="M50 104q10-6 20 0t20 0t20 0t20 0t20 0t20 0t20 0" />
				<path class="ink ink--soft drift drift--slow" d="M70 126q8-5 16 0t16 0t16 0t16 0t16 0t16 0" />
			</template>

			<template v-else-if="scene === 'board'">
				<rect class="sky" width="240" height="160" />
				<circle
					class="sun"
					cx="150"
					cy="70"
					r="16" />
				<rect
					class="sea"
					x="40"
					y="96"
					width="160"
					height="22" />
				<rect
					class="sand"
					x="40"
					y="114"
					width="160"
					height="60" />
				<path class="ink" d="M40 96H200" />
				<g transform="translate(106 124) rotate(-8)">
					<path class="coral" d="M0 0C-15-20-15-74 0-92C15-74 15-20 0 0Z" />
					<path class="stripe" d="M0-6V-86" />
				</g>
				<path class="sand" d="M88 124q18-8 36 0Z" />
				<path class="hibiscus" d="M150 132q4-8 8 0q-4 3-8 0Z" />
				<path class="ink ink--soft" d="M164 138l4-1M80 140l5 1" />
			</template>

			<template v-else-if="scene === 'wave'">
				<rect class="sky" width="240" height="160" />
				<circle
					class="sun"
					cx="88"
					cy="56"
					r="13" />
				<path class="sea" d="M30 170V122C58 118 78 100 92 78C108 52 150 38 176 58C196 74 188 102 166 102C150 102 146 86 158 80C148 76 136 84 136 100C136 120 160 130 210 128V170Z" />
				<path class="ink ink--soft" d="M110 70C126 56 152 52 168 64" />
				<path class="ink ink--soft drift drift--slow" d="M56 140q8-5 16 0t16 0t16 0" />
			</template>

			<template v-else-if="scene === 'tracks'">
				<rect class="sand sand--flat" width="240" height="160" />
				<path class="sea" d="M30 0H210V30Q190 44 170 34T130 30T90 38T50 30T30 34Z" />
				<path class="ink ink--soft drift" d="M50 46q10-6 20 0t20 0t20 0t20 0t20 0t20 0" />
				<ellipse
					v-for="step in STEPS"
					:key="step.x"
					class="step"
					:cx="step.x"
					:cy="step.y"
					rx="3.2"
					ry="5.4"
					:transform="`rotate(45 ${step.x} ${step.y})`"
					:opacity="step.opacity" />
				<path class="hibiscus" d="M160 120q5-10 10 0q-5 4-10 0Z" />
			</template>
		</g>
		<circle
			class="ring"
			cx="120"
			cy="80"
			r="70" />
	</svg>
</template>

<script>
/** every scene by name, for callers that want to check one exists */
export const SCENES = ['tide', 'islands', 'hammock', 'plumeria', 'bottle', 'board', 'wave', 'tracks']

/** the plumeria's five petals, a fifth of a turn apart */
const PETALS = [0, 72, 144, 216, 288]

/**
 * Footprints walking up the beach towards the water, alternating feet and
 * fading as they go.
 */
const STEPS = Array.from({ length: 8 }, (_, i) => {
	const side = i % 2 ? 1 : -1
	return {
		x: +(80 + i * 11.5 + side * 4).toFixed(1),
		y: +(140 - i * 11 + side * 4).toFixed(1),
		opacity: +(1 - i * 0.1).toFixed(2),
	}
})

let instances = 0

export default {
	name: 'AlohaScene',

	props: {
		/** one of SCENES */
		scene: {
			type: String,
			required: true,
			validator: (value) => SCENES.includes(String(value)),
		},
	},

	data() {
		instances += 1
		return {
			/** the round window's clip, unique per drawing on the page */
			clipId: `aloha-scene-clip-${instances}`,
			PETALS,
			STEPS,
		}
	},
}
</script>

<style scoped>
/*
 * The sunset palette, mixed with the page's own background so that the same
 * drawing is bright on a light theme and dimmed on a dark one.
 */
.aloha-scene {
	--aloha-coral: #e6604a;
	--aloha-gold: #f0b23c;
	--aloha-teal: #138a86;
	--aloha-hibiscus: #d94677;
	--aloha-ground: var(--color-main-background);
	--aloha-sky: color-mix(in oklab, var(--aloha-coral) 22%, var(--aloha-ground));
	display: block;
	max-width: 100%;
	height: auto;
	color: color-mix(in oklab, var(--color-main-text) 70%, var(--aloha-teal));
	overflow: visible;
}

.sky { fill: var(--aloha-sky); }

.sea { fill: color-mix(in oklab, var(--aloha-teal) 62%, var(--aloha-ground)); }

.sun { fill: var(--aloha-gold); }

.sand { fill: color-mix(in oklab, var(--aloha-gold) 38%, var(--aloha-ground)); }

.leaf { fill: color-mix(in oklab, var(--aloha-teal) 80%, var(--color-main-text)); }

.coral { fill: var(--aloha-coral); }

.hibiscus { fill: var(--aloha-hibiscus); }

.petal { fill: color-mix(in oklab, #fffaf2 90%, var(--aloha-ground)); }

.glass { fill: color-mix(in oklab, var(--aloha-teal) 28%, var(--aloha-ground)); }

.sea, .sun, .sand, .leaf, .coral, .hibiscus, .petal, .glass {
	stroke: currentColor;
	stroke-width: 2.4;
	stroke-linejoin: round;
}

.sea--flat, .sand--flat {
	stroke: none;
}

.ink, .glint, .frond, .route, .step, .stripe {
	fill: none;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.ink {
	stroke: currentColor;
	stroke-width: 2.4;
}

.ink--soft {
	opacity: .6;
}

.glint {
	stroke: var(--aloha-gold);
	stroke-width: 2.4;
}

.frond {
	stroke: color-mix(in oklab, var(--aloha-teal) 80%, var(--color-main-text));
	stroke-width: 2.6;
}

/* the canoe routes between islands */
.route {
	stroke: currentColor;
	stroke-width: 2.2;
	stroke-dasharray: 1 6;
}

.step {
	stroke: currentColor;
	stroke-width: 2;
}

.stripe {
	stroke: var(--aloha-ground);
	stroke-width: 3;
}

.ring {
	fill: none;
	stroke: currentColor;
	stroke-width: 2.4;
}

/* the water moves a little, and what floats on it bobs */
.drift {
	animation: aloha-drift 5.5s ease-in-out infinite alternate;
}

.drift--slow {
	animation-duration: 7.5s;
	animation-direction: alternate-reverse;
}

.bob {
	animation: aloha-bob 4s ease-in-out infinite;
	transform-box: fill-box;
	transform-origin: center;
}

@keyframes aloha-drift {
	from { transform: translateX(-5px); }
	to { transform: translateX(5px); }
}

@keyframes aloha-bob {
	0%, 100% { transform: translateY(0) rotate(0); }
	50% { transform: translateY(3px) rotate(2deg); }
}

@media (prefers-reduced-motion: reduce) {
	.drift,
	.bob {
		animation: none;
	}
}
</style>

<style>
/*
 * A sunset sky mixed into a dark page turns brown, so a dark theme gets the
 * sky after sunset instead: dusk blue, with the same gold sun in it. Not
 * scoped, because the theme is an attribute on <body>.
 */
@media (prefers-color-scheme: dark) {
	body:not([data-themes*='light']) .aloha-scene {
		--aloha-sky: color-mix(in oklab, #4a5fc1 38%, var(--color-main-background));
	}
}

body[data-themes*='dark'] .aloha-scene {
	--aloha-sky: color-mix(in oklab, #4a5fc1 38%, var(--color-main-background));
}
</style>
