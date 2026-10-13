<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="securysign-settings" @submit.prevent="save">
		<NcSettingsSection
			:name="t('libresign', 'SecurySign connection')"
			:description="t('libresign', 'Which SecurySign GoPaperless talks to. The login provider, the address and the signing secret must all belong to the same SecurySign, live or staging. If one points elsewhere, people can sign in but cannot sign.')">
			<div class="securysign-settings__fields">
				<NcSelect
					v-model="provider"
					:options="providerOptions"
					label="label"
					track-by="id"
					:clearable="false"
					:input-label="t('libresign', 'SecurySign login provider')" />
				<NcTextField
					v-model="url"
					:label="t('libresign', 'SecurySign address')"
					placeholder="https://securysign.com" />
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('libresign', 'Save') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('libresign', 'Sign with SecurySign')"
			:description="t('libresign', 'When on, people who sign in with SecurySign sign documents with their own SecurySign certificate, approving each signature with their passkey in the SecurySign window. When off, or while SecurySign is down, everyone signs with the GoPaperless certificate.')">
			<div class="securysign-settings__fields">
				<NcCheckboxRadioSwitch
					type="switch"
					class="securysign-settings__signing"
					:model-value="signingEnabled && canSign"
					:disabled="!canSign"
					@update:model-value="signingEnabled = $event">
					{{ t('libresign', 'Sign with SecurySign') }}
				</NcCheckboxRadioSwitch>
				<NcPasswordField
					v-model="signingSecret"
					:label="t('libresign', 'SecurySign signing (SSC) secret')"
					:placeholder="signingSecretSet ? t('libresign', 'Saved. Type a new one to replace it.') : ''" />
				<p class="securysign-settings__hint">
					{{ t('libresign', 'Needs a login provider and the signing secret. SecurySign must also list this site as an authorised signing origin.') }}
				</p>
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('libresign', 'Save') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('libresign', 'MIMI passkey check')"
			:description="t('libresign', 'When on, people who sign in with SecurySign confirm it is them with their MIMI passkey straight after sign-in. When off, sign-in ends at SecurySign.')">
			<div class="securysign-settings__fields">
				<NcCheckboxRadioSwitch
					type="switch"
					class="securysign-settings__passkey"
					:model-value="mimiPasskeyEnabled && canCheckPasskey"
					:disabled="!canCheckPasskey"
					@update:model-value="mimiPasskeyEnabled = $event">
					{{ t('libresign', 'Ask for the MIMI passkey after sign-in') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-model="mimiUrl"
					:label="t('libresign', 'MIMI address')"
					:helper-text="t('libresign', 'Empty means https://gopaperless.mimi.ke')"
					placeholder="https://gopaperless.mimi.ke" />
				<NcTextField
					v-model="mimiClientId"
					:label="t('libresign', 'MIMI client ID')"
					:helper-text="t('libresign', 'Empty means gopaperless')"
					placeholder="gopaperless" />
				<NcPasswordField
					v-model="mimiClientSecret"
					:label="t('libresign', 'MIMI client secret')"
					:placeholder="mimiClientSecretSet ? t('libresign', 'Saved. Type a new one to replace it.') : ''" />
				<p class="securysign-settings__hint">
					{{ t('libresign', 'Needs a login provider and the MIMI client secret.') }}
				</p>
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('libresign', 'Save') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('libresign', 'Enrolment for users without a certificate')"
			:description="t('libresign', 'Where someone who signs in with SecurySign but has no certificate yet goes to get one. They come back to GoPaperless when it is done.')">
			<div class="securysign-settings__fields">
				<NcCheckboxRadioSwitch
					v-model="enrolmentSite"
					type="radio"
					value="tendaworld"
					name="securysign_enrolment_site">
					{{ t('libresign', 'TendaWorld website') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="enrolmentSite === 'tendaworld'"
					v-model="tendaworldUrl"
					:label="t('libresign', 'TendaWorld website address')"
					:helper-text="t('libresign', 'Empty means https://tendaworld.com')"
					placeholder="https://tendaworld.com" />
				<NcCheckboxRadioSwitch
					v-model="enrolmentSite"
					type="radio"
					value="mimi"
					name="securysign_enrolment_site">
					{{ t('libresign', 'MIMI') }}
				</NcCheckboxRadioSwitch>
				<p v-if="enrolmentSite === 'mimi'" class="securysign-settings__hint">
					{{ t('libresign', 'Uses the MIMI address and client ID above. MIMI only sends people back to addresses registered for that client.') }}
				</p>
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('libresign', 'Save') }}
				</NcButton>
			</div>
		</NcSettingsSection>
	</form>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { showError, showSuccess } from '@/services/toast'
import type { AdminInitialState } from '../../types'

defineOptions({ name: 'SecurySignSettings' })

type ProviderOption = { id: number, label: string }

const OFF: ProviderOption = { id: 0, label: t('libresign', 'Off') }
const providerOptions: ProviderOption[] = [OFF, ...loadState<AdminInitialState['user_oidc_providers']>('libresign', 'user_oidc_providers', [])]
const providerId = loadState<AdminInitialState['securysign_provider_id']>('libresign', 'securysign_provider_id', 0)

const provider = ref<ProviderOption>(providerOptions.find(option => option.id === providerId) ?? OFF)
const url = ref(loadState<AdminInitialState['securysign_url']>('libresign', 'securysign_url', ''))
const signingEnabled = ref(loadState<AdminInitialState['securysign_signing_enabled']>('libresign', 'securysign_signing_enabled', true))
const signingSecretSet = ref(loadState<AdminInitialState['securysign_signing_secret_set']>('libresign', 'securysign_signing_secret_set', false))
const signingSecret = ref('')
const mimiPasskeyEnabled = ref(loadState<AdminInitialState['mimi_passkey_enabled']>('libresign', 'mimi_passkey_enabled', true))
const mimiUrl = ref(loadState<AdminInitialState['mimi_url']>('libresign', 'mimi_url', ''))
const mimiClientId = ref(loadState<AdminInitialState['mimi_client_id']>('libresign', 'mimi_client_id', ''))
const mimiClientSecretSet = ref(loadState<AdminInitialState['mimi_client_secret_set']>('libresign', 'mimi_client_secret_set', false))
const mimiClientSecret = ref('')
const enrolmentSite = ref(loadState<AdminInitialState['enrolment_site']>('libresign', 'enrolment_site', 'tendaworld'))
const tendaworldUrl = ref(loadState<AdminInitialState['tendaworld_url']>('libresign', 'tendaworld_url', ''))
const saving = ref(false)

// A switch can only be on once what it needs is there, saved or being typed.
const canSign = computed(() => provider.value.id !== 0 && (signingSecretSet.value || signingSecret.value.trim() !== ''))
const canCheckPasskey = computed(() => provider.value.id !== 0 && (mimiClientSecretSet.value || mimiClientSecret.value.trim() !== ''))

async function save() {
	saving.value = true
	try {
		await axios.post(generateOcsUrl('/apps/libresign/api/v1/admin/securysign-config'), {
			providerId: provider.value.id,
			url: url.value.trim(),
			signingEnabled: signingEnabled.value,
			signingSecret: signingSecret.value.trim(),
			mimiPasskeyEnabled: mimiPasskeyEnabled.value,
			mimiUrl: mimiUrl.value.trim(),
			mimiClientId: mimiClientId.value.trim(),
			mimiClientSecret: mimiClientSecret.value.trim(),
			enrolmentSite: enrolmentSite.value,
			tendaworldUrl: tendaworldUrl.value.trim(),
		})
		signingSecretSet.value ||= signingSecret.value.trim() !== ''
		signingSecret.value = ''
		mimiClientSecretSet.value ||= mimiClientSecret.value.trim() !== ''
		mimiClientSecret.value = ''
		showSuccess(t('libresign', 'SecurySign settings saved'))
	} catch (error) {
		const message = (error as { response?: { data?: { ocs?: { data?: { error?: string } } } } }).response?.data?.ocs?.data?.error
		showError(message ?? t('libresign', 'Could not save the SecurySign settings'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.securysign-settings__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 400px;
}

.securysign-settings__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
