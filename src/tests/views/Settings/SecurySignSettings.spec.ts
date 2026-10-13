/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { flushPromises, mount } from '@vue/test-utils'

import SecurySignSettings from '../../../views/Settings/SecurySignSettings.vue'

const state: Record<string, unknown> = {}
const post = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, fallback: unknown) => key in state ? state[key] : fallback,
}))
vi.mock('@nextcloud/l10n', () => createL10nMock())
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path: string) => path }))
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args: unknown[]) => post(...args) } }))
vi.mock('@/services/toast', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const field = {
	props: ['modelValue', 'label'],
	emits: ['update:modelValue'],
	template: '<label>{{ label }}<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></label>',
}

function createWrapper() {
	return mount(SecurySignSettings, {
		global: {
			stubs: {
				NcSettingsSection: {
					props: ['name', 'description'],
					template: '<section><h2>{{ name }}</h2><p>{{ description }}</p><slot /></section>',
				},
				NcCheckboxRadioSwitch: {
					props: ['modelValue', 'type', 'value', 'disabled'],
					emits: ['update:modelValue'],
					template: `<input v-if="type === 'radio'" type="radio" :value="value" :checked="modelValue === value" @change="$emit('update:modelValue', value)">
						<button v-else type="button" role="switch" :aria-checked="String(modelValue)" :disabled="disabled" @click="$emit('update:modelValue', !modelValue)"><slot /></button>`,
				},
				NcSelect: {
					props: ['modelValue', 'options'],
					template: '<select />',
				},
				NcTextField: field,
				NcPasswordField: field,
				NcButton: { props: ['type'], template: '<button :type="type"><slot /></button>' },
			},
		},
	})
}

describe('SecurySignSettings.vue', () => {
	beforeEach(() => {
		post.mockReset().mockResolvedValue({})
		for (const key of Object.keys(state)) {
			delete state[key]
		}
	})

	it('shows each feature under its own heading', () => {
		const headings = createWrapper().findAll('h2').map(heading => heading.text())

		expect(headings).toEqual([
			'SecurySign connection',
			'Sign with SecurySign',
			'MIMI passkey check',
			'Enrolment for users without a certificate',
		])
	})

	it('keeps a switch off and greyed out until what it needs is saved', () => {
		const wrapper = createWrapper()
		const signing = wrapper.find('[role="switch"]')

		expect(signing.attributes('aria-checked')).toBe('false')
		expect(signing.attributes('disabled')).toBeDefined()
	})

	it('turns a switch on once its secret is saved and a provider is chosen', () => {
		state.user_oidc_providers = [{ id: 1, label: 'SecurySign' }]
		state.securysign_provider_id = 1
		state.securysign_signing_secret_set = true
		const signing = createWrapper().find('[role="switch"]')

		expect(signing.attributes('aria-checked')).toBe('true')
		expect(signing.attributes('disabled')).toBeUndefined()
	})

	it('saves both switches, the MIMI address and the enrolment site', async () => {
		state.user_oidc_providers = [{ id: 1, label: 'SecurySign' }]
		state.securysign_provider_id = 1
		state.securysign_signing_secret_set = true
		state.mimi_url = 'https://staging-gopaperless.mimi.ke'
		const wrapper = createWrapper()

		await wrapper.find('[role="switch"]').trigger('click')
		await wrapper.find('input[type="radio"][value="mimi"]').trigger('change')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/libresign/api/v1/admin/securysign-config', expect.objectContaining({
			providerId: 1,
			signingEnabled: false,
			mimiPasskeyEnabled: true,
			mimiUrl: 'https://staging-gopaperless.mimi.ke',
			enrolmentSite: 'mimi',
		}))
	})

	it('asks for the website address only when the website enrols', async () => {
		const wrapper = createWrapper()
		expect(wrapper.text()).toContain('TendaWorld website address')

		await wrapper.find('input[type="radio"][value="mimi"]').trigger('change')

		expect(wrapper.text()).not.toContain('TendaWorld website address')
		expect(wrapper.text()).toContain('Uses the MIMI address and client ID above.')
	})
})
