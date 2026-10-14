<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="editorRoot" class="pdf-editor">
		<PDFElements ref="pdfElements"
			:init-files="files"
			:init-file-names="fileNames"
			:page-count-format="t('libresign', '{currentPage} of {totalPages}')"
			:page-aria-label="getPageAriaLabel"
			:auto-fit-zoom="true"
			:read-only="readOnly"
			:emit-object-click="true"
			:hide-selection-ui="readOnly"
			:show-selection-handles="!readOnly"
			:show-element-actions="!readOnly"
			:ignore-click-outside-selectors="ignoreClickOutsideSelectors"
			:style="toolbarStyleVars"
			@pdf-elements:end-init="endInit"
			@pdf-elements:object-click="handleObjectClick"
			@pdf-elements:delete-object="handleDeleteObject"
			@pdf-elements:adding-ended="handleAddingEnded">
			<template #actions="slotProps">
				<slot name="actions" v-bind="slotProps">
					<SignerMenu
						v-if="hasMultipleSigners && slotProps.object?.signer"
						:signers="signers"
						:current-signer="slotProps.object?.signer"
						:get-signer-label="getSignerLabel"
						@change="onSignerChange(slotProps.object, $event)" />
					<NcButton
						:class="['action-btn', slotProps.actionClass]"
						v-bind="slotProps.actionAttrs"
						type="button"
						variant="tertiary"
						:aria-label="t('libresign', 'Duplicate')"
						:title="t('libresign', 'Duplicate')"
						@click.stop="slotProps.onDuplicate">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentCopy" :size="16" />
						</template>
					</NcButton>
					<NcButton
						:class="['action-btn', slotProps.actionClass]"
						v-bind="slotProps.actionAttrs"
						type="button"
						variant="tertiary"
						:aria-label="t('libresign', 'Delete')"
						:title="t('libresign', 'Delete')"
						@click.stop="slotProps.onDelete">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDelete" :size="16" />
						</template>
					</NcButton>
				</slot>
			</template>
			<template #element-signature="{ object }">
				<SignatureBox
					:label="getSignerLabel(object.signer)"
					:signer="object.signer"
					:active-sign-request-id="activeSignRequestId"
					:palette="paletteForObject(object)"
					:is-overlapping="overlappingIds.has(String(object.id))"
					:is-highlighted="highlightId !== null && String(object.id) === String(highlightId)" />
			</template>
		</PDFElements>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import { computed, nextTick, ref } from 'vue'
import PDFElements from '@libresign/pdf-elements'
import '@libresign/pdf-elements/dist/index.css'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import {
	mdiContentCopy,
	mdiDelete,
} from '@mdi/js'

import SignerMenu from '../../PdfEditor/SignerMenu.vue'
import SignatureBox from './SignatureBoxNext.vue'
import {
	buildPdfEditorSignerPayload,
	calculatePdfPlacement,
	createPdfEditorObject,
	findPdfObjectLocation,
	getPdfEditorSignerLabel,
	resolvePdfEditorSignerChange,
} from '../../PdfEditor/pdfEditorModel'
import { ensurePdfWorker } from '../../../helpers/pdfWorker'
import type { SignerDetailRecord, SignerSummaryRecord, VisibleElementRecord } from '../../../types/index'

type PdfInput = string | Blob | ArrayBuffer | ArrayBufferView | Record<string, unknown>
type PdfEditorMeasurement = Record<number, { width: number, height: number }>
type EndInitPayload = Record<string, unknown>
type PdfPage = {
	getViewport: (options: { scale: number }) => {
		width: number
		height: number
	}
}
type PdfEditorObject = {
	id: string
	type?: string
	x: number
	y: number
	width: number
	height: number
	signer?: SignerSummaryRecord | SignerDetailRecord | null
	visibleElement?: VisibleElementRecord | null
	documentIndex?: number
}
type PdfDocument = {
	numPages?: number
	pages?: Array<Promise<PdfPage>>
	allObjects?: PdfEditorObject[][]
}

type PdfElementsInstance = {
	startAddingElement: (payload: Record<string, unknown>) => void
	updateObject: (docIndex: number, objectId: string, patch: Record<string, unknown>) => void
	addObjectToPage: (object: PdfEditorObject, pageIndex: number, docIndex: number) => void
	cancelAdding: () => void
	adjustZoomToFit?: () => void
	getPageHeight?: (docIndex: number, pageIndex: number) => number
	getPageWidth?: (docIndex: number, pageIndex: number) => number
	selectPage?: (docIndex: number, pageIndex: number) => void
	isAddingMode?: boolean
	pdfDocuments?: PdfDocument[]
	selectedDocIndex?: number
	selectedPageIndex?: number
	autoFitZoom?: boolean
	scale?: number
	commitZoom?: () => void
}

defineOptions({
	name: 'PdfEditorNext',
})

ensurePdfWorker()

type SignerPalette = {
	base: string
	tint: string
	contrast: string
}

const props = withDefaults(defineProps<{
	files?: PdfInput[]
	fileNames?: string[]
	readOnly?: boolean
	signers?: Array<SignerSummaryRecord | SignerDetailRecord>
	/**
	 * Optional theming/feedback for the signature-position editor. All default to
	 * inert values so other consumers (e.g. the signing view) are unaffected.
	 */
	activeSignRequestId?: number | null
	signerColors?: Record<string | number, SignerPalette>
	overlappingIds?: Set<string>
	highlightId?: string | null
}>(), {
	files: () => [],
	fileNames: () => [],
	readOnly: false,
	signers: () => [],
	activeSignRequestId: null,
	signerColors: () => ({}),
	overlappingIds: () => new Set<string>(),
	highlightId: null,
})

function paletteForObject(object: PdfEditorObject | null | undefined): SignerPalette | undefined {
	const signRequestId = object?.signer?.signRequestId
	if (signRequestId === undefined || signRequestId === null) {
		return undefined
	}
	return props.signerColors[signRequestId] ?? props.signerColors[String(signRequestId)]
}

const emit = defineEmits<{
	(event: 'pdf-editor:end-init', payload: EndInitPayload): void
	(event: 'pdf-editor:on-delete-signer', payload: VisibleElementRecord): void
	(event: 'pdf-editor:object-click', payload: Record<string, unknown>): void
	(event: 'pdf-editor:signer-added'): void
	(event: 'pdf-editor:adding-ended', payload: any): void
}>()

const pdfElements = ref<PdfElementsInstance | null>(null)
const editorRoot = ref<HTMLElement | null>(null)

const ignoreClickOutsideSelectors = computed(() => ['.action-item__popper', '.action-item'])

const toolbarStyleVars = computed(() => ({
	'--pdf-elements-toolbar-gap': '10px',
	'--pdf-elements-toolbar-padding': '10px 10px 6px 18px',
	'--pdf-elements-toolbar-background': 'var(--color-main-background)',
	'--pdf-elements-toolbar-color': 'var(--color-main-text)',
	'--pdf-elements-toolbar-border-color': 'var(--color-border)',
	'--pdf-elements-toolbar-border-radius': '10px',
	'--pdf-elements-toolbar-shadow': '0 8px 20px rgba(15, 23, 42, 0.18)',
	'--pdf-elements-action-btn-border': 'none',
	'--pdf-elements-action-btn-background': 'transparent',
	'--pdf-elements-action-btn-color': 'var(--color-main-text)',
	'--pdf-elements-action-btn-padding': '6px',
	'--pdf-elements-action-btn-radius': '6px',
	'--pdf-elements-action-btn-min-height': '30px',
	'--pdf-elements-action-btn-min-width': '30px',
	'--pdf-elements-action-btn-shadow': 'none',
	'--pdf-elements-action-btn-hover-background': 'var(--color-background-hover)',
}))

const hasMultipleSigners = computed(() => (props.signers || []).length > 1)

function getPageAriaLabel({ docIndex, docName, totalDocs, pageNumber, totalPages, isAddingMode }: {
	docIndex: number
	docName: string
	totalDocs: number
	pageNumber: number
	totalPages: number
	isAddingMode: boolean
}) {
	const docNumber = docIndex + 1
	if (totalDocs > 1 && isAddingMode) {
		return t('libresign', 'Document {docNumber} of {totalDocs} ({docName}), page {pageNumber} of {totalPages}. Press Enter or Space to place the signature here.', { docNumber, totalDocs, docName, pageNumber, totalPages })
	}
	if (totalDocs > 1) {
		return t('libresign', 'Document {docNumber} of {totalDocs} ({docName}), page {pageNumber} of {totalPages}.', { docNumber, totalDocs, docName, pageNumber, totalPages })
	}
	if (isAddingMode) {
		return t('libresign', 'Page {pageNumber} of {totalPages}. Press Enter or Space to place the signature here.', { pageNumber, totalPages })
	}
	return t('libresign', 'Page {pageNumber} of {totalPages}.', { pageNumber, totalPages })
}

async function endInit(event: EndInitPayload) {
	await nextTick()
	await nextTick()
	const measurement = await calculatePdfMeasurement()
	emit('pdf-editor:end-init', { ...event, measurement })
}

async function calculatePdfMeasurement() {
	const measurement: PdfEditorMeasurement = {}
	const firstDocument = pdfElements.value?.pdfDocuments?.[0]
	if (!firstDocument?.pages?.length || !firstDocument.numPages) {
		return measurement
	}

	for (let pageIndex = 0; pageIndex < firstDocument.numPages; pageIndex++) {
		const pageNumber = pageIndex + 1
		const pdfPage = await firstDocument.pages[pageIndex]
		const viewport = pdfPage.getViewport({ scale: 1 })

		measurement[pageNumber] = {
			width: viewport.width,
			height: viewport.height,
		}
	}

	return measurement
}

function onDeleteSigner(visibleElement: VisibleElementRecord) {
	emit('pdf-editor:on-delete-signer', visibleElement)
}

function handleDeleteObject({ object }: { object?: PdfEditorObject }) {
	if (object?.visibleElement) {
		onDeleteSigner(object.visibleElement)
	}
}

function handleObjectClick(event: Record<string, unknown>) {
	emit('pdf-editor:object-click', event)
}

function getSignerLabel(signer: SignerSummaryRecord | SignerDetailRecord | null | undefined) {
	return getPdfEditorSignerLabel(signer)
}

function onSignerChange(object: PdfEditorObject | null | undefined, signer: SignerSummaryRecord | SignerDetailRecord | null | undefined) {
	if (!object || !signer || !pdfElements.value) {
		return
	}
	const resolved = resolvePdfEditorSignerChange({
		availableSigners: props.signers || [],
		selectedSigner: signer,
		object,
		documents: pdfElements.value.pdfDocuments,
	})
	if (!resolved) {
		return
	}

	object.signer = resolved.signer
	pdfElements.value.updateObject(resolved.docIndex, object.id, { signer: resolved.signer })
}

function findObjectLocation(pdfElementsInstance: PdfElementsInstance | null | undefined, objectId: string) {
	return findPdfObjectLocation(pdfElementsInstance?.pdfDocuments, objectId)
}

function getTotalObjectsCount() {
	const documents = pdfElements.value?.pdfDocuments || []
	return documents.reduce((total, document) => {
		const pageObjects = document?.allObjects || []
		return total + pageObjects.reduce((pageTotal, objects) => pageTotal + (objects?.length || 0), 0)
	}, 0)
}

function handleAddingEnded(event: {
    reason: 'placed' | 'cancelled'
    object?: unknown
    docIndex?: number
    pageIndex?: number
}) {
    emit('pdf-editor:adding-ended', event)
}
function startAddingSigner(signer: SignerSummaryRecord | SignerDetailRecord | null | undefined, size: { width?: number, height?: number }) {
	if (!pdfElements.value || !size?.width || !size?.height) {
		return false
	}

	const signerPayload = buildPdfEditorSignerPayload(signer)
	if (!signerPayload) {
		return false
	}

	pdfElements.value.startAddingElement({
		type: 'signature',
		x: 0,
		y: 0,
		width: size.width,
		height: size.height,
		signer: signerPayload,
	})

	return true
}

function cancelAdding() {
	pdfElements.value?.cancelAdding()
}

// Find the page currently most in view within the scroll container, mapped to
// (docIndex, pageIndex). Page wrappers render in flat doc→page order.
function findVisiblePage(bottomInset = 0): { docIndex: number, pageIndex: number } | null {
	const el = pdfElements.value
	const rootEl = editorRoot.value
	if (!el || !rootEl) {
		return null
	}
	const wrappers = Array.from(rootEl.querySelectorAll('.page-wrapper'))
	if (wrappers.length === 0) {
		return null
	}
	const viewport = rootEl.getBoundingClientRect()
	// Prefer the visible window to the area above any overlay (e.g. mobile sheet).
	const effectiveBottom = viewport.bottom - bottomInset
	let bestIndex = 0
	let bestVisible = -1
	wrappers.forEach((wrapper, index) => {
		const rect = wrapper.getBoundingClientRect()
		const visible = Math.max(0, Math.min(rect.bottom, effectiveBottom) - Math.max(rect.top, viewport.top))
		if (visible > bestVisible) {
			bestVisible = visible
			bestIndex = index
		}
	})
	// Map the flat wrapper index back to (docIndex, pageIndex).
	const docs = el.pdfDocuments || []
	let flat = bestIndex
	for (let docIndex = 0; docIndex < docs.length; docIndex++) {
		const pageCount = docs[docIndex].pages?.length || 0
		if (flat < pageCount) {
			return { docIndex, pageIndex: flat }
		}
		flat -= pageCount
	}
	return { docIndex: 0, pageIndex: 0 }
}

// Drop a signature box at the centre of the currently-visible page (no
// tap-to-place). Returns the placed object so the caller can enter edit mode.
function dropSignerAtView(signer: SignerSummaryRecord | SignerDetailRecord | null | undefined, size: { width?: number, height?: number }, bottomInset = 0) {
	const el = pdfElements.value
	if (!el || !size?.width || !size?.height) {
		return false
	}
	const signerPayload = buildPdfEditorSignerPayload(signer)
	if (!signerPayload) {
		return false
	}
	const location = findVisiblePage(bottomInset)
	if (!location) {
		return false
	}
	const { docIndex, pageIndex } = location

	const pageWidth = el.getPageWidth?.(docIndex, pageIndex) || 0
	const pageHeight = el.getPageHeight?.(docIndex, pageIndex) || 0
	if (!pageWidth || !pageHeight) {
		return false
	}

	const boxWidth = Math.min(size.width, pageWidth)
	const boxHeight = Math.min(size.height, pageHeight)
	const x = Math.max(0, Math.round((pageWidth - boxWidth) / 2))
	const y = Math.max(0, Math.round((pageHeight - boxHeight) / 2))

	const object = createPdfEditorObject({
		signer: signerPayload,
		documentIndex: docIndex,
		placement: { docIndex, pageIndex, x, y, width: boxWidth, height: boxHeight },
	})

	el.addObjectToPage(object, pageIndex, docIndex)
	el.selectPage?.(docIndex, pageIndex)
	return object.id
}

async function addSigner(signer: SignerSummaryRecord | SignerDetailRecord, visibleElement: VisibleElementRecord, options: { documentIndex?: number } = {}) {
	if (!pdfElements.value || !visibleElement.coordinates) {
		return
	}

	const docIndex = options.documentIndex !== undefined
		? options.documentIndex
		: pdfElements.value.selectedDocIndex || 0
	const previewPlacement = calculatePdfPlacement({
		visibleElement,
		documentIndex: options.documentIndex,
		defaultDocIndex: docIndex,
		pageHeight: 0,
	})
	if (!previewPlacement) {
		return
	}

	const pageIndex = previewPlacement.pageIndex
	await waitForPageRender(docIndex, pageIndex)

	const pageHeight = pdfElements.value.getPageHeight?.(docIndex, pageIndex) || 0
	const placement = calculatePdfPlacement({
		visibleElement,
		documentIndex: options.documentIndex,
		defaultDocIndex: docIndex,
		pageHeight,
	})
	if (!placement) {
		return
	}

	const object = createPdfEditorObject({
		signer,
		visibleElement,
		documentIndex: docIndex,
		placement,
	})

	pdfElements.value.addObjectToPage(object, pageIndex, docIndex)
}

async function waitForPageRender(docIndex: number, pageIndex: number) {
	const document = pdfElements.value?.pdfDocuments?.[docIndex]
	if (!document?.pages?.[pageIndex]) {
		return
	}

	await document.pages[pageIndex]
	await nextTick()
	await nextTick()
}

defineExpose({
	t,
	mdiContentCopy,
	mdiDelete,
	pdfElements,
	ignoreClickOutsideSelectors,
	toolbarStyleVars,
	hasMultipleSigners,
	getPageAriaLabel,
	endInit,
	calculatePdfMeasurement,
	onDeleteSigner,
	handleDeleteObject,
	handleObjectClick,
	getSignerLabel,
	paletteForObject,
	onSignerChange,
	findObjectLocation,
	startAddingSigner,
	dropSignerAtView,
	findVisiblePage,
	cancelAdding,
	addSigner,
	waitForPageRender,
	getTotalObjectsCount,
	handleAddingEnded,
})
</script>

<style lang="scss">
.pdf-editor {
	width: 100%;
	height: 100%;
	overflow: hidden;
	overscroll-behavior: contain;
}

</style>
