<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Middleware;

use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Controller\SignatureElementsController;
use OCA\Libresign\Controller\SignFileController;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SecurySignMiddleware extends Middleware {
	public function __construct(
		private SecurySignService $signa,
		private IRequest $request,
		private IURLGenerator $urls,
		private IUserSession $users,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The page gate below only covers the Vue app. The signing API is reachable
	 * on its own, so the same requirement is enforced here before any signature
	 * is produced. Anonymous token signers never match: applies() needs a logged
	 * in user whose session came from the configured SecurySign provider.
	 */
	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		if (!$this->signa->applies()) {
			return;
		}

		// A session's first readiness answer imports the SecurySign signature and
		// deletes the one it replaces. Asked after the page controller, the page had
		// already shipped the deleted one, and signing with it failed with "Field
		// <id> does not belong to user". afterController reuses this answer from
		// the session, and handles a failure itself.
		if ($controller instanceof PageController && $methodName !== 'legal') {
			try {
				$this->signa->isReady();
			} catch (\Throwable) {
			}
			return;
		}

		// SecurySign owns the visible signature. Refusing the three endpoints that
		// change one is what makes that real: hiding a button in the Vue leaves the
		// API able to replace the card that goes on a document. Reads are
		// untouched, so the mirrored signature still renders everywhere.
		//
		// Only while a mirrored signature is actually there. With none, LibreSign's
		// own signature module is the fallback, or an import that failed would
		// leave the user unable to sign and unable to do anything about it.
		if ($controller instanceof SignatureElementsController
			&& in_array($methodName, ['createSignatureElement', 'patchSignatureElement', 'deleteSignatureElement'], true)
			&& $this->signa->hasMirroredSignature()) {
			throw new LibresignException('Your signature is managed in SecurySign and cannot be changed here.', Http::STATUS_FORBIDDEN);
		}

		if (!$controller instanceof SignFileController
			|| !in_array($methodName, ['signByFileId', 'signBySignerUuid'], true)) {
			return;
		}
		// The session's answer is enough here. When SecurySign signs, the handler
		// re-reads the certificate anyway, and forcing a fresh answer cost two
		// round trips to SecurySign on each of a signature's two requests. When
		// SecurySign is down, LibreSign's own engine signs instead, so an outage
		// must not stop the request.
		try {
			$ready = $this->signa->isReady();
		} catch (\Throwable $e) {
			$this->logger->warning('SecurySign readiness check failed before signing; the local engine signs if SecurySign cannot', ['exception' => $e]);
			return;
		}
		if (!$ready) {
			throw new LibresignException('Finish your SecurySign setup in GoPaperless before signing.', Http::STATUS_FORBIDDEN);
		}
		if ($this->signa->passkeyPending()) {
			throw new LibresignException('Confirm it is you with your MIMI passkey, then sign.', Http::STATUS_FORBIDDEN);
		}
	}

	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		// The terms and the data protection policy stay readable to someone who is
		// still signing up: they are what the sign-up asks them to accept.
		if (!$controller instanceof PageController || !$response instanceof TemplateResponse
			|| $response->getTemplateName() !== 'main' || $methodName === 'legal' || !$this->signa->applies()) {
			return $response;
		}
		try {
			if ($this->signa->isReady()) {
				// Set up: the MIMI passkey confirms it is them before the app opens.
				if ($this->signa->passkeyPending()) {
					return new RedirectResponse($this->urls->linkToRoute('libresign.securySign.passkey', [
						'returnTo' => SecurySignService::returnPath($this->request->getRequestUri()),
					]));
				}
				return $response;
			}
			return new RedirectResponse($this->urls->linkToRoute('libresign.securySign.onboard', [
				'returnTo' => SecurySignService::returnPath($this->request->getRequestUri()),
			]));
		} catch (\Throwable $e) {
			$this->logger->error('SecurySign readiness check failed on a page load', ['exception' => $e]);
			// A dead session means signing in again, straight from the login page.
			if (in_array($e->getCode(), [401, 403], true)) {
				$this->users->logout();
				return new RedirectResponse($this->urls->linkToRoute('core.login.showLoginForm'));
			}
			// An outage should go unnoticed: the page renders, and signing uses the
			// local engine with its usual confirm dialog until SecurySign is back.
			return $response;
		}
	}
}
