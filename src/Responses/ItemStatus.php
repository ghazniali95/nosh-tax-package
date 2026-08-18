<?php

namespace Nosh\OmniTax\Responses;

/**
 * Per-line accept/reject detail from the authority.
 * Mirrors FBR's invoiceStatuses[] entries.
 */
class ItemStatus
{
    public function __construct(
        public string $itemSNo,
        public string $statusCode,   // "00" valid, "01" invalid
        public string $status,
        public ?string $invoiceNo = null,
        public ?string $errorCode = null,
        public ?string $error = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->statusCode === '00';
    }

    public static function fromArray(array $d): self
    {
        return new self(
            itemSNo: (string) ($d['itemSNo'] ?? ''),
            statusCode: (string) ($d['statusCode'] ?? ''),
            status: (string) ($d['status'] ?? ''),
            invoiceNo: $d['invoiceNo'] ?? null,
            errorCode: $d['errorCode'] ?? null,
            error: $d['error'] ?? null,
        );
    }
}
