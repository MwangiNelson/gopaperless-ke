<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu__eco">
		<div class="pu__eco-main">
			<div class="pu__eco-about">
				<span class="pu__eco-kicker">About</span>
				<p class="pu__eco-lede">
					The easiest way to sign documents online in minutes across Africa. Digitalize any process that requires document signing with full legal validity.
				</p>
			</div>

			<div class="pu__eco-powered">
				<span class="pu__eco-kicker">Powered by</span>
				<div class="pu__eco-items">
					<a v-for="p in ecosystem"
						:key="p.name"
						class="pu__eco-item"
						:href="p.href"
						target="_blank"
						rel="noopener">
						<svg class="pu__eco-logo" viewBox="0 0 24 24" aria-hidden="true"><path :d="p.icon" /></svg>
						<span class="pu__eco-txt">
							<b>{{ p.name }}</b>
							<span>{{ p.sub }}</span>
						</span>
					</a>
				</div>
			</div>
		</div>

		<nav class="pu__eco-links">
			<a href="https://tendaworld.com/gopaperless" target="_blank" rel="noopener">About GoPaperless</a>
			<a :href="termsPage">Terms &amp; Conditions</a>
			<a :href="privacyPage">Privacy Policy</a>
		</nav>
	</div>
</template>

<script setup lang="ts">
import { mdiShieldCheckOutline, mdiShieldLockOutline } from '@mdi/js'
import { generateUrl } from '@nextcloud/router'

defineOptions({ name: 'PublicUploadEcosystem' })

const termsPage = generateUrl('/apps/libresign/p/terms')
const privacyPage = generateUrl('/apps/libresign/p/privacy')

const ecosystem = [
	{ name: 'JuliCA Certification Authority', sub: 'Issued and Regulated by the Communications Authority of Kenya', href: 'https://tendaworld.com/julica', icon: mdiShieldCheckOutline },
	{ name: 'Securysign', sub: 'The public key infrastructure built for Africa', href: 'https://tendaworld.com/securysign', icon: mdiShieldLockOutline },
]
</script>

<style scoped lang="scss">
/* Two tiers, the about line and trust chain above the legal links. On desktop
   the landing fits it on the first screen (PublicUpload.vue, pu--fit). */
.pu__eco {
	flex: none;
	display: flex;
	flex-direction: column;
	gap: 40px;
	padding: 56px var(--pu-pad) 24px;
	border-top: 1px solid var(--eco-edge);
	font-size: 12px;
	line-height: 1.35;
	color: var(--eco-dim);
	background: var(--eco-bg);
}

.pu__eco-main {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 32px 64px;
}

// Narrow columns at either end, so the footer reads as two short blocks rather than one wide line.
.pu__eco-about {
	flex: 0 1 380px;
}

.pu__eco-powered {
	flex: 0 1 300px;
}

.pu__eco-kicker {
	display: block;
	margin-bottom: 14px;
	font-size: 10px;
	font-weight: 500;
	letter-spacing: .14em;
	text-transform: uppercase;
}

.pu__eco-lede {
	margin: 0;
	font-size: 13px;
	font-weight: 300;
	line-height: 1.7;
	color: var(--eco-fg);
}

.pu__eco-items {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.pu__eco-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	color: inherit;
	text-decoration: none;

	&:hover b,
	&:focus-visible b {
		color: var(--eco-accent);
	}
}

.pu__eco-logo {
	flex: none;
	width: 18px;
	height: 18px;
	margin-top: 1px;
	color: var(--eco-fg);
}

.pu__eco-txt {
	b {
		display: block;
		font-size: 12.5px;
		font-weight: 500;
		color: var(--eco-fg);
		transition: color .18s;
	}

	span {
		display: block;
		margin-top: 3px;
		font-size: 10.5px;
		font-weight: 300;
		line-height: 1.5;
	}
}

.pu__eco-links {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 6px 10px;
	padding-top: 20px;
	font-weight: 300;
	border-top: 1px solid rgba(255, 255, 255, .08);

	a {
		color: inherit;
		text-decoration: none;
		transition: color .18s;

		&:hover,
		&:focus-visible {
			color: var(--eco-accent);
			text-decoration: underline;
		}
	}

	// inline-block keeps the hover underline off the separator
	a + a::before {
		content: '·';
		display: inline-block;
		margin-inline-end: 10px;
		color: rgba(255, 255, 255, .28);
	}
}

@media (max-width: 860px) {
	.pu__eco {
		gap: 32px;
		padding-block: 40px 24px;
	}

	// The links wrap here, and a separator would start the second line.
	.pu__eco-links {
		justify-content: flex-start;
		column-gap: 18px;

		a + a::before {
			display: none;
		}
	}
}
</style>
