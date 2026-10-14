<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Handler\SignEngine;

use OCA\Libresign\DataObjects\VisibleElementAssoc;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Handler\SignEngine\PhpNativeHandler;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Handler\SignEngine\SecurySignHandler;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class SigningCardLayoutTest extends TestCase {
	private string $handwriting;

	protected function setUp(): void {
		// 300 x 100: the handwriting has to keep this 3:1 shape on the card.
		$image = imagecreatetruecolor(300, 100);
		$this->handwriting = tempnam(sys_get_temp_dir(), 'card') . '.png';
		imagepng($image, $this->handwriting);
	}

	protected function tearDown(): void {
		@unlink($this->handwriting);
	}

	private function card(string $name): array {
		return [
			'name' => $name,
			'issuer' => 'Signa Hardware CA',
			'time' => new \DateTimeImmutable('2026-10-05T11:32:08Z'),
			'handwriting' => $this->handwriting,
		];
	}

	public function testTheCardShowsTheNameIssuerAndTheTimeInNairobi(): void {
		[$xObject] = PhpNativeHandler::signingCardLayout($this->card('Jane Wanjiku Njoroge'), 200.0, 90.0);

		self::assertStringContainsString('(JANE WANJIKU NJOROGE) Tj', $xObject->stream);
		self::assertStringContainsString('(ISSUER: SIGNA HARDWARE CA) Tj', $xObject->stream);
		self::assertStringContainsString('(TIMESTAMP: 05 Oct 2026, 14:32:08 EAT) Tj', $xObject->stream);
		self::assertStringNotContainsString('re S', $xObject->stream, 'no border around the card');
		self::assertSame('/Helvetica-Bold', $xObject->resources['Font']['F2']['BaseFont']);
	}

	public function testALongNameShrinksToFitInsteadOfRunningOffTheBox(): void {
		$name = 'Bartholomew Ochieng Wanjiku Kamau Otieno Njoroge';
		foreach ([[200.0, 90.0], [120.0, 60.0]] as [$width, $height]) {
			[$xObject] = PhpNativeHandler::signingCardLayout($this->card($name), $width, $height);
			preg_match_all('/\/F\d (\d+\.\d+) Tf [\d. ]+ rg (\d+\.\d+) [\d.]+ Td/', $xObject->stream, $lines, PREG_SET_ORDER);
			self::assertCount(3, $lines);
			foreach ($lines as [, $size, $x]) {
				self::assertGreaterThan(0.0, (float)$x);
				self::assertLessThan($width / 2, (float)$x, 'centred text starts in the left half');
				self::assertGreaterThan(0.0, (float)$size);
			}
		}
	}

	public function testTheHandwritingKeepsItsShapeAboveTheText(): void {
		[$xObject, $frame] = PhpNativeHandler::signingCardLayout($this->card('Jane Njoroge'), 200.0, 90.0);
		[$x, $y, $w, $h] = $frame;

		self::assertEqualsWithDelta(3.0, $w / $h, 0.01);
		self::assertGreaterThanOrEqual(0.0, $x);
		self::assertLessThanOrEqual(200.0, $x + $w);
		self::assertLessThanOrEqual(90.0, $y + $h);
		preg_match('/\(JANE NJOROGE\)/', $xObject->stream);
		preg_match_all('/([\d.]+) ([\d.]+) Td \(JANE NJOROGE\)/', $xObject->stream, $name);
		self::assertGreaterThan((float)$name[2][0], $y, 'the handwriting sits above the name');
	}

	/** The horizontal A/B card: handwriting and name left, ISSUER over TIMESTAMP right. */
	public function testTheHorizontalCardKeepsTheHandwritingWholeOnTheLeft(): void {
		foreach ([[240.0, 70.0], [160.0, 50.0], [300.0, 110.0]] as [$width, $height]) {
			$card = $this->card('Bartholomew Ochieng Wanjiku Kamau') + ['layout' => 'horizontal'];
			[$xObject, $frame] = PhpNativeHandler::signingCardLayout($card, $width, $height);
			[$x, $y, $w, $h] = $frame;

			self::assertEqualsWithDelta(3.0, $w / $h, 0.01, 'the handwriting keeps its shape');
			self::assertGreaterThanOrEqual(0.0, $x);
			self::assertLessThanOrEqual($width * 0.56, $x + $w, 'the handwriting stays in the left column');
			self::assertLessThanOrEqual($height, $y + $h, 'the handwriting is not cut off at the top');

			preg_match_all('/([\d.]+) Tf ([\d.]+) Tc [\d. ]+ rg ([\d.]+) ([\d.]+) Td \(([^)]*)\) Tj/', $xObject->stream, $lines, PREG_SET_ORDER);
			$at = [];
			foreach ($lines as [, $size, , $lx, $ly, $text]) {
				$at[$text] = [(float)$lx, (float)$ly, (float)$size];
			}
			$time = '05 Oct 2026, 14:32:08 EAT';
			self::assertArrayHasKey('ISSUER', $at);
			self::assertArrayHasKey('TIMESTAMP', $at);
			self::assertArrayHasKey($time, $at);
			self::assertGreaterThan($at['SIGNA HARDWARE CA'][1], $at['ISSUER'][1], 'label over value');
			self::assertGreaterThan($at['TIMESTAMP'][1], $at['SIGNA HARDWARE CA'][1], 'ISSUER above TIMESTAMP');

			// The long name may wrap; every word is there, in order, on the left.
			$name = array_diff_key($at, array_flip(['ISSUER', 'SIGNA HARDWARE CA', 'TIMESTAMP', $time]));
			uasort($name, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
			self::assertSame('BARTHOLOMEW OCHIENG WANJIKU KAMAU', implode(' ', array_keys($name)));
			self::assertEqualsWithDelta(min(array_column($name, 1)), $at[$time][1], 0.01, 'TIMESTAMP level with the name');
			foreach ($name as [$nx, $ny, $size]) {
				self::assertLessThan($at['ISSUER'][0], $nx, 'name on the left');
				self::assertLessThan($y, $ny, 'the name sits under the handwriting');
				self::assertGreaterThanOrEqual($at[$time][2] * 0.6, $size, 'a long name stays legible next to the values');
			}
			self::assertGreaterThanOrEqual($at['SIGNA HARDWARE CA'][2] * 0.99, $at[$time][2], 'one scale on the right');
		}
	}

	/**
	 * horizontal-top, the default: one composition scaled whole into the box.
	 * ISSUER and TIMESTAMP sit against the scribble's top right like a
	 * superscript, the name under it, and a tighter box gets a smaller copy of
	 * the same card rather than a rearranged one.
	 */
	public function testTheDefaultCardIsOneCompositionScaledIntoTheBox(): void {
		$textWidth = static fn (string $text, bool $bold): float => (new \ReflectionMethod(PhpNativeHandler::class, 'helveticaWidth'))->invoke(null, $text, $bold);
		$time = '05 Oct 2026, 14:32:08 EAT';
		$shares = [];
		foreach ([[350.0, 100.0], [160.0, 40.0], [120.0, 90.0]] as [$width, $height]) {
			$card = $this->card('Jane Wanjiku Njoroge') + ['layout' => 'horizontal-top'];
			[$xObject, [$x, $y, $w, $h]] = PhpNativeHandler::signingCardLayout($card, $width, $height);
			preg_match_all('/([\d.]+) Tf ([\d.]+) Tc [\d. ]+ rg ([\d.]+) ([\d.]+) Td \(([^)]*)\) Tj/', $xObject->stream, $lines, PREG_SET_ORDER);
			$at = [];
			foreach ($lines as [, $size, , $lx, $ly, $text]) {
				$at[$text] = [(float)$lx, (float)$ly, (float)$size];
			}
			$name = $at['JANE WANJIKU NJOROGE'];

			self::assertEqualsWithDelta(3.0, $w / $h, 0.01, 'the scribble keeps its shape');
			self::assertGreaterThanOrEqual(0.0, $x, 'inside the box');
			self::assertLessThanOrEqual($height, $y + $h, 'inside the box');
			self::assertGreaterThanOrEqual(0.0, $name[1] - $name[2] * 0.22, 'inside the box');
			self::assertLessThanOrEqual($width, $at[$time][0] + $textWidth($time, false) * $at[$time][2], 'inside the box');

			self::assertEqualsWithDelta($x + $w, $at['ISSUER'][0], $w * 0.05, 'ISSUER just after the scribble');
			self::assertEqualsWithDelta($y + $h * 0.9, $at['ISSUER'][1] + $at['ISSUER'][2] * 0.75, $h * 0.02, 'ISSUER a little below the scribble top');
			self::assertGreaterThan($at['ISSUER'][2] * 1.3, $at[$time][2], 'the values read larger than the labels');
			$order = ['ISSUER', 'SIGNA HARDWARE CA', 'TIMESTAMP', $time];
			for ($i = 1; $i < count($order); $i++) {
				self::assertGreaterThan($at[$order[$i]][1], $at[$order[$i - 1]][1], $order[$i - 1] . ' above ' . $order[$i]);
				self::assertEqualsWithDelta($at['ISSUER'][0], $at[$order[$i]][0], 0.01, 'one left edge');
			}
			self::assertEqualsWithDelta($x, $name[0], 0.01, 'the name starts under the scribble');
			self::assertLessThan($y, $name[1], 'the name under the scribble');
			$shares[] = $name[2] / $h;
		}
		foreach ($shares as $share) {
			self::assertEqualsWithDelta($shares[0], $share, $shares[0] * 0.02, 'every box gets the same card, only its size changes');
		}
	}

	/** A long name stays on one line and runs on under the column, which stays against the scribble. */
	public function testALongNameRunsOnWithoutMovingTheSuperscript(): void {
		$name = 'BARTHOLOMEW OCHIENG WANJIKU KAMAU OTIENO NJOROGE';
		[$xObject, [$x, , $w]] = PhpNativeHandler::signingCardLayout($this->card($name) + ['layout' => 'horizontal-top'], 350.0, 100.0);
		preg_match_all('/\/F2 ([\d.]+) Tf [\d.]+ Tc [\d. ]+ rg ([\d.]+) [\d.]+ Td \(([^)]*)\)/', $xObject->stream, $bold, PREG_SET_ORDER);
		preg_match('/([\d.]+) [\d.]+ Td \(ISSUER\)/', $xObject->stream, $issuer);

		self::assertCount(1, $bold, 'the name on one line');
		self::assertSame($name, $bold[0][3]);
		self::assertEqualsWithDelta($x + $w, (float)$issuer[1], $w * 0.05, 'ISSUER still just after the scribble');
		$textWidth = (new \ReflectionMethod(PhpNativeHandler::class, 'helveticaWidth'))->invoke(null, $name, true);
		self::assertLessThanOrEqual(350.0, (float)$bold[0][2] + $textWidth * (float)$bold[0][1], 'the name stays inside the box');
	}

	public function testTheScribbleIsCutToItsInk(): void {
		$canvas = imagecreatetruecolor(600, 200);
		imagesavealpha($canvas, true);
		imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
		imagefilledrectangle($canvas, 100, 60, 399, 139, imagecolorallocate($canvas, 20, 30, 90));
		$path = tempnam(sys_get_temp_dir(), 'ink') . '.png';
		imagepng($canvas, $path);

		$trimmed = imagecreatefromstring((string)PhpNativeHandler::trimmedScribble($path));
		self::assertSame([304, 84], [imagesx($trimmed), imagesy($trimmed)], 'the ink plus two pixels of air');
		self::assertNull(PhpNativeHandler::trimmedScribble($this->handwriting), 'nothing to cut');
		unlink($path);
	}

	/** Without SecurySign, GoPaperless fills the card: profile name, issuer, signing time, the box's own drawing. */
	public function testTheBuiltInCardUsesTheProfileNameAndTheSigningTime(): void {
		$time = new \DateTimeImmutable('2026-10-09T08:00:00Z');
		$params = ['SignerName' => 'Jane Njoroge', 'SignerCommonName' => 'jane@example.com', 'IssuerCommonName' => 'GoPaperless CA'];
		$box = [new VisibleElementAssoc(new FileElement(), $this->handwriting)];

		self::assertSame(
			['name' => 'Jane Njoroge', 'issuer' => 'GoPaperless CA', 'time' => $time, 'layout' => 'horizontal-top'],
			Pkcs12Handler::builtInCard($box, $params, 'horizontal-top', $time),
		);
		self::assertSame('jane@example.com', Pkcs12Handler::builtInCard($box, ['SignerName' => ' '] + $params, 'stacked', $time)['name']);
	}

	/** SecurySign's scribble beats the box's imported image; the built-in card draws the box's own. */
	public function testTheCardsOwnScribbleWinsOverTheBoxImage(): void {
		$boxImage = tempnam(sys_get_temp_dir(), 'box') . '.png';
		copy($this->handwriting, $boxImage);
		$handler = new PhpNativeHandler(
			$this->createMock(IAppConfig::class),
			$this->createMock(DocMdpConfigService::class),
			$this->createMock(SignatureTextService::class),
			$this->createMock(SignatureBackgroundService::class),
			$this->createMock(CertificateEngineFactory::class),
		);
		$draw = static fn (array $card): string => (new \ReflectionMethod($handler, 'buildAppearanceForElement'))
			->invoke($handler->setSigningCard($card), 10.0, 20.0, 210.0, 80.0, 800.0, 0, 200, 60, $boxImage)
			->signatureImagePath;
		$builtIn = $this->card('Jane Njoroge');
		unset($builtIn['handwriting']);

		self::assertSame($this->handwriting, $draw($this->card('Jane Njoroge')), 'SecurySign card');
		self::assertSame($boxImage, $draw($builtIn), 'built-in card');
		unlink($boxImage);
	}

	public function testTheBuiltInCardStepsAsideWhenItHasNothingToDraw(): void {
		$time = new \DateTimeImmutable();
		$params = ['SignerName' => 'Jane Njoroge', 'IssuerCommonName' => 'GoPaperless CA'];
		$box = [new VisibleElementAssoc(new FileElement(), $this->handwriting)];

		self::assertNull(Pkcs12Handler::builtInCard([], $params, 'stacked', $time), 'no visible box');
		self::assertNull(Pkcs12Handler::builtInCard([new VisibleElementAssoc(new FileElement())], $params, 'stacked', $time), 'nothing drawn');
		self::assertNull(Pkcs12Handler::builtInCard($box, ['IssuerCommonName' => ''] + $params, 'stacked', $time), 'no issuer');
	}

	/** SecurySign with no card for the certificate: the same card from the certificate, never LibreSign's old appearance. */
	public function testWithoutSecurySignsCardTheCertificateFillsIt(): void {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		$csr = openssl_csr_new(['commonName' => 'Jane Njoroge', 'organizationName' => 'Signa Hardware CA'], $key);
		openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $pem);

		$card = SecurySignHandler::certificateCard($pem, 'horizontal-top');
		self::assertSame(['Jane Njoroge', 'Jane Njoroge', 'horizontal-top'], [$card['name'], $card['issuer'], $card['layout']], 'self-signed: issuer is the subject');
		self::assertArrayNotHasKey('handwriting', $card, 'the box draws the imported scribble');
		self::assertNull(SecurySignHandler::certificateCard('not a certificate', 'stacked'));
	}
}
