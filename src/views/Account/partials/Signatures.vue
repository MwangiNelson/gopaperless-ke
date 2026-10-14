<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="isSignaturesAvailable()" class="signatures">
		<p class="sign-section-title">{{ t('libresign', 'Your signatures') }}</p>

		<Signature type="signature">
			<template #title>
				{{ t('libresign', 'Signature') }}
			</template>

			<template #no-signatures>
				{{ t('libresign', 'No signature, click here to create a new one') }}
			</template>
		</Signature>

		<Signature v-if="false" type="initial">
			<template #title>
				{{ t('libresign', 'Initials') }}
			</template>

			<template #no-signatures>
				{{ t('libresign', 'No initials, click here to create a new one') }}
			</template>
		</Signature>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import { getCapabilities } from '@nextcloud/capabilities'
import { onMounted } from 'vue'

import Signature from './Signature.vue'
import { useSignatureElementsStore } from '../../../store/signatureElements.js'
import type { LibresignCapabilities } from '../../../types/index'

defineOptions({
	name: 'Signatures',
})

// A signature changed on SecurySign shows here when the page opens.
const signatureElementsStore = useSignatureElementsStore()
onMounted(() => signatureElementsStore.loadSignatures(true))

function isSignaturesAvailable() {
	const capabilities = getCapabilities() as LibresignCapabilities
	const signElementsConfig = capabilities.libresign?.config['sign-elements']
	return signElementsConfig?.['is-available'] === true
		&& signElementsConfig['can-create-signature'] === true
}

defineExpose({
	isSignaturesAvailable,
})
</script>

<style lang="scss" scoped>
.signatures {
	align-items: flex-start;
	max-width: 350px;

	h1{
		font-size: 11px;
		font-weight: 700;
		letter-spacing: .08em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
		border-bottom: 1px solid #000;
	}
}
</style>
