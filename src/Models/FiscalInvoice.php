<?php

namespace Nosh\OmniTax\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nosh\OmniTax\Data\Invoice;
use Nosh\OmniTax\Responses\FiscalResponse;
use Nosh\OmniTax\Support\Qr\QrCode;

/**
 * Persistent record of a fiscal invoice and its lifecycle. Used for the
 * background-submission flow and for your audit trail.
 *
 * Status:
 *   pending  not reported yet — new, queued, or the authority could not be
 *            reached (network / 5xx). Retried automatically.
 *   valid    accepted; `fiscal_number` and `qr_payload` are set. Final.
 *   failed   the authority REJECTED it (bad data, credentials). Retrying the
 *            same payload will not help — someone has to look at `last_error`.
 *
 * @property string|null $tenant_id
 * @property string|null $reference
 * @property string|null $authority
 * @property string      $idempotency_key
 * @property string      $status  pending|submitted|valid|failed
 * @property int         $attempts
 * @property string|null $last_error
 * @property string|null $fiscal_number
 * @property string|null $qr_payload
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
        'attempts'     => 'integer',
        'submitted_at' => 'datetime',
    ];

    /**
     * Persist a canonical invoice as a pending record (idempotent on key).
     *
     * An already-ACCEPTED record is returned untouched. This used to be a plain
     * updateOrCreate, which reset a valid invoice to `pending` whenever the
     * same sale was recorded again (a retried request, a reprint that re-ran
     * the hook) — and the job then reported it to the authority a second time.
     *
     * `$reference` is your own key for the sale (e.g. "bill:1234"); see
     * {@see self::scopeForReference()}.
     */
    public static function fromInvoice(
        Invoice $invoice,
        mixed $tenant = null,
        ?string $authority = null,
        ?string $reference = null,
    ): self {
        $record = static::firstOrNew(['idempotency_key' => $invoice->key()]);

        if ($record->exists && $record->status === self::VALID) {
            return $record;
        }

        $record->fill([
            'tenant_id' => self::tenantId($tenant),
            'authority' => $authority,
            'reference' => $reference ?? $record->reference,
            'status'    => self::PENDING,
            'payload'   => $invoice->toArray(),
        ]);
        $record->save();

        return $record;
    }

    public function toInvoice(): Invoice
    {
        return Invoice::fromArray($this->payload ?? []);
    }

    /**
     * Store the authority's answer.
     *
     * Accepted → valid. Never reached / authority error (status 0 or 5xx) →
     * stays pending, so it is retried. Anything else is a rejection → failed.
     */
    public function recordResponse(FiscalResponse $response): self
    {
        $this->attempts = (int) $this->attempts + 1;
        $this->response = $response->toArray();
        $this->submitted_at = now();

        if ($response->isValid()) {
            $this->status = self::VALID;
            $this->fiscal_number = $response->invoiceNumber();
            $this->qr_payload = $response->qrPayload();
            $this->last_error = null;
        } else {
            $this->status = $response->isRetryable() ? self::PENDING : self::FAILED;
            $this->last_error = implode(' | ', $response->errors()) ?: 'Rejected without a reason.';
        }

        $this->save();

        return $this;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isReported(): bool
    {
        return $this->status === self::VALID;
    }

    public function fiscalNumber(): ?string
    {
        return $this->fiscal_number;
    }

    /**
     * The QR to print. Encodes what the AUTHORITY said to encode: the fiscal
     * number for FBR, a verification URL for SRB and PRA. Building it from
     * `fiscal_number` alone (as before v1.2) printed an SRB/PRA QR that
     * pointed nowhere.
     */
    public function qr(): ?QrCode
    {
        $payload = $this->qr_payload
            ?? ($this->response['qrPayload'] ?? null)
            ?? $this->fiscal_number;

        return $payload ? new QrCode($payload) : null;
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }

    public function scopeReported(Builder $query): Builder
    {
        return $query->where('status', self::VALID);
    }

    /** Every fiscal document for one of your sales — the invoice and any credit notes. */
    public function scopeForReference(Builder $query, string $reference): Builder
    {
        return $query->where('reference', $reference);
    }

    protected static function tenantId(mixed $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }
        if ($tenant instanceof Model) {
            return (string) $tenant->getKey();
        }
        if (is_object($tenant)) {
            return isset($tenant->id) ? (string) $tenant->id : null;
        }

        return (string) $tenant;
    }
}
