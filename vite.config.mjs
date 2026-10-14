/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { resolve } from 'node:path'

export default createAppConfig({
	main: resolve('src/main.ts'),
	init: resolve('src/init.ts'),
	tab: resolve('src/tab.ts'),
	settings: resolve('src/settings.ts'),
	external: resolve('src/external.ts'),
	validation: resolve('src/validation.ts'),
	login: resolve('src/login.ts'),
	'legal-links': resolve('src/legal-links.ts'),
	passkey: resolve('src/passkey.ts'),
}, {
	config: {
		server: {
			port: 3000,
			host: '0.0.0.0',
		},
		resolve: {
			alias: {
				'@': resolve(import.meta.dirname, 'src'),
			},
		},
		plugins: [
			{
				// ponytail: @libresign/pdf-elements resizes a box down to a fixed
				// 16 px and has no minimum of its own. This raises that floor to the
				// box's minWidth/minHeight (pdfEditorModel.ts atLeastMinimum), so a
				// box can't be dragged smaller than the admin's minimum signature
				// size. Replace with a library option once it has one; the build
				// fails if an update moves the code this relies on.
				name: 'pdf-elements-minimum-box',
				transform(code, id) {
					if (!id.replaceAll('\\', '/').includes('@libresign/pdf-elements/dist/index.mjs')) {
						return null
					}
					const floor = '16 / (this.pagesScale || 1)'
					if (code.split(floor).length !== 2) {
						this.error('@libresign/pdf-elements changed: the resize floor this build raises to the minimum signature size is gone')
					}
					return code.replace(floor, `Math.max(${floor}, this.object.minWidth || 0, (this.object.minHeight || 0) * this.aspectRatio)`)
				},
			},
			{
				name: 'vue-devtools',
				config(_, { mode }) {
					return {
						define: {
							__VUE_PROD_DEVTOOLS__: mode !== 'production',
						},
					}
				},
			},
		],
	},
})
