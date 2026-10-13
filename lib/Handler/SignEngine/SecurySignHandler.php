<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Handler\SignEngine;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Exception\SecurySignApprovalRequired;
use OCA\Libresign\Exception\SecurySignUnavailable;
use OCA\Libresign\Service\SecurySignService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use SignerPHP\Infrastructure\Native\Contract\Pkcs7SignerInterface;
use SignerPHP\Infrastructure\PdfCore\Buffer;
use SignerPHP\Infrastructure\PdfCore\Signature;

/**
 * Signs with the user's own SecurySign certificate. Its private key never
 * leaves SecurySign's HSM, so one signature takes two requests:
 *
 * 1. Prepare. The signed revision is built here, appearance included, with an
 *    empty signature slot. It is parked with its CMS signed attributes, and the
 *    browser gets the SHA-256 of those attributes plus a SecurySign signing token.
 * 2. Finalize. The browser returns what SecurySign's signing frame produced after
 *    the passkey prompt. It has to verify against the user's certificate or
 *    nothing is written; then the CMS goes into the parked revision.
 *
 * SecurySign's own PAdES endpoints are not used: they drop the appearance, and
 * fall back to a throwaway key when a credential is missing. See the runbook.
 *
 * Dependencies come from the server container rather than the constructor, so
 * this class does not have to repeat Pkcs12Handler's and break when it changes.
 */
class SecurySignHandler extends Pkcs12Handler {
	private const PENDING_TTL = 900;
	private ?string $pem = null;

	#[\Override]
	public function getCertificate(): string {
		return $this->pem ??= self::userFacing(static fn () => \OCP\Server::get(SecurySignService::class)->certificatePem());
	}

	/**
	 * A session or certificate problem (401, 403, 409) is the user's to fix, so
	 * SecurySignService's message is shown as it is. Anything else means
	 * SecurySign cannot sign right now, and SignFileService falls back to
	 * LibreSign's own engine.
	 */
	private static function userFacing(callable $call): mixed {
		try {
			return $call();
		} catch (\Exception $e) {
			if (in_array($e->getCode(), [401, 403, 409], true)) {
				throw new LibresignException($e->getMessage(), $e->getCode(), $e);
			}
			throw new SecurySignUnavailable($e);
		}
	}

	#[\Override]
	public function readCertificate(): array {
		return $this->certificateEngineFactory->getEngine()->parseCertificate($this->getCertificate());
	}

	/** No local root CA is involved, so its health is not checked first. */
	#[\Override]
	public function sign(): File {
		$this->getInputFile()->putContent($this->getSignedContent());
		return $this->getInputFile();
	}

	#[\Override]
	public function getSignedContent(): string {
		$signature = \OCP\Server::get(IRequest::class)->getParam('securysignSignature', '');
		return is_string($signature) && $signature !== '' ? $this->finalize($signature) : $this->prepare();
	}

	private function prepare(): never {
		$pem = $this->getCertificate();
		$card = $this->signingCard($pem);
		// The card's time is the one signing time: on the card and in the CMS.
		// It is never later than our own clock: LibreSign refuses a signature
		// from the future, and SecurySign's clock can run ahead of ours.
		// ponytail: the PDF's own /M date is written by signer-php from the
		// server clock, a second or so later; it has no option to take ours.
		$time = self::signingTime($card['time'] ?? null, time());
		if ($card !== null) {
			$card['time'] = new \DateTimeImmutable('@' . $time);
		}
		$capture = new class($pem, $time) implements Pkcs7SignerInterface {
			public string $attributes = '';

			public function __construct(
				private string $pem,
				private int $time,
			) {
			}

			public function sign(Signature $signatureHandler, Buffer $signableDocument): string {
				$this->attributes = SecurySignHandler::signedAttributes(hash('sha256', $signableDocument->raw(), true), $this->pem, $this->time);
				return str_repeat('0', Signature::SIGNATURE_MAX_LENGTH);
			}
		};

		$native = \OCP\Server::get(PhpNativeHandler::class);
		try {
			$prepared = $native->setExternalSigner($capture)
				->setSigningCard($card)
				->setCertificate($pem)
				->setPassword('')
				->setInputFile($this->getInputFile())
				->setSignatureParams($this->getSignatureParams())
				// ponytail: one passkey approval signs one box. LibreSign signs each
				// extra box as another revision; loop prepare/finalize to support that.
				->setVisibleElements(array_slice($this->getVisibleElements(), 0, 1))
				->getSignedContent();
		} finally {
			$native->setExternalSigner(null)->setSigningCard(null);
		}

		$securySign = \OCP\Server::get(SecurySignService::class);
		$hash = hash('sha256', $capture->attributes);
		$approval = [
			'token' => self::userFacing(static fn () => $securySign->signingToken($hash, self::certificateEmail($pem))),
			'documentHash' => $hash,
			'documentName' => $this->getInputFile()->getName(),
			'origin' => $securySign->signingOrigin(),
		];

		$key = $this->pendingKey();
		$folder = $this->pendingFolder();
		foreach ($folder->getDirectoryListing() as $file) {
			if (str_starts_with($file->getName(), $key) || $file->getMTime() < time() - 3600) {
				$file->delete();
			}
		}
		$folder->newFile($key . '.pdf', $prepared);
		$folder->newFile($key . '.attrs', $capture->attributes);

		throw new SecurySignApprovalRequired($approval);
	}

	/** SecurySign's time when it has one, but never later than ours. */
	public static function signingTime(?\DateTimeImmutable $securySign, int $now): int {
		return $securySign === null ? $now : min($securySign->getTimestamp(), $now);
	}

	/**
	 * The signing card, when SecurySign has one for the certificate about to
	 * sign and it is still fresh. Anything else signs with the usual appearance:
	 * a card naming another certificate would put the wrong name on the page.
	 *
	 * @return array{name: string, issuer: string, time: \DateTimeImmutable, handwriting: string, layout: string}|null
	 */
	private function signingCard(string $pem): ?array {
		$logger = \OCP\Server::get(LoggerInterface::class);
		try {
			$context = \OCP\Server::get(SecurySignService::class)->signingContext();
		} catch (\Exception $e) {
			$logger->warning('SecurySign signing card unavailable; using the usual appearance', ['exception' => $e]);
			return null;
		}
		if ($context === null) {
			return null;
		}
		if (!hash_equals((string)openssl_x509_fingerprint($pem, 'sha256'), $context['certificateSha256'])
			|| $context['expiresAt'] <= new \DateTimeImmutable()) {
			$logger->warning('SecurySign signing card is for another certificate or has expired; using the usual appearance');
			return null;
		}
		$path = \OCP\Server::get(ITempManager::class)->getTemporaryFile('.png');
		file_put_contents($path, $context['handwriting']);
		return ['name' => $context['name'], 'issuer' => $context['issuer'], 'time' => $context['time'], 'handwriting' => $path,
			'layout' => $this->cardLayout()];
	}

	private function finalize(string $signature): string {
		$key = $this->pendingKey();
		$folder = $this->pendingFolder();
		try {
			$pdf = $folder->getFile($key . '.pdf');
			$attributes = $folder->getFile($key . '.attrs');
		} catch (NotFoundException) {
			throw new LibresignException('This signature request has expired. Sign again.', 410);
		}
		if ($pdf->getMTime() < time() - self::PENDING_TTL) {
			throw new LibresignException('This signature request has expired. Sign again.', 410);
		}
		$prepared = $pdf->getContent();
		// The parked revision is appended to what the document held at prepare
		// time. If a signature landed since, writing it back would erase that one.
		// An unsigned input is rebuilt with a fresh footer on every request, so
		// only a signed one can be compared.
		$current = $this->getInputFile()->getContent();
		if (str_contains($current, '/ByteRange') && !str_starts_with($prepared, $current)) {
			throw new LibresignException('Someone signed this document while you were approving. Sign again.', 409);
		}
		$raw = base64_decode($signature, true);
		$signed = self::embed($prepared, $attributes->getContent(), $raw === false ? '' : $raw, $this->getCertificate());
		$pdf->delete();
		$attributes->delete();
		return $signed;
	}

	/** One pending signature per user and document; a second prepare replaces it. */
	private function pendingKey(): string {
		$uid = \OCP\Server::get(IUserSession::class)->getUser()?->getUID()
			?? throw new LibresignException('Sign in to sign with SecurySign.', 401);
		return hash('sha256', $uid . "\0" . ($this->getSignatureParams()['DocumentUUID'] ?? ''));
	}

	private function pendingFolder(): ISimpleFolder {
		$appData = \OCP\Server::get(IAppDataFactory::class)->get(Application::APP_ID);
		try {
			return $appData->getFolder('securysign');
		} catch (NotFoundException) {
			return $appData->newFolder('securysign');
		}
	}

	/**
	 * CMS signed attributes, DER encoded as the SET that gets signed: content
	 * type, signing time (LibreSign reads the signed date from it), the byte
	 * range digest, and ESS signing-certificate-v2 so the signature names the
	 * certificate it was made for.
	 */
	public static function signedAttributes(string $digest, string $pem, int $time): string {
		return self::set(
			self::seq(self::oid('1.2.840.113549.1.9.3'), self::set(self::oid('1.2.840.113549.1.7.1'))),
			self::seq(self::oid('1.2.840.113549.1.9.5'), self::set(self::tlv(0x17, gmdate('ymdHis', $time) . 'Z'))),
			self::seq(self::oid('1.2.840.113549.1.9.4'), self::set(self::tlv(0x04, $digest))),
			self::seq(self::oid('1.2.840.113549.1.9.16.2.47'), self::set(self::seq(self::seq(self::seq(
				self::tlv(0x04, hash('sha256', self::der($pem), true)),
			))))),
		);
	}

	/**
	 * Put SecurySign's signature into the prepared revision. Refuses anything
	 * that does not verify against the user's certificate: that is the only
	 * proof the HSM used their key and not some other one.
	 */
	public static function embed(string $pdf, string $attributes, string $signature, string $pem): string {
		// NetHSM may answer with raw r||s; CMS needs the DER form.
		if (strlen($signature) === 64) {
			$int = static function (string $x): string {
				$x = ltrim($x, "\0");
				return self::tlv(0x02, (ord($x[0] ?? "\0") & 0x80) ? "\0" . $x : $x);
			};
			$signature = self::seq($int(substr($signature, 0, 32)), $int(substr($signature, 32)));
		}
		if (openssl_verify($attributes, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
			// The approval came from a passkey whose SecurySign key is not the one
			// behind this user's certificate: in practice, another account's passkey.
			$email = self::certificateEmail($pem);
			throw new LibresignException($email !== ''
				? sprintf('Wrong passkey. Choose the passkey for %s.', $email)
				: 'Wrong passkey. Choose the passkey of the SecurySign account you signed in with.', 403);
		}

		if (!preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $ranges, PREG_SET_ORDER)) {
			throw new LibresignException('The prepared document has no signature slot. Sign again.', 422);
		}
		[, $from1, $length1, $from2, $length2] = array_map('intval', end($ranges));
		$start = $from1 + $length1 + 1;
		$length = $from2 - $start - 1;
		$digest = hash('sha256', substr($pdf, $from1, $length1) . substr($pdf, $from2, $length2), true);
		if (strspn($pdf, '0', $start, $length) !== $length || !str_contains($attributes, self::tlv(0x04, $digest))) {
			throw new LibresignException('The prepared document changed before it was signed. Sign again.', 409);
		}

		$cms = bin2hex(self::signedData($attributes, $signature, self::der($pem)));
		if (strlen($cms) > $length) {
			throw new LibresignException('The signature does not fit the prepared document.', 500);
		}
		return substr_replace($pdf, str_pad($cms, $length, '0'), $start, $length);
	}

	/** The SecurySign account the certificate was issued to, or '' when it names none. */
	private static function certificateEmail(string $pem): string {
		$email = openssl_x509_parse($pem)['subject']['emailAddress'] ?? '';
		return is_string($email) ? $email : '';
	}

	private static function signedData(string $attributes, string $signature, string $certificate): string {
		$tbs = self::children(self::children($certificate)[0]);
		if ($tbs[0][0] === "\xA0") {
			array_shift($tbs);
		}
		[$serial, , $issuer] = $tbs;
		$sha256 = self::seq(self::oid('2.16.840.1.101.3.4.2.1'));
		$signerInfo = self::seq(
			self::tlv(0x02, "\x01"),
			self::seq($issuer, $serial),
			$sha256,
			"\xA0" . substr($attributes, 1),
			self::seq(self::oid('1.2.840.10045.4.3.2')),
			self::tlv(0x04, $signature),
		);
		return self::seq(self::oid('1.2.840.113549.1.7.2'), self::tlv(0xA0, self::seq(
			self::tlv(0x02, "\x01"),
			self::set($sha256),
			self::seq(self::oid('1.2.840.113549.1.7.1')),
			self::tlv(0xA0, $certificate),
			self::set($signerInfo),
		)));
	}

	private static function der(string $pem): string {
		return (string)base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $pem));
	}

	private static function tlv(int $tag, string $value): string {
		$length = strlen($value);
		if ($length < 0x80) {
			return chr($tag) . chr($length) . $value;
		}
		$bytes = ltrim(pack('N', $length), "\0");
		return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $value;
	}

	private static function seq(string ...$items): string {
		return self::tlv(0x30, implode('', $items));
	}

	/** DER sorts a SET OF by encoding; OpenSSL re-encodes before verifying. */
	private static function set(string ...$items): string {
		sort($items, SORT_STRING);
		return self::tlv(0x31, implode('', $items));
	}

	private static function oid(string $dotted): string {
		$arcs = array_map('intval', explode('.', $dotted));
		$body = chr(40 * $arcs[0] + $arcs[1]);
		foreach (array_slice($arcs, 2) as $arc) {
			$chunk = chr($arc & 0x7f);
			while ($arc >>= 7) {
				$chunk = chr(0x80 | ($arc & 0x7f)) . $chunk;
			}
			$body .= $chunk;
		}
		return self::tlv(0x06, $body);
	}

	/** @return list<string> the TLVs directly inside a constructed TLV */
	private static function children(string $tlv): array {
		[$offset, $length] = self::header($tlv, 0);
		$items = [];
		for ($at = $offset, $end = $offset + $length; $at < $end;) {
			[$contentAt, $contentLength] = self::header($tlv, $at);
			$items[] = substr($tlv, $at, $contentAt - $at + $contentLength);
			$at = $contentAt + $contentLength;
		}
		return $items;
	}

	/** @return array{int, int} where the content starts, and how long it is */
	private static function header(string $der, int $at): array {
		$length = ord($der[$at + 1]);
		if ($length < 0x80) {
			return [$at + 2, $length];
		}
		$count = $length & 0x7f;
		return [$at + 2 + $count, (int)hexdec(bin2hex(substr($der, $at + 2, $count)))];
	}
}
