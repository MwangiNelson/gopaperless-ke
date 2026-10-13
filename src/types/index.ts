/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { components as ApiComponents } from './openapi/openapi'
import type { operations as ApiOperations } from './openapi/openapi'
import type { components as AdminComponents } from './openapi/openapi-administration'

type ApiJsonBody<TRequestBody> = TRequestBody extends {
	content: {
		'application/json': infer Body
	}
}
	? Body
	: never

type ApiOperationRequestBody<TOperation> = TOperation extends {
	requestBody?: infer RequestBody
}
	? NonNullable<RequestBody>
	: never

type ApiOperationResponses<TOperation> = TOperation extends {
	responses: infer Responses
}
	? Responses
	: never

type ApiOcsJsonData<TResponse> = TResponse extends {
	content: {
		'application/json': {
			ocs: {
				data: infer Data
			}
		}
	}
}
	? Data
	: never

type ApiRequestJsonBody<TOperation> = ApiJsonBody<ApiOperationRequestBody<TOperation>>

type ApiOcsResponseData<TOperation, TStatusCode extends keyof ApiOperationResponses<TOperation>>
	= ApiOcsJsonData<ApiOperationResponses<TOperation>[TStatusCode]>

export type SignatureFlowMode = ApiComponents['schemas']['DetailedFileResponse']['signatureFlow']
export type SignatureFlowValue = SignatureFlowMode
export type NewFilePayload = ApiComponents['schemas']['NewFile']
export type IdentifyMethodRecord = ApiComponents['schemas']['IdentifyMethod']
export type IdentifyAccountRecord = ApiComponents['schemas']['IdentifyAccount']
export type VisibleElementRecord = ApiComponents['schemas']['VisibleElement']
export type FileSettings = ApiComponents['schemas']['FolderSettings']
export type IdentifyMethodSetting = AdminComponents['schemas']['IdentifyMethodSetting']
export type ProgressPayload = ApiComponents['schemas']['ProgressPayload']
export type FileDetailRecord = ApiComponents['schemas']['DetailedFile']
export type ValidationFileRecord = ApiComponents['schemas']['ValidatedFile']
export type FileSummaryRecord = ApiComponents['schemas']['FileSummary']
export type FileListItemRecord = ApiComponents['schemas']['FileListItem']
export type SignerDetailRecord = ApiComponents['schemas']['SignerDetail']
export type SignerSummaryRecord = ApiComponents['schemas']['SignerSummary']
export type ValidatedChildFileRecord = ApiComponents['schemas']['ValidatedChildFile']
export type LoadedValidationDocument = ValidationFileRecord & {
   /**
	* Whether the current session can view the document's PDF.
	*/
	canViewDocument?: boolean
}
export type LoadedValidationFileDocument = LoadedValidationDocument & {
	nodeType: 'file'
}
export type LoadedValidationEnvelopeDocument = LoadedValidationDocument & {
	nodeType: 'envelope'
}
export type SignatureMethodsRecord = ApiComponents['schemas']['SignatureMethods']
export type UserElementRecord = ApiComponents['schemas']['UserElement']
export type SignActionResponseRecord = ApiComponents['schemas']['SignActionResponse']
export type SigningJobRecord = ApiComponents['schemas']['SigningJob']
export type FileUuidReferenceRecord = ApiComponents['schemas']['FileUuidReference']
export type RequestSignerRecord = ApiComponents['schemas']['NewSigner']
export type ValidationMetadataRecord = ApiComponents['schemas']['ValidateMetadata']
export type RequestedByRecord = ApiComponents['schemas']['RequestedBy']
export type SettingsRecord = ApiComponents['schemas']['Settings']
export type FileListResponseData = ApiOcsResponseData<ApiOperations['file-list'], 200>
export type FileListEntry = FileListResponseData['data'][number]
export type FileValidationResponse = ApiOcsResponseData<ApiOperations['file-validate-uuid'], 200>
export type FileValidationSigner = NonNullable<FileValidationResponse['signers']>[number]
export type RequestSignatureCreatePayload = ApiRequestJsonBody<ApiOperations['request_signature-request']>
export type RequestSignatureUpdatePayload = ApiRequestJsonBody<ApiOperations['request_signature-update-sign']>
export type RequestSignaturePayload = RequestSignatureCreatePayload | RequestSignatureUpdatePayload
export type RequestSignatureResponse = ApiOcsResponseData<ApiOperations['request_signature-update-sign'], 200>
export type RequestSignatureSignerPayload = NonNullable<RequestSignatureUpdatePayload['signers']>[number]
export type RequestSignatureSignerResponse = NonNullable<RequestSignatureResponse['signers']>[number]
export type RequestSignatureVisibleElementPayload = NonNullable<RequestSignatureUpdatePayload['visibleElements']>[number]
export type FileStatus = FileListEntry['status']
export type FileStatusText = FileListEntry['statusText']
export type SelectedFileView = Pick<FileListEntry, 'id' | 'nodeId' | 'name' | 'status' | 'statusText'>
export type SigningModeState = 'sync' | 'async'
export type WorkerTypeState = 'local' | 'external'
export type SignatureEngineId = 'JSignPdf' | 'PhpNative'
export type CertificateEngineId = 'openssl' | 'cfssl' | 'none'
export type SponsorshipType = 'self' | 'requester'

export type AdminDocMdpLevelOption = {
	value: number
	label: string
	description: string
}
export type AdminDocMdpConfigState = {
	enabled: boolean
	defaultLevel: number
	availableLevels: AdminDocMdpLevelOption[]
}
export type AdminInitialState = {
	docmdp_config: AdminDocMdpConfigState
	signature_engine: SignatureEngineId
	signing_mode: SigningModeState
	worker_type: WorkerTypeState
	parallel_workers: string
	show_confetti_after_signing: boolean
	crl_external_validation_enabled: boolean
	ldap_extension_available: boolean
	envelope_enabled: boolean
	free_credits_enabled: boolean
	free_credits_uses: number
	one_time_signing_enabled: boolean
	sponsorship_enabled: boolean
	product_default_currency: string
	product_sign_document_price: number
	product_certificate_access_price: number
	certificate_gate_enabled: boolean
	certificate_validity_days: number
	files_list_show_signers: boolean
	files_list_next_enabled: boolean
	visible_elements_next_enabled: boolean
	public_upload_landing_enabled: boolean
	public_account_creation_enabled: boolean
	public_accept_terms_enabled: boolean
	public_upload_login_provider_id: number
	user_oidc_providers: Array<{ id: number, label: string }>
	oidc_sso_handoff_enabled: boolean
	securysign_provider_id: number
	securysign_url: string
	tendaworld_url: string
	mimi_url: string
	securysign_signing_secret_set: boolean
	securysign_signing_enabled: boolean
	mimi_client_secret_set: boolean
	mimi_client_id: string
	mimi_passkey_enabled: boolean
	enrolment_site: 'tendaworld' | 'mimi'
	phone_mno_routing_v2_enabled: boolean
}

export type RuntimeFileSettingsRecord = FileSettings & Partial<SettingsRecord>

export type EditableFileSettingsDraft = Partial<RuntimeFileSettingsRecord> & {
	path?: string
	allowEdit?: boolean
	requireAuth?: boolean
	newSetting?: string
}

export type VisibleElementDraft = Partial<Omit<VisibleElementRecord, 'coordinates'>> & {
	id?: number | string
	coordinates?: Partial<VisibleElementRecord['coordinates']>
}

export type Sponsorship = {
	type: SponsorshipType
	sponsorUserId?: string
	sponsored?: boolean
}

export type LibresignCapabilities = ApiComponents['schemas']['PublicCapabilities']
