<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="content-account">
		<div class="user">
			<UserImage v-bind="{ user }" />
			<div class="details">
				<div class="user-details">
					<h3>{{ t('libresign', 'Details') }}</h3>
					<div class="user-display-name icon-user">
						<p>{{ user.displayName }}</p>
					</div>
				</div>
				<div class="user-password">
					<h3>{{ t('libresign', 'Certificate') }}</h3>
					<a v-if="securySignSigns"
						:href="securySignProfileUrl"
						class="securysign-certificate"
						target="_blank"
						rel="noopener noreferrer">
						<dl v-if="securySignCertificate">
							<dt>{{ t('libresign', 'Issuer') }}</dt>
							<dd>{{ securySignCertificate.issuer }}</dd>
							<dt>{{ t('libresign', 'Serial') }}</dt>
							<dd>{{ securySignCertificate.serial }}</dd>
							<dt>{{ t('libresign', 'Valid until') }}</dt>
							<dd>{{ securySignCertificate.validUntil }}</dd>
						</dl>
						<p v-else>
							{{ t('libresign', 'Your SecurySign certificate details are not available right now.') }}
						</p>
						<span class="securysign-certificate__open">{{ t('libresign', 'View on TendaWorld') }} ↗</span>
					</a>
					<ManagePassword v-else />
				</div>
			</div>
		</div>

		<div class="user">
			<Signatures />
			<Documents />
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'

import Documents from './partials/Documents.vue'
import ManagePassword from './partials/ManagePassword.vue'
import Signatures from './partials/Signatures.vue'
import UserImage from './partials/UserImage.vue'

defineOptions({
	name: 'Account',
})

type User = {
	uid: string
	displayName: string
}

const currentUser = getCurrentUser()
const user: User = {
	uid: currentUser?.uid ?? '',
	displayName: currentUser?.displayName ?? '',
}

type SecurySignCertificate = {
	issuer: string
	serial: string
	validUntil: string
}

// SecurySign holds the key, so its users have no local certificate to manage.
const securySignSigns = loadState<boolean>('libresign', 'securysign_signs', false)
const securySignCertificate = loadState<SecurySignCertificate | null>('libresign', 'securysign_certificate', null)
// The website signs a SecurySign user in from the session both sites share.
const securySignProfileUrl = loadState<string>('libresign', 'securysign_profile_url', 'https://tendaworld.com/profile')

defineExpose({
	user,
	t,
})
</script>

<style lang="scss" scoped>

.app-content{
	display: flex;
	flex-direction: row;

	.content-account{
		width: 100%;
		margin-top: 50px;
		display: flex;
		height: 100%;

		.user{
			width: 50%;
			max-width: 350px;
			display: flex;
			flex-direction: column;
			align-items: flex-start;

			&:first-child {
				width: 25%;
				min-width: 240px;
			}

			@media screen and (max-width: 768px) {
				&, &:first-child {
					width: 50%;
				}
			}

			.details{
				display: flex;
				flex-direction: column;
				width: 100%;
				padding: 10px;
				border: 0;
			}

			.user-details{
				display: flex;
				flex-direction: column;
				width: 100%;
				border: 0;

				.user-display-name[class*='icon']{
					width: 100%;
					background-position: 0px 4px;
					opacity: 0.7;
					margin-right: 10%;
					margin-bottom: 12px;
					margin-top: 12px;
					margin-left: 12px;
					padding-left: 22px;
				}
			}

			.user-password{
				display: flex;
				flex-direction: column;
			}

			.securysign-certificate{
				display: block;
				margin: 0 -8px;
				padding: 8px;
				border-radius: var(--border-radius-large);
				color: inherit;
				text-decoration: none;

				&:hover, &:focus-visible{
					background-color: var(--color-background-hover);
				}

				dl, p{
					margin: 0;
				}

				&__open{
					display: inline-block;
					margin-top: 12px;
					color: var(--color-primary-element);
				}

				dt{
					color: var(--color-text-maxcontrast);
					margin-top: 8px;
				}

				dd{
					margin: 0;
					overflow-wrap: anywhere;
				}
			}
		}

		@media (max-width: 650px) {
			flex-direction: column;

			.user{
				width: 100%;
			}
		}
	}

}
</style>
