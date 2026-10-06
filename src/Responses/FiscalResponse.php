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
        protected ?string $qrPayload = null,
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

    /**
     * Worth trying again unchanged? True when the authority was never reached
     * (status 0 — network, timeout) or failed on its side (5xx). A business
     * rejection (4xx, or a 200 carrying an error code) is not: the same
     * payload will be refused again.
     */
    public function isRetryable(): bool
    {
        return ! $this->valid && ($this->httpStatus === 0 || $this->httpStatus >= 500);
    }

    /** The exact string a driver wants encoded in the QR, when it differs from the
     *  fiscal number (SRB encodes the verification URL; FBR encodes the number). */
    public function qrPayload(): ?string
    {
        return $this->qrPayload ?? $this->invoiceNumber;
    }

    /**
     * The QR code to print. Encodes the driver-supplied QR payload when present
     * (SRB → verification URL), otherwise the fiscal invoice number (FBR).
     * Null if the invoice was rejected.
     */
    public function qr(): ?QrCode
    {
        $payload = $this->qrPayload();

        return $payload ? new QrCode($payload) : null;
    }

    public function toArray(): array
    {
        return [
            'valid'         => $this->valid,
            'invoiceNumber' => $this->invoiceNumber,
            'qrPayload'     => $this->qrPayload(),
            'dated'         => $this->dated,
            'statusCode'    => $this->statusCode,
            'status'        => $this->status,
            'errors'        => $this->errors,
            'itemStatuses'  => array_map(fn (ItemStatus $s) => (array) $s, $this->itemStatuses),
            'httpStatus'    => $this->httpStatus,
        ];
    }
}
