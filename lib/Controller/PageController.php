<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\JSActions;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Middleware\Attribute\PrivateValidation;
use OCA\Libresign\Middleware\Attribute\RequireSetupOk;
use OCA\Libresign\Middleware\Attribute\RequireSignRequestUuid;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\DocMdp\ConfigService;
use OCA\Libresign\Service\File\FileListService;
use OCA\Libresign\Service\FileAccessService;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\TokenService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\RequestSignatureService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\SessionService;
use OCA\Libresign\Service\SignerElementsService;
use OCA\Libresign\Service\SignFileService;
use OCA\Viewer\Event\LoadViewer;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends AEnvironmentPageAwareController {
	public function __construct(
		IRequest $request,
		protected IUserSession $userSession,
		private SessionService $sessionService,
		private IInitialState $initialState,
		private AccountService $accountService,
		protected SignFileService $signFileService,
		protected RequestSignatureService $requestSignatureService,
		private SignerElementsService $signerElementsService,
		protected IL10N $l10n,
		private IdentifyMethodService $identifyMethodService,
		private IAppConfig $appConfig,
		private FileService $fileService,
		private FileListService $fileListService,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequestMapper,
		private FileAccessService $fileAccessService,
		private LoggerInterface $logger,
		private ValidateHelper $validateHelper,
		private IEventDispatcher $eventDispatcher,
		private IURLGenerator $urlGenerator,
		private ConfigService $docMdpConfigService,
		private IAppManager $appManager,
	) {
		parent::__construct(
			request: $request,
			signFileService: $signFileService,
			l10n: $l10n,
			userSession: $userSession,
		);
	}

	/**
	 * Index page
	 *
	 * Authenticated users get the main app. Anonymous visitors are redirected
	 * to the public upload landing (marketing on-ramp) instead of being
	 * bounced to the Nextcloud login screen — hence PublicPage with a guest
	 * redirect rather than an authed-only route.
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>|RedirectResponse<Http::STATUS_SEE_OTHER, array{}>
	 *
	 * 200: OK
	 * 303: Anonymous visitor redirected to the public upload landing
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[RequireSetupOk(template: 'main')]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse|RedirectResponse {
		if (!$this->userSession->isLoggedIn()) {
			if (!$this->appConfig->getValueBool(Application::APP_ID, 'public_upload_landing_enabled', false)) {
				return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
			}
			return new RedirectResponse($this->urlGenerator->linkToRoute('libresign.page.publicUpload'));
		}
		return $this->renderMainApp();
	}

	/**
	 * Render the authenticated main app shell.
	 *
	 * Shared by the authed page routes (index, indexF, indexFPath, signF,
	 * signFPath). Kept separate from index() so these internal callers never
	 * trigger index()'s anonymous → upload-landing redirect.
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 */
	private function renderMainApp(): TemplateResponse {
		$this->initialState->provideInitialState('config', $this->accountService->getConfig($this->userSession->getUser()));
		$this->initialState->provideInitialState('filters', $this->accountService->getConfigFilters($this->userSession->getUser()));
		$this->initialState->provideInitialState('sorting', $this->accountService->getConfigSorting($this->userSession->getUser()));
		$this->initialState->provideInitialState('certificate_engine', $this->accountService->getCertificateEngineName());
		// SecurySign users sign with SecurySign's certificate, so the account page
		// shows that one instead of a local certificate they would never use.
		$securySign = \OCP\Server::get(SecurySignService::class);
		$securySignSigns = $securySign->signs();
		$this->initialState->provideInitialState('securysign_signs', $securySignSigns);
		if ($securySignSigns) {
			$this->initialState->provideInitialState('securysign_profile_url', $securySign->profileUrl());
		}
		// Initial state refuses null; a missing key reads as null on the page.
		$securySignCertificate = $securySignSigns ? $securySign->signingCertificate() : null;
		if ($securySignCertificate !== null) {
			$this->initialState->provideInitialState('securysign_certificate', $securySignCertificate);
		}

		try {
			$this->validateHelper->canRequestSign($this->userSession->getUser());
			$this->initialState->provideInitialState('can_request_sign', true);
		} catch (LibresignException) {
			$this->initialState->provideInitialState('can_request_sign', false);
		}

		$this->provideSignerSignatues();
		$this->initialState->provideInitialState('identify_methods', $this->identifyMethodService->getIdentifyMethodsSettings());
		$this->initialState->provideInitialState('signature_flow', $this->appConfig->getValueString(Application::APP_ID, 'signature_flow', \OCA\Libresign\Enum\SignatureFlow::NONE->value));
		$this->initialState->provideInitialState('docmdp_config', $this->docMdpConfigService->getConfig());
		$this->initialState->provideInitialState('legal_information', $this->appConfig->getValueString(Application::APP_ID, 'legal_information'));

		Util::addScript(Application::APP_ID, 'libresign-main');
		Util::addStyle(Application::APP_ID, 'libresign-main');

		Util::addScript(Application::APP_ID, 'gopaperless');
		Util::addStyle(Application::APP_ID, 'gopaperless');

		if (class_exists(LoadViewer::class)) {
			$this->eventDispatcher->dispatchTyped(new LoadViewer());
		}

		$response = new TemplateResponse(Application::APP_ID, 'main');

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain('\'self\'');
		$policy->addAllowedWorkerSrcDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/**
	 * Public upload landing page (anonymous entry point)
	 *
	 * Renders the upload view for a visitor with no Nextcloud session, as a
	 * marketing on-ramp: the dropzone and value prop show immediately, and
	 * auth is gated at the first action.
	 *
	 * Reachable directly as the shareable public URL, and as the redirect
	 * target for anonymous visitors hitting the app root (see index()).
	 *
	 * A logged-in visitor are sent straight to the authenticated upload/request view instead.
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>|RedirectResponse<Http::STATUS_SEE_OTHER, array{}>
	 *
	 * 200: OK
	 * 303: Logged-in visitor redirected to the authenticated request view
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/p/upload')]
	public function publicUpload(): TemplateResponse|RedirectResponse {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'public_upload_landing_enabled', false)) {
			if ($this->userSession->isLoggedIn()) {
				return new RedirectResponse($this->urlGenerator->linkToRoute('libresign.page.indexFPath', ['path' => 'request']));
			}
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
		}

		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('libresign.page.indexFPath', ['path' => 'request']));
		}
		return $this->landingPage();
	}

	/**
	 * GoPaperless's Terms of Use and Data Protection Policy, as pages in the
	 * landing's look. Anyone can read them, signed in or not, and the onboarding
	 * gate lets them through (SecurySignMiddleware).
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/p/{document}', requirements: ['document' => 'terms|privacy'])]
	public function legal(string $document): TemplateResponse {
		return $this->landingPage();
	}

	/** @return TemplateResponse<Http::STATUS_OK, array{}> */
	private function landingPage(): TemplateResponse {
		$this->initialState->provideInitialState('config', $this->accountService->getConfig($this->userSession->getUser()));
		$this->initialState->provideInitialState('public_upload_oidc_login_url', $this->getPublicUploadOidcLoginUrl());

		Util::addScript(Application::APP_ID, 'libresign-main');
		Util::addStyle(Application::APP_ID, 'libresign-main');

		Util::addScript(Application::APP_ID, 'gopaperless');
		Util::addStyle(Application::APP_ID, 'gopaperless');

		if (class_exists(LoadViewer::class)) {
			$this->eventDispatcher->dispatchTyped(new LoadViewer());
		}

		$response = new TemplateResponse(Application::APP_ID, 'main', [], TemplateResponse::RENDER_AS_PUBLIC);

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain('\'self\'');
		$policy->addAllowedWorkerSrcDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/**
	 * Build the login URL for the Public Upload landing page's "Sign in" /
	 * "Get started" gate when an administrator has configured a User OIDC
	 * provider as the login destination.
	 *
	 * Returns an empty string when no User OIDC provider is configured
	 * (provider id 0, the default) or when the `user_oidc` app is not enabled,
	 * allowing the caller to fall back to the existing Nextcloud `/login` flow.
	 */
	private function getPublicUploadOidcLoginUrl(): string {
		$providerId = $this->appConfig->getValueInt(Application::APP_ID, 'public_upload_login_provider_id', 0);
		if ($providerId <= 0) {
			return '';
		}
		if (!$this->appManager->isEnabledForUser('user_oidc')) {
			return '';
		}
		return $this->urlGenerator->linkToRoute('user_oidc.login.login', [
			'providerId' => $providerId,
			'redirectUrl' => $this->urlGenerator->linkToRoute('libresign.page.indexFPath', ['path' => 'request']),
		]);
	}

	/**
	 * Index page to authenticated users
	 *
	 * This router is used to be possible render pages with /f/, is a
	 * workaround at frontend side to identify pages with authenticated accounts
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk(template: 'main')]
	#[FrontpageRoute(verb: 'GET', url: '/f/')]
	public function indexF(): TemplateResponse {
		return $this->renderMainApp();
	}

	/**
	 * Incomplete page
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/f/incomplete')]
	public function incomplete(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'libresign-main');
		Util::addStyle(Application::APP_ID, 'libresign-main');

		Util::addScript(Application::APP_ID, 'gopaperless');
		Util::addStyle(Application::APP_ID, 'gopaperless');
		$response = new TemplateResponse(Application::APP_ID, 'main');
		return $response;
	}

	/**
	 * Incomplete page in full screen
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/p/incomplete')]
	public function incompleteP(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'libresign-main');
		Util::addStyle(Application::APP_ID, 'libresign-main');

		Util::addScript(Application::APP_ID, 'gopaperless');
		Util::addStyle(Application::APP_ID, 'gopaperless');
		$response = new TemplateResponse(Application::APP_ID, 'main', [], TemplateResponse::RENDER_AS_BASE);
		return $response;
	}

	/**
	 * Main page to authenticated signer with a path
	 *
	 * The path is used only by frontend
	 *
	 * @param string $path The path that was sent from frontend
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk(template: 'main')]
	#[FrontpageRoute(verb: 'GET', url: '/f/{path}', requirements: ['path' => '.+'])]
	public function indexFPath(string $path): TemplateResponse {
		if (preg_match('/validation\/(?<uuid>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/', $path, $matches)) {
			$signRequest = null;

			try {
				$this->fileService->setFileByUuid($matches['uuid']);
			} catch (LibresignException) {
				try {
					$this->fileService->setFileBySignerUuid($matches['uuid']);
					$signRequest = $this->signRequestMapper->getBySignerUuidAndUserId($matches['uuid']);
				} catch (LibresignException) {
					throw new LibresignException(json_encode([
						'action' => JSActions::ACTION_DO_NOTHING,
						'errors' => [['message' => $this->l10n->t('Invalid UUID')]],
					]), Http::STATUS_NOT_FOUND);
				}
			}

			if ($signRequest) {
				$this->fileService->setSignRequest($signRequest);
			}

			$fileInfo = $this->fileService
				->setIdentifyMethodId($this->sessionService->getIdentifyMethodId())
				->setHost($this->request->getServerHost())
				->setMe($this->userSession->getUser())
				->showVisibleElements()
				->showSigners()
				->showSettings()
				->showMessages()
				->showValidateFile()
				->toArray();
			$fileInfo['canViewDocument'] = $this->canViewValidationDocument((int)($fileInfo['id'] ?? 0));
			$this->initialState->provideInitialState('file_info', $fileInfo);
		} elseif (preg_match('/sign\/(?<uuid>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/', $path, $matches)) {
			try {
				$signRequest = $this->signFileService->getSignRequestByUuid($matches['uuid']);
				if ($signRequest->getStatusEnum() === \OCA\Libresign\Enum\SignRequestStatus::SIGNED) {
					$file = $this->signFileService->getFile($signRequest->getFileId());
					$redirectUrl = $this->urlGenerator->linkToRouteAbsolute('libresign.page.indexFPath', [
						'path' => 'validation/' . $file->getUuid(),
					]);
					throw new LibresignException(json_encode([
						'action' => JSActions::ACTION_REDIRECT,
						'redirect' => $redirectUrl,
					]), Http::STATUS_SEE_OTHER);
				}
				$file = $this->fileService
					->setFile($this->signFileService->getFile($signRequest->getFileId()))
					->setSignRequest($signRequest)
					->setMe($this->userSession->getUser())
					->showSettings()
					->toArray();
				$this->initialState->provideInitialState('needIdentificationDocuments', $file['settings']['needIdentificationDocuments'] ?? false);
				$this->initialState->provideInitialState('identificationDocumentsWaitingApproval', $file['settings']['identificationDocumentsWaitingApproval'] ?? false);
			} catch (LibresignException $e) {
				throw $e;
			} catch (\Throwable) {
				throw new LibresignException(json_encode([
					'action' => JSActions::ACTION_DO_NOTHING,
					'errors' => [['message' => $this->l10n->t('Invalid UUID')]],
				]), Http::STATUS_NOT_FOUND);
			}
		}
		return $this->renderMainApp();
	}


	/**
	 * Sign page to authenticated signer
	 *
	 * @param string $uuid Sign request uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk]
	#[PublicPage]
	#[RequireSignRequestUuid(redirectIfSignedToValidation: true, allowIdDocs: true)]
	#[FrontpageRoute(verb: 'GET', url: '/f/sign/{uuid}')]
	public function signF(string $uuid): TemplateResponse {
		$this->initialState->provideInitialState('action', JSActions::ACTION_SIGN_INTERNAL);
		return $this->renderMainApp();
	}

	/**
	 * Sign page to authenticated signer with the path of file
	 *
	 * The path is used only by frontend
	 *
	 * @param string $uuid Sign request uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk]
	#[PublicPage]
	#[RequireSignRequestUuid(redirectIfSignedToValidation: true, allowIdDocs: true)]
	#[FrontpageRoute(verb: 'GET', url: '/f/sign/{uuid}/{path}', requirements: ['path' => '.+'])]
	public function signFPath(string $uuid): TemplateResponse {
		$this->initialState->provideInitialState('action', JSActions::ACTION_SIGN_INTERNAL);
		return $this->renderMainApp();
	}

	/**
	 * Sign page to unauthenticated signer
	 *
	 * The path is used only by frontend
	 *
	 * @param string $uuid Sign request uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk]
	#[PublicPage]
	#[RequireSignRequestUuid(redirectIfSignedToValidation: true, allowIdDocs: true)]
	#[FrontpageRoute(verb: 'GET', url: '/p/sign/{uuid}/{path}', requirements: ['path' => '.+'])]
	public function signPPath(string $uuid): TemplateResponse {
		return $this->sign($uuid);
	}

	/**
	 * Sign page to unauthenticated signer
	 *
	 * The path is used only by frontend
	 *
	 * @param string $uuid Sign request uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[PrivateValidation]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk]
	#[PublicPage]
	#[RequireSignRequestUuid(redirectIfSignedToValidation: true, allowIdDocs: true)]
	#[FrontpageRoute(verb: 'GET', url: '/p/sign/{uuid}')]
	public function sign(string $uuid): TemplateResponse {
		$this->initialState->provideInitialState('action', JSActions::ACTION_SIGN);
		$config = $this->accountService->getConfig($this->userSession->getUser());
		$this->initialState->provideInitialState('filename', $this->getFileEntity()->getName());
		$file = $this->fileService
			->setFile($this->getFileEntity())
			->setSignRequest($this->getSignRequestEntity())
			->setHost($this->request->getServerHost())
			->setMe($this->userSession->getUser())
			->setSignerIdentified()
			->setIdentifyMethodId($this->sessionService->getIdentifyMethodId())
			->showVisibleElements()
			->showSigners()
			->showSettings()
			->toArray();
		$this->initialState->provideInitialState('config', array_merge($config, [
			'identificationDocumentsFlow' => $file['settings']['needIdentificationDocuments'] ?? false,
		]));
		$this->initialState->provideInitialState('id', $file['id']);
		$this->initialState->provideInitialState('nodeId', $file['nodeId']);
		$this->initialState->provideInitialState('needIdentificationDocuments', $file['settings']['needIdentificationDocuments'] ?? false);
		$this->initialState->provideInitialState('identificationDocumentsWaitingApproval', $file['settings']['identificationDocumentsWaitingApproval'] ?? false);
		$this->initialState->provideInitialState('status', $file['status']);
		$this->initialState->provideInitialState('statusText', $file['statusText']);
		$this->initialState->provideInitialState('signers', $file['signers']);
		$this->initialState->provideInitialState('visibleElements', $file['visibleElements'] ?? []);
		$this->initialState->provideInitialState('sign_request_uuid', $uuid);
		$this->provideSignerSignatues();
		$this->initialState->provideInitialState('token_length', TokenService::TOKEN_LENGTH);
		$this->initialState->provideInitialState('description', $this->getSignRequestEntity()->getDescription() ?? '');
		if ($this->getFileEntity()->getNodeType() === 'envelope') {
			$this->initialState->provideInitialState('pdfs', []);
			$this->initialState->provideInitialState('envelopeFiles', $this->getEnvelopeChildFiles());
		} else {
			$this->initialState->provideInitialState('pdfs', $this->getPdfUrls());
			$this->initialState->provideInitialState('envelopeFiles', []);
		}
		$this->initialState->provideInitialState('nodeId', $this->getFileEntity()->getNodeId());
		$this->initialState->provideInitialState('nodeType', $this->getFileEntity()->getNodeType());

		Util::addScript(Application::APP_ID, 'libresign-external');
		Util::addStyle(Application::APP_ID, 'libresign-external');
		if (class_exists(LoadViewer::class)) {
			$this->eventDispatcher->dispatchTyped(new LoadViewer());
		}
		$response = new TemplateResponse(Application::APP_ID, 'external', [], TemplateResponse::RENDER_AS_BASE);

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedWorkerSrcDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	private function provideSignerSignatues(): void {
		$signatures = [];
		if ($this->userSession->getUser()) {
			$signatures = $this->signerElementsService->getUserElements($this->userSession->getUser()->getUID());
		} else {
			$signatures = $this->signerElementsService->getElementsFromSessionAsArray();
		}
		$this->initialState->provideInitialState('user_signatures', $signatures);
	}

	/**
	 * @return string[] Array of PDF URLs
	 */
	private function getPdfUrls(): array {
		return $this->signFileService->getPdfUrlsForSigning(
			$this->getFileEntity(),
			$this->getSignRequestEntity()
		);
	}

	private function getEnvelopeChildFiles(): array {
		$parentFileId = $this->getFileEntity()->getId();
		$currentSignRequest = $this->getSignRequestEntity();
		$childFiles = $this->fileMapper->getChildrenFiles($parentFileId);
		$childSignRequests = $this->signRequestMapper->getByEnvelopeChildrenAndIdentifyMethod(
			$parentFileId,
			$currentSignRequest->getId()
		);

		$signRequestsByFileId = [];
		foreach ($childSignRequests as $childSignRequest) {
			$signRequestsByFileId[$childSignRequest->getFileId()] = true;
		}

		foreach ($childFiles as $childFile) {
			if (!isset($signRequestsByFileId[$childFile->getId()])) {
				$this->logger->warning('Missing sign request for envelope child file', [
					'parentFileId' => $parentFileId,
					'childFileId' => $childFile->getId(),
					'signRequestId' => $currentSignRequest->getId(),
				]);
			}
		}

		return $this->fileListService->formatEnvelopeChildFilesForSignRequest(
			$childFiles,
			$childSignRequests,
			$currentSignRequest,
		);
	}

	/**
	 * Use UUID of file to get PDF
	 *
	 * @param string $uuid File uuid
	 * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_NOT_FOUND, array<empty>, array{}>
	 *
	 * 200: OK
	 * 401: Validation page not accessible if unauthenticated
	 * 404: File not found
	 */
	#[PrivateValidation]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/pdf/{uuid}')]
	public function getPdf($uuid) {
		try {
			if ($this->appConfig->getValueBool(Application::APP_ID, 'restrict_validation_document_access', false)) {
				$libresignFile = $this->fileMapper->getByUuid($uuid);
				if (!$this->fileAccessService->userCanViewFileById($libresignFile->getId())) {
					throw new LibresignException(json_encode([
						'action' => JSActions::ACTION_DO_NOTHING,
						'title' => $this->l10n->t('Access denied'),
						'errors' => [['message' => $this->l10n->t('You do not have permission to view this document')]],
					]), Http::STATUS_FORBIDDEN);
				}
			}
			$file = $this->accountService->getPdfByUuid($uuid);
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $file->getMimeType()]);
	}

	/**
	 * Check whether the current user can view the validation document.
	 *
	 * Used to control the visibility of the "View document" action.
	 */
	private function canViewValidationDocument(int $fileId): bool {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'restrict_validation_document_access', false)) {
			return true;
		}
		if ($fileId <= 0) {
			return false;
		}
		return $this->fileAccessService->userCanViewFileById($fileId);
	}

	/**
	 * Use UUID of user to get PDF
	 *
	 * @param string $uuid Sign request uuid
	 * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>
	 *
	 * 200: OK
	 * 401: Validation page not accessible if unauthenticated
	 */
	#[PrivateValidation]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSignRequestUuid(allowIdDocs: true)]
	#[PublicPage]
	#[RequireSetupOk]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/pdf/{uuid}')]
	public function getPdfFile($uuid): FileDisplayResponse {
		$files = $this->getNextcloudFiles();
		if (empty($files)) {
			throw new LibresignException(json_encode([
				'action' => JSActions::ACTION_DO_NOTHING,
				'errors' => [['message' => $this->l10n->t('File not found')]],
			]), Http::STATUS_NOT_FOUND);
		}
		$file = current($files);
		return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $file->getMimeType()]);
	}

	/**
	 * Show validation page
	 *
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 * 401: Validation page not accessible if unauthenticated
	 */
	#[PrivateValidation]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk(template: 'validation')]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/validation')]
	public function validation(): TemplateResponse {
		if ($this->getFileEntity()) {
			$this->initialState->provideInitialState('config',
				$this->accountService->getConfig($this->userSession->getUser())
			);
			$this->initialState->provideInitialState('file', [
				'uuid' => $this->getFileEntity()?->getUuid(),
				'description' => $this->getSignRequestEntity()?->getDescription(),
			]);
			$this->initialState->provideInitialState('filename', $this->getFileEntity()?->getName());
			$this->initialState->provideInitialState('pdfs', $this->getPdfUrls());
			$this->initialState->provideInitialState('signer',
				$this->signFileService->getSignerData(
					$this->userSession->getUser(),
					$this->getSignRequestEntity(),
				)
			);
		}

		Util::addScript(Application::APP_ID, 'libresign-validation');
		Util::addStyle(Application::APP_ID, 'libresign-validation');
		$response = new TemplateResponse(Application::APP_ID, 'validation', [], TemplateResponse::RENDER_AS_BASE);

		return $response;
	}

	/**
	 * Show validation page
	 *
	 * The path is used only by frontend
	 *
	 * @param string $uuid Sign request uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 * 303: Redirected to validation page
	 * 401: Validation page not accessible if unauthenticated
	 */
	#[PrivateValidation]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/validation/{uuid}')]
	public function validationFileWithShortUrl(): TemplateResponse {
		return $this->validationFilePublic($this->request->getParam('uuid'));
	}

	/**
	 * Show validation page
	 *
	 * @param string $uuid Sign request uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk(template: 'main')]
	#[PublicPage]
	#[RequireSignRequestUuid]
	#[FrontpageRoute(verb: 'GET', url: '/reset-password')]
	public function resetPassword(): TemplateResponse {
		$this->initialState->provideInitialState('config',
			$this->accountService->getConfig($this->userSession->getUser())
		);

		Util::addScript(Application::APP_ID, 'libresign-main');
		Util::addStyle(Application::APP_ID, 'libresign-main');

		Util::addScript(Application::APP_ID, 'gopaperless');
		Util::addStyle(Application::APP_ID, 'gopaperless');
		$response = new TemplateResponse(Application::APP_ID, 'reset_password');

		return $response;
	}

	/**
	 * Public page to show validation for a specific file UUID
	 *
	 * @param string $uuid File uuid
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 *
	 * 200: OK
	 * 401: Validation page not accessible if unauthenticated
	 */
	#[PrivateValidation]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireSetupOk(template: 'validation')]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/validation/{uuid}')]
	public function validationFilePublic(string $uuid): TemplateResponse {
		$signRequest = null;
		try {
			$this->signFileService->getFileByUuid($uuid);
			$this->fileService->setFileByUuid($uuid);
		} catch (DoesNotExistException) {
			try {
				$signRequest = $this->signFileService->getSignRequestByUuid($uuid);
				$libresignFile = $this->signFileService->getFile($signRequest->getFileId());
				$this->fileService->setFile($libresignFile);
			} catch (DoesNotExistException) {
				$this->initialState->provideInitialState('action', JSActions::ACTION_DO_NOTHING);
				$this->initialState->provideInitialState('errors', [['message' => $this->l10n->t('Invalid UUID')]]);
			}
		}
		if ($this->userSession->isLoggedIn()) {
			$this->initialState->provideInitialState('config',
				$this->accountService->getConfig($this->userSession->getUser())
			);
			$this->fileService->setMe($this->userSession->getUser());
		} else {
			$this->initialState->provideInitialState('config',
				$this->accountService->getConfig()
			);
		}

		if ($signRequest) {
			$this->fileService->setSignRequest($signRequest);
		}

		$this->initialState->provideInitialState('legal_information', $this->appConfig->getValueString(Application::APP_ID, 'legal_information'));

		$fileInfo = $this->fileService
			->setIdentifyMethodId($this->sessionService->getIdentifyMethodId())
			->setHost($this->request->getServerHost())
			->showVisibleElements()
			->showSigners()
			->showSettings()
			->showMessages()
			->showValidateFile()
			->toArray();
		$fileInfo['canViewDocument'] = $this->canViewValidationDocument((int)($fileInfo['id'] ?? 0));
		$this->initialState->provideInitialState('file_info', $fileInfo);

		Util::addScript(Application::APP_ID, 'libresign-validation');
		if (class_exists(LoadViewer::class)) {
			$this->eventDispatcher->dispatchTyped(new LoadViewer());
		}
		$response = new TemplateResponse(Application::APP_ID, 'validation', [], TemplateResponse::RENDER_AS_BASE);

		return $response;
	}
}
