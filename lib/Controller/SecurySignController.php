<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

class SecurySignController extends Controller {
	public function __construct(
		IRequest $request,
		private SecurySignService $signa,
		private ISession $session,
		private IUserSession $users,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/onboard')]
	public function onboard(?string $returnTo = null): RedirectResponse {
		$path = SecurySignService::returnPath($returnTo);
		if (!$this->signa->applies()) {
			return new RedirectResponse($path);
		}
		try {
			$identity = $this->signa->identity();
			if ($this->signa->isReady(true)) {
				return new RedirectResponse($path);
			}
			$state = bin2hex(random_bytes(32));
			$this->session->set('libresign.securysign.onboarding', [
				'state' => $state, 'sub' => $identity['sub'], 'uid' => $this->users->getUser()->getUID(),
				'returnTo' => $path, 'expires' => time() + 3600,
			]);
			return new RedirectResponse($this->signa->onboardingUrl() . '?' . http_build_query([
				'state' => $state, 'subject' => $identity['sub'],
			]));
		} catch (\Throwable $e) {
			return $this->bail($e, $path);
		}
	}

	/**
	 * MIMI sends the user here when setup finishes, and when they log out there.
	 * Anything short of a finished setup is discarded: the user signs in again and
	 * the gate sends them back to MIMI if they are still not set up.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/return')]
	public function complete(string $state = ''): RedirectResponse {
		$pending = $this->session->get('libresign.securysign.onboarding');
		if (!is_array($pending) || $pending['expires'] < time() || !hash_equals($pending['state'], $state)
			|| $pending['uid'] !== $this->users->getUser()?->getUID()) {
			// A stale or foreign link. It must not sign anyone out, so the gate on
			// the home page decides where this user goes next.
			return new RedirectResponse(SecurySignService::returnPath(null));
		}
		$this->session->remove('libresign.securysign.onboarding');
		$path = SecurySignService::returnPath($pending['returnTo']);
		try {
			if ($pending['sub'] !== $this->signa->identity()['sub'] || !$this->signa->isReady(true)) {
				return $this->signOut();
			}
			return new RedirectResponse($path);
		} catch (\Throwable $e) {
			return $this->bail($e, $path);
		}
	}

	/**
	 * After sign-in the user confirms it is them with their MIMI passkey, in
	 * MIMI's own page framed on this one.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/passkey')]
	public function passkey(?string $returnTo = null): RedirectResponse|TemplateResponse {
		$path = SecurySignService::returnPath($returnTo);
		if (!$this->signa->passkeyPending()) {
			return new RedirectResponse($path);
		}
		try {
			$frame = $this->signa->passkeyFrame($this->l10n->getLanguageCode());
		} catch (\RuntimeException $e) {
			$this->signa->skipPasskey($e->getMessage());
			return new RedirectResponse($path);
		}
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-passkey');
		$response = new TemplateResponse(Application::APP_ID, 'mimi_passkey', [
			'returnTo' => $path,
			'frameUrl' => $frame['url'],
			'logoutUrl' => $this->urls->linkToRoute('core.login.logout', ['requesttoken' => Util::callRegister()]),
		], TemplateResponse::RENDER_AS_GUEST);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($frame['origin']);
		$response->setContentSecurityPolicy($policy);
		$response->cacheFor(0);
		return $response;
	}

	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/securysign/passkey/options')]
	public function passkeyOptions(): DataResponse {
		try {
			$options = $this->signa->passkeyOptions();
		} catch (\Throwable $e) {
			if (in_array($e->getCode(), [401, 403], true)) {
				$this->users->logout();
				return new DataResponse(['redirect' => $this->urls->linkToRoute('core.login.showLoginForm')]);
			}
			$this->signa->skipPasskey($e->getMessage());
			return new DataResponse(['skip' => true]);
		}
		if ($options === null) {
			// No MIMI passkey yet: setting one up is the onboarding the gate runs.
			return new DataResponse(['redirect' => $this->urls->linkToRoute('libresign.securySign.onboard')]);
		}
		return new DataResponse(['options' => $options]);
	}

	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/securysign/passkey/verify')]
	public function passkeyVerify(array $response = []): DataResponse {
		try {
			if ($this->signa->verifyPasskey($response)) {
				return new DataResponse(['verified' => true]);
			}
		} catch (\Throwable $e) {
			$this->signa->skipPasskey($e->getMessage());
			return new DataResponse(['verified' => true]);
		}
		return new DataResponse([
			'verified' => false,
			'error' => 'That passkey could not be confirmed. Use the MIMI passkey for this account and try again.',
		], Http::STATUS_FORBIDDEN);
	}

	/**
	 * A dead session means signing in again. Anything else is an outage, which the
	 * user should not notice: the page loads and the local engine signs.
	 */
	private function bail(\Throwable $e, string $path): RedirectResponse {
		$this->logger->error('SecurySign onboarding handoff failed', ['exception' => $e]);
		if (in_array($e->getCode(), [401, 403], true)) {
			return $this->signOut();
		}
		// A cached "not ready" would send the page straight back here.
		$this->signa->forgetReadiness();
		return new RedirectResponse($path);
	}

	private function signOut(): RedirectResponse {
		$this->users->logout();
		return new RedirectResponse($this->urls->linkToRoute('core.login.showLoginForm'));
	}
}
