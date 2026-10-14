<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Handler\SignEngine;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Service\SignerElementsService;
use OCP\Files\File;
use OCP\IAppConfig;
use SignerPHP\Application\Contract\CertificateValidatorInterface;
use SignerPHP\Application\DTO\CertificateCredentialsDto;
use SignerPHP\Application\DTO\CertificationLevel;
use SignerPHP\Application\DTO\PdfContentDto;
use SignerPHP\Application\DTO\SignatureActorDto;
use SignerPHP\Application\DTO\SignatureAppearanceDto;
use SignerPHP\Application\DTO\SignatureAppearanceXObjectDto;
use SignerPHP\Application\DTO\SignatureMetadataDto;
use SignerPHP\Application\DTO\SigningOptionsDto;
use SignerPHP\Application\DTO\SignPdfRequestDto;
use SignerPHP\Application\DTO\TimestampOptionsDto;
use SignerPHP\Application\Service\PdfSigningService;
use SignerPHP\Domain\ValueObject\VerifiedCertificate;
use SignerPHP\Infrastructure\Legacy\OpenSslCertificateValidator;
use SignerPHP\Infrastructure\Native\Contract\Pkcs7SignerInterface;
use SignerPHP\Infrastructure\Native\NativePdfSigningEngine;
use SignerPHP\Infrastructure\Native\Service\SignedBufferBuilder;
use SignerPHP\Infrastructure\Native\Service\XrefContentResolver;

class PhpNativeHandler extends Pkcs12Handler {
	private ?Pkcs7SignerInterface $externalSigner = null;
	/** @var array{name: string, issuer: string, time: \DateTimeImmutable, handwriting?: string, layout?: string}|null */
	private ?array $signingCard = null;

	public function __construct(
		private IAppConfig $appConfig,
		private DocMdpConfigService $docMdpConfigService,
		private SignatureTextService $signatureTextService,
		private SignatureBackgroundService $signatureBackgroundService,
		protected CertificateEngineFactory $certificateEngineFactory,
	) {
	}

	#[\Override]
	public function sign(): File {
		$this->beforeSign();
		$signedContent = $this->getSignedContent();
		$this->getInputFile()->putContent($signedContent);
		return $this->getInputFile();
	}

	/**
	 * Sign with a key this server does not hold. The certificate is then a bare
	 * PEM and the signer returns the CMS for the byte range it is handed. No
	 * document timestamp is applied: it would cover a signature that does not
	 * exist yet.
	 */
	public function setExternalSigner(?Pkcs7SignerInterface $signer): self {
		$this->externalSigner = $signer;
		return $this;
	}

	/**
	 * Draw the signing card instead of the configured appearance: the
	 * handwriting (the box's drawn signature, or the imported one), the
	 * signer's name, the certificate's issuer and the signing time. Null goes
	 * back to the configured appearance.
	 *
	 * @param array{name: string, issuer: string, time: \DateTimeImmutable, handwriting?: string, layout?: string}|null $card
	 */
	public function setSigningCard(?array $card): self {
		$this->signingCard = $card;
		return $this;
	}

	#[\Override]
	public function getSignedContent(): string {
		$pdfContent = $this->getInputFile()->getContent();
		$certificate = CertificateCredentialsDto::fromContent(
			$this->getCertificate(),
			$this->getPassword(),
		);
		$service = $this->externalSigner === null
			? new PdfSigningService(new OpenSslCertificateValidator(), new NativePdfSigningEngine())
			: new PdfSigningService(
				new class implements CertificateValidatorInterface {
					public function validate(CertificateCredentialsDto $credentials): VerifiedCertificate {
						$pem = (string)$credentials->certificateContent;
						return new VerifiedCertificate($credentials, openssl_x509_parse($pem) ?: [], ['cert' => $pem, 'pkey' => '']);
					}
				},
				new NativePdfSigningEngine(signedBufferBuilder: new SignedBufferBuilder(new XrefContentResolver(), $this->externalSigner)),
			);

		$visibleElements = $this->getVisibleElements();
		$metadata = $this->buildMetadata();
		$timestamp = $this->externalSigner === null ? $this->buildTimestampOptions() : null;
		$certificationLevel = $this->resolveCertificationLevel(empty($visibleElements));

		if (empty($visibleElements)) {
			return $service->sign(SignPdfRequestDto::fromRequired(
				new PdfContentDto($pdfContent),
				$certificate,
				new SigningOptionsDto(
					metadata: $metadata,
					timestamp: $timestamp,
					certificationLevel: $certificationLevel,
					useDefaultAppearance: false,
				),
			));
		}

		$applyOnce = $certificationLevel;
		// signer-php expects screen/top-left coords (Y=0 at top, grows downward).
		// LibreSign stores PDF bottom-left coords (Y=0 at bottom, lly < ury).
		// Conversion: screen_y = pageHeight - pdf_y
		// Page dimensions come from FileEntity::getMetadata()['d'] (0-based array of ['w','h']).
		$pageDimensions = $this->getSignatureParams()['PageDimensions'] ?? [];
		foreach ($visibleElements as $element) {
			$fileElement = $element->getFileElement();
			$llx = (float)($fileElement->getLlx() ?? 0);
			$lly = (float)($fileElement->getLly() ?? 0);
			$urx = (float)($fileElement->getUrx() ?? 0);
			$ury = (float)($fileElement->getUry() ?? 0);
			$width = (int)($urx - $llx);
			$height = (int)($ury - $lly);
			// signer-php uses 0-based page index; LibreSign stores 1-based
			$pageIndex = max(0, $fileElement->getPage() - 1);
			$pageHeight = $this->resolvePageHeight($pageDimensions, $pageIndex);
			$appearance = $this->buildAppearanceForElement(
				llx: $llx,
				lly: $lly,
				urx: $urx,
				ury: $ury,
				pageHeight: $pageHeight,
				pageIndex: $pageIndex,
				width: $width,
				height: $height,
				signatureImagePath: $element->getTempFile(),
			);
			$pdfContent = $service->sign(SignPdfRequestDto::fromRequired(
				new PdfContentDto($pdfContent),
				$certificate,
				new SigningOptionsDto(
					metadata: $metadata,
					appearance: $appearance,
					timestamp: $timestamp,
					// DocMDP only applies once (the first signature certifies)
					certificationLevel: $applyOnce,
				),
			));
			$applyOnce = null;
		}

		return $pdfContent;
	}

	private function buildAppearanceForElement(
		float $llx,
		float $lly,
		float $urx,
		float $ury,
		float $pageHeight,
		int $pageIndex,
		int $width,
		int $height,
		string $signatureImagePath = '',
	): SignatureAppearanceDto {
		$rect = [$llx, $pageHeight - $ury, $urx, $pageHeight - $lly];
		if ($this->signingCard !== null && ($this->signingCard['handwriting'] ?? $signatureImagePath) !== '') {
			// SecurySign's card brings its own visible signature scribble, which
			// wins over the box's imported copy. A card drawn from the certificate,
			// or the built-in one, has none and draws the box's signature.
			$card = $this->signingCard + ['handwriting' => $signatureImagePath];
			[$xObject, $frame] = self::signingCardLayout($card, (float)$width, (float)$height);
			return new SignatureAppearanceDto(
				backgroundImagePath: null,
				rect: $rect,
				page: $pageIndex,
				xObject: $xObject,
				signatureImagePath: $card['handwriting'],
				signatureImageFrame: $frame,
			);
		}

		$renderMode = $this->signatureTextService->getRenderMode();

		// n0 layer: background stamp is always placed full-bbox when enabled.
		$imagePath = $this->signatureBackgroundService->isEnabled()
			? $this->signatureBackgroundService->getImagePath()
			: null;
		$imagePath = $this->prepareBackgroundImage($imagePath, $width, $height);

		// GRAPHIC_AND_DESCRIPTION: user's drawn image goes into the n2 xObject layer
		// on the left half of the bbox so it does not distort or cover the description text.
		// Background (if enabled) still occupies the full n0 layer behind everything.
		// GRAPHIC_ONLY: user's drawn image occupies the full bbox in n2; no description text.
		$userImgPath = null;
		$userImgRect = null;
		if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION) {
			if ($signatureImagePath !== '' && is_file($signatureImagePath)) {
				$userImgPath = $signatureImagePath;

				$nameAreaHeight = max(12.0, (float)$height * 0.15);
				$userImgRect = [0.0, $nameAreaHeight, (float)$width / 2.0, (float)$height];
			}
		} elseif ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_ONLY) {
			if ($signatureImagePath !== '' && is_file($signatureImagePath)) {
				$userImgPath = $signatureImagePath;
				$userImgRect = null; // full bbox
			}
		}

		return new SignatureAppearanceDto(
			backgroundImagePath: $imagePath,
			rect: [
				$llx,
				$pageHeight - $ury,  // screen top = pageH - PDF ury
				$urx,
				$pageHeight - $lly,  // screen bottom = pageH - PDF lly
			],
			page: $pageIndex,
			xObject: $this->buildXObject($width, $height, $renderMode),
			signatureImagePath: $userImgPath,
			signatureImageFrame: $userImgRect,
		);
	}

	private function prepareBackgroundImage(?string $imagePath, int $width, int $height): ?string {
		if (!$this->signatureTextService->hasQrCodeInTemplate()) {
			return $imagePath;
		}

		$params = $this->getSignatureParams();
		$documentUuid = $params['DocumentUUID'] ?? null;
		if (empty($documentUuid) || !is_string($documentUuid)) {
			return $imagePath;
		}

		$validationUrl = $this->signatureTextService->buildValidationUrl($documentUuid);
		$base64 = $this->signatureTextService->getQrCodeImageBase64($validationUrl);
		$content = base64_decode($base64, true);
		if ($content === false) {
			return $imagePath;
		}

		$tempManager = \OCP\Server::get(\OCP\ITempManager::class);
		$qrCodePath = $tempManager->getTemporaryFile('_qrcode.png');
		if (!$qrCodePath) {
			return $imagePath;
		}
		file_put_contents($qrCodePath, $content);

		if (!extension_loaded('imagick')) {
			return $imagePath;
		}

		$canvas = new \Imagick();
		$canvas->newImage($width, $height, new \ImagickPixel('transparent'));
		$canvas->setImageFormat('png32');
		$canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

		if ($imagePath !== null && $imagePath !== '' && is_file($imagePath)) {
			$background = new \Imagick($imagePath);
			$background->setImageFormat('png');
			$background->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
			$background->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
			$bgX = (int)(($width - $background->getImageWidth()) / 2);
			$bgY = (int)(($height - $background->getImageHeight()) / 2);
			$canvas->compositeImage($background, \Imagick::COMPOSITE_OVER, $bgX, $bgY);
			$background->clear();
		}

		$qrCode = new \Imagick($qrCodePath);
		$qrCode->setImageFormat('png');
		$qrSize = (int)(min($width, $height) * 0.35);
		$qrCode->resizeImage($qrSize, $qrSize, \Imagick::FILTER_LANCZOS, 1, true);
		$qrX = $width - $qrCode->getImageWidth() - max(4, (int)($qrSize * 0.05));
		$qrY = max(4, (int)($qrSize * 0.05));
		$canvas->compositeImage($qrCode, \Imagick::COMPOSITE_OVER, $qrX, $qrY);
		$qrCode->clear();

		$compositePath = $tempManager->getTemporaryFile('_background_qr.png');
		if (!$compositePath) {
			return $imagePath;
		}
		$canvas->writeImage($compositePath);
		$canvas->clear();

		return $compositePath;
	}

	#[\Override]
	public function readCertificate(): array {
		$result = $this->certificateEngineFactory
			->getEngine()
			->readCertificate(
				$this->getCertificate(),
				$this->getPassword()
			);

		if (!is_array($result)) {
			throw new \RuntimeException('Failed to read certificate data');
		}

		return $result;
	}

	private function buildMetadata(): SignatureMetadataDto {
		$params = $this->getSignatureParams();
		$name = !empty($params['SignerCommonName']) ? (string)$params['SignerCommonName'] : null;
		$email = !empty($params['SignerEmail']) ? (string)$params['SignerEmail'] : null;

		return new SignatureMetadataDto(
			actor: ($name !== null || $email !== null)
				? new SignatureActorDto(name: $name, contactInfo: $email)
				: null,
		);
	}

	private function resolvePageHeight(array $pageDimensions, int $pageIndex): float {
		$pageHeight = $pageDimensions[$pageIndex]['h'] ?? null;
		if (!is_numeric($pageHeight) || (float)$pageHeight <= 0.0) {
			throw new \RuntimeException(sprintf('Missing or invalid PageDimensions for page index %d.', $pageIndex));
		}
		return (float)$pageHeight;
	}

	private function buildTimestampOptions(): ?TimestampOptionsDto {
		$tsaUrl = $this->appConfig->getValueString(Application::APP_ID, 'tsa_url', '');
		if (empty($tsaUrl)) {
			return null;
		}

		$username = null;
		$password = null;
		$authType = $this->appConfig->getValueString(Application::APP_ID, 'tsa_auth_type', 'none');
		if ($authType === 'basic') {
			$username = $this->appConfig->getValueString(Application::APP_ID, 'tsa_username', '') ?: null;
			$password = $this->appConfig->getValueString(Application::APP_ID, 'tsa_password', '') ?: null;
		}

		return new TimestampOptionsDto(
			tsaUrl: $tsaUrl,
			username: $username,
			password: $password,
		);
	}

	private function resolveCertificationLevel(bool $noVisibleElements): ?CertificationLevel {
		if (!$this->docMdpConfigService->isEnabled()) {
			return null;
		}

		// DocMDP values mirror CertificationLevel: 1=NoChanges, 2=FormFilling, 3=FormFillAndAnnotations
		$level = $this->docMdpConfigService->getLevel()->value;
		// Only certify on invisible signatures or on the first visible element
		if ($noVisibleElements || !$this->hasExistingSignatures($this->getInputFile()->getContent())) {
			return CertificationLevel::fromInt($level);
		}

		return null;
	}

	private function hasExistingSignatures(string $pdfContent): bool {
		return (bool)preg_match('/\/ByteRange\s*\[|\/Type\s*\/Sig\b|\/DocMDP\b|\/Perms\b/', $pdfContent);
	}

	/**
	 * Builds the xObject (n2 layer) for all render modes using only PDF text operators.
	 *
	 * DESCRIPTION_ONLY      → description text, full width.
	 * GRAPHIC_AND_DESCRIPTION → description text, right half only
	 *                           (user image is in imagePath/n0, handled natively by signer-php).
	 * SIGNAME_AND_DESCRIPTION → signer name as large text on the left half
	 *                           + description text on the right half.
	 *                           No image generation: pure PDF text operators.
	 */
	private function buildXObject(int $width, int $height, string $renderMode): SignatureAppearanceXObjectDto {
		// GRAPHIC_ONLY: only the background/signature image is shown; no text in n2.
		if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_ONLY) {
			return new SignatureAppearanceXObjectDto(stream: '', resources: []);
		}

		$params = $this->getSignatureParams();
		$params['ServerSignatureDate'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->format(\DateTimeInterface::ATOM);

		$textData = $this->signatureTextService->parse(context: $params);
		$parsed = trim((string)($textData['parsed'] ?? ''));

		$descFontSize = (float)($textData['templateFontSize'] ?? $this->signatureTextService->getTemplateFontSize());
		$descLineHeight = $descFontSize * 1.0;
		$leftPadding = max(2.0, $descFontSize * 0.15);

		$isDescriptionOnly = $renderMode === SignerElementsService::RENDER_MODE_DESCRIPTION_ONLY;
		$textStartX = $isDescriptionOnly ? $leftPadding : ((float)$width / 2.0) + $leftPadding;
		$availableWidth = $isDescriptionOnly ? (float)$width : (float)$width / 2.0;

		$stream = '';

		// Left half: signer name as large text operators (SIGNAME_AND_DESCRIPTION only).
		// No image generation — the name is drawn directly with PDF text commands.
		// Left half: user's display/common name below the signature.
		if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION) {
			$commonName = !empty($params['SignerCommonName'])
				? (string)$params['SignerCommonName']
				: ($this->readCertificate()['subject']['CN'] ?? '');

			if ($commonName !== '') {
				$nameFontSize = min(8.0, $descFontSize);
				$leftHalfW = (float)$width / 2.0;
				$nameWidth = strlen($commonName) * ($nameFontSize * 0.52);
				$nameX = max(
					$leftPadding,
					($leftHalfW - $nameWidth) / 2.0
				);

				$nameY = 18.0;

				$escaped = $this->escapePdfText($commonName);

				$stream .= "BT\n";
				$stream .= sprintf("/F1 %.2F Tf\n", $nameFontSize);
				$stream .= "0 0 0 rg\n";
				$stream .= sprintf("%.2F %.2F Td\n", $nameX, $nameY);
				$stream .= sprintf("(%s) Tj\n", $escaped);
				$stream .= "ET\n";
			}
		}

		// Right half (or full width): description text.
		$currentY = (float)$height - $descFontSize - 2.0;
		foreach (explode(PHP_EOL, $parsed) as $line) {
			$wrappedLines = $this->wrapTextForPdf($line, $availableWidth, $descFontSize);
			foreach ($wrappedLines as $wrappedLine) {
				if ($currentY < 0) {
					break 2;
				}
				$escaped = $this->escapePdfText($wrappedLine);
				$stream .= "BT\n";
				$stream .= sprintf("/F1 %.2F Tf\n", $descFontSize);
				$stream .= "0 0 0 rg\n";
				$stream .= sprintf("%.2F %.2F Td\n", $textStartX, $currentY);
				$stream .= sprintf("(%s) Tj\n", $escaped);
				$stream .= "ET\n";
				$currentY -= $descLineHeight;
			}
		}

		return new SignatureAppearanceXObjectDto(
			stream: $stream,
			resources: [
				'Font' => [
					'F1' => [
						'Type' => '/Font',
						'Subtype' => '/Type1',
						'BaseFont' => '/Helvetica',
					],
				],
			],
		);
	}

	/**
	 * @return string[]
	 */
	private function wrapTextForPdf(string $line, float $availableWidth, float $fontSize): array {
		$trimmed = trim($line);
		if ($trimmed === '') {
			return [''];
		}

		$estimatedCharWidth = max(1.0, $fontSize * 0.52);
		$maxChars = max(1, (int)floor($availableWidth / $estimatedCharWidth));
		if (strlen($trimmed) <= $maxChars) {
			return [$trimmed];
		}

		$result = [];
		$current = '';
		foreach (preg_split('/\s+/', $trimmed) ?: [] as $word) {
			if ($word === '') {
				continue;
			}

			$candidate = $current === '' ? $word : $current . ' ' . $word;
			if (strlen($candidate) <= $maxChars) {
				$current = $candidate;
				continue;
			}

			if ($current !== '') {
				$result[] = $current;
				$current = '';
			}

			while (strlen($word) > $maxChars) {
				$result[] = substr($word, 0, $maxChars);
				$word = substr($word, $maxChars);
			}

			$current = $word;
		}

		if ($current !== '') {
			$result[] = $current;
		}

		return $result;
	}

	private static function escapePdfText(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace('(', '\\(', $value);
		$value = str_replace(')', '\\)', $value);

		return $value;
	}

	/**
	 * The stacked signing card in a box of $width x $height points: the
	 * handwriting on top at its own proportions, then the name (large, bold),
	 * "ISSUER: ..." and the time in EAT, each centred, with no border. Text
	 * shrinks to fit a long name rather than being clipped.
	 *
	 * @param array{name: string, issuer: string, time: \DateTimeImmutable, handwriting: string} $card
	 * @return array{0: SignatureAppearanceXObjectDto, 1: array{0: float, 1: float, 2: float, 3: float}|null}
	 */
	public static function signingCardLayout(array $card, float $width, float $height): array {
		$layout = $card['layout'] ?? 'stacked';
		if ($layout === 'horizontal' || $layout === 'horizontal-top') {
			return self::horizontalCardLayout($card, $width, $height, $layout === 'horizontal-top');
		}
		$pad = max(4.0, $height * 0.06);
		$room = max(1.0, $width - 2 * $pad);
		$lines = [
			['F2', mb_strtoupper($card['name']), 1.0, '0.04 0.07 0.13'],
			['F1', 'ISSUER: ' . mb_strtoupper($card['issuer']), 0.72, '0.25 0.27 0.32'],
			['F1', 'TIMESTAMP: ' . $card['time']->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('d M Y, H:i:s') . ' EAT', 0.62, '0.40 0.42 0.47'],
		];
		// The name sets the scale; every line keeps its share of it and fits the width.
		$size = min($height * 0.12, 16.0);
		foreach ($lines as [$font, $text, $share]) {
			$size = min($size, $room / max(0.01, self::helveticaWidth($text, $font === 'F2') * $share));
		}
		$size = max(3.0, $size);

		// Lines are laid from the bottom up: time, issuer, name.
		$stream = '';
		$baseline = $pad + $size * 0.62 * 0.25;
		$top = $pad;
		foreach (array_reverse($lines) as $i => [$font, $text, $share, $colour]) {
			$lineSize = $size * $share;
			if ($i > 0) {
				$baseline += $lineSize * 1.3;
			}
			$encoded = (string)(iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text);
			$x = ($width - self::helveticaWidth($text, $font === 'F2') * $lineSize) / 2;
			$stream .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $font, $lineSize, $colour, max($pad, $x), $baseline, self::escapePdfText($encoded));
			$top = $baseline + $lineSize * 0.75;
		}

		// The handwriting fills what is left above the text, keeping its proportions.
		$frame = null;
		$areaBottom = $top + $pad * 0.5;
		$areaHeight = $height - $pad - $areaBottom;
		$image = @getimagesize($card['handwriting']);
		if ($areaHeight > 1 && is_array($image) && $image[0] > 0 && $image[1] > 0) {
			$fit = min($room / $image[0], $areaHeight / $image[1]);
			$w = $image[0] * $fit;
			$h = $image[1] * $fit;
			$frame = [($width - $w) / 2, $areaBottom + ($areaHeight - $h) / 2, $w, $h];
		}

		$font = static fn (string $name): array => ['Type' => '/Font', 'Subtype' => '/Type1', 'BaseFont' => '/' . $name, 'Encoding' => '/WinAnsiEncoding'];
		return [
			new SignatureAppearanceXObjectDto(stream: $stream, resources: ['Font' => ['F1' => $font('Helvetica'), 'F2' => $font('Helvetica-Bold')]]),
			$frame,
		];
	}

	/**
	 * The horizontal card (signing_card_layout = horizontal):
	 *
	 *   handwriting        ISSUER
	 *                      SIGNA HARDWARE CA
	 *   NAME               TIMESTAMP
	 *                      05 Oct 2026, 16:32:08 EAT
	 *
	 * ISSUER sits level with the top of the handwriting and TIMESTAMP level with
	 * the name. With horizontal-top, TIMESTAMP sits straight under ISSUER, both
	 * from the top of the right column. Labels are small spaced grey capitals
	 * over darker values. The handwriting is fitted whole into the left column,
	 * never cropped.
	 *
	 * @param array{name: string, issuer: string, time: \DateTimeImmutable, handwriting: string} $card
	 * @return array{0: SignatureAppearanceXObjectDto, 1: array{0: float, 1: float, 2: float, 3: float}|null}
	 */
	private static function horizontalCardLayout(array $card, float $width, float $height, bool $topAligned = false): array {
		$pad = max(3.0, min($width, $height) * 0.07);
		$gap = $pad * 1.4;
		$split = $width * 0.56;
		$leftRoom = max(1.0, $split - $pad - $gap / 2);
		$rightX = $split + $gap / 2;
		$rightRoom = max(1.0, $width - $rightX - $pad);

		$name = mb_strtoupper(trim($card['name']));
		$issuer = mb_strtoupper($card['issuer']);
		$time = $card['time']->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('d M Y, H:i:s') . ' EAT';

		// A long name takes two lines rather than shrinking out of proportion.
		$nameCap = min($height * 0.13, 10.0);
		$nameLines = [$name];
		if ($leftRoom * 0.94 / max(0.01, self::helveticaWidth($name, true)) < $nameCap * 0.75 && preg_match('/\s/', $name)) {
			$nameLines = self::balancedLines($name);
		}
		$widest = max(array_map(static fn (string $line): float => self::helveticaWidth($line, true), $nameLines));
		$nameSize = max(3.0, min($nameCap, $leftRoom * 0.94 / max(0.01, $widest)));

		// The right column has its own scale, so a long name never shrinks it.
		$valueSize = min($height * 0.105, 8.5, $rightRoom / max(0.01, self::helveticaWidth($issuer, false), self::helveticaWidth($time, false)));
		$labelSize = $valueSize * 0.62;
		// The two blocks on the right stack in the height, with room between them.
		$needed = 2 * ($labelSize * 1.3 + $valueSize * 1.05) + $valueSize * 0.5;
		if ($needed > $height - 2 * $pad) {
			$shrink = ($height - 2 * $pad) / $needed;
			$valueSize *= $shrink;
			$labelSize *= $shrink;
		}
		$valueSize = max(2.5, $valueSize);
		$labelSize = max(2.0, $labelSize);
		$tracking = $labelSize * 0.12;

		$text = static function (string $font, float $size, string $colour, float $x, float $y, string $value, float $spacing = 0.0): string {
			$encoded = (string)(iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: $value);
			return sprintf("BT /%s %.2F Tf %.2F Tc %s rg %.2F %.2F Td (%s) Tj ET\n", $font, $size, $spacing, $colour, $x, $y, self::escapePdfText($encoded));
		};
		$label = '0.48 0.51 0.57';
		$value = '0.20 0.22 0.27';

		$nameY = $pad + $nameSize * 0.22;
		$issuerLabelY = $height - $pad - $labelSize * 0.75;
		$issuerY = $issuerLabelY - $labelSize * 0.45 - $valueSize * 0.9;
		if ($topAligned) {
			// horizontal-top: TIMESTAMP straight under ISSUER, both from the top.
			$timeLabelY = $issuerY - $valueSize * 0.85 - $labelSize * 0.95;
			$timeY = $timeLabelY - $labelSize * 0.45 - $valueSize * 0.9;
		} else {
			// TIMESTAMP shares the baseline of the name's last line.
			$timeY = $nameY;
			$timeLabelY = $timeY + $valueSize * 0.95 + $labelSize * 0.3;
		}

		$stream = '';
		foreach (array_reverse($nameLines) as $i => $line) {
			$stream .= $text('F2', $nameSize, '0.04 0.07 0.13', $pad, $nameY + $i * $nameSize * 1.12, $line);
		}
		$stream .= $text('F1', $labelSize, $label, $rightX, $issuerLabelY, 'ISSUER', $tracking)
			. $text('F1', $valueSize, $value, $rightX, $issuerY, $issuer)
			. $text('F1', $labelSize, $label, $rightX, $timeLabelY, 'TIMESTAMP', $tracking)
			. $text('F1', $valueSize, $value, $rightX, $timeY, $time);

		// The handwriting fills the left column above the name, whole: it is
		// scaled to fit, never cropped, and sits on the name like a signed line.
		$frame = null;
		$areaBottom = $nameY + (count($nameLines) - 1) * $nameSize * 1.12 + $nameSize * 0.75 + $pad * 0.45;
		$areaHeight = $height - $pad - $areaBottom;
		$image = @getimagesize($card['handwriting']);
		if ($areaHeight > 1 && is_array($image) && $image[0] > 0 && $image[1] > 0) {
			$fit = min($leftRoom / $image[0], $areaHeight / $image[1]);
			$frame = [$pad, $areaBottom, $image[0] * $fit, $image[1] * $fit];
		}

		$font = static fn (string $base): array => ['Type' => '/Font', 'Subtype' => '/Type1', 'BaseFont' => '/' . $base, 'Encoding' => '/WinAnsiEncoding'];
		return [
			new SignatureAppearanceXObjectDto(stream: $stream, resources: ['Font' => ['F1' => $font('Helvetica'), 'F2' => $font('Helvetica-Bold')]]),
			$frame,
		];
	}

	/**
	 * $text in two lines as even as its words allow, in Helvetica Bold.
	 *
	 * @return list<string>
	 */
	private static function balancedLines(string $text): array {
		$words = preg_split('/\s+/', trim($text)) ?: [$text];
		$best = [$text];
		$bestWidth = INF;
		for ($i = 1; $i < count($words); $i++) {
			$lines = [implode(' ', array_slice($words, 0, $i)), implode(' ', array_slice($words, $i))];
			$widest = max(self::helveticaWidth($lines[0], true), self::helveticaWidth($lines[1], true));
			if ($widest < $bestWidth) {
				$best = $lines;
				$bestWidth = $widest;
			}
		}
		return $best;
	}

	/** Width of $text at size 1 in Helvetica (AFM widths; other characters count as a digit). */
	private static function helveticaWidth(string $text, bool $bold): float {
		static $upper = [
			false => [667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611],
			true => [722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611],
		];
		static $lower = [
			false => [556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500],
			true => [556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500],
		];
		$total = 0;
		foreach (mb_str_split($text) as $char) {
			$total += match (true) {
				$char >= 'A' && $char <= 'Z' => $upper[$bold][ord($char) - 65],
				$char >= 'a' && $char <= 'z' => $lower[$bold][ord($char) - 97],
				in_array($char, [' ', ',', '.', ':', ';'], true) => $bold && $char === ':' ? 333 : 278,
				in_array($char, ['-', '(', ')'], true) => 333,
				default => 556,
			};
		}
		return $total / 1000;
	}
}
