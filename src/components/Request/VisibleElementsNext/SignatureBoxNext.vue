<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="signature-box"
		:class="{ 'signature-box--overlapping': isOverlapping, 'signature-box--highlighted': isHighlighted }"
		:style="boxStyle"
		role="img"
		:aria-label="signatureBoxAriaLabel">
		<span class="label" :class="{ 'label--chip': !!palette }" :style="labelStyle" aria-hidden="true">{{ label }}</span>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { usernameToColor } from '@nextcloud/vue/functions/usernameToColor'
import { computed } from 'vue'
import type { SignerDetailRecord, SignerSummaryRecord } from '../../../types/index'

defineOptions({
	name: 'SignatureBoxNext',
})

type Signer = SignerSummaryRecord | SignerDetailRecord | null
type Palette = {
	base: string
	tint: string
	contrast: string
}

const props = withDefaults(defineProps<{
	label?: string
	signer?: Signer
	/**
	 * Signature-position editor extras. All optional: when absent the box falls
	 * back to the legacy `usernameToColor` styling so the signing view is
	 * unchanged.
	 */
	palette?: Palette | null
	activeSignRequestId?: number | null
	isOverlapping?: boolean
	/** Briefly pulse this box to draw the eye to a freshly-dropped position. */
	isHighlighted?: boolean
}>(), {
	label: '',
	signer: null,
	palette: null,
	activeSignRequestId: null,
	isOverlapping: false,
	isHighlighted: false,
})

const signatureBoxAriaLabel = computed(() => {
	return t('libresign', 'Signature position for {name}', { name: props.label })
})

// Dim boxes that don't belong to the actively-selected signer, so the one being
// placed stands out. No active id → everyone is at full opacity.
const isDimmed = computed(() => {
	if (props.activeSignRequestId === null || props.activeSignRequestId === undefined) {
		return false
	}
	const signRequestId = props.signer?.signRequestId
	if (signRequestId === undefined || signRequestId === null) {
		return false
	}
	return Number(signRequestId) !== Number(props.activeSignRequestId)
})

const legacyColor = computed(() => {
	const seed = props.signer?.displayName || props.signer?.email || props.signer?.signRequestId || props.label
	if (!seed) {
		return null
	}
	const { r, g, b } = usernameToColor(String(seed))
	return {
		border: `rgb(${r}, ${g}, ${b})`,
		fill: `rgba(${r}, ${g}, ${b}, 0.12)`,
	}
})

const boxStyle = computed(() => {
	const style: Record<string, string> = {}

	if (props.palette) {
		style.borderColor = props.palette.base
		style.backgroundColor = props.palette.tint
	} else if (legacyColor.value) {
		style.borderColor = legacyColor.value.border
		style.backgroundColor = legacyColor.value.fill
	}

	if (isDimmed.value) {
		style.opacity = '0.25'
	}

	return style
})

const labelStyle = computed(() => {
	if (!props.palette) {
		return {}
	}
	return {
		backgroundColor: props.palette.base,
		color: props.palette.contrast,
	}
})

defineExpose({
	signatureBoxAriaLabel,
	boxStyle,
	labelStyle,
	isDimmed,
	props,
})
</script>

<style lang="scss" scoped>
.signature-box {
	box-sizing: border-box;
	border: 2px dashed #2563eb;
	background: rgba(37, 99, 235, 0.08);
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 6px 8px;
	border-radius: 6px;
	width: 100%;
	height: 100%;
	line-height: 1.2;
	overflow: hidden;
	transition: opacity 120ms ease;

	&--overlapping {
		border-style: solid;
		border-color: var(--color-error, #c6533f) !important;
		box-shadow: 0 0 0 1px var(--color-error, #c6533f);
	}

	&--highlighted {
		border-style: solid;
		animation: ve-box-pulse 1.1s ease-out infinite;
		z-index: 2;
	}
}

@keyframes ve-box-pulse {
	0% { box-shadow: 0 0 0 0 rgba(4, 213, 109, 0.55); }
	70% { box-shadow: 0 0 0 10px rgba(4, 213, 109, 0); }
	100% { box-shadow: 0 0 0 0 rgba(4, 213, 109, 0); }
}

@media (prefers-reduced-motion: reduce) {
	.signature-box--highlighted {
		animation: none;
		box-shadow: 0 0 0 3px rgba(4, 213, 109, 0.6);
	}
}
.label {
	font-weight: 600;
	white-space: nowrap;
	text-overflow: ellipsis;
	overflow: hidden;
	max-width: 100%;

	// Chip styling only applies in the position editor (palette present); the
	// signing view keeps the original plain label.
	&--chip {
		padding: 1px 6px;
		border-radius: 4px;
		font-size: 11px;
	}
}
</style>
