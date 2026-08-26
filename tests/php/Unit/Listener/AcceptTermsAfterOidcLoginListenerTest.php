<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Listener\AcceptTermsAfterOidcLoginListener;
use OCA\Libresign\Service\AccountService;
use OCP\ISession;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AcceptTermsAfterOidcLoginListenerTest extends TestCase {
	private ISession&MockObject $session;
	private AccountService&MockObject $accountService;
	private LoggerInterface&MockObject $logger;
	private AcceptTermsAfterOidcLoginListener $listener;

	protected function setUp(): void {
		$this->session = $this->createMock(ISession::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new AcceptTermsAfterOidcLoginListener(
			$this->session,
			$this->accountService,
			$this->logger,
		);
	}

	public function testAcceptTermsAfterOidcLogin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('get')->with('oidc.providerid')->willReturn(2);
		$this->accountService->expects($this->once())
			->method('acceptTermsForUserId')
			->with('alice');

		$this->listener->handle(new UserLoggedInEvent($user, 'alice', null, false));
	}

	public function testIgnoreNonOidcLogin(): void {
		$user = $this->createMock(IUser::class);
		$this->session->method('get')->with('oidc.providerid')->willReturn(null);
		$this->accountService->expects($this->never())->method('acceptTermsForUserId');

		$this->listener->handle(new UserLoggedInEvent($user, 'alice', null, false));
	}

	public function testTermsFailureDoesNotBreakLogin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('get')->willReturn(2);
		$exception = new \RuntimeException('database unavailable');
		$this->accountService->method('acceptTermsForUserId')->willThrowException($exception);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Could not accept Terms of Service after OIDC login',
				['exception' => $exception],
			);

		$this->listener->handle(new UserLoggedInEvent($user, 'alice', null, false));
	}
}
