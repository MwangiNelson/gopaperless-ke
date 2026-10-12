<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu__main">
		<!-- Top left, just under the navbar. -->
		<div class="pu__tagline">
			<div class="pu__kicker">
				Sign · Seal · Deliver
			</div>
			<div class="pu__by">
				<span>with</span>
				<img :src="tendaLogo" alt="Tendaworld">
			</div>
		</div>
		<section class="pu__hero">
			<div class="pu__intro">
				<h1 class="pu__title">
					Simplify your paperwork.
				</h1>
				<p class="pu__lede">
					Upload your document, add your signers, place the signature fields, and send it — the whole process takes just a couple of minutes.
				</p>
				<div class="pu__actions">
					<button type="button" class="pu__cta" @click="$emit('getStarted')">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiTrayArrowUp" /></svg>
						Upload your document
					</button>
					<a class="pu__how" href="https://tendaworld.com/gopaperless" target="_blank" rel="noopener">See how it works</a>
				</div>
			</div>

			<div class="pu__stage">
				<div class="pu__devices">
					<img class="pu__laptop"
						:src="laptop"
						width="1350"
						height="1080"
						alt="The GoPaperless Request Signature screen on a laptop">
					<img class="pu__phone"
						:src="phone"
						width="1350"
						height="1080"
						alt="The GoPaperless document action centre on a phone">
				</div>
			</div>
		</section>
	</div>
</template>

<script setup lang="ts">
import { mdiTrayArrowUp } from '@mdi/js'

import laptop from '../../img/landing-desktop.webp'
import tendaLogo from '../../img/tenda-logo-green-updated.png'
import phone from '../../img/landing-mobile.webp'

defineOptions({ name: 'PublicUploadHero' })

defineEmits<{
	getStarted: []
}>()
</script>

<style scoped lang="scss">
// The tagline row, then the hero in the height left, so the hero keeps a
// definite height for the device stage's container query units.
.pu__main {
	display: grid;
	grid-template-rows: auto 1fr;
	min-height: 0;
}

.pu__tagline {
	padding-block-start: clamp(16px, 3vh, 28px);
	padding-inline: var(--pu-pad);
}

.pu__hero {
	flex: 1 1 auto;
	display: flex;
	align-items: center;
	gap: 48px;
	min-height: 0;
	padding-inline: var(--pu-pad);
}

.pu__intro {
	flex: 0 1 520px;
	padding-block: clamp(24px, 4vh, 48px);
}

.pu__kicker {
	margin-bottom: 6px;
	font-size: 11px;
	font-weight: 600;
	line-height: 14px;
	letter-spacing: .16em;
	text-transform: uppercase;
	color: var(--brand-strong);
}

.pu__by {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	line-height: 16px;
	color: var(--slate);

	// The PNG carries transparent padding: at 19px the wordmark itself is about
	// 13px, and the negative margin lines its baseline up with "with".
	img {
		display: block;
		width: auto;
		height: 19px;
		margin-top: -3px;
	}
}

.pu__title {
	margin: 0 0 20px;
	font-size: clamp(36px, 4.45vw, 64px);
	font-weight: 700;
	line-height: 1;
	letter-spacing: -.035em;
	color: var(--ink);
}

.pu__lede {
	max-width: 440px;
	margin: 0 0 34px;
	font-size: clamp(16px, 1.25vw, 18px);
	line-height: 1.6;
	color: var(--slate);
}

.pu__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 16px 20px;
	margin-bottom: 20px;
}

// Nextcloud's button rule adds a 3px margin and outranks a single class.
.pu__actions .pu__cta {
	margin: 0;
}

.pu__cta {
	display: inline-flex;
	align-items: center;
	gap: 9px;
	padding: 16px 28px;
	border: none;
	border-radius: 11px;
	font: inherit;
	font-size: 15px;
	font-weight: 600;
	line-height: 18px;
	color: var(--on-brand);
	background: var(--brand);
	cursor: pointer;
	transition: background .2s, transform .12s;

	svg {
		width: 18px;
		height: 18px;
	}

	&:hover {
		background: var(--brand-strong);
	}

	&:active {
		transform: translateY(1px);
	}

	&:focus-visible {
		outline: 2px solid var(--ink);
		outline-offset: 2px;
	}
}

.pu__how {
	font-size: 15px;
	font-weight: 500;
	color: var(--slate);
	text-decoration: none;

	&:hover,
	&:focus-visible {
		color: var(--ink);
		text-decoration: underline;
	}
}

/* The stage takes its size from the hero, never from the images, so the devices
   scale to whatever the first screen leaves beside (or below) the copy. */
.pu__stage {
	flex: 1 1 400px;
	align-self: stretch;
	display: flex;
	align-items: center;
	min-width: 0;
	container-type: size;
}

.pu__devices {
	position: relative;
	flex: none;
	// ponytail: 124cqw and 105cqh reproduce the Paper artboard at 1440×900, where an 860px laptop box sits in a 696px stage. Tune here if the devices crowd the copy.
	width: min(124cqw, 105cqh);
	aspect-ratio: 1350 / 1080;

	img {
		display: block;
		max-width: none;
		height: auto;
		// The transparent margins overlap the copy, so they must not catch taps.
		pointer-events: none;
	}
}

/* Both baked-in shadows stop at their image's bottom edge and leave a faint line
   on a plain background, so each image fades out below its device. */
.pu__laptop {
	width: 100%;
	mask-image: linear-gradient(to bottom, #000 86%, transparent);
}

/* Offsets from the Paper artboard, as fractions of the laptop box. */
.pu__phone {
	position: absolute;
	top: 38.7%;
	left: -9%;
	width: 60.5%;
	mask-image: linear-gradient(to bottom, #000 91%, transparent);
}

// Tighter spacing on a short laptop screen, so the landing still fits one screen.
@media (min-width: 861px) and (max-height: 800px) {
	.pu__intro {
		padding-block: 12px;
	}

	.pu__title {
		margin-bottom: 12px;
	}

	.pu__lede {
		margin-bottom: 20px;
	}
}

@media (max-width: 860px) {
	// Grid, so the stage row has a definite height for 91cqh below. As a flex
	// column item its height only came from growing, and cqh resolved to 0.
	.pu__hero {
		display: grid;
		grid-template-rows: auto minmax(300px, 1fr);
		gap: 0;
		padding-block: 20px 12px;
	}

	.pu__intro {
		padding-block: 0;
	}

	.pu__title {
		margin-bottom: 14px;
	}

	.pu__lede {
		margin-bottom: 20px;
		font-size: 15px;
	}

	.pu__actions {
		gap: 12px 14px;
		margin-bottom: 16px;
	}

	.pu__cta {
		padding: 14px 18px;
	}

	/* The stage takes whatever height the first screen has left and runs to both
	   screen edges, and the devices scale to fit it. */
	.pu__stage {
		margin-inline: calc(-1 * var(--pu-pad));
		justify-content: center;
	}

	/* Each image is placed on its own, from where its device sits inside the file
	   (laptop 8.4-91.6% across and 20.8-83.8% down, phone 34.8-65% and 11-89%).
	   The phone stands in front on the left and the laptop runs off the right edge. */
	.pu__devices {
		width: min(100cqw, 91cqh);
		aspect-ratio: 1 / 1.1;
	}

	.pu__laptop {
		position: absolute;
		top: -10.9%;
		left: 14.9%;
		width: 108.2%;
	}

	.pu__phone {
		top: 12.5%;
		left: -39.6%;
		width: 128.2%;
	}
}
</style>
