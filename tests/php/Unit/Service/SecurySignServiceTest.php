<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Service\SecurySignService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecurySignServiceTest extends TestCase {
	private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";
	private const IDENTITY = ['sub' => 'google-oauth2|1', 'issuer' => 'https://idp.test/realms/signa', 'accessToken' => 'at'];

	public function testReturnPathsCannotEscapeTheAppOrRestartAuthentication(): void {
		foreach ([null, '//evil.test', '/apps/libresign/../settings', '/apps/libresign/%2e%2e/settings', '/apps/libresign/%252e%252e/settings', '/apps/libresign/\\evil.test', '/apps/libresign/sso', '/apps/libresign/securysign/return', 'https://evil.test', "/apps/libresign/f/\nfoo"] as $path) {
			self::assertSame('/apps/libresign/', SecurySignService::returnPath($path));
		}
		self::assertSame('/apps/libresign/f/document?tab=sign', SecurySignService::returnPath('/apps/libresign/f/document?tab=sign'));
	}

	public function testOnlyTheConfiguredOidcSessionIsIncluded(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(2);
		$session = $this->createMock(ISession::class);
		$provider = null;
		$session->method('get')->willReturnCallback(static function () use (&$provider) {
			return $provider;
		});
		$users = $this->createMock(IUserSession::class);
		$users->method('isLoggedIn')->willReturn(true);
		$service = new SecurySignService($config, $this->createMock(IConfig::class), $session, $users, $this->createMock(IServerContainer::class), $this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));
		self::assertFalse($service->applies());
		$provider = 1;
		self::assertFalse($service->applies());
		$provider = 2;
		self::assertTrue($service->applies());
	}

	public function testTheOnboardingTargetComesFromOccOrTheSystemValue(): void {
		$fromOcc = '';
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static function () use (&$fromOcc) {
			return $fromOcc;
		});
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValueString')->willReturn('https://staging.tendaworld.com');
		$service = new SecurySignService($appConfig, $system, $this->createMock(ISession::class), $this->createMock(IUserSession::class), $this->createMock(IServerContainer::class), $this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));

		// Nobody ran occ: the system value, which an NC_tendaworld_url env var fills.
		self::assertSame('https://staging.tendaworld.com/onboarding/gopaperless', $service->onboardingUrl());

		// occ wins once it is set, so the image's env cannot override an admin.
		$fromOcc = 'https://tendaworld.com';
		self::assertSame('https://tendaworld.com/onboarding/gopaperless', $service->onboardingUrl());
	}

	public function testThePasskeyFrameIsMimisPageForOurClient(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'tendaworld_url' ? 'https://localhost:3000' : $default,
		);
		$service = new SecurySignService($appConfig, $this->createMock(IConfig::class), $this->createMock(ISession::class), $this->createMock(IUserSession::class), $this->createMock(IServerContainer::class), $this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));

		self::assertSame([
			'origin' => 'https://localhost:3000',
			'url' => 'https://localhost:3000/passkey/frame?client_id=gopaperless&lang=en',
		], $service->passkeyFrame());
		// Nextcloud names regions too; MIMI only needs the language.
		self::assertStringEndsWith('&lang=pt', $service->passkeyFrame('pt_BR')['url']);
		self::assertStringEndsWith('&lang=sw', $service->passkeyFrame('sw')['url']);
	}

	public function testMissingCertificateIsDifferentFromAnOutage(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(null);
		$constructor = [
			$this->createMock(IAppConfig::class),
			$this->createMock(IConfig::class),
			$session,
			$this->createMock(IUserSession::class),
			$this->createMock(IServerContainer::class),
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
		];
		$identity = ['sub' => 'google-oauth2|1', 'issuer' => 'https://idp.test/realms/signa', 'accessToken' => 'at'];
		$service = $this->getMockBuilder(SecurySignService::class)->setConstructorArgs($constructor)->onlyMethods(['identity', 'request'])->getMock();
		$service->method('identity')->willReturn($identity);
		$service->method('request')->willReturn(['status' => 'none']);
		self::assertFalse($service->isReady());
		$service = $this->getMockBuilder(SecurySignService::class)->setConstructorArgs($constructor)->onlyMethods(['identity', 'request'])->getMock();
		$service->method('identity')->willReturn($identity);
		$service->method('request')->willReturn(['error' => 'unavailable']);
		$this->expectException(\RuntimeException::class);
		$service->isReady();
	}

	public function testACertificateOutsideItsValidityWindowIsNotUsable(): void {
		$now = time();
		self::assertTrue(SecurySignService::isCurrent(['validFrom_time_t' => $now - 10, 'validTo_time_t' => $now + 10]));
		self::assertFalse(SecurySignService::isCurrent(['validFrom_time_t' => $now - 20, 'validTo_time_t' => $now - 10]));
		self::assertFalse(SecurySignService::isCurrent(['validFrom_time_t' => $now + 10, 'validTo_time_t' => $now + 20]));
		self::assertFalse(SecurySignService::isCurrent([]));
		self::assertFalse(SecurySignService::isCurrent(['validFrom_time_t' => 'soon', 'validTo_time_t' => 'later']));
	}

	public function testTheVisibleSignatureMustBelongToTheActiveCertificate(): void {
		$pem = self::selfSignedPem();
		$png = base64_encode(self::PNG_MAGIC . 'body');
		$certificate = ['status' => 'active', 'credentialId' => 'cred-1', 'certificate' => ['certificateId' => 7, 'certificatePem' => $pem]];

		self::assertTrue($this->readiness($certificate, ['certificateId' => 7, 'imagePngBase64' => $png]));
		// A card left over from a previous certificate must not count as canonical.
		self::assertFalse($this->readiness($certificate, ['certificateId' => 6, 'imagePngBase64' => $png]));
		// No card captured yet: onboarding, not an outage.
		self::assertFalse($this->readiness($certificate, null));

		$this->expectException(\RuntimeException::class);
		$this->readiness($certificate, ['certificateId' => 7, 'imagePngBase64' => base64_encode('<html>')]);
	}

	/**
	 * @param array<string, mixed> $certificate
	 * @param array<string, mixed>|null $signature
	 */
	private function readiness(array $certificate, ?array $signature, ?string $handwriting = null): bool {
		return $this->readinessOf($certificate, $signature, $handwriting) !== null;
	}

	/**
	 * @param array<string, mixed> $certificate
	 * @param array<string, mixed>|null $signature
	 */
	private function readinessOf(array $certificate, ?array $signature, ?string $handwriting): ?array {
		$service = $this->getMockBuilder(SecurySignService::class)
			->disableOriginalConstructor()->onlyMethods(['request', 'handwriting'])->getMock();
		$service->method('request')->willReturnCallback(
			static fn (string $path) => str_starts_with($path, 'pki/') ? $certificate : $signature,
		);
		$service->method('handwriting')->willReturn($handwriting);
		return $service->readiness(self::IDENTITY);
	}

	/**
	 * SecurySign's signature row carries its assembled card (handwriting, name,
	 * certificate details, QR code). Drawn inside GoPaperless's own signing card,
	 * that stamped a card within a card, so the handwriting-only image is used.
	 */
	public function testTheHandwritingAloneIsImportedNotSecurySignsCard(): void {
		$pem = self::selfSignedPem();
		$certificate = ['status' => 'active', 'credentialId' => 'cred-1', 'certificate' => ['certificateId' => 7, 'certificatePem' => $pem]];
		$card = ['certificateId' => 7, 'imagePngBase64' => base64_encode(self::PNG_MAGIC . 'card')];

		self::assertSame(base64_encode(self::PNG_MAGIC . 'scribble'), $this->readinessOf($certificate, $card, self::PNG_MAGIC . 'scribble')['imagePngBase64']);
		// A SecurySign without the handwriting route still yields its card.
		self::assertSame($card['imagePngBase64'], $this->readinessOf($certificate, $card, null)['imagePngBase64']);
	}

	public function testTheHandwritingRouteIsReadWithTheUsersToken(): void {
		self::$gets = 0;
		self::assertSame(self::PNG_MAGIC . 'scribble', self::serviceAnswering(200, self::PNG_MAGIC . 'scribble')->handwriting(self::IDENTITY));
		self::assertNull(self::serviceAnswering(404)->handwriting(self::IDENTITY));
		try {
			self::serviceAnswering(403)->handwriting(self::IDENTITY);
			self::fail('HTTP 403 accepted');
		} catch (\RuntimeException $e) {
			self::assertSame(401, $e->getCode());
		}
	}

	private static function selfSignedPem(): string {
		$key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = $key ? @openssl_csr_new(['commonName' => 'SecurySign Test'], $key, ['digest_alg' => 'sha256']) : false;
		$x509 = $csr ? @openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']) : false;
		if (!$x509 || !openssl_x509_export($x509, $pem)) {
			self::markTestSkipped('openssl cannot issue a certificate here; set OPENSSL_CONF to the runtime openssl.cnf');
		}
		return $pem;
	}

	public function testConfiguredOriginCannotIncludeCredentialsOrPaths(): void {
		self::assertSame('https://signa.test', SecurySignService::origin('https://signa.test/'));
		self::assertSame('http://localhost:3000', SecurySignService::origin('http://localhost:3000'));
		self::assertSame('http://127.0.0.1', SecurySignService::origin('http://127.0.0.1/'));
		foreach ([
			'http://signa.test', 'http://localhost.signa.test', 'http://127.0.0.1.signa.test',
			'https://user:password@signa.test', 'https://signa.test/api', 'https://signa.test?x=1',
			'https://signa.test#fragment', 'http://localhost/api',
		] as $url) {
			try {
				SecurySignService::origin($url);
				self::fail('Invalid origin accepted: ' . $url);
			} catch (\RuntimeException $e) {
				self::assertSame(503, $e->getCode());
			}
		}
	}
	/**
	 * @return array{0: SecurySignService, 1: object}
	 */
	private static int $gets = 0;
	/** @var list<array<string, mixed>> */
	private static array $posts = [];

	public function testTheSigningTokenNamesThePasskeyWhenTheEmailIsKnown(): void {
		$service = self::serviceAnswering(200, '{"token":"t"}');
		self::$posts = [];

		$service->signingToken('ab', 'signer@example.com');
		$service->signingToken('ab');

		// LOA-4 binds the user's passkey, which is how a mimi.ke passkey gets asked for.
		self::assertSame(['LOA-4', 'signer@example.com'], [self::$posts[0]['json']['loa'], self::$posts[0]['json']['email']]);
		self::assertSame('LOA-2', self::$posts[1]['json']['loa']);
		self::assertArrayNotHasKey('email', self::$posts[1]['json']);
	}

	public function testTheSigningCardIsReadOrLeftOutWhenSecurySignHasNone(): void {
		$png = "\x89PNG\r\n\x1a\nrest";
		$context = json_encode([
			'certificateId' => 7,
			'certificateSha256' => 'AB:CD',
			'verifiedFullName' => 'JANE WANJIKU NJOROGE',
			'nameSource' => 'verified_identity_document',
			'issuerName' => 'Signa Hardware CA',
			'handwritingPngBase64' => base64_encode($png),
			'signingTime' => '2026-10-05T11:32:08Z',
			'signingTimeMeaning' => 'preparation',
			'expiresAt' => '2026-10-05T11:37:08Z',
		]);
		$card = self::serviceAnswering(200, $context)->signingContext();
		self::assertSame(['abcd', 'JANE WANJIKU NJOROGE', 'Signa Hardware CA', $png], [$card['certificateSha256'], $card['name'], $card['issuer'], $card['handwriting']]);
		self::assertSame('2026-10-05T11:32:08+00:00', $card['time']->format(\DateTimeInterface::ATOM));

		// No verified identity, a profile-name certificate, the feature off: the usual appearance.
		foreach ([409, 403, 429] as $status) {
			self::assertNull(self::serviceAnswering($status, '{"error":"Renew the certificate"}')->signingContext());
		}
		self::assertNull(self::serviceAnswering(200, str_replace(base64_encode($png), base64_encode('GIF89a'), $context))->signingContext());
	}

	public function testAnOutageSkipsSecurySignForAMinute(): void {
		$store = ['oidc.providerid' => 1];
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$session->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): void {
			$store[$key] = $value;
		});
		$service = self::serviceAnswering(502, '{}', $session);
		self::$gets = 0;

		foreach ([1, 2] as $attempt) {
			try {
				$service->request('pki/certificates/me');
				self::fail('A 502 was treated as an answer');
			} catch (\RuntimeException $e) {
				self::assertSame(503, $e->getCode());
			}
		}
		// The second attempt fell back at once instead of waiting on SecurySign again.
		self::assertSame(1, self::$gets);
	}

	private static function serviceAnswering(int $status, string $body = '{}', ?ISession $session = null): SecurySignService {
		$test = new self('t');
		$config = $test->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(1);
		$config->method('getValueString')->willReturn('https://signa.test');
		if ($session === null) {
			$session = $test->createMock(ISession::class);
			$session->method('get')->willReturn(1);
		}
		$users = $test->createMock(IUserSession::class);
		$users->method('isLoggedIn')->willReturn(true);

		$token = new class {
			public function isExpired(): bool {
				return false;
			}
			public function getProviderId(): int {
				return 1;
			}
			public function getAccessToken(): string {
				return 'at';
			}
		};
		$tokens = new class($token) {
			public function __construct(
				private object $token,
			) {
			}
			public function getToken(): object {
				return $this->token;
			}
			public function decodeIdToken(object $t): array {
				return ['iss' => 'https://idp.test/realms/signa', 'sub' => 'google-1'];
			}
		};
		$providers = new class {
			public function getProvider(int $id): object {
				return new class {
					public function getDiscoveryEndpoint(): string {
						return 'https://idp.test/realms/signa/.well-known/openid-configuration?kc_idp_hint=google';
					}
					public function getClientId(): string {
						return 'signa-rp-test';
					}
				};
			}
		};
		$container = $test->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => str_ends_with($id, 'ProviderMapper') ? $providers : $tokens);

		$response = $test->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$client = $test->createMock(IClient::class);
		$client->method('get')->willReturnCallback(static function () use ($response) {
			self::$gets++;
			return $response;
		});
		$client->method('post')->willReturnCallback(static function (string $url, array $options) use ($response) {
			self::$posts[] = $options;
			return $response;
		});
		$clients = $test->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new SecurySignService($config, $test->createMock(IConfig::class), $session, $users, $container, $clients, $test->createMock(LoggerInterface::class));
	}

	public function testARejectedSessionAsksForReauthAndAnOutageNamesTheStatus(): void {
		foreach ([401, 403] as $status) {
			try {
				self::serviceAnswering($status)->request('pki/certificates/me');
				self::fail('HTTP ' . $status . ' accepted');
			} catch (\RuntimeException $e) {
				self::assertSame(401, $e->getCode());
				self::assertStringContainsString((string)$status, $e->getMessage());
			}
		}

		try {
			self::serviceAnswering(500)->request('pki/certificates/me');
			self::fail('HTTP 500 accepted');
		} catch (\RuntimeException $e) {
			self::assertSame(503, $e->getCode());
			self::assertStringContainsString('500', $e->getMessage());
			self::assertStringContainsString('pki/certificates/me', $e->getMessage());
		}

		self::assertNull(self::serviceAnswering(404)->request('signature/visible', true));
		self::assertSame(['status' => 'none'], self::serviceAnswering(200, '{"status":"none"}')->request('pki/certificates/me'));
	}
	public function testClaimNamesNeverLeakValues(): void {
		$payload = rtrim(strtr(base64_encode('{"sub":"google-1","email":"a@b.test"}'), '+/', '-_'), '=');
		$names = SecurySignService::claimNames('header.' . $payload . '.signature');
		self::assertSame('sub email', $names);
		self::assertStringNotContainsString('google-1', $names);
		self::assertStringNotContainsString('a@b.test', $names);

		self::assertStringContainsString('opaque', SecurySignService::claimNames('not-a-jwt'));
		self::assertStringContainsString('unreadable', SecurySignService::claimNames('a.!!!.c'));
	}
	public function testACurrentCertificateAndItsCardAreWhatMakeAUserReady(): void {
		$pem = self::selfSignedPem();
		$png = base64_encode(self::PNG_MAGIC . 'body');
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(null);
		$service = $this->getMockBuilder(SecurySignService::class)
			->setConstructorArgs([
				$this->createMock(IAppConfig::class),
				$this->createMock(IConfig::class),
				$session,
				$this->createMock(IUserSession::class),
				$this->createMock(IServerContainer::class),
				$this->createMock(IClientService::class),
				$this->createMock(LoggerInterface::class),
			])->onlyMethods(['identity', 'request', 'handwriting'])->getMock();
		$service->method('identity')->willReturn([
			'sub' => 'google-oauth2|1',
			'issuer' => 'https://idp.test/realms/signa',
			'accessToken' => 'at',
		]);
		$service->method('request')->willReturnCallback(static fn (string $path) => $path === 'pki/certificates/me'
			? ['status' => 'active', 'credentialId' => 'c1', 'certificate' => ['certificateId' => 7, 'certificatePem' => $pem]]
			: ['certificateId' => 7, 'imagePngBase64' => $png]);
		$service->method('handwriting')->willReturn(null);

		$readiness = $service->readiness();

		self::assertNotNull($readiness);
		self::assertSame('7', $readiness['certificateId'], 'the id is normalised to a string for comparison');
		self::assertSame($png, $readiness['imagePngBase64']);
		self::assertTrue($service->isReady());
	}

	public function testReadinessIsCachedPerIdentityUntilForcedToRefresh(): void {
		$session = $this->createMock(ISession::class);
		$cache = null;
		$session->method('get')->willReturnCallback(static function () use (&$cache) {
			return $cache;
		});
		$session->method('set')->willReturnCallback(static function (string $key, array $value) use (&$cache): void {
			$cache = $value;
		});

		$service = $this->getMockBuilder(SecurySignService::class)
			->setConstructorArgs([
				$this->createMock(IAppConfig::class),
				$this->createMock(IConfig::class),
				$session,
				$this->createMock(IUserSession::class),
				$this->createMock(IServerContainer::class),
				$this->createMock(IClientService::class),
				$this->createMock(LoggerInterface::class),
			])->onlyMethods(['identity', 'request'])->getMock();
		$service->method('identity')->willReturn([
			'sub' => 'google-oauth2|1',
			'issuer' => 'https://idp.test/realms/signa',
			'accessToken' => 'at',
		]);
		$service->expects(self::exactly(2))->method('request')->willReturn([
			'status' => 'none',
		]);

		self::assertFalse($service->isReady());
		self::assertFalse($service->isReady());
		self::assertFalse($service->isReady(true));
	}
}
