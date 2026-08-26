<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<UserLoggedInEvent> */
class AcceptTermsAfterOidcLoginListener implements IEventListener {
	private const OIDC_PROVIDER_SESSION_KEY = 'oidc.providerid';

	public function __construct(
		private ISession $session,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		// ponytail: SecurySign is the only working OIDC provider; compare provider IDs if that changes.
		if (!($event instanceof UserLoggedInEvent)
			|| $this->session->get(self::OIDC_PROVIDER_SESSION_KEY) === null) {
			return;
		}

		try {
			$this->accountService->acceptTermsForUserId($event->getUser()->getUID());
		} catch (\Throwable $e) {
			$this->logger->warning('Could not accept Terms of Service after OIDC login', [
				'exception' => $e,
			]);
		}
	}
}
