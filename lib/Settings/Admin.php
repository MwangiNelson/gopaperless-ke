<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Settings;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Service\CertificatePolicyService;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\FooterService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Payment\MnoRoutingRegistry;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureProfile\SignatureProfileService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Service\UserOidcProviderService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * @psalm-import-type LibresignAdminSignatureEngine from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignAdminSigningMode from \OCA\Libresign\ResponseDefinitions
 * @psalm-import-type LibresignAdminWorkerType from \OCA\Libresign\ResponseDefinitions
 */
class Admin implements ISettings {
	public const PASSWORD_PLACEHOLDER = '••••••••';

	public function __construct(
		private IInitialState $initialState,
		private IdentifyMethodService $identifyMethodService,
		private CertificateEngineFactory $certificateEngineFactory,
		private CertificatePolicyService $certificatePolicyService,
		private IAppConfig $appConfig,
		private IConfig $config,
		private SignatureTextService $signatureTextService,
		private SignatureBackgroundService $signatureBackgroundService,
		private FooterService $footerService,
		private DocMdpConfigService $docMdpConfigService,
		private SignatureProfileService $signatureProfileService,
		private PhoneOverrideAdminService $phoneOverrideAdminService,
		private MnoRoutingRegistry $mnoRoutingRegistry,
		private UserOidcProviderService $userOidcProviderService,
	) {
	}
	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'libresign-settings');
		Util::addStyle(Application::APP_ID, 'libresign-settings');
		try {
			$signatureParsed = $this->signatureTextService->parse();
			$this->initialState->provideInitialState('signature_text_parsed', $signatureParsed['parsed']);
		} catch (LibresignException $e) {
			$this->initialState->provideInitialState('signature_text_parsed', '');
			$this->initialState->provideInitialState('signature_text_template_error', $e->getMessage());
		}
		$this->initialState->provideInitialState('certificate_engine', $this->certificateEngineFactory->getEngine()->getName());
		$this->initialState->provideInitialState('certificate_policies_oid', $this->certificatePolicyService->getOid());
		$this->initialState->provideInitialState('certificate_policies_cps', $this->certificatePolicyService->getCps());
		$this->initialState->provideInitialState('config_path', $this->appConfig->getValueString(Application::APP_ID, 'config_path'));
		$this->initialState->provideInitialState('default_signature_font_size', SignatureTextService::SIGNATURE_DEFAULT_FONT_SIZE);
		$this->initialState->provideInitialState('default_signature_height', SignatureTextService::DEFAULT_SIGNATURE_HEIGHT);
		$this->initialState->provideInitialState('default_signature_minimum_height', SignatureTextService::MINIMUM_SIGNATURE_HEIGHT);
		$this->initialState->provideInitialState('default_signature_minimum_width', SignatureTextService::MINIMUM_SIGNATURE_WIDTH);
		$this->initialState->provideInitialState('default_signature_text_template', $this->signatureTextService->getDefaultTemplate());
		$this->initialState->provideInitialState('default_signature_width', SignatureTextService::DEFAULT_SIGNATURE_WIDTH);
		$this->initialState->provideInitialState('default_template_font_size', $this->signatureTextService->getDefaultTemplateFontSize());
		$this->initialState->provideInitialState('identify_methods', $this->identifyMethodService->getIdentifyMethodsSettings());
		$this->initialState->provideInitialState('legal_information', $this->appConfig->getValueString(Application::APP_ID, 'legal_information', ''));
		$this->initialState->provideInitialState('signature_available_variables', $this->signatureTextService->getAvailableVariables());
		$this->initialState->provideInitialState('signature_background_type', $this->signatureBackgroundService->getSignatureBackgroundType());
		$this->initialState->provideInitialState('signature_font_size', $this->signatureTextService->getSignatureFontSize());
		$this->initialState->provideInitialState('signature_height', $this->signatureTextService->getFullSignatureHeight());
		$this->initialState->provideInitialState('signature_minimum_enabled', $this->signatureTextService->getMinimumSignatureEnabled());
		$this->initialState->provideInitialState('signature_minimum_height', $this->signatureTextService->getMinimumSignatureHeight());
		$this->initialState->provideInitialState('signature_minimum_width', $this->signatureTextService->getMinimumSignatureWidth());
		$this->initialState->provideInitialState('signature_preview_zoom_level', $this->appConfig->getValueFloat(Application::APP_ID, 'signature_preview_zoom_level', 100));
		$this->initialState->provideInitialState('footer_preview_zoom_level', $this->appConfig->getValueFloat(Application::APP_ID, 'footer_preview_zoom_level', 100));
		$this->initialState->provideInitialState('footer_preview_width', $this->appConfig->getValueInt(Application::APP_ID, 'footer_preview_width', 595));
		$this->initialState->provideInitialState('footer_preview_height', $this->appConfig->getValueInt(Application::APP_ID, 'footer_preview_height', 100));
		$this->initialState->provideInitialState('footer_template_variables', $this->footerService->getTemplateVariablesMetadata());
		$this->initialState->provideInitialState('footer_template', $this->footerService->getTemplate());
		$this->initialState->provideInitialState('footer_template_is_default', $this->footerService->isDefaultTemplate());
		$this->initialState->provideInitialState('signature_engine', $this->getSignatureEngineInitialState());
		$this->initialState->provideInitialState('signature_render_mode', $this->signatureTextService->getRenderMode());
		$this->initialState->provideInitialState('signature_text_template', $this->signatureTextService->getTemplate());
		$this->initialState->provideInitialState('signature_width', $this->signatureTextService->getFullSignatureWidth());
		$this->initialState->provideInitialState('template_font_size', $this->signatureTextService->getTemplateFontSize());
		$this->initialState->provideInitialState('tsa_url', $this->appConfig->getValueString(Application::APP_ID, 'tsa_url', ''));
		$this->initialState->provideInitialState('tsa_policy_oid', $this->appConfig->getValueString(Application::APP_ID, 'tsa_policy_oid', ''));
		$this->initialState->provideInitialState('tsa_auth_type', $this->appConfig->getValueString(Application::APP_ID, 'tsa_auth_type', 'none'));
		$this->initialState->provideInitialState('tsa_username', $this->appConfig->getValueString(Application::APP_ID, 'tsa_username', ''));
		$this->initialState->provideInitialState('tsa_password', $this->appConfig->getValueString(Application::APP_ID, 'tsa_password', self::PASSWORD_PLACEHOLDER));
		$this->initialState->provideInitialState('docmdp_config', $this->docMdpConfigService->getConfig());
		$this->initialState->provideInitialState('signature_flow', $this->appConfig->getValueString(Application::APP_ID, 'signature_flow', \OCA\Libresign\Enum\SignatureFlow::NONE->value));
		$this->initialState->provideInitialState('signing_mode', $this->getSigningModeInitialState());
		$this->initialState->provideInitialState('worker_type', $this->getWorkerTypeInitialState());
		$this->initialState->provideInitialState('identification_documents', $this->appConfig->getValueBool(Application::APP_ID, 'identification_documents', false));
		$this->initialState->provideInitialState('approval_group', $this->appConfig->getValueArray(Application::APP_ID, 'approval_group', ['admin']));
		$this->initialState->provideInitialState('envelope_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'envelope_enabled', true));
		$this->initialState->provideInitialState('parallel_workers', $this->appConfig->getValueString(Application::APP_ID, 'parallel_workers', '4'));
		$this->initialState->provideInitialState('show_confetti_after_signing', $this->appConfig->getValueBool(Application::APP_ID, 'show_confetti_after_signing', true));
		$this->initialState->provideInitialState('crl_external_validation_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'crl_external_validation_enabled', true));
		$this->initialState->provideInitialState('ldap_extension_available', function_exists('ldap_connect'));
		$this->initialState->provideInitialState('appearance_profiles_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'appearance_profiles_enabled', false));

		//	SIGNATURE APPEARANCE PROFILES
		// Prune profiles whose Nextcloud group has been deleted, then expose the
		// clean map plus the ids that were removed so the admin can be informed.
		$reconciledProfiles = $this->signatureProfileService->reconcileConfiguredGroups();
		$this->initialState->provideInitialState('appearance_profiles', $reconciledProfiles['profiles']);
		$this->initialState->provideInitialState('appearance_profiles_removed', $reconciledProfiles['removed']);

		//	FREE SIGNING CREDITS (signup bonus) & ONE-TIME SIGNING OPTION
		$this->initialState->provideInitialState('free_credits_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'free_credits_enabled', true));
		$this->initialState->provideInitialState('free_credits_uses', $this->appConfig->getValueInt(Application::APP_ID, 'free_credits_uses', 2));
		$this->initialState->provideInitialState('one_time_signing_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'one_time_signing_enabled', true));
		$this->initialState->provideInitialState('sponsorship_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'sponsorship_enabled', false));

		//	PRODUCT PRICING DEFAULTS
		$this->initialState->provideInitialState('product_default_currency', $this->appConfig->getValueString(Application::APP_ID, 'product_default_currency', 'KES'));
		$this->initialState->provideInitialState('product_sign_document_price', $this->appConfig->getValueInt(Application::APP_ID, 'product_sign_document_price', 8000));
		$this->initialState->provideInitialState('product_certificate_access_price', $this->appConfig->getValueInt(Application::APP_ID, 'product_certificate_access_price', 30000));

		//	PERSONAL DIGITAL CERTIFICATE GATE
		//	Gate kill-switch defaults to false = inert (nobody paywalled).
		$this->initialState->provideInitialState('certificate_gate_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'certificate_gate_enabled', false));
		$this->initialState->provideInitialState('certificate_validity_days', $this->appConfig->getValueInt(Application::APP_ID, 'certificate_validity_days', 365));

		//	FILES LIST COLUMNS
		$this->initialState->provideInitialState('files_list_show_signers', $this->appConfig->getValueBool(Application::APP_ID, 'files_list_show_signers', true));

		//	FEATURE FLAGS (opt-in "Next" UI rework, default false = legacy)
		$this->initialState->provideInitialState('files_list_next_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'files_list_next_enabled', false));
		$this->initialState->provideInitialState('visible_elements_next_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'visible_elements_next_enabled', false));

		//	PUBLIC ONBOARDING / LANDING PAGE GATES
		//	All public-facing onboarding flows are disabled by default and must
		//	be explicitly enabled by an administrator.
		$this->initialState->provideInitialState('public_upload_landing_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'public_upload_landing_enabled', false));
		$this->initialState->provideInitialState('public_account_creation_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'public_account_creation_enabled', false));
		$this->initialState->provideInitialState('public_accept_terms_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'public_accept_terms_enabled', false));
		// Public Upload login destination; provider id 0 keeps the default Nextcloud login.
		// Independent of the separate OIDC SSO handoff feature.
		$this->initialState->provideInitialState('public_upload_login_provider_id', $this->appConfig->getValueInt(Application::APP_ID, 'public_upload_login_provider_id', 0));
		// Discovered User OIDC providers for the admin login-destination selector.
		$this->initialState->provideInitialState('user_oidc_providers', $this->userOidcProviderService->getAvailableProviders());

		//	OIDC SSO HANDOFF
		//	Gate the public /sso handoff endpoint that sends users through
		//	user_oidc. Disabled by default until the IdP integration is ready.
		$this->initialState->provideInitialState('oidc_sso_handoff_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'oidc_sso_handoff_enabled', false));

		//	SECURYSIGN
		//	Only whether a signing secret is stored reaches the page, never the secret.
		$this->initialState->provideInitialState('securysign_provider_id', $this->appConfig->getValueInt(Application::APP_ID, 'securysign_provider_id', 0));
		$this->initialState->provideInitialState('securysign_url', $this->appConfig->getValueString(Application::APP_ID, 'securysign_url', ''));
		$addresses = SecurySignService::splitAddresses(
			$this->appConfig->getValueString(Application::APP_ID, 'tendaworld_url', ''),
			$this->appConfig->getValueString(Application::APP_ID, 'mimi_url', ''),
		);
		$this->initialState->provideInitialState('tendaworld_url', $addresses['website']);
		$this->initialState->provideInitialState('mimi_url', $addresses['mimi']);
		$this->initialState->provideInitialState('securysign_signing_secret_set', $this->appConfig->getValueString(Application::APP_ID, 'securysign_signing_secret', '') !== '');
		$this->initialState->provideInitialState('securysign_signing_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'securysign_signing_enabled', true));
		$this->initialState->provideInitialState('mimi_client_secret_set', $this->appConfig->getValueString(Application::APP_ID, 'mimi_client_secret', '') !== '');
		$this->initialState->provideInitialState('mimi_client_id', $this->appConfig->getValueString(Application::APP_ID, 'mimi_client_id', ''));
		$this->initialState->provideInitialState('mimi_passkey_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'mimi_passkey_enabled', true));
		$this->initialState->provideInitialState('enrolment_site', $this->appConfig->getValueString(Application::APP_ID, 'enrolment_site', 'tendaworld'));

		//	SMS & TIARA API CONFIG
		$this->initialState->provideInitialState('sms_otp_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'sms_otp_enabled', false));
		$this->initialState->provideInitialState('tiara_api_key_set', $this->appConfig->getValueString(Application::APP_ID, 'tiara_api_key', '') !== '');
		$this->initialState->provideInitialState('tiara_api_url', $this->appConfig->getValueString(Application::APP_ID, 'tiara_api_url', ''));
		$this->initialState->provideInitialState('tiara_sender_id', $this->appConfig->getValueString(Application::APP_ID, 'tiara_sender_id', ''));

		//	DPO API CONFIG
		$this->initialState->provideInitialState('dpo_endpoint', $this->appConfig->getValueString(Application::APP_ID, 'dpo_endpoint', ''));
		$this->initialState->provideInitialState('dpo_company_token_set', $this->appConfig->getValueString(Application::APP_ID, 'dpo_company_token', '') !== '');
		$this->initialState->provideInitialState('dpo_service_id', $this->appConfig->getValueString(Application::APP_ID, 'dpo_service_id', ''));
		$this->initialState->provideInitialState('dpo_payment_url', $this->appConfig->getValueString(Application::APP_ID, 'dpo_payment_url', ''));

		//	DARAJA API CONFIG
		$this->initialState->provideInitialState('daraja_base_url', $this->appConfig->getValueString(Application::APP_ID, 'daraja_base_url', ''));
		$this->initialState->provideInitialState('daraja_consumer_key', $this->appConfig->getValueString(Application::APP_ID, 'daraja_consumer_key', ''));
		$this->initialState->provideInitialState('daraja_consumer_secret_set', $this->appConfig->getValueString(Application::APP_ID, 'daraja_consumer_secret', '') !== '');
		$this->initialState->provideInitialState('daraja_pass_key_set', $this->appConfig->getValueString(Application::APP_ID, 'daraja_pass_key', '') !== '');
		$this->initialState->provideInitialState('daraja_shortcode', $this->appConfig->getValueString(Application::APP_ID, 'daraja_shortcode', ''));

		//	GOPAPERLESS CALLBACK CONFIG
		$this->initialState->provideInitialState('gopaperless_callback_base_url', $this->appConfig->getValueString(Application::APP_ID, 'gopaperless_callback_base_url', ''));

		//	PHONE NUMBER EXCEPTIONS (admin-managed Safaricom overrides)
		$this->initialState->provideInitialState('phone_mno_routing_v2_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'phone_mno_routing_v2_enabled', false));
		$this->initialState->provideInitialState('phone_overrides', $this->phoneOverrideAdminService->listAll());
		$this->initialState->provideInitialState('default_phone_region', $this->config->getSystemValueString('default_phone_region', 'KE'));

		$this->initialState->provideInitialState('phone_override_options', [
			'mnos' => $this->mnoRoutingRegistry->supportedMnosByRegion(),
			'providers' => array_map(
				fn (PaymentProvider $provider): array => [
					'value' => $provider->value,
					'label' => match ($provider) {
						PaymentProvider::DPO => 'DPO',
						PaymentProvider::DARAJA => 'Daraja',
					},
				],
				PaymentProvider::cases(),
			),
		]);

		// PAYMENT VERIFICATION DRIVER SETTINGS
		$this->initialState->provideInitialState('payment_verification_dispatcher', $this->getPaymentVerificationDispatcherInitialState());

		// WEBHOOK OTP CONFIG
		$this->initialState->provideInitialState('webhook_otp_enabled', $this->appConfig->getValueBool(Application::APP_ID, 'webhook_otp_enabled', false));
		$this->initialState->provideInitialState('webhook_otp_url', $this->appConfig->getValueString(Application::APP_ID, 'webhook_otp_url', ''));
		$this->initialState->provideInitialState('webhook_otp_shared_secret_set', $this->appConfig->getValueString(Application::APP_ID, 'webhook_otp_shared_secret', '') !== '');

		return new TemplateResponse(Application::APP_ID, 'admin_settings');
	}

	/**
	 * @psalm-return 'libresign'
	 */
	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * @psalm-return 100
	 */
	#[\Override]
	public function getPriority(): int {
		return 100;
	}

	/** @return LibresignAdminSignatureEngine */
	private function getSignatureEngineInitialState(): string {
		$engine = $this->appConfig->getValueString(Application::APP_ID, 'signature_engine', 'JSignPdf');
		if ($engine === 'PhpNative') {
			return $engine;
		}
		return 'JSignPdf';
	}

	/** @return LibresignAdminSigningMode */
	private function getSigningModeInitialState(): string {
		$mode = $this->appConfig->getValueString(Application::APP_ID, 'signing_mode', 'sync');
		if ($mode === 'async') {
			return $mode;
		}
		return 'sync';
	}

	/** @return LibresignAdminWorkerType */
	private function getWorkerTypeInitialState(): string {
		$workerType = $this->appConfig->getValueString(Application::APP_ID, 'worker_type', 'local');
		if ($workerType === 'external') {
			return $workerType;
		}
		return 'local';
	}

	/**
	 * @return 'nextcloud'|'rabbitmq'
	 */
	private function getPaymentVerificationDispatcherInitialState(): string {
		$dispatcher = $this->appConfig->getValueString(
			Application::APP_ID,
			'payment_verification_dispatcher',
			'nextcloud'
		);

		if ($dispatcher === 'rabbitmq') {
			return $dispatcher;
		}

		return 'nextcloud';
	}
}
