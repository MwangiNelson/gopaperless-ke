<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SecurySignService {
	private const READINESS_CACHE_KEY = 'libresign.securysign.readiness';
	private const DOWN_KEY = 'libresign.securysign.down_until';
	private const DOWN_SECONDS = 60;
	/**
	 * Bump to re-import every mirrored signature. 3 dropped the composed card;
	 * 4 takes the handwriting alone instead of SecurySign's assembled card.
	 */
	public const CARD_VERSION = 4;

	public function __construct(
		private IAppConfig $config,
		private IConfig $systemConfig,
		private ISession $session,
		private IUserSession $users,
		private IServerContainer $container,
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	public function providerId(): int {
		return $this->config->getValueInt(Application::APP_ID, 'securysign_provider_id', 0);
	}

	public function applies(): bool {
		$provider = $this->providerId();
		return $provider > 0 && $this->users->isLoggedIn()
			&& (int)$this->session->get('oidc.providerid') === $provider;
	}

	/** @return array{sub: string, issuer: string, accessToken: string} */
	public function identity(): array {
		if (!$this->applies()) {
			throw new \RuntimeException('A SecurySign OIDC session is required.', 401);
		}
		$tokens = $this->container->get('OCA\\UserOIDC\\Service\\TokenService');
		$token = $tokens->getToken();
		if ($token === null || $token->isExpired()
			|| $token->getProviderId() !== (int)$this->session->get('oidc.providerid')) {
			throw new \RuntimeException('Sign in again to connect to SecurySign. Enable user_oidc store_login_token if this persists.', 401);
		}
		$claims = $tokens->decodeIdToken($token);
		// OIDC discovery lives at <issuer>/.well-known/openid-configuration, so the
		// provider's own discovery URL names the issuer. A second setting could only
		// disagree with it, and did when the provider moved to production.
		$issuer = (string)preg_replace('~/\.well-known/openid-configuration(\?.*)?$~', '', $this->provider()->getDiscoveryEndpoint());
		if ($issuer === '' || ($claims['iss'] ?? null) !== $issuer || empty($claims['sub'])) {
			throw new \RuntimeException('The SecurySign identity provider does not match this session.', 403);
		}
		return ['sub' => $claims['sub'], 'issuer' => $issuer, 'accessToken' => $token->getAccessToken()];
	}

	public function request(string $path, bool $allowMissing = false, ?array $identity = null): ?array {
		$identity ??= $this->identity();
		$base = self::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
		$response = $this->send('get', $base . '/api/' . $path, [
			'headers' => ['Authorization' => 'Bearer ' . $identity['accessToken'], 'Accept' => 'application/json'],
		]);
		if ($allowMissing && $response->getStatusCode() === 404) {
			return null;
		}
		// The upstream status is the whole diagnosis, and a 503 that omits it sends
		// whoever reads the log back to the browser for another round trip. Only the
		// log sees this text: callers replace it before it reaches a user.
		$status = $response->getStatusCode();
		if ($status !== 200) {
			// Signa's own reason lives in the body, and without it a 401 is
			// indistinguishable from an expired token, a rejected audience or a
			// misconfigured introspection client. Logged, never shown.
			$this->logger->error('SecurySign refused a request', [
				'status' => $status,
				'path' => $path,
				'body' => substr((string)$response->getBody(), 0, 500),
				'tokenClaims' => self::claimNames($identity['accessToken']),
			]);
		}
		if ($status === 401 || $status === 403) {
			throw new \RuntimeException('SecurySign rejected your session (HTTP ' . $status . '). Sign out and in again to reconnect.', 401);
		}
		if ($status !== 200) {
			throw new \RuntimeException('SecurySign answered HTTP ' . $status . ' for /api/' . $path . '. Please retry.', 503);
		}
		$data = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \RuntimeException('SecurySign returned an invalid response.', 503);
		}
		return $data;
	}

	/**
	 * Whether this user's documents are signed by SecurySign. Everyone else, and
	 * every instance without the RP's signing secret, keeps LibreSign's own engine.
	 */
	public function signs(): bool {
		return $this->applies()
			&& $this->config->getValueString(Application::APP_ID, 'securysign_signing_secret') !== '';
	}

	/** The certificate SecurySign's HSM signs with for this user. */
	public function certificatePem(): string {
		$data = $this->request('pki/certificates/me');
		$pem = (string)($data['certificate']['certificatePem'] ?? '');
		$parsed = openssl_x509_parse($pem);
		if (($data['status'] ?? null) !== 'active' || $parsed === false || !self::isCurrent($parsed)) {
			throw new \RuntimeException('You need an active SecurySign certificate to sign. Finish enrolment and try again.', 409);
		}
		return $pem;
	}

	/**
	 * What goes on the user's signing card, from SecurySign: the raw handwriting,
	 * the verified ID name, the certificate's actual issuer and the preparation
	 * time. Null when SecurySign has none for them (no verified identity yet, a
	 * certificate still carrying a profile name, or the feature off), and the
	 * document is signed with the usual appearance instead.
	 *
	 * @return array{certificateSha256: string, name: string, issuer: string, handwriting: string, time: \DateTimeImmutable, expiresAt: \DateTimeImmutable}|null
	 */
	public function signingContext(): ?array {
		$identity = $this->identity();
		$base = self::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
		$response = $this->send('post', $base . '/api/signature/visible/signing-context', [
			'headers' => ['Authorization' => 'Bearer ' . $identity['accessToken'], 'Accept' => 'application/json'],
			'json' => new \stdClass(),
		]);
		$status = $response->getStatusCode();
		$body = json_decode((string)$response->getBody(), true);
		if ($status !== 200 || !is_array($body)) {
			$this->logger->info('No SecurySign signing card for this user', [
				'status' => $status,
				'error' => is_array($body) ? substr((string)($body['error'] ?? ''), 0, 200) : null,
			]);
			return null;
		}
		$png = base64_decode((string)($body['handwritingPngBase64'] ?? ''), true);
		$text = static fn (string $key): string => is_string($body[$key] ?? null) ? trim($body[$key]) : '';
		if ($png === false || !str_starts_with($png, "\x89PNG") || $text('verifiedFullName') === '' || $text('issuerName') === ''
			|| $text('certificateSha256') === '' || $text('signingTime') === '' || $text('expiresAt') === '') {
			$this->logger->warning('SecurySign returned an unreadable signing card');
			return null;
		}
		try {
			$time = new \DateTimeImmutable($text('signingTime'));
			$expiresAt = new \DateTimeImmutable($text('expiresAt'));
		} catch (\Exception) {
			$this->logger->warning('SecurySign returned an unreadable signing card time');
			return null;
		}
		return [
			'certificateSha256' => strtolower(str_replace(':', '', $text('certificateSha256'))),
			'name' => $text('verifiedFullName'),
			'issuer' => $text('issuerName'),
			'handwriting' => $png,
			'time' => $time,
			'expiresAt' => $expiresAt,
		];
	}

	/**
	 * A five-minute token for SecurySign's signing frame, bound to one hash. The
	 * frame runs the passkey prompt on SecurySign's origin, then the HSM signs
	 * the hash with the key behind the user's certificate.
	 *
	 * With the account's email the token is LOA-4 and names the user's passkey,
	 * so the frame asks for it under its own RP ID (mimi.ke or securysign.com) and
	 * the browser offers no other. Without one it falls back to LOA-2, where any
	 * passkey registered with SecurySign can approve and only securysign.com
	 * passkeys are offered.
	 */
	public function signingToken(string $documentHash, string $email = ''): string {
		$response = $this->send('post', $this->signingOrigin() . '/api/ssc/token', [
			'json' => [
				'clientId' => $this->provider()->getClientId(),
				'clientSecret' => $this->config->getValueString(Application::APP_ID, 'securysign_signing_secret'),
				'documentHash' => $documentHash,
			] + ($email === '' ? ['loa' => 'LOA-2'] : ['loa' => 'LOA-4', 'email' => $email]),
		]);
		$body = (string)$response->getBody();
		$token = json_decode($body, true)['token'] ?? null;
		if ($response->getStatusCode() !== 200 || !is_string($token)) {
			$this->logger->error('SecurySign refused a signing token', [
				'status' => $response->getStatusCode(),
				'body' => substr($body, 0, 500),
			]);
			throw new \RuntimeException('SecurySign could not start signing. Please retry shortly.', 503);
		}
		return $token;
	}

	/**
	 * One call to SecurySign. A failure (no connection, a timeout or a 5xx) marks
	 * SecurySign down for this session for a minute, so the pages and signatures
	 * that follow fall back at once instead of waiting on it again.
	 */
	private function send(string $method, string $url, array $options): IResponse {
		$downUntil = $this->session->get(self::DOWN_KEY);
		if (is_int($downUntil) && $downUntil > time()) {
			throw new \RuntimeException('SecurySign is unreachable.', 503);
		}
		$options += ['connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => false, 'http_errors' => false];
		try {
			$client = $this->http->newClient();
			$response = $method === 'post' ? $client->post($url, $options) : $client->get($url, $options);
		} catch (\Exception $e) {
			$this->session->set(self::DOWN_KEY, time() + self::DOWN_SECONDS);
			throw new \RuntimeException('SecurySign is unreachable.', 503, $e);
		}
		if ($response->getStatusCode() >= 500) {
			$this->session->set(self::DOWN_KEY, time() + self::DOWN_SECONDS);
		}
		return $response;
	}

	/** The user_oidc provider row: its discovery URL and client id. */
	private function provider(): object {
		return $this->container->get('OCA\\UserOIDC\\Db\\ProviderMapper')->getProvider($this->providerId());
	}

	public function signingOrigin(): string {
		return self::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
	}

	/**
	 * Whether SecurySign holds a certificate and a signature this user can sign
	 * with.
	 *
	 * The session keeps its answer until logout. The caller can force a fresh
	 * answer after onboarding or before signing. Anything unexpected is an outage
	 * and throws, because silently treating a broken response as "not ready"
	 * would send a paid user back through payment.
	 *
	 * A fresh answer is also where the signature is mirrored. The card arrives on
	 * the same pair of calls the gate already makes, so importing it here keeps
	 * every caller on one method and costs no extra request.
	 */
	public function isReady(bool $forceRefresh = false): bool {
		$identity = $this->identity();
		$cacheKey = hash('sha256', $identity['issuer'] . "\0" . $identity['sub']);
		$cached = $this->session->get(self::READINESS_CACHE_KEY);
		if (!$forceRefresh && is_array($cached)
			&& ($cached['identity'] ?? null) === $cacheKey
			&& is_bool($cached['ready'] ?? null)) {
			return $cached['ready'];
		}

		$readiness = $this->readiness($identity);
		$this->cacheReadiness($cacheKey, $readiness !== null);
		if ($readiness !== null) {
			$this->syncVisibleSignature($readiness);
		}
		return $readiness !== null;
	}

	/**
	 * What SecurySign holds for this user, or null when they are not ready yet.
	 * Returns the handwriting alongside the certificate id so a caller can both
	 * gate on readiness and mirror the signature without asking twice.
	 *
	 * @param array{sub: string, issuer: string, accessToken: string}|null $identity
	 * @return array{certificateId: string, imagePngBase64: string, issuer: string, serial: string, validFrom: string, validUntil: string}|null
	 */
	public function readiness(?array $identity = null): ?array {
		$identity ??= $this->identity();
		$certificate = $this->request('pki/certificates/me', false, $identity);
		if (($certificate['status'] ?? null) === 'none') {
			return null;
		}
		if (($certificate['status'] ?? null) !== 'active' || !is_array($certificate['certificate'] ?? null)) {
			throw new \RuntimeException('SecurySign returned an unknown certificate status.', 503);
		}
		$leaf = $certificate['certificate'];
		$parsed = openssl_x509_parse($leaf['certificatePem'] ?? '');
		if ($parsed === false || empty($certificate['credentialId']) || empty($leaf['certificateId'])) {
			throw new \RuntimeException('SecurySign returned an invalid certificate.', 503);
		}
		if (!self::isCurrent($parsed)) {
			return null;
		}
		$signature = $this->request('signature/visible', true, $identity);
		if ($signature === null) {
			return null;
		}
		if ((string)($signature['certificateId'] ?? '') !== (string)$leaf['certificateId']) {
			return null;
		}
		// The row says which certificate the signature belongs to. Its image is
		// SecurySign's assembled card, and the built-in signing card would draw
		// that card inside itself, so the handwriting comes from its own route.
		$image = $this->handwriting($identity) ?? base64_decode($signature['imagePngBase64'] ?? '', true);
		if (!is_string($image) || !str_starts_with($image, "\x89PNG\r\n\x1a\n")) {
			throw new \RuntimeException('SecurySign returned an invalid visible signature.', 503);
		}
		return [
			'certificateId' => (string)$leaf['certificateId'],
			'imagePngBase64' => base64_encode($image),
			'issuer' => (string)($leaf['issuer'] ?? 'SecurySign'),
			'serial' => (string)($leaf['serialNumberHex'] ?? ''),
			'validFrom' => self::day($leaf['validFrom'] ?? null),
			'validUntil' => self::day($leaf['validUntil'] ?? null),
		];
	}


	/**
	 * The user's handwriting alone, as SecurySign serves it for an RP to place
	 * in its own documents: no name, certificate details or QR code. Null where
	 * a SecurySign deployment has no such route (404), and readiness falls back
	 * to the card.
	 *
	 * @param array{sub: string, issuer: string, accessToken: string} $identity
	 */
	public function handwriting(array $identity): ?string {
		$base = self::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
		$response = $this->send('get', $base . '/api/signature/visible/' . rawurlencode($identity['sub']) . '/image', [
			'headers' => ['Authorization' => 'Bearer ' . $identity['accessToken'], 'Accept' => 'image/png'],
		]);
		$status = $response->getStatusCode();
		if ($status === 404) {
			return null;
		}
		if ($status === 401 || $status === 403) {
			throw new \RuntimeException('SecurySign rejected your session (HTTP ' . $status . '). Sign out and in again to reconnect.', 401);
		}
		if ($status !== 200) {
			throw new \RuntimeException('SecurySign answered HTTP ' . $status . ' for the handwriting image. Please retry.', 503);
		}
		return (string)$response->getBody();
	}

	/**
	 * Mirror the SecurySign card into the user's LibreSign signature elements so
	 * it is what lands on a document. SecurySign owns it: SecurySignMiddleware
	 * refuses the endpoints that create, change or delete one, so any element a
	 * gated user has came from here, and one that names a superseded certificate
	 * is replaced after a renewal.
	 *
	 * Takes the readiness array rather than re-reading it, so a page load still
	 * costs one pair of calls to SecurySign. Failures are logged and swallowed:
	 * the card is already verified, and failing to cache it locally is no reason
	 * to lock someone out of the app.
	 *
	 * @param array{certificateId: string, imagePngBase64: string} $readiness
	 */
	public function syncVisibleSignature(array $readiness): void {
		$user = $this->users->getUser();
		if ($user === null) {
			return;
		}
		try {
			// Resolved lazily: AccountService reaches SignerElementsService, which
			// reaches this class, so constructor injection would not build.
			$mapper = $this->container->get(\OCA\Libresign\Db\UserElementMapper::class);
			$existing = $mapper->findMany(['user_id' => $user->getUID(), 'type' => 'signature']);
			// Both the certificate and the card layout have to match. Without the
			// version, changing the card design would leave every existing user on
			// the old image forever, because their certificate had not changed.
			foreach ($existing as $element) {
				$metadata = $element->getMetadata() ?? [];
				if (($metadata['securysign_certificate_id'] ?? null) === $readiness['certificateId']
					&& ($metadata['securysign_card'] ?? null) === self::CARD_VERSION) {
					return;
				}
			}

			// The handwriting is stored exactly as SecurySign returned it. The
			// account, issuer and validity details are added at signing time by
			// LibreSign's own signature text template, which already has variables
			// for every one of them — see the runbook. Compositing a card here
			// duplicated a feature the app ships.
			$accounts = $this->container->get(\OCA\Libresign\Service\AccountService::class);
			$accounts->saveVisibleElement([
				'type' => 'signature',
				'file' => ['base64' => 'data:image/png;base64,' . $readiness['imagePngBase64']],
				'starred' => 1,
			], '', $user);

			$stale = array_column(array_map(static fn ($e) => ['id' => $e->getId()], $existing), 'id');
			foreach ($mapper->findMany(['user_id' => $user->getUID(), 'type' => 'signature']) as $element) {
				if (in_array($element->getId(), $stale, true)) {
					$mapper->delete($element);
					continue;
				}
				$element->setMetadata(($element->getMetadata() ?? []) + [
					'securysign_certificate_id' => $readiness['certificateId'],
					'securysign_card' => self::CARD_VERSION,
				]);
				$mapper->update($element);
			}
			$this->logger->info('Imported the SecurySign visible signature', [
				'certificateId' => $readiness['certificateId'],
				'replaced' => count($stale),
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Could not import the SecurySign visible signature', ['exception' => $e]);
		}
	}

	/** The next isReady() asks SecurySign again instead of trusting the session. */
	public function forgetReadiness(): void {
		$this->session->remove(self::READINESS_CACHE_KEY);
	}

	private function cacheReadiness(string $identity, bool $ready): void {
		$this->session->set(self::READINESS_CACHE_KEY, [
			'identity' => $identity,
			'ready' => $ready,
		]);
	}

	/**
	 * Whether this user already has a signature mirrored from SecurySign.
	 *
	 * While one is there, SecurySign owns it and LibreSign's own signature module
	 * stays shut. With none, the import has either not run yet or has failed, and
	 * refusing the module as well would leave the user told to draw a signature
	 * they are not allowed to draw. The next successful import replaces whatever
	 * they drew, so the fallback cannot outlive the outage that caused it.
	 *
	 * An unreadable mapper answers false, which opens the module rather than
	 * locking the user out. That is the recoverable side of the choice.
	 */
	public function hasMirroredSignature(): bool {
		$user = $this->users->getUser();
		if ($user === null) {
			return false;
		}
		try {
			$mapper = $this->container->get(\OCA\Libresign\Db\UserElementMapper::class);
			foreach ($mapper->findMany(['user_id' => $user->getUID(), 'type' => 'signature']) as $element) {
				if (!empty(($element->getMetadata() ?? [])['securysign_certificate_id'])) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('Could not read the mirrored SecurySign signature', ['exception' => $e]);
		}
		return false;
	}

	/**
	 * Where a user without a usable certificate is sent.
	 *
	 * Read from `occ config:app:set libresign tendaworld_url` first, then from
	 * the system value, which Nextcloud also fills from an `NC_tendaworld_url`
	 * environment variable. A deployment that only sets env therefore needs no
	 * occ run, and one that ran occ is not overridden by the image's env.
	 */
	public function onboardingUrl(): string {
		$configured = $this->config->getValueString(Application::APP_ID, 'tendaworld_url')
			?: $this->systemConfig->getSystemValueString('tendaworld_url', 'https://tendaworld.com');
		return self::origin($configured) . '/onboarding/gopaperless';
	}

	private function mimiOrigin(): string {
		$configured = $this->config->getValueString(Application::APP_ID, 'tendaworld_url')
			?: $this->systemConfig->getSystemValueString('tendaworld_url', 'https://gopaperless.mimi.ke');
		return self::origin($configured);
	}

	private const PASSKEY_KEY = 'libresign.mimi.passkey';
	private const PASSKEY_STATE_KEY = 'libresign.mimi.passkey_state';

	/**
	 * Whether this session still owes the MIMI passkey check that follows
	 * sign-in. Off until the MIMI client secret is set.
	 */
	public function passkeyPending(): bool {
		return $this->applies()
			&& $this->config->getValueString(Application::APP_ID, 'mimi_client_secret') !== ''
			&& $this->session->get(self::PASSKEY_KEY) === null;
	}

	/**
	 * MIMI's page that runs the passkey prompt on MIMI's own origin, which
	 * templates/mimi_passkey.php frames. MIMI lets only our registered origins
	 * frame it. It opens in `$language` (Nextcloud's, like pt_BR) when MIMI has
	 * that language, else in English.
	 *
	 * @return array{origin: string, url: string}
	 */
	public function passkeyFrame(string $language = 'en'): array {
		$origin = $this->mimiOrigin();
		// ponytail: MIMI translates by language, not region, so pt_BR asks for pt.
		$lang = strtolower(explode('_', str_replace('-', '_', $language))[0]);
		return ['origin' => $origin, 'url' => $origin . '/passkey/frame?' . http_build_query(['client_id' => $this->mimiClientId(), 'lang' => $lang])];
	}

	/**
	 * WebAuthn options for the user's MIMI passkey, which our page hands to
	 * MIMI's frame. Null when MIMI holds no passkey for them. Throws 503 when
	 * MIMI cannot answer.
	 */
	public function passkeyOptions(): ?array {
		$answer = $this->mimi('options', ['subject' => $this->identity()['sub']]);
		if ($answer['status'] === 404) {
			return null;
		}
		if ($answer['status'] !== 200 || !is_array($answer['body']['options'] ?? null) || !is_string($answer['body']['state'] ?? null)) {
			throw new \RuntimeException('MIMI answered HTTP ' . $answer['status'] . ' to the passkey request.', 503);
		}
		$this->session->set(self::PASSKEY_STATE_KEY, $answer['body']['state']);
		return $answer['body']['options'];
	}

	/** True, and the check recorded as done, when MIMI accepts the assertion as this user's passkey. */
	public function verifyPasskey(array $assertion): bool {
		$state = $this->session->get(self::PASSKEY_STATE_KEY);
		$this->session->remove(self::PASSKEY_STATE_KEY);
		if (!is_string($state)) {
			return false;
		}
		$answer = $this->mimi('verify', ['state' => $state, 'response' => $assertion]);
		if ($answer['status'] >= 500) {
			throw new \RuntimeException('MIMI answered HTTP ' . $answer['status'] . ' to the passkey check.', 503);
		}
		$verified = $answer['status'] === 200 && ($answer['body']['verified'] ?? false) === true
			&& ($answer['body']['subject'] ?? null) === $this->identity()['sub'];
		if ($verified) {
			$this->session->set(self::PASSKEY_KEY, time());
		}
		return $verified;
	}

	/** Lets this session in when MIMI cannot run the check, so an outage locks nobody out. */
	public function skipPasskey(string $reason): void {
		$this->logger->warning('MIMI passkey check skipped: ' . $reason);
		$this->session->set(self::PASSKEY_KEY, 'skipped');
	}

	private function mimiClientId(): string {
		return $this->config->getValueString(Application::APP_ID, 'mimi_client_id', 'gopaperless');
	}

	/** @return array{status: int, body: array} */
	private function mimi(string $action, array $payload): array {
		try {
			$response = $this->http->newClient()->post($this->mimiOrigin() . '/api/partner/passkey/' . $action, [
				'json' => $payload,
				'auth' => [
					$this->mimiClientId(),
					$this->config->getValueString(Application::APP_ID, 'mimi_client_secret'),
				],
				'connect_timeout' => 3,
				'timeout' => 10,
				'allow_redirects' => false,
				'http_errors' => false,
			]);
		} catch (\Exception $e) {
			throw new \RuntimeException('MIMI is unreachable.', 503, $e);
		}
		$body = json_decode((string)$response->getBody(), true);
		return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
	}


	/**
	 * Claim NAMES only. The values are the user's identity and must not be logged.
	 * Which names the access token carries is the whole diagnosis when Signa says
	 * a token is missing a claim, and it cannot be read any other way once the
	 * token is sealed in the session.
	 */
	public static function claimNames(string $jwt): string {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return 'opaque token, ' . count($parts) . ' segment(s)';
		}
		$payload = strtr($parts[1], '-_', '+/');
		$payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
		$decoded = base64_decode($payload, true);
		$claims = $decoded === false ? null : json_decode($decoded, true);
		return is_array($claims) ? implode(' ', array_keys($claims)) : 'unreadable payload';
	}


	/** Dates go on a card a person reads, so seconds and timezones are noise. */
	private static function day(?string $value): string {
		$time = $value === null ? false : strtotime($value);
		return $time === false ? '—' : date('j M Y', $time);
	}

	/** @param array<string, mixed> $parsed openssl_x509_parse() output */
	public static function isCurrent(array $parsed): bool {
		$from = $parsed['validFrom_time_t'] ?? null;
		$to = $parsed['validTo_time_t'] ?? null;
		return is_int($from) && is_int($to) && $from <= time() && $to > time();
	}

	/**
	 * HTTPS everywhere except loopback, where a local development stack has no
	 * certificate. The carve-out is the same one browsers and RFC 8252 make: a
	 * loopback address cannot be reached off the machine, so plaintext there
	 * exposes nothing to the network.
	 */
	public static function origin(string $url): string {
		$parts = parse_url($url);
		$host = $parts['host'] ?? '';
		$scheme = $parts['scheme'] ?? '';
		$allowed = $scheme === 'https'
			|| ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
		if (!$parts || !$allowed || $host === ''
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| !in_array($parts['path'] ?? '', ['', '/'], true)) {
			throw new \RuntimeException('Configure a valid HTTPS origin for the signing integration.', 503);
		}
		return rtrim($url, '/');
	}

	public static function returnPath(?string $path): string {
		if ($path === null || strlen($path) > 2048 || preg_match('/[\\\\\x00-\x20]/', $path)) {
			return '/apps/libresign/';
		}
		$decoded = rawurldecode(explode('?', $path, 2)[0]);
		if (!str_starts_with($decoded, '/apps/libresign/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x20%]~', $decoded)
			|| preg_match('~^/apps/libresign/(?:sso|securysign)(?:/|$)~', $decoded)) {
			return '/apps/libresign/';
		}
		return $path;
	}
}
