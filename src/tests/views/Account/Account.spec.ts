/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

import Account from '../../../views/Account/Account.vue'

vi.mock('@nextcloud/l10n', () => createL10nMock())

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({
		uid: 'ada',
		displayName: 'Ada Lovelace',
	})),
}))

const initialState: Record<string, unknown> = {}
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((_app: string, key: string, fallback: unknown) => key in initialState ? initialState[key] : fallback),
}))

describe('Account.vue', () => {
	function createWrapper() {
		return mount(Account, {
			global: {
				stubs: {
					UserImage: {
						name: 'UserImage',
						props: ['user'],
						template: '<div class="user-image-stub">{{ user.displayName }}</div>',
					},
					ManagePassword: {
						name: 'ManagePassword',
						template: '<div class="manage-password-stub"></div>',
					},
					Signatures: {
						name: 'Signatures',
						template: '<div class="signatures-stub"></div>',
					},
					Documents: {
						name: 'Documents',
						template: '<div class="documents-stub"></div>',
					},
				},
			},
		})
	}

	it('renders the current user details and certificate section', () => {
		const wrapper = createWrapper()

		expect(wrapper.text()).toContain('Details')
		expect(wrapper.text()).toContain('Certificate')
		expect(wrapper.text()).toContain('Ada Lovelace')
	})

	it('passes the current user to UserImage', () => {
		const wrapper = createWrapper()
		const userImage = wrapper.findComponent({ name: 'UserImage' })

		expect(userImage.props('user')).toEqual({
			uid: 'ada',
			displayName: 'Ada Lovelace',
		})
	})

	it('renders the account partial sections', () => {
		const wrapper = createWrapper()

		expect(wrapper.find('.manage-password-stub').exists()).toBe(true)
		expect(wrapper.find('.signatures-stub').exists()).toBe(true)
		expect(wrapper.find('.documents-stub').exists()).toBe(true)
	})

	describe('when SecurySign signs for the user', () => {
		afterEach(() => {
			delete initialState.securysign_signs
			delete initialState.securysign_certificate
			delete initialState.securysign_profile_url
		})

		it('links the certificate to its page on the TendaWorld website, in a new tab', () => {
			initialState.securysign_signs = true
			initialState.securysign_profile_url = 'https://staging.tendaworld.com/profile'
			const link = createWrapper().find('a.securysign-certificate')

			expect(link.attributes('href')).toBe('https://staging.tendaworld.com/profile')
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toContain('noopener')
			expect(link.text()).toContain('View on TendaWorld')
		})

		it('shows the SecurySign certificate read-only instead of the local certificate buttons', () => {
			initialState.securysign_signs = true
			initialState.securysign_certificate = { issuer: 'Signa Hardware CA', serial: '4F3A', validUntil: '13 Oct 2027' }
			const wrapper = createWrapper()

			expect(wrapper.find('.manage-password-stub').exists()).toBe(false)
			const text = wrapper.find('.securysign-certificate').text()
			expect(text).toContain('Issuer')
			expect(text).toContain('Signa Hardware CA')
			expect(text).toContain('Serial')
			expect(text).toContain('4F3A')
			expect(text).toContain('Valid until')
			expect(text).toContain('13 Oct 2027')
		})

		it('says the details are unavailable rather than offering a local certificate', () => {
			initialState.securysign_signs = true
			const wrapper = createWrapper()

			expect(wrapper.find('.manage-password-stub').exists()).toBe(false)
			expect(wrapper.text()).toContain('Your SecurySign certificate details are not available right now.')
		})
	})
})
