<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Middleware;

use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Controller\SignatureElementsController;
use OCA\Libresign\Controller\SignFileController;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Middleware\SecurySignMiddleware;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecurySignMiddlewareTest extends TestCase {
	private SecurySignService&MockObject $signa;
	private IRequest&MockObject $request;
	private SecurySignMiddleware $middleware;

	protected function setUp(): void {
		$this->signa = $this->createMock(SecurySignService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRequestUri')->willReturn('/apps/libresign/f/document');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => $route === 'libresign.securySign.onboard'
				? '/apps/libresign/securysign/onboard?returnTo=' . rawurlencode($params['returnTo'])
				: '/' . str_replace('.', '/', $route) . '?' . http_build_query($params),
		);
		$urls->method('linkToDefaultPageUrl')->willReturn('/apps/dashboard/');
		$this->middleware = new SecurySignMiddleware($this->signa, $this->request, $urls, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
	}

	private function page(): TemplateResponse {
		return new TemplateResponse('libresign', 'main');
	}

	public function testASetUpUserConfirmsTheirMimiPasskeyBeforeTheAppOpens(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willReturn(true);
		$this->signa->method('passkeyPending')->willReturn(true);

		$response = $this->middleware->afterController($this->createMock(PageController::class), 'index', $this->page());

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringStartsWith('/libresign/securySign/passkey?', $response->getRedirectURL());
		self::assertStringContainsString('returnTo=%2Fapps%2Flibresign%2Ff%2Fdocument', $response->getRedirectURL());

		try {
			$this->middleware->beforeController($this->createMock(SignFileController::class), 'signByFileId');
			self::fail('signing was allowed before the passkey check');
		} catch (LibresignException $e) {
			self::assertSame(403, $e->getCode());
		}
	}

	public function testAnUnpreparedUserIsSentIntoOnboardingWithTheirTaskAttached(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willReturn(false);

		$response = $this->middleware->afterController($this->createMock(PageController::class), 'index', $this->page());

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(
			'/apps/libresign/securysign/onboard?returnTo=%2Fapps%2Flibresign%2Ff%2Fdocument',
			$response->getRedirectURL(),
		);
	}

	public function testAPreparedUserAndAnEmailPasswordUserBothSeeTheAppUnchanged(): void {
		$this->signa->method('applies')->willReturnOnConsecutiveCalls(true, false);
		$this->signa->method('isReady')->willReturn(true);
		// A prepared user still gets their SecurySign card mirrored locally.
		$page = $this->page();

		self::assertSame($page, $this->middleware->afterController($this->createMock(PageController::class), 'index', $page));
		self::assertSame($page, $this->middleware->afterController($this->createMock(PageController::class), 'index', $page));
	}

	/** Someone still signing up can read the terms and the data protection policy. */
	public function testTheLegalPagesAreNeverGated(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->expects(self::never())->method('isReady');
		$page = $this->page();

		$this->middleware->beforeController($this->createMock(PageController::class), 'legal');
		self::assertSame($page, $this->middleware->afterController($this->createMock(PageController::class), 'legal', $page));
	}

	/**
	 * The import that replaces the user's signature runs before the page lists
	 * their signatures, so the page never offers the one it deleted.
	 */
	public function testThePageAsksBeforeItListsTheSignatures(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->expects(self::once())->method('isReady')->willThrowException(new \RuntimeException('upstream said no', 401));

		$this->middleware->beforeController($this->createMock(PageController::class), 'index');
	}

	public function testAnOutageGoesUnnoticedOnAPageLoad(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willThrowException(new \RuntimeException('down', 503));
		$page = $this->page();

		// The page renders, and signing falls back to the local engine behind its confirm dialog.
		self::assertSame($page, $this->middleware->afterController($this->createMock(PageController::class), 'index', $page));
	}

	public function testTheSigningApiCannotBeUsedToSkipOnboarding(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willReturn(false);

		try {
			$this->middleware->beforeController($this->createMock(SignFileController::class), 'signByFileId');
			self::fail('Signing was allowed before SecurySign was ready');
		} catch (LibresignException $e) {
			self::assertSame(403, $e->getCode());
		}
	}

	public function testAnOutageLetsTheLocalEngineSign(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('isReady')->willThrowException(new \RuntimeException('down', 503));

		// No exception: SignFileService signs with the local engine when SecurySign cannot.
		$this->middleware->beforeController($this->createMock(SignFileController::class), 'signBySignerUuid');
		$this->addToAssertionCount(1);
	}

	public function testTokenSignersAndOtherEndpointsAreNotGated(): void {
		$this->signa->method('applies')->willReturn(false);
		$this->signa->expects(self::never())->method('isReady');

		$this->middleware->beforeController($this->createMock(SignFileController::class), 'signByFileId');

		$ready = $this->createMock(SecurySignService::class);
		$ready->method('applies')->willReturn(true);
		$ready->expects(self::never())->method('isReady');
		$other = new SecurySignMiddleware($ready, $this->request, $this->createMock(IURLGenerator::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$other->beforeController($this->createMock(SignFileController::class), 'requestCodeByFileId');
	}

	/**
	 * A rejected session signs the user out to the login page. No notice page:
	 * signing in again is the fix, so that is where they land.
	 */
	public function testADeadSessionSignsOutToTheLoginPage(): void {
		foreach ([401, 403] as $code) {
			$signa = $this->createMock(SecurySignService::class);
			$signa->method('applies')->willReturn(true);
			$signa->method('isReady')->willThrowException(new \RuntimeException('upstream said no', $code));
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/' . $route);
			$users = $this->createMock(IUserSession::class);
			$users->expects(self::once())->method('logout');
			$middleware = new SecurySignMiddleware($signa, $this->request, $urls, $users, $this->createMock(LoggerInterface::class));

			$response = $middleware->afterController($this->createMock(PageController::class), 'index', $this->page());

			self::assertInstanceOf(RedirectResponse::class, $response);
			self::assertSame('/core.login.showLoginForm', $response->getRedirectURL());
		}
	}

	/**
	 * SecurySign owns the card, so the endpoints that would replace it are
	 * refused. Hiding the button in the Vue would leave the API open, and the API
	 * is what actually decides what lands on a document. Reads stay open: the
	 * mirrored signature has to render.
	 */
	public function testTheSignatureCannotBeReplacedThroughTheApi(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('hasMirroredSignature')->willReturn(true);
		$controller = $this->createMock(SignatureElementsController::class);

		foreach (['createSignatureElement', 'patchSignatureElement', 'deleteSignatureElement'] as $method) {
			try {
				$this->middleware->beforeController($controller, $method);
				self::fail($method . ' was allowed');
			} catch (LibresignException $e) {
				self::assertSame(403, $e->getCode());
				self::assertStringContainsString('SecurySign', $e->getMessage());
			}
		}

		// Reading them must still work, or the mirrored card cannot be shown.
		$this->middleware->beforeController($controller, 'getSignatureElements');
		$this->middleware->beforeController($controller, 'getSignatureElementPreview');

		// And an email/password user keeps full control of their own signature.
		$other = $this->createMock(SecurySignService::class);
		$other->method('applies')->willReturn(false);
		$middleware = new SecurySignMiddleware($other, $this->request, $this->createMock(IURLGenerator::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$middleware->beforeController($controller, 'createSignatureElement');
	}

	/**
	 * With nothing mirrored, LibreSign's own signature module is the fallback.
	 * Refusing it as well would tell a user whose import failed to draw a
	 * signature they are not allowed to draw, with no way out of the loop.
	 */
	public function testTheLibreSignModuleIsTheFallbackWhileNothingIsMirrored(): void {
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('hasMirroredSignature')->willReturn(false);
		$controller = $this->createMock(SignatureElementsController::class);

		foreach (['createSignatureElement', 'patchSignatureElement', 'deleteSignatureElement'] as $method) {
			$this->middleware->beforeController($controller, $method);
		}

		self::assertTrue(true, 'reaching here is the assertion: none of the three was refused');
	}
}
