<?php

namespace Nosh\OmniTax\Drivers;

use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Support\Feature;

/**
 * FBR POS (IMS) driver — FBR's point-of-sale integration for Tier-1 retailers
 * and restaurants. NOT FBR Digital Invoicing (that is FbrDriver).
 *
 * Selected for authority `fbr` when the credentials' mode is `pos`. FBR issues
 * a POS ID and a Bearer token per registered POS (IRIS → POS registration, with
 * the sending server's IP + MAC), and its invoice JSON and `{InvoiceNumber,
 * Code, Response}` answer are PRAL's IMS format — the same one PRA uses — so
 * this driver is PraDriver with FBR's endpoints and field names:
 *
 *   • live    POST https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData
 *   • sandbox POST https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData
 *   • buyer   `BuyerNTN` (PRA calls it `BuyerPNTN`)
 *   • success Code "100"; the receipt QR encodes the FBR invoice number
 *     (scanned and verified in FBR's Tax Asaan app).
 *
 * Verified live 2026-11-23: Code 100, "Invoice received
 * successfully", ~1.3 s from the Singapore tax server.
 */
class FbrPosDriver extends PraDriver
{
    public const MODE_POS = 'pos';

    /** InvoiceType 3 (return) with RefUSIN, as in the IMS spec. */
    protected function features(): array
    {
        return [Feature::CREDIT_NOTE];
    }

    public function key(): string
    {
        return 'fbr';
    }

    public function mode(): string
    {
        return self::MODE_POS;
    }

    protected function endpoint(): string
    {
        $urls = $this->config['urls'] ?? [];

        return $this->credentials->sandbox
            ? ($urls['pos_sb'] ?? 'https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData')
            : ($urls['pos'] ?? 'https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData');
    }

    /** FBR POS always carries the Bearer token issued with the POS ID. */
    protected function headers(): array
    {
        return [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.($this->credentials->token ?? ''),
        ];
    }

    public function buildPayload(Invoice $invoice): array
    {
        [$payload, $errors] = parent::buildPayload($invoice);

        // FBR's field name for the buyer's NTN.
        $payload = ['BuyerNTN' => $payload['BuyerPNTN'] ?? ''] + $payload;
        unset($payload['BuyerPNTN']);

        if (empty($this->credentials->token)) {
            $errors[] = 'FBR POS requires the token issued with the POS ID.';
        }

        $errors = array_map(fn (string $e) => str_replace('PRA POS ID', 'FBR POS ID', $e), $errors);

        return [$payload, $errors];
    }

    public function parse(int $httpStatus, array $body): FiscalResponse
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return new FiscalResponse(
                valid: false,
                errors: [$httpStatus === 401
                    ? 'Unauthorized — invalid or missing FBR POS token.'
                    : 'FBR refused this POS (403): check the token and that this server\'s IP and MAC are registered on the POS in IRIS.'],
                raw: $body,
                httpStatus: $httpStatus,
            );
        }

        $response = parent::parse($httpStatus, $body);

        // FBR's receipt QR is the invoice number itself (Tax Asaan scans it).
        return new FiscalResponse(
            valid: $response->isValid(),
            invoiceNumber: $response->invoiceNumber(),
            statusCode: $response->statusCode(),
            status: $response->status(),
            errors: array_map(fn (string $e) => str_replace('PRA ', 'FBR ', $e), $response->errors()),
            raw: $response->raw(),
            httpStatus: $response->httpStatus(),
            qrPayload: null,   // null → the QR encodes the invoice number
        );
    }

    /** FBR has no public verification URL; the number is verified in Tax Asaan. */
    public function verificationUrl(string $invoiceNumber): string
    {
        return $invoiceNumber;
    }
}
