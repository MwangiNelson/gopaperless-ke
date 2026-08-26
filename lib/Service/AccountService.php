<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use InvalidArgumentException;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\FileTypeMapper;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\UserElement;
use OCA\Libresign\Db\UserElementMapper;
use OCA\Libresign\Enum\CRLReason;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Exception\InvalidPasswordException;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Helper\FileUploadHelper;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\Crl\CrlService;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\ExtendedAccount\ExtendedAccountService;
use OCA\Libresign\Service\PhoneNumber\PhoneNumberService;
use OCA\Settings\Mailer\NewUserMailHelper;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;
use Throwable;

class AccountService {
	/**
	 * Product code used when granting the free signing-credit signup bonus.
	 * Mirrors the frontend DEFAULT_SIGN_PRODUCT_CODE ('SIGN_DOCUMENT').
	 */
	private const SIGNUP_BONUS_PRODUCT_CODE = 'SIGN_DOCUMENT';

	private ?SignRequest $signRequest = null;
	private ?\OCA\Libresign\Db\File $fileData = null;
	private ?\OCP\Files\File $fileToSign = null;

	public function __construct(
		private IL10N $l10n,
		private SignRequestMapper $signRequestMapper,
		private IUserManager $userManager,
		private IAccountManager $accountManager,
		private IRootFolder $root,
		private IMimeTypeDetector $mimeTypeDetector,
		private FileMapper $fileMapper,
		private FileTypeMapper $fileTypeMapper,
		private SignFileService $signFileService,
		private RequestSignatureService $requestSignatureService,
		private CertificateEngineFactory $certificateEngineFactory,
		protected IConfig $config,
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IMountProviderCollection $mountProviderCollection,
		private NewUserMailHelper $newUserMail,
		private IdentifyMethodService $identifyMethodService,
		private IdentifyMethodMapper $identifyMethodMapper,
		private ValidateHelper $validateHelper,
		private IURLGenerator $urlGenerator,
		private Pkcs12Handler $pkcs12Handler,
		private IGroupManager $groupManager,
		private IdDocsService $idDocsService,
		private SignerElementsService $signerElementsService,
		private UserElementMapper $userElementMapper,
		private FolderService $folderService,
		private IClientService $clientService,
		private ITimeFactory $timeFactory,
		private FileUploadHelper $uploadHelper,
		private CrlService $crlService,
		private LoggerInterface $logger,
		private PhoneNumberService $phoneNumberService,
		private EntitlementService $entitlementService,
		private ExtendedAccountService $extendedAccountService,
		private IDBConnection $connection,
	) {
	}

	public function validateCreateToSign(array $data): array {
		if (!UUIDUtil::validateUUID($data['uuid'])) {
			throw new LibresignException($this->l10n->t('Invalid UUID'), 1);
		}

		try {
			$signRequest = $this->getSignRequestByUuid($data['uuid']);
			$data['signRequest'] = $signRequest;
		} catch (\Throwable) {
			throw new LibresignException($this->l10n->t('UUID not found'), 1);
		}

		$identifyMethods = $this->identifyMethodService
			->getIdentifyMethodsFromSignRequestId($signRequest->getId());

		if (!array_key_exists('identify', $data['user'])) {
			throw new LibresignException(
				$this->l10n->t('Invalid identification method'),
				1
			);
		}

		foreach ($data['user']['identify'] as $method => $value) {
			if (!array_key_exists($method, $identifyMethods)) {
				throw new LibresignException($this->l10n->t('Invalid identification method'), 1);
			}

			foreach ($identifyMethods[$method] as $identifyMethod) {
				$identifyMethod->validateToCreateAccount($value);
			}
		}

		$email = trim(
			strtolower(
				(string)($data['user']['identify']['email'] ?? '')
			)
		);

		if ($email === '') {
			throw new LibresignException(
				$this->l10n->t('Email is required'),
				1
			);
		}

		if ($this->userManager->get($email) !== null) {
			throw new LibresignException(
				$this->l10n->t('Account with this email already exists.'),
				1
			);
		}

		$data['user']['identify']['email'] = $email;

		if (empty($data['password'])) {
			throw new LibresignException(
				$this->l10n->t('Password is required'),
				1
			);
		}

		$file = $this->getFileByUuid($data['uuid']);

		if (empty($file['fileToSign'])) {
			throw new LibresignException(
				$this->l10n->t('File not found')
			);
		}

		$data['file'] = $file;

		if (!empty($data['phoneNumber']) && is_string($data['phoneNumber'])) {
			$resolved = $this->phoneNumberService->resolve(
				$data['phoneNumber']
			);

			if (!$resolved->valid) {
				throw new LibresignException(
					$this->l10n->t('Please provide a valid phone number.')
				);
			}

			$data['phoneNumber'] = $resolved->e164;
		}

		return $data;
	}

	public function getFileByUuid(string $uuid): array {
		$signRequest = $this->getSignRequestByUuid($uuid);
		if (!$this->fileData instanceof \OCA\Libresign\Db\File) {
			$this->fileData = $this->fileMapper->getById($signRequest->getFileId());

			$nodeId = $this->fileData->getNodeId();

			$fileToSign = $this->root->getUserFolder($this->fileData->getUserId())->getFirstNodeById($nodeId);

			$this->logger->error('[AccountService] getFileByUuid debug', [
				'uuid' => $uuid,
				'signRequestId' => $signRequest->getId(),
				'fileId' => $this->fileData->getId(),
				'fileUuid' => $this->fileData->getUuid(),
				'nodeId' => $nodeId,
				'nodeClass' => $fileToSign ? get_class($fileToSign) : 'null',
				'parentFileId' => $this->fileData->getParentFileId(),
				'nodeType' => $this->fileData->getNodeType(),
			]);

			if ($fileToSign) {
				$this->fileToSign = $fileToSign;
			}
		}
		return [
			'fileData' => $this->fileData,
			'fileToSign' => $this->fileToSign
		];
	}

	public function validateCertificateData(array $data): void {
		if (array_key_exists('email', $data['user']) && empty($data['user']['email'])) {
			throw new LibresignException($this->l10n->t('You must have an email. You can define the email in your profile.'), 1);
		}
		if (!empty($data['user']['email']) && !filter_var($data['user']['email'], FILTER_VALIDATE_EMAIL)) {
			throw new LibresignException($this->l10n->t('Invalid email'), 1);
		}
		if (empty($data['signPassword'])) {
			throw new LibresignException($this->l10n->t('Password to sign is mandatory'), 1);
		}
	}

	/**
	 * Get signRequest by Uuid
	 */
	public function getSignRequestByUuid(string $uuid): SignRequest {
		if (!$this->signRequest instanceof SignRequest) {
			$this->signRequest = $this->signRequestMapper->getByUuid($uuid);
		}
		return $this->signRequest;
	}

	public function createToSign(string $uuid, string $email, string $password, ?string $signPassword, ?string $phoneNumber = null): string {
		$signRequest = $this->getSignRequestByUuid($uuid);

		$newUser = $this->userManager->createUser($email, $password);
		$newUser->setDisplayName($signRequest->getDisplayName());
		$newUser->setSystemEMailAddress($email);

		if ($newUser instanceof \OCP\IUser && !empty($phoneNumber)) {
			$this->setPhoneNumber($newUser, $phoneNumber);
			// The user manager in Nextcloud typically persists changes to the IUser object
			// when the object itself is updated. No explicit updateUser call is usually needed.
		}

		$this->extendedAccountService->create(
			$newUser->getUID(),
		);

		$this->updateIdentifyMethodToAccount($signRequest->getId(), $email, $newUser->getUID());

		if ($this->appConfig->getValueString('core', 'newUser.sendEmail', 'yes') === 'yes') {
			try {
				$emailTemplate = $this->newUserMail->generateTemplate($newUser, false);
				$this->newUserMail->sendMail($newUser, $emailTemplate);
			} catch (\Exception $e) {
				$this->logger->error(
					'[AccountService] Failed to send invitation',
					[
						'email' => $email,
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		if ($signPassword) {
			$certificate = $this->pkcs12Handler->generateCertificate(
				[
					'host' => $newUser->getPrimaryEMailAddress(),
					'uid' => 'account:' . $newUser->getUID(),
					'name' => $newUser->getDisplayName()
				],
				$signPassword,
				$newUser->getDisplayName()
			);
			$this->pkcs12Handler->savePfx($newUser->getPrimaryEMailAddress(), $certificate);
		}

		$this->awardSignupBonus($newUser->getUID());

		return $newUser->getUID();
	}

	public function createOnly(
		string $email,
		string $password,
		?string $phoneNumber = null,
	): array {
		$email = trim(strtolower($email));

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new LibresignException('Invalid email address');
		}

		if ($this->userManager->get($email) !== null) {
			throw new LibresignException('Account with the email already exists');
		}

		$resolved = null;

		if (!empty($phoneNumber)) {
			$resolved = $this->phoneNumberService->resolve($phoneNumber);

			if (!$resolved->valid) {
				throw new LibresignException('Invalid phone number');
			}
		}

		$newUser = $this->userManager->createUser($email, $password);
		$newUser->setSystemEMailAddress($email);

		if ($newUser instanceof \OCP\IUser && $resolved !== null) {
			$this->setPhoneNumber($newUser, $resolved->e164);
		}

		$this->extendedAccountService->create(
			$newUser->getUID(),
		);

		if ($this->appConfig->getValueString('core', 'newUser.sendEmail', 'yes') === 'yes') {
			try {
				$emailTemplate = $this->newUserMail->generateTemplate($newUser, false);
				$this->newUserMail->sendMail($newUser, $emailTemplate);
			} catch (\Exception $e) {
				$this->logger->error(
					'[AccountService] Failed to send new user email',
					[
						'email' => $email,
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		$this->awardSignupBonus($newUser->getUID());

		return [
			'message' => 'Success',
			'email' => $newUser->getSystemEMailAddress(),
			'uid' => $newUser->getUID(),
		];
	}

	/**
	 * Record acceptance for every active Terms of Service document. Calls are
	 * idempotent: documents the user has already accepted are left untouched.
	 *
	 * @return int Number of new acceptance records created.
	 */
	public function acceptTermsForUserId(string $userId): int {
		if (!$this->connection->tableExists('termsofservice_terms')
			|| !$this->connection->tableExists('termsofservice_sigs')) {
			return 0;
		}

		$termQuery = $this->connection->getQueryBuilder();
		$termQuery->select('id')
			->from('termsofservice_terms');
		$result = $termQuery->executeQuery();
		$termIds = [];
		while ($row = $result->fetch()) {
			$termIds[] = (int)$row['id'];
		}
		$result->closeCursor();

		$acceptedTerms = 0;
		foreach ($termIds as $termId) {
			$check = $this->connection->getQueryBuilder();
			$check->select($check->expr()->literal(1))
				->from('termsofservice_sigs')
				->where($check->expr()->eq('user_id', $check->createNamedParameter($userId)))
				->andWhere($check->expr()->eq('terms_id', $check->createNamedParameter($termId)))
				->setMaxResults(1);
			$checkResult = $check->executeQuery();
			$exists = $checkResult->fetchOne();
			$checkResult->closeCursor();
			if ($exists) {
				continue;
			}

			$insert = $this->connection->getQueryBuilder();
			$insert->insert('termsofservice_sigs')
				->setValue('user_id', $insert->createNamedParameter($userId))
				->setValue('terms_id', $insert->createNamedParameter($termId))
				->setValue('timestamp', $insert->createNamedParameter(time()));
			$insert->executeStatement();
			$acceptedTerms++;
		}

		$this->logger->info('Accepted Terms of Service on behalf of account', [
			'userId' => $userId,
			'acceptedTerms' => $acceptedTerms,
		]);

		return $acceptedTerms;
	}

	public function getExtendedAccount(
		string $userId,
		?string $email,
	): array {

		$extended = $this->extendedAccountService->getOrCreate(
			$userId,
			$email
		);

		return [
			'createdAt' => $extended->getCreatedAtImmutable()->format(DATE_ATOM),
			'paidCertificate' => $extended->getPaidCertificate(),
			'certPaidAt' => $extended->getCertPaidAtImmutable()?->format(DATE_ATOM),
			'validUntil' => $extended->getValidUntilImmutable()?->format(DATE_ATOM),
			// Gate-aware: applies the admin kill-switch on top of DB state.
			// This boolean is the FE's single source of truth for gating.
			'isCertificateValid' => $this->extendedAccountService->isCertificateValidForAccount($extended),
		];
	}

	/**
	 * Grant the free signing-credit signup bonus to a newly created account.
	 *
	 * Controlled by two admin settings:
	 * - free_credits_enabled (bool, default true): whether to award anything
	 * - free_credits_uses (int, default 2): how many uses to grant
	 *
	 * Never throws: a bonus failure must not break account creation.
	 */
	private function awardSignupBonus(string $userId): void {
		if ($userId === '') {
			return;
		}
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'free_credits_enabled', true)) {
			return;
		}
		$uses = $this->appConfig->getValueInt(Application::APP_ID, 'free_credits_uses', 2);
		if ($uses <= 0) {
			return;
		}
		try {
			$this->entitlementService->create($userId, self::SIGNUP_BONUS_PRODUCT_CODE, $uses);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[AccountService] Failed to award signup bonus credits',
				[
					'userId' => $userId,
					'uses' => $uses,
					'exception' => $e->getMessage(),
				]
			);
		}
	}

	public function getCertificateEngineName(): string {
		return $this->certificateEngineFactory->getEngine()->getName();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getConfig(?IUser $user = null): array {

		$info['identificationDocumentsFlow'] = $this->appConfig->getValueBool(Application::APP_ID, 'identification_documents', false);
		$info['hasSignatureFile'] = $this->hasSignatureFile($user);
		$info['phoneNumber'] = $this->getPhoneNumber($user);
		$info['isApprover'] = $this->validateHelper->userCanApproveValidationDocuments($user, false);
		$info['id_docs_filters'] = $this->getUserConfigIdDocsFilters($user);
		$info['id_docs_sort'] = $this->getUserConfigIdDocsSort($user);
		$info['crl_filters'] = $this->getUserConfigCrlFilters($user);
		$info['crl_sort'] = $this->getUserConfigCrlSort($user);
		$info['files_list_grid_view'] = $this->getUserConfigByKey('files_list_grid_view', $user) === '1';
		$info['files_list_signer_identify_tab'] = $this->getUserConfigByKey('files_list_signer_identify_tab', $user);
		$info['files_list_sorting_mode'] = $this->getUserConfigByKey('files_list_sorting_mode', $user) ?: 'name';
		$info['files_list_sorting_direction'] = $this->getUserConfigByKey('files_list_sorting_direction', $user) ?: 'asc';

		$config = array_filter($info);

		// Appended AFTER array_filter so a `false` value survives (array_filter
		// would strip it, wrongly falling back to the frontend default).
		$config['one_time_signing_enabled'] = $this->appConfig->getValueBool(Application::APP_ID, 'one_time_signing_enabled', true);
		$config['files_list_show_signers'] = $this->appConfig->getValueBool(Application::APP_ID, 'files_list_show_signers', true);
		$config['files_list_next_enabled'] = $this->appConfig->getValueBool(Application::APP_ID, 'files_list_next_enabled', false);
		$config['visible_elements_next_enabled'] = $this->appConfig->getValueBool(Application::APP_ID, 'visible_elements_next_enabled', false);
		$config['sponsorship_enabled'] = $this->appConfig->getValueBool(Application::APP_ID, 'sponsorship_enabled', false);

		return $config;
	}

	public function getConfigFilters(?IUser $user = null): array {
		$info['files_list_filter_modified'] = $this->getUserConfigByKey('files_list_filter_modified', $user);
		$info['files_list_filter_status'] = $this->getUserConfigByKey('files_list_filter_status', $user);

		return $info;
	}

	public function getConfigSorting(?IUser $user = null): array {
		$info['files_list_sorting_mode'] = $this->getUserConfigByKey('files_list_sorting_mode', $user) ?: 'name';
		$info['files_list_sorting_direction'] = $this->getUserConfigByKey('files_list_sorting_direction', $user) ?: 'asc';

		return $info;
	}

	private function updateIdentifyMethodToAccount(int $signRequestId, string $email, string $uid): void {
		$identifyMethods = $this->identifyMethodService->getIdentifyMethodsFromSignRequestId($signRequestId);
		foreach ($identifyMethods as $name => $methods) {
			if ($name === IdentifyMethodService::IDENTIFY_EMAIL) {
				foreach ($methods as $identifyMethod) {
					$entity = $identifyMethod->getEntity();
					if ($entity->getIdentifierValue() === $email) {
						$entity->setIdentifierKey(IdentifyMethodService::IDENTIFY_ACCOUNT);
						$entity->setIdentifierValue($uid);
						$this->identifyMethodMapper->update($entity);
					}
				}
			}
		}
	}

	private function getPhoneNumber(?IUser $user): string {
		if (!$user) {
			return '';
		}
		$userAccount = $this->accountManager->getAccount($user);
		return $userAccount->getProperty(IAccountManager::PROPERTY_PHONE)->getValue();
	}

	private function setPhoneNumber(?IUser $user, ?string $phone): bool {
		if (!$user || !$phone) {
			return false;
		}

		$account = $this->accountManager->getAccount($user);

		$property = $account->getProperty(IAccountManager::PROPERTY_PHONE);

		$account->setProperty(
			IAccountManager::PROPERTY_PHONE,
			$phone,
			$property->getScope(),
			IAccountManager::NOT_VERIFIED
		);

		$this->accountManager->updateAccount($account);

		return true;
	}

	public function hasSignatureFile(?IUser $user = null): bool {
		if (!$user) {
			return false;
		}
		try {
			$this->pkcs12Handler->getPfxOfCurrentSigner($user->getUID());
			return true;
		} catch (LibresignException) {
			return false;
		}
	}

	private function getUserConfigByKey(string $key, ?IUser $user = null): string {
		if (!$user) {
			return '';
		}
		return $this->userConfig->getValueString($user->getUID(), Application::APP_ID, $key);
	}

	private function getUserConfigIdDocsFilters(?IUser $user = null): array {
		if (!$user) {
			return [];
		}

		$value = $this->userConfig->getValueString($user->getUID(), Application::APP_ID, 'id_docs_filters', '');
		if (empty($value)) {
			return [];
		}

		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function getUserConfigCrlFilters(?IUser $user = null): array {
		if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
			return [];
		}

		$value = $this->userConfig->getValueString($user->getUID(), Application::APP_ID, 'crl_filters', '');
		if (empty($value)) {
			return [];
		}

		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function getUserConfigCrlSort(?IUser $user): array {
		if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
			return ['sortBy' => 'revoked_at', 'sortOrder' => 'DESC'];
		}

		$value = $this->userConfig->getValueString($user->getUID(), Application::APP_ID, 'crl_sort', '');
		if (empty($value)) {
			return ['sortBy' => 'revoked_at', 'sortOrder' => 'DESC'];
		}

		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : ['sortBy' => 'revoked_at', 'sortOrder' => 'DESC'];
	}

	private function getUserConfigIdDocsSort(?IUser $user): array {
		if (!$user || !$this->validateHelper->userCanApproveValidationDocuments($user, false)) {
			return ['sortBy' => null, 'sortOrder' => null];
		}

		$value = $this->userConfig->getValueString($user->getUID(), Application::APP_ID, 'id_docs_sort', '');
		if (empty($value)) {
			return ['sortBy' => null, 'sortOrder' => null];
		}

		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : ['sortBy' => null, 'sortOrder' => null];
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @throws Throwable
	 */
	public function getPdfByUuid(string $uuid): File {
		$fileData = $this->fileMapper->getByUuid($uuid);

		if (in_array($fileData->getStatus(), [FileStatus::PARTIAL_SIGNED->value, FileStatus::SIGNED->value])) {
			$nodeId = $fileData->getSignedNodeId();
		} else {
			$nodeId = $fileData->getNodeId();
		}
		if ($nodeId === null) {
			throw new DoesNotExistException('Not found');
		}

		$userId = $this->fileMapper->getStorageUserIdByUuid($uuid);
		$this->folderService->setUserId($userId);
		return $this->folderService->getFileByNodeId($nodeId);
	}

	public function getFileByNodeId(int $nodeId): File {
		try {
			return $this->folderService->getFileByNodeId($nodeId);
		} catch (NotFoundException) {
			throw new DoesNotExistException('Not found');
		}
	}

	public function canRequestSign(?IUser $user = null): bool {
		if (!$user) {
			return false;
		}
		$authorized = $this->appConfig->getValueArray(Application::APP_ID, 'groups_request_sign', ['admin']);
		if (empty($authorized)) {
			return false;
		}
		$userGroups = $this->groupManager->getUserGroupIds($user);
		if (!array_intersect($userGroups, $authorized)) {
			return false;
		}
		return true;
	}

	public function getSettings(?IUser $user = null): array {
		$return['canRequestSign'] = $this->canRequestSign($user);
		$return['hasSignatureFile'] = $this->hasSignatureFile($user);
		$return['phoneNumber'] = $this->getPhoneNumber($user);
		return $return;
	}

	public function addFilesToAccount(array $files, IUser $user): void {
		$this->idDocsService->addIdDocs($files, $user);
	}

	public function deleteFileFromAccount(int $nodeId, IUser $user): void {
		$this->idDocsService->deleteIdDoc($nodeId, $user);
	}

	public function saveVisibleElements(array $elements, string $sessionId, ?IUser $user): void {
		foreach ($elements as $element) {
			$this->saveVisibleElement($element, $sessionId, $user);
		}
	}

	public function saveVisibleElement(array $data, string $sessionId, ?IUser $user): void {
		if (isset($data['elementId'])) {
			$this->updateFileOfVisibleElement($data);
			$this->updateDataOfVisibleElement($data);
		} elseif ($user instanceof IUser) {
			$file = $this->saveFileOfVisibleElementUsingUser($data, $user);
			$this->insertVisibleElement($data, $user, $file);
		} else {
			$file = $this->saveFileOfVisibleElementUsingSession($data, $sessionId);
		}
	}

	private function updateFileOfVisibleElement(array $data): void {
		if (!isset($data['file'])) {
			return;
		}
		$userElement = $this->userElementMapper->findOne(['id' => $data['elementId']]);
		$file = $this->folderService->getFileByNodeId($userElement->getNodeId());
		$file->putContent($this->getFileRaw($data));
	}

	private function updateDataOfVisibleElement(array $data): void {
		if (!isset($data['starred'])) {
			return;
		}
		$userElement = $this->userElementMapper->findOne(['id' => $data['elementId']]);
		$userElement->setStarred($data['starred'] ? 1 : 0);
		$this->userElementMapper->update($userElement);
	}

	private function saveFileOfVisibleElementUsingUser(array $data, IUser $user): File {
		$rootSignatureFolder = $this->folderService->getFolder();
		$folderName = $this->folderService->getFolderName($data, $user);
		$folderToFile = $rootSignatureFolder->newFolder($folderName);
		return $folderToFile->newFile(UUIDUtil::getUUID() . '.png', $this->getFileRaw($data));
	}

	private function saveFileOfVisibleElementUsingSession(array $data, string $sessionId): File {
		if (!empty($data['nodeId'])) {
			return $this->updateFileOfVisibleElementUsingSession($data, $sessionId);
		}
		return $this->createFileOfVisibleElementUsingSession($data, $sessionId);
	}

	private function updateFileOfVisibleElementUsingSession(array $data, string $sessionId): File {
		$fileList = $this->signerElementsService->getElementsFromSession();
		$element = array_filter($fileList, fn (File $element) => $element->getId() === $data['nodeId']);
		$element = current($element);
		if (!$element instanceof File) {
			throw new \Exception($this->l10n->t('File not found'));
		}
		$element->putContent($this->getFileRaw($data));
		return $element;
	}

	private function createFileOfVisibleElementUsingSession(array $data, string $sessionId): File {
		$rootSignatureFolder = $this->folderService->getFolder();
		$folderName = $sessionId;
		$folderToFile = $rootSignatureFolder->newFolder($folderName);
		$filename = implode(
			'_',
			[
				$data['type'],
				$this->timeFactory->getDateTime()->getTimestamp(),
			]
		) . '.png';
		return $folderToFile->newFile($filename, $this->getFileRaw($data));
	}

	private function insertVisibleElement(array $data, IUser $user, File $file): void {
		$userElement = new UserElement();
		$userElement->setType($data['type']);
		$userElement->setNodeId($file->getId());
		$userElement->setUserId($user->getUID());
		$userElement->setStarred(isset($data['starred']) && $data['starred'] ? 1 : 0);
		$userElement->setCreatedAt($this->timeFactory->getDateTime());
		$this->userElementMapper->insert($userElement);
	}

	private function getFileRaw(array $data): string {
		if (!empty($data['file']['url'])) {
			if (!filter_var($data['file']['url'], FILTER_VALIDATE_URL)) {
				throw new \Exception($this->l10n->t('Invalid URL file'));
			}
			$response = $this->clientService->newClient()->get($data['file']['url']);
			$contentType = $response->getHeader('Content-Type');
			if ($contentType !== 'image/png') {
				throw new \Exception($this->l10n->t('Visible element file must be png.'));
			}
			$content = (string)$response->getBody();
			if (empty($content)) {
				throw new \Exception($this->l10n->t('Empty file'));
			}
			$this->validateHelper->validateBase64($content, ValidateHelper::TYPE_VISIBLE_ELEMENT_USER);
			return $content;
		}
		$this->validateHelper->validateBase64($data['file']['base64'], ValidateHelper::TYPE_VISIBLE_ELEMENT_USER);
		$withMime = explode(',', (string)$data['file']['base64']);
		if (count($withMime) === 2) {
			$content = base64_decode($withMime[1]);
		} else {
			$content = base64_decode((string)$data['file']['base64']);
		}
		if (!$content) {
			return '';
		}
		return $content;
	}

	public function deleteSignatureElement(?IUser $user, string $sessionId, int $nodeId): void {
		if ($user instanceof IUser) {
			$element = $this->userElementMapper->findOne([
				'node_id' => $nodeId,
				'user_id' => $user->getUID(),
			]);
			$this->userElementMapper->delete($element);
			try {
				$file = $this->folderService->getFileByNodeId($element->getNodeId());
				$file->delete();
			} catch (\Throwable) {
			}
			return;
		}

		$this->deleteSignatureElementFromSession($sessionId, $nodeId);
	}

	private function deleteSignatureElementFromSession(string $sessionId, int $nodeId): void {
		$rootSignatureFolder = $this->folderService->getFolder();
		try {
			/** @var \OCP\Files\Folder $sessionFolder */
			$sessionFolder = $rootSignatureFolder->get($sessionId);
		} catch (NotFoundException) {
			throw new DoesNotExistException($this->l10n->t('Element not found'));
		}

		$element = $sessionFolder->getFirstNodeById($nodeId);
		if (!$element instanceof File) {
			throw new DoesNotExistException($this->l10n->t('Element not found'));
		}
		$element->delete();

		// Clean up empty session folder
		if (count($sessionFolder->getDirectoryListing()) === 0) {
			$sessionFolder->delete();
		}
	}

	/**
	 * @throws LibresignException at savePfx
	 * @throws InvalidArgumentException
	 */
	public function uploadPfx(array $file, IUser $user): void {
		try {
			$this->uploadHelper->validateUploadedFile($file);
		} catch (InvalidArgumentException) {
			// TRANSLATORS Error when the uploaded certificate file is not valid
			throw new InvalidArgumentException($this->l10n->t('Invalid file provided. Need to be a .pfx file.'));
		}

		if ($file['size'] > 10 * 1024) {
			// TRANSLATORS Error when the certificate file is bigger than normal
			throw new InvalidArgumentException($this->l10n->t('File is too big'));
		}
		$content = file_get_contents($file['tmp_name']);
		$mimetype = $this->mimeTypeDetector->detectString($content);
		if ($mimetype !== 'application/octet-stream') {
			// TRANSLATORS Error when the mimetype of uploaded file is not valid
			throw new InvalidArgumentException($this->l10n->t('Invalid file provided. Need to be a .pfx file.'));
		}
		$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
		if ($extension !== 'pfx') {
			// TRANSLATORS Error when the certificate file is not a pfx file
			throw new InvalidArgumentException($this->l10n->t('Invalid file provided. Need to be a .pfx file.'));
		}
		unlink($file['tmp_name']);
		$this->pkcs12Handler->savePfx($user->getUID(), $content);
	}

	public function deletePfx(IUser $user): void {
		$uid = $user->getUID();

		$this->crlService->revokeUserCertificates(
			$uid,
			CRLReason::CESSATION_OF_OPERATION,
			'Certificate deleted by account owner.',
			$uid,
		);

		$this->pkcs12Handler->deletePfx($uid);
	}

	/**
	 * @throws LibresignException when have not a certificate file
	 */
	public function updatePfxPassword(IUser $user, string $current, string $new): void {
		try {
			$pfx = $this->pkcs12Handler->updatePassword($user->getUID(), $current, $new);
		} catch (InvalidPasswordException) {
			throw new LibresignException($this->l10n->t('Invalid user or password'));
		}
	}

	/**
	 * @throws LibresignException when have not a certificate file
	 */
	public function readPfxData(IUser $user, string $password): array {
		try {
			return $this->pkcs12Handler
				->setCertificate($this->pkcs12Handler->getPfxOfCurrentSigner($user->getUID()))
				->setPassword($password)
				->readCertificate();
		} catch (InvalidPasswordException) {
			throw new LibresignException($this->l10n->t('Invalid user or password'));
		}
	}
}
