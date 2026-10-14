<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\SignatureElementsController;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\SessionService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Service\SignerElementsService;
use OCA\Libresign\Service\SignFileService;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Preview\IMimeIconProvider;
use PHPUnit\Framework\TestCase;

/**
 * A SecurySign user only reaches this endpoint when SecurySign has no visible
 * signature scribble for them: the signature they draw goes to SecurySign.
 */
final class SignatureElementsControllerSecurySignTest extends TestCase {
	private const SIGNATURE = ['type' => 'signature', 'file' => ['base64' => 'data:image/png;base64,c2lnbg==']];
	private const INITIAL = ['type' => 'initial', 'file' => ['base64' => 'data:image/png;base64,aW5pdA==']];

	private function controller(bool $securySignUser, AccountService $accounts, SecurySignService $securySign): SignatureElementsController {
		$securySign->method('applies')->willReturn($securySignUser);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone@example.test');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$elements = $this->createMock(SignerElementsService::class);
		$elements->method('getUserElementByNodeId')->willReturn(['id' => 1, 'type' => 'signature']);
		return new SignatureElementsController(
			$this->createMock(IRequest::class), $l10n, $accounts, $elements,
			$this->createMock(SignatureTextService::class), $users, $this->createMock(SessionService::class),
			$this->createMock(SignFileService::class), $this->createMock(IPreview::class), $this->createMock(IMimeIconProvider::class),
			$this->createMock(IURLGenerator::class), $this->createMock(ValidateHelper::class), $securySign,
		);
	}

	public function testASecurySignUsersSignatureGoesToSecurySignAndTheRestStaysHere(): void {
		$securySign = $this->createMock(SecurySignService::class);
		$securySign->expects(self::once())->method('saveDrawnSignature')->with(self::SIGNATURE['file']['base64']);
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects(self::once())->method('saveVisibleElements')->with([self::INITIAL]);

		$response = $this->controller(true, $accounts, $securySign)->createSignatureElement([self::SIGNATURE, self::INITIAL]);

		self::assertSame(200, $response->getStatus());
	}

	public function testSecurySignsRefusalIsShownAndNothingIsSavedHere(): void {
		$securySign = $this->createMock(SecurySignService::class);
		$securySign->method('saveDrawnSignature')->willThrowException(new \RuntimeException('SecurySign could not save your signature: Invalid image', 503));
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects(self::never())->method('saveVisibleElements');

		$response = $this->controller(true, $accounts, $securySign)->createSignatureElement([self::SIGNATURE]);

		self::assertSame([422, 'SecurySign could not save your signature: Invalid image'], [$response->getStatus(), $response->getData()['message']]);
	}

	/** Changing a signature they already have goes to SecurySign too, not only a first one. */
	public function testARedrawnSignatureGoesToSecurySign(): void {
		$securySign = $this->createMock(SecurySignService::class);
		$securySign->expects(self::once())->method('saveDrawnSignature')->with(self::SIGNATURE['file']['base64']);
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects(self::never())->method('saveVisibleElement');

		$response = $this->controller(true, $accounts, $securySign)->patchSignatureElement(5, 'signature', self::SIGNATURE['file']);

		self::assertSame(200, $response->getStatus());
	}

	/** Fetching the signatures is when a change made on SecurySign is picked up. */
	public function testFetchingTheSignaturesChecksSecurySignFirst(): void {
		$securySign = $this->createMock(SecurySignService::class);
		$securySign->expects(self::once())->method('refreshVisibleSignature');

		$response = $this->controller(true, $this->createMock(AccountService::class), $securySign)->getSignatureElements();

		self::assertSame(200, $response->getStatus());
	}

	public function testEveryoneElseSavesHereAsBefore(): void {
		$securySign = $this->createMock(SecurySignService::class);
		$securySign->expects(self::never())->method('saveDrawnSignature');
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects(self::once())->method('saveVisibleElements')->with([self::SIGNATURE]);

		$this->controller(false, $accounts, $securySign)->createSignatureElement([self::SIGNATURE]);
	}
}
