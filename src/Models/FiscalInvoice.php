<?php

namespace Nosh\OmniTax\Models;

use Illuminate\Database\Eloquent\Model;
use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Support\Qr\QrCode;

/**
 * Persistent record of a fiscal invoice and its lifecycle. Used for the
 * background-submission flow and for your audit trail.
 *
 * @property string      $tenant_id
 * @property string      $authority
 * @property string      $idempotency_key
 * @property string      $status  pending|submitted|valid|failed
 * @property string|null $fiscal_number
 * @property array       $payload
 * @property array|null  $response
 */
class FiscalInvoice extends Model
{
    public const PENDING = 'pending';
    public const SUBMITTED = 'submitted';
    public const VALID = 'valid';
    public const FAILED = 'failed';

    protected $table = 'fiscal_invoices';

    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'response'     => 'array',
        'submitted_at' => 'datetime',
    ];

    /**
     * Persist a canonical invoice as a pending record (idempotent on key).
     */
    public static function fromInvoice(Invoice $invoice, mixed $tenant = null, ?string $authority = null): self
    {
        return static::updateOrCreate(
            ['idempotency_key' => $invoice->key()],
            [
                'tenant_id' => self::tenantId($tenant),
                'authority' => $authority,
                'status'    => self::PENDING,
                'payload'   => $invoice->toArray(),
            ]
        );
    }

    public function toInvoice(): Invoice
    {
        return Invoice::fromArray($this->payload ?? []);
    }

    public function recordResponse(FiscalResponse $response): self
    {
        $this->fiscal_number = $response->invoiceNumber();
        $this->status = $response->isValid() ? self::VALID : self::FAILED;
        $this->response = $response->toArray();
        $this->submitted_at = now();
        $this->save();

        return $this;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function fiscalNumber(): ?string
    {
        return $this->fiscal_number;
    }

    public function qr(): ?QrCode
    {
        return $this->fiscal_number ? new QrCode($this->fiscal_number) : null;
    }

    protected static function tenantId(mixed $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }
        if (is_object($tenant)) {
            return (string) ($tenant->getKey() ?? $tenant->id ?? null);
        }

        return (string) $tenant;
    }
}
