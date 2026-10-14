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
	 * What a mirrored signature is. A lower one is replaced at the next sign-in,
	 * never the other way round. 6 is the visible signature scribble alone, from
	 * either SecurySign source. Anything lower may be SecurySign's whole card.
	 */
	public const CARD_VERSION = 6;

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
	 * every instance with the switch off or without the RP's signing secret,
	 * keeps LibreSign's own engine.
	 */
	public function signs(): bool {
		return $this->applies()
			&& $this->config->getValueBool(Application::APP_ID, 'securysign_signing_enabled', true)
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
		$cacheKey = self::cacheKey($identity);
		$cached = $this->session->get(self::READINESS_CACHE_KEY);
		if (!$forceRefresh && is_array($cached)
			&& ($cached['identity'] ?? null) === $cacheKey
			&& is_bool($cached['ready'] ?? null)) {
			return $cached['ready'];
		}

		$readiness = $this->readiness($identity);
		$this->cacheReadiness($cacheKey, $readiness);
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
	 * @return array{certificateId: string, imagePngBase64: string, imageSha256: string, card: int, issuer: string, serial: string, validFrom: string, validUntil: string}|null
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
		// SecurySign's assembled card, which never goes on a document. The
		// scribble alone comes from the signing context, which needs a verified
		// ID, or else from the image route. With neither (an account older than
		// SecurySign's separate scribble), the user draws one in GoPaperless,
		// which saves it to SecurySign: see saveDrawnSignature().
		$scribble = $this->contextScribble((string)$leaf['certificatePem'])
			?? $this->handwriting($identity, (string)($signature['imageSha256'] ?? ''));
		if ($scribble === null) {
			$this->logger->warning('SecurySign has no visible signature scribble for this user; they draw one in GoPaperless', [
				'certificateId' => (string)$leaf['certificateId'],
			]);
		}
		return [
			'certificateId' => (string)$leaf['certificateId'],
			'imagePngBase64' => $scribble === null ? '' : base64_encode($scribble),
			// Changes whenever the signature changes on SecurySign: how a redraw
			// there is noticed here (refreshVisibleSignature()).
			'imageSha256' => (string)($signature['imageSha256'] ?? ''),
			'card' => self::CARD_VERSION,
			'issuer' => (string)($leaf['issuer'] ?? 'SecurySign'),
			'serial' => (string)($leaf['serialNumberHex'] ?? ''),
			'validFrom' => self::day($leaf['validFrom'] ?? null),
			'validUntil' => self::day($leaf['validUntil'] ?? null),
		];
	}

	/**
	 * The visible signature scribble from SecurySign's signing context, when the
	 * context is for this certificate. Null when SecurySign has none to give,
	 * and the import tries the image route instead.
	 */
	private function contextScribble(string $pem): ?string {
		try {
			$context = $this->signingContext();
		} catch (\Throwable $e) {
			$this->logger->info('No SecurySign signing context for the signature import', ['exception' => $e]);
			return null;
		}
		return $context !== null && hash_equals((string)openssl_x509_fingerprint($pem, 'sha256'), $context['certificateSha256'])
			? $context['handwriting']
			: null;
	}


	/**
	 * The visible signature scribble alone, as SecurySign serves it for an RP to
	 * place in its own documents. Null when the route has none to give. It then
	 * serves SecurySign's card instead, tagged with the card's own hash, so a
	 * picture tagged $cardSha256 (or not tagged) is refused. A 5xx is an outage.
	 *
	 * This route alone also compares the URL's sub with the one SecurySign
	 * resolves from the access token. A client without Keycloak's `basic` scope
	 * mints access tokens with no sub, and the route answers 403 to a valid login.
	 *
	 * @param array{sub: string, issuer: string, accessToken: string} $identity
	 */
	public function handwriting(array $identity, string $cardSha256): ?string {
		$base = self::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
		$response = $this->send('get', $base . '/api/signature/visible/' . rawurlencode($identity['sub']) . '/image', [
			'headers' => ['Authorization' => 'Bearer ' . $identity['accessToken'], 'Accept' => 'image/png'],
		]);
		$status = $response->getStatusCode();
		if ($status >= 500) {
			throw new \RuntimeException('SecurySign is unreachable.', 503);
		}
		$body = (string)$response->getBody();
		if ($status === 200 && str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
			$tag = self::pngText($body, 'signa-image-sha256');
			if ($tag !== '' && $tag !== $cardSha256) {
				return $body;
			}
			$this->logger->warning('SecurySign sent its card, not the visible signature scribble');
			return null;
		}
		$this->logger->warning('SecurySign gave no visible signature scribble', [
			'status' => $status,
			'tokenClaims' => self::claimNames($identity['accessToken']),
		]);
		return null;
	}

	/** A PNG tEXt value, or '' when the image has none under that keyword. */
	private static function pngText(string $png, string $keyword): string {
		$at = 8;
		while ($at + 8 <= strlen($png)) {
			$length = unpack('N', $png, $at)[1];
			if (substr($png, $at + 4, 4) === 'tEXt') {
				[$key, $value] = explode("\0", substr($png, $at + 8, $length), 2) + [1 => ''];
				if ($key === $keyword) {
					return $value;
				}
			}
			$at += 12 + $length;
		}
		return '';
	}

	/**
	 * Mirror the SecurySign card into the user's LibreSign signature elements so
	 * it is what lands on a document. SecurySign owns it: one drawn here is saved
	 * to SecurySign first and arrives through here too, deleting one is refused,
	 * and one that names a superseded certificate is replaced after a renewal.
	 *
	 * Takes the readiness array rather than re-reading it, so a page load still
	 * costs one pair of calls to SecurySign. Failures are logged and swallowed:
	 * the card is already verified, and failing to cache it locally is no reason
	 * to lock someone out of the app.
	 *
	 * @param array{certificateId: string, imagePngBase64: string, imageSha256?: string, card: int} $readiness
	 * @param bool $replace true for a signature just drawn: it replaces the current one even when that one is up to date
	 */
	public function syncVisibleSignature(array $readiness, bool $replace = false): void {
		$user = $this->users->getUser();
		if ($user === null) {
			return;
		}
		try {
			// Resolved lazily: AccountService reaches SignerElementsService, which
			// reaches this class, so constructor injection would not build.
			$mapper = $this->container->get(\OCA\Libresign\Db\UserElementMapper::class);
			$existing = $mapper->findMany(['user_id' => $user->getUID(), 'type' => 'signature']);
			if ($readiness['imagePngBase64'] === '') {
				// SecurySign has no scribble. An import older than the current
				// version may be its card: it goes, and LibreSign asks for a
				// signature, which createSignatureElement sends to SecurySign.
				foreach ($existing as $element) {
					$metadata = $element->getMetadata() ?? [];
					if (isset($metadata['securysign_certificate_id'])
						&& ($metadata['securysign_certificate_id'] !== $readiness['certificateId'] || ($metadata['securysign_card'] ?? 0) < self::CARD_VERSION)) {
						$mapper->delete($element);
					}
				}
				return;
			}
			// The certificate, the card layout and SecurySign's signature all have
			// to match. Without the version, changing the card design would leave
			// every existing user on the old image forever, because their
			// certificate had not changed. Without the signature's hash, a redraw
			// on SecurySign would never replace the copy here.
			foreach ($replace ? [] : $existing as $element) {
				$metadata = $element->getMetadata() ?? [];
				if (($metadata['securysign_certificate_id'] ?? null) === $readiness['certificateId']
					&& ($metadata['securysign_card'] ?? 0) >= $readiness['card']
					&& ($metadata['securysign_image_sha256'] ?? '') === ($readiness['imageSha256'] ?? '')) {
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
					'securysign_card' => $readiness['card'],
					'securysign_image_sha256' => $readiness['imageSha256'] ?? '',
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

	/**
	 * A visible signature scribble drawn in GoPaperless, for someone SecurySign
	 * has none for. SecurySign keeps it, so every app gets it from now on, and
	 * it becomes the signature here just as an imported one does. $image is the
	 * signature box's data URL. Throws with SecurySign's reason when it refuses.
	 */
	public function saveDrawnSignature(string $image): void {
		// Any data URL prefix: the typed signature arrives as
		// data:application/octet-streamng;base64, (LibreSign's TextInput.vue).
		// The bytes decide what it is.
		$png = base64_decode(str_starts_with($image, 'data:') ? substr($image, (int)strpos($image, ',') + 1) : $image, true);
		if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
			throw new \RuntimeException('Your signature could not be read. Please try again.', 400);
		}
		$identity = $this->identity();
		$response = $this->send('post', $this->signingOrigin() . '/api/signature/visible', [
			'headers' => ['Authorization' => 'Bearer ' . $identity['accessToken'], 'Accept' => 'application/json'],
			'json' => ['imagePngBase64' => base64_encode($png), 'sourceType' => 'draw'],
		]);
		$body = json_decode((string)$response->getBody(), true);
		if ($response->getStatusCode() !== 200 || !is_array($body) || empty($body['certificateId'])) {
			$reason = is_array($body) && is_string($body['error'] ?? null) ? substr($body['error'], 0, 200) : '';
			$this->logger->warning('SecurySign refused a visible signature scribble drawn in GoPaperless', [
				'status' => $response->getStatusCode(),
				'error' => $reason,
			]);
			throw new \RuntimeException('SecurySign could not save your signature' . ($reason === '' ? '. Please try again.' : ': ' . $reason), 503);
		}
		$this->syncVisibleSignature([
			'certificateId' => (string)$body['certificateId'],
			'imagePngBase64' => base64_encode($png),
			'imageSha256' => (string)($body['imageSha256'] ?? ''),
			'card' => self::CARD_VERSION,
		], true);
	}

	/**
	 * A signature changed on SecurySign reaches GoPaperless at the next page
	 * load, not the next sign-in. One call while nothing changed. Failures are
	 * logged and swallowed: the gate has already let this session in.
	 */
	public function refreshVisibleSignature(): void {
		if (!$this->applies()) {
			return;
		}
		try {
			$identity = $this->identity();
			$signature = $this->request('signature/visible', true, $identity);
			$cached = $this->session->get(self::READINESS_CACHE_KEY);
			if (is_array($cached) && ($cached['identity'] ?? null) === self::cacheKey($identity)
				&& ($cached['imageSha256'] ?? null) === (string)($signature['imageSha256'] ?? '')) {
				return;
			}
			$this->isReady(true);
		} catch (\Throwable $e) {
			$this->logger->info('Could not check SecurySign for a changed visible signature', ['exception' => $e]);
		}
	}

	/** The next isReady() asks SecurySign again instead of trusting the session. */
	public function forgetReadiness(): void {
		$this->session->remove(self::READINESS_CACHE_KEY);
	}

	/** @param array{sub: string, issuer: string} $identity */
	private static function cacheKey(array $identity): string {
		return hash('sha256', $identity['issuer'] . "\0" . $identity['sub']);
	}

	private function cacheReadiness(string $identity, ?array $readiness): void {
		$this->session->set(self::READINESS_CACHE_KEY, [
			'identity' => $identity,
			'ready' => $readiness !== null,
			'imageSha256' => $readiness['imageSha256'] ?? null,
			// Kept for the account page, so showing it costs no request.
			'certificate' => $readiness === null ? null : [
				'issuer' => $readiness['issuer'],
				'serial' => $readiness['serial'],
				'validUntil' => $readiness['validUntil'],
			],
		]);
	}

	/**
	 * The user's certificate page on the TendaWorld website. A visitor who is
	 * not signed in there is signed in from their live SecurySign session,
	 * which both sites share, and lands on the page with nothing to click.
	 */
	public function profileUrl(): string {
		return $this->websiteOrigin() . '/profile';
	}

	/**
	 * The certificate SecurySign signs this user's documents with, for the
	 * account page: issuer, serial and expiry, from the readiness answer the
	 * session already holds. A session from before they were kept asks once.
	 * Null when SecurySign can't say right now.
	 *
	 * @return array{issuer: string, serial: string, validUntil: string}|null
	 */
	public function signingCertificate(): ?array {
		try {
			$cached = $this->session->get(self::READINESS_CACHE_KEY);
			if (!is_array($cached) || ($cached['identity'] ?? null) !== self::cacheKey($this->identity())
				|| !array_key_exists('certificate', $cached)) {
				$this->isReady(true);
				$cached = $this->session->get(self::READINESS_CACHE_KEY);
			}
			return is_array($cached['certificate'] ?? null) ? $cached['certificate'] : null;
		} catch (\Throwable $e) {
			$this->logger->info('No SecurySign certificate details for the account page', ['exception' => $e]);
			return null;
		}
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
	 * Where a user without a usable certificate gets one, and how they come
	 * back: the TendaWorld website by default, or MIMI when the admin picks it.
	 * Either way they return to $returnUrl, which checks $state.
	 */
	public function enrolmentUrl(string $state, string $sub, string $email, string $returnUrl): string {
		if ($this->config->getValueString(Application::APP_ID, 'enrolment_site', 'tendaworld') === 'mimi') {
			return $this->mimiEnrolmentUrl($sub, $email, $returnUrl);
		}
		return $this->websiteOrigin() . '/onboarding/gopaperless?' . http_build_query(['state' => $state, 'subject' => $sub]);
	}

	/**
	 * MIMI's enrolment for this account. MIMI switches to it if signed in as
	 * another one, asking for this email, runs the steps still missing (a
	 * SecurySign certificate made elsewhere is moved onto the new MIMI passkey),
	 * then sends the user to $returnUrl if it is one of our addresses MIMI has.
	 */
	public function mimiEnrolmentUrl(string $sub, string $email, string $returnUrl): string {
		return $this->mimiOrigin() . '/enrol?' . http_build_query(['sub' => $sub, 'email' => $email, 'returnTo' => $returnUrl]);
	}

	/**
	 * The TendaWorld website's and MIMI's addresses as configured, empty for the
	 * default. Each reads `occ config:app:set libresign <key>` first, then the
	 * system value, which an `NC_<key>` environment variable fills. A deployment
	 * that only sets env needs no occ run, and env never overrides an admin.
	 *
	 * @return array{website: string, mimi: string}
	 */
	public function addresses(): array {
		$read = fn (string $key): string => $this->config->getValueString(Application::APP_ID, $key)
			?: $this->systemConfig->getSystemValueString($key, '');
		return self::splitAddresses($read('tendaworld_url'), $read('mimi_url'));
	}

	/**
	 * ponytail: tendaworld_url held MIMI's address as well until mimi_url
	 * existed, so a MIMI address found there is MIMI's. Drop this once every
	 * instance has saved the SecurySign settings since.
	 *
	 * @return array{website: string, mimi: string}
	 */
	public static function splitAddresses(string $website, string $mimi): array {
		if (str_contains((string)parse_url($website, PHP_URL_HOST), 'mimi')) {
			return ['website' => '', 'mimi' => $mimi ?: $website];
		}
		return ['website' => $website, 'mimi' => $mimi];
	}

	private function websiteOrigin(): string {
		return self::origin($this->addresses()['website'] ?: 'https://tendaworld.com');
	}

	private function mimiOrigin(): string {
		return self::origin($this->addresses()['mimi'] ?: 'https://gopaperless.mimi.ke');
	}

	private const PASSKEY_KEY = 'libresign.mimi.passkey';
	private const PASSKEY_STATE_KEY = 'libresign.mimi.passkey_state';

	/**
	 * Whether this session still owes the MIMI passkey check that follows
	 * sign-in. Off while the switch is off or the MIMI client secret is unset.
	 */
	public function passkeyPending(): bool {
		return $this->applies()
			&& $this->config->getValueBool(Application::APP_ID, 'mimi_passkey_enabled', true)
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
