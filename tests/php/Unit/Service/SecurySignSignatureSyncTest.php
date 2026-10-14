<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\UserElement;
use OCA\Libresign\Db\UserElementMapper;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\SecurySignService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The card SecurySign holds is mirrored into LibreSign's own signature elements,
 * because that is what actually gets placed on a document. The interesting cases
 * are the ones that repeat: a page load for someone already in sync must not
 * write anything, and a renewed certificate must replace the old card rather
 * than leave two.
 */
final class SecurySignSignatureSyncTest extends TestCase {
	private const CARD = ['certificateId' => '7', 'imagePngBase64' => 'cGluZw==', 'card' => SecurySignService::CARD_VERSION,
		'issuer' => 'Test CA', 'serial' => 'AA BB', 'validFrom' => '1 Jan 2026', 'validUntil' => '1 Jan 2027'];

	private UserElementMapper&MockObject $mapper;
	private AccountService&MockObject $accounts;
	private SecurySignService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(UserElementMapper::class);
		$this->accounts = $this->createMock(AccountService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone@example.test');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);

		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(fn (string $class) => match ($class) {
			UserElementMapper::class => $this->mapper,
			AccountService::class => $this->accounts,
			default => throw new \RuntimeException('unexpected ' . $class),
		});

		// readiness() has already used this origin successfully by the time the
		// sync runs, so an unparseable one is not a real state — but the QR
		// payload reads it, and a bare mock would return ''.
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://signa.test');

		$this->service = new SecurySignService(
			$config,
			$this->createMock(IConfig::class),
			$this->createMock(ISession::class),
			$users,
			$container,
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function element(int $id, ?string $certificateId): UserElement {
		$element = new UserElement();
		$element->setId($id);
		$element->setType('signature');
		$element->setUserId('someone@example.test');
		if ($certificateId !== null) {
			$element->setMetadata(['securysign_certificate_id' => $certificateId, 'securysign_card' => SecurySignService::CARD_VERSION]);
		}
		return $element;
	}

	public function testAnAlreadyMirroredCardIsNotWrittenAgain(): void {
		$this->mapper->method('findMany')->willReturn([$this->element(1, '7')]);
		$this->accounts->expects(self::never())->method('saveVisibleElement');
		$this->mapper->expects(self::never())->method('delete');

		$this->service->syncVisibleSignature(self::CARD);
	}

	/**
	 * SecurySign has no scribble for an old account, only its card. An older
	 * import may be that card, so it goes and the user draws one; the current
	 * import, or a local drawing, stays.
	 */
	public function testWithoutAScribbleAnImportThatMayBeTheCardGoes(): void {
		$card = $this->element(1, '7');
		$card->setMetadata(['securysign_certificate_id' => '7', 'securysign_card' => 4]);
		$current = $this->element(2, '7');
		$local = $this->element(3, null);
		$this->mapper->method('findMany')->willReturn([$card, $current, $local]);
		$this->accounts->expects(self::never())->method('saveVisibleElement');
		$this->mapper->expects(self::once())->method('delete')->with($card);

		$this->service->syncVisibleSignature(['imagePngBase64' => ''] + self::CARD);
	}

	public function testAFirstCardIsImportedAndStampedWithItsCertificate(): void {
		$imported = $this->element(9, null);
		$this->mapper->method('findMany')->willReturnOnConsecutiveCalls([], [$imported]);
		$this->accounts->expects(self::once())->method('saveVisibleElement')
			->willReturnCallback(function (array $data, string $session, ?IUser $user): void {
				self::assertSame('signature', $data['type']);
				self::assertNotNull($user);
				// Stored untouched: the account, issuer and dates are added at signing
				// time by LibreSign's signature text template, not baked in here.
				self::assertSame('data:image/png;base64,cGluZw==', $data['file']['base64']);
			});
		$this->mapper->expects(self::never())->method('delete');
		$this->mapper->expects(self::once())->method('update');

		$this->service->syncVisibleSignature(self::CARD);

		self::assertSame('7', ($imported->getMetadata() ?? [])['securysign_certificate_id']);
	}

	public function testARenewedCertificateReplacesTheOldCardInsteadOfStacking(): void {
		$stale = $this->element(1, '6');
		$fresh = $this->element(2, null);
		$this->mapper->method('findMany')->willReturnOnConsecutiveCalls([$stale], [$stale, $fresh]);
		$this->accounts->expects(self::once())->method('saveVisibleElement');

		$deleted = [];
		$this->mapper->method('delete')->willReturnCallback(function (UserElement $e) use (&$deleted) {
			$deleted[] = $e->getId();
			return $e;
		});

		$this->service->syncVisibleSignature(self::CARD);

		self::assertSame([1], $deleted, 'only the card from the superseded certificate goes');
		self::assertSame('7', ($fresh->getMetadata() ?? [])['securysign_certificate_id']);
	}

	public function testAnOlderImportNeverReplacesTheScribble(): void {
		$this->mapper->method('findMany')->willReturn([$this->element(1, '7')]);
		$this->accounts->expects(self::never())->method('saveVisibleElement');

		$this->service->syncVisibleSignature(['card' => 3] + self::CARD);
	}

	public function testTheScribbleReplacesAnOlderImportThatMayBeTheCard(): void {
		$fallback = $this->element(1, '7');
		$fallback->setMetadata(['securysign_certificate_id' => '7', 'securysign_card' => 4]);
		$fresh = $this->element(2, null);
		$this->mapper->method('findMany')->willReturnOnConsecutiveCalls([$fallback], [$fallback, $fresh]);
		$this->accounts->expects(self::once())->method('saveVisibleElement');
		$this->mapper->expects(self::once())->method('delete')->with($fallback);

		$this->service->syncVisibleSignature(self::CARD);

		self::assertSame(SecurySignService::CARD_VERSION, ($fresh->getMetadata() ?? [])['securysign_card']);
	}

	public function testAFailedMirrorDoesNotLockTheUserOut(): void {
		$this->mapper->method('findMany')->willThrowException(new \RuntimeException('database gone'));

		$this->service->syncVisibleSignature(self::CARD);

		self::assertTrue(true, 'reaching here is the assertion: the throw was swallowed');
	}

	public function testTheCardIsMirroredOnAFreshCheckAndNotOnACachedOne(): void {
		$session = $this->createMock(ISession::class);
		$cached = null;
		$session->method('get')->willReturnCallback(static function () use (&$cached) {
			return $cached;
		});
		$session->method('set')->willReturnCallback(static function (string $key, array $value) use (&$cached): void {
			$cached = $value;
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
			])->onlyMethods(['identity', 'readiness', 'syncVisibleSignature'])->getMock();
		$service->method('identity')->willReturn(['sub' => 'google-oauth2|1', 'issuer' => 'https://idp.test/realms/signa', 'accessToken' => 'at']);
		$service->method('readiness')->willReturn(self::CARD);
		$service->expects(self::once())->method('syncVisibleSignature')->with(self::CARD);

		self::assertTrue($service->isReady());
		// Second page load of the same session: cached, so nothing is imported again.
		self::assertTrue($service->isReady());
	}

	public function testTheModuleStaysOpenWhileNothingOfOursIsStored(): void {
		$this->mapper->method('findMany')->willReturnOnConsecutiveCalls([], [$this->element(1, null)]);

		self::assertFalse($this->service->hasMirroredSignature(), 'nothing stored yet');
		self::assertFalse($this->service->hasMirroredSignature(), 'a signature the user drew is not one of ours');
	}

	public function testAMirroredCardClosesTheModule(): void {
		$this->mapper->method('findMany')->willReturn([$this->element(1, '7')]);

		self::assertTrue($this->service->hasMirroredSignature());
	}

	public function testAnUnreadableMapperLeavesTheModuleOpen(): void {
		$this->mapper->method('findMany')->willThrowException(new \RuntimeException('database gone'));

		self::assertFalse($this->service->hasMirroredSignature(), 'locking the user out is the worse failure');
	}
}
