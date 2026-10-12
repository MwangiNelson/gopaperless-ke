<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu" :class="{ 'pu--dark': theme === 'dark', 'pu--fit': !document }">
		<div class="pu__fold">
			<PublicUploadHeader :theme="theme" @sign-in="gateToLogin" @toggle-theme="toggleTheme" />
			<PublicUploadHero v-if="!document" @get-started="gateToLogin" />
			<PublicLegal v-else :document="document" />
		</div>
		<PublicUploadEcosystem />
	</div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

import PublicLegal from '../components/PublicLegal.vue'
import PublicUploadEcosystem from '../components/PublicUploadEcosystem.vue'
import PublicUploadHeader from '../components/PublicUploadHeader.vue'
import PublicUploadHero from '../components/PublicUploadHero.vue'

defineOptions({ name: 'PublicUpload' })

const props = defineProps<{
	// Set on /p/terms and /p/privacy, which show that document instead of the hero.
	document?: 'terms' | 'privacy'
}>()

type Theme = 'light' | 'dark'

const THEME_KEY = 'gopaperless-landing-theme'

const router = useRouter()

/**
 * The landing follows the visitor's system colour scheme until they pick one
 * with the header toggle. That choice is remembered in this browser.
 */
const darkScheme = window.matchMedia?.('(prefers-color-scheme: dark)')
const systemDark = ref(Boolean(darkScheme?.matches))
const chosenTheme = ref<Theme | null>(readThemeChoice())
const theme = computed<Theme>(() => chosenTheme.value ?? (systemDark.value ? 'dark' : 'light'))

function readThemeChoice(): Theme | null {
	try {
		const saved = localStorage.getItem(THEME_KEY)
		return saved === 'light' || saved === 'dark' ? saved : null
	} catch {
		return null
	}
}

function toggleTheme(): void {
	chosenTheme.value = theme.value === 'dark' ? 'light' : 'dark'
	try {
		localStorage.setItem(THEME_KEY, chosenTheme.value)
	} catch {
		// Private windows can refuse storage, so the choice lasts for this visit only.
	}
}

function onSchemeChange(event: MediaQueryListEvent): void {
	systemDark.value = event.matches
}

/**
 * PHP only runs on the full-page load, so a logged-in user who reaches this
 * view via in-SPA navigation is sent to the authed upload/request view.
 */
onMounted(() => {
	if (getCurrentUser() && !props.document) {
		router.replace({ name: 'requestFiles' })
	}
})

onMounted(() => darkScheme?.addEventListener?.('change', onSchemeChange))
onBeforeUnmount(() => darkScheme?.removeEventListener?.('change', onSchemeChange))

/**
 * Server-built login destination for the configured User OIDC provider.
 * Empty when Public Upload uses the default Nextcloud login.
 */
const oidcLoginUrl = loadState<string>('libresign', 'public_upload_oidc_login_url', '')

/**
 * We gate before any file bytes: any action sends the visitor to a login
 * destination, which returns them to the authenticated upload/request view.
 */
function gateToLogin(): void {
	if (oidcLoginUrl) {
		window.location.href = oidcLoginUrl
		return
	}
	// redirect_url must be a server-relative path: Nextcloud prepends the host itself
	const target = generateUrl('/apps/libresign/f/request')
	window.location.href = generateUrl('/login') + '?redirect_url=' + encodeURIComponent(target)
}
</script>

<style scoped lang="scss">
.pu {
	// Self-contained palette so the landing renders consistently regardless of
	// the surrounding Nextcloud theme (the rest of the app forces light mode).
	--ink: #0e1116;
	--slate: #5b6472;
	--line: #e6e8ec;
	--surface: #ffffff;
	--brand: #04d56d;
	--brand-strong: #03b95e;
	--on-brand: #0e1116;
	--eco-bg: #0f172a;
	--eco-fg: #e2e8f0;
	--eco-dim: #94a3b8;
	--eco-accent: #04d56d;
	--eco-edge: transparent;
	// Side gutter that also caps content at 1264px, the 1440 artboard minus its
	// gutters. 100% resolves against .pu in each section's padding.
	--pu-pad: max(clamp(20px, 6vw, 88px), (100% - 1264px) / 2);
	display: flex;
	flex-direction: column;
	width: 100%;
	min-height: 100%;
	// Clips the device mockups, which bleed past the stage on purpose.
	overflow: hidden;
	font-family: var(--tenda-font-family, 'Space Grotesk', -apple-system, blinkmacsystemfont, 'Segoe UI', roboto, sans-serif);
	color: var(--ink);
	background: var(--surface);
	color-scheme: light;
}

.pu--dark {
	--ink: #f1f5f9;
	--slate: #9ba5b4;
	--line: rgba(255, 255, 255, .1);
	// Same navy as the footer, so dark mode reads as one surface.
	--surface: #0f172a;
	--eco-edge: rgba(255, 255, 255, .08);
	color-scheme: dark;
}

// Header and hero fill at least the first screen. Grid rather than flex: a 1fr
// row stretched to a min-height gets a definite height, which the hero's device
// stage needs for its container query units.
.pu__fold {
	display: grid;
	grid-template-rows: auto 1fr;
	min-height: 100svh;
}

// The landing is one screen on desktop, footer included, so it never scrolls.
// The legal pages and phones still do. ponytail: below 560px tall nothing fits, so
// the scrolling layout above takes over.
@media (min-width: 861px) and (min-height: 560px) {
	.pu--fit {
		height: 100%;

		.pu__fold {
			flex: 1 1 auto;
			min-height: 0;
		}

		.pu__eco {
			gap: 20px;
			padding-top: 24px;
		}
	}
}

// A short laptop screen keeps only the footer's links: the about line and
// "Powered by" take about 110px the hero can't give up.
@media (min-width: 861px) and (min-height: 560px) and (max-height: 800px) {
	.pu--fit :deep(.pu__eco-main) {
		display: none;
	}

	// Its divider would sit alone at the top of the footer.
	.pu--fit :deep(.pu__eco-links) {
		padding-top: 0;
		border-top: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.pu * {
		transition: none !important;
	}
}
</style>
