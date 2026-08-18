<?php

namespace Nosh\OmniTax\Responses;

use Nosh\OmniTax\Support\Qr\QrCode;

/**
 * The authority-neutral result of a validate()/submit() call.
 * Drivers normalise their raw payload into this shape.
 */
class FiscalResponse
{
    /** @param ItemStatus[] $itemStatuses */
    public function __construct(
        protected bool $valid,
        protected ?string $invoiceNumber = null,
        protected ?string $dated = null,
        protected ?string $statusCode = null,
        protected ?string $status = null,
        protected array $errors = [],
        protected array $itemStatuses = [],
        protected array $raw = [],
        protected int $httpStatus = 200,
    ) {
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function invoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function dated(): ?string
    {
        return $this->dated;
    }

    public function statusCode(): ?string
    {
        return $this->statusCode;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    /** @return string[] human-readable errors, e.g. "0052 – Invalid HS Code" */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return ItemStatus[] */
    public function itemStatuses(): array
    {
        return $this->itemStatuses;
    }

    public function raw(): array
    {
        return $this->raw;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The QR code to print (encodes the fiscal invoice number). Null if rejected. */
    public function qr(): ?QrCode
    {
        return $this->invoiceNumber ? new QrCode($this->invoiceNumber) : null;
    }

    public function toArray(): array
    {
        return [
            'valid'         => $this->valid,
            'invoiceNumber' => $this->invoiceNumber,
            'dated'         => $this->dated,
            'statusCode'    => $this->statusCode,
            'status'        => $this->status,
            'errors'        => $this->errors,
            'itemStatuses'  => array_map(fn (ItemStatus $s) => (array) $s, $this->itemStatuses),
            'httpStatus'    => $this->httpStatus,
        ];
    }
}
