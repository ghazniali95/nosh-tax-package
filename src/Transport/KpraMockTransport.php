<?php

namespace Nosh\OmniTax\Transport;

use Nosh\OmniTax\Contracts\Transport;

/**
 * An in-package fake KPRA RIMS authority. Returns responses in KPRA's EXACT
 * documented shape — a 201 `{status, message, data:{transaction_id, invoice_no,
 * pos_id, date_time}}` on success, and the documented 400/401/404 error bodies
 * on failure — and runs the same validations KPRA documents (required fields,
 * credentials, amount/rate rules, date format, invoice_no uniqueness per pos_id,
 * credit-note lookup). The whole flow is testable with NO POS ID and NO network,
 * for both the live API (cloud) and the local RIMS utility (offline).
 *
 * Flip transport back to 'http' and the identical KpraDriver talks to the real
 * KPRA live API (cloud) or the local RIMS utility (offline) — zero code changes.
 */
class KpraMockTransport implements Transport
{
    /** @var array<int,array{url:string,payload:array}> */
    public array $recorded = [];

    /** @var array<string,bool> "pos_id|invoice_no" pairs already accepted, for duplicate detection */
    protected array $seenInvoices = [];

    /** @var array<string,array> credit notes already recorded, keyed by "pos_id|credit_note_no" */
    protected array $seenCreditNotes = [];

    public function post(string $url, array $payload, array $headers = []): array
    {
        $this->recorded[] = ['url' => $url, 'payload' => $payload];

        return str_contains($url, 'credit-note')
            ? $this->creditNote($payload)
            : $this->invoice($payload);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        // KPRA publishes no server-to-server reference data.
        return ['status' => 200, 'body' => []];
    }

    // ---- Sales invoice (live API + offline utility share one body) --------

    protected function invoice(array $payload): array
    {
        $required = ['ntn', 'pos_id', 'key', 'invoice_no', 'amount', 'tax_rate', 'tax_amount', 'total_amount', 'date_time'];
        if ($missing = $this->missing($payload, $required)) {
            return $this->error(400, 'Missing required field(s): '.implode(', ', $missing));
        }

        // 401 — credentials. The mock has no registry, so it rejects only a
        // blank pos_id/key (a real KPRA also rejects a wrong pair with 401).
        if ((string) $payload['pos_id'] === '' || (string) $payload['key'] === '') {
            return $this->error(401, 'Invalid POS ID or key.');
        }

        if (strlen((string) $payload['invoice_no']) > 50) {
            return $this->error(400, 'invoice_no must be at most 50 characters.');
        }
        if (! $this->validDateTime((string) $payload['date_time'])) {
            return $this->error(400, 'Invalid date_time. Use YYYY-MM-DD HH:MM:SS.');
        }
        if ((float) $payload['amount'] <= 0 || (float) $payload['total_amount'] <= 0) {
            return $this->error(400, 'amount and total_amount must be greater than 0.');
        }
        if ((float) $payload['tax_rate'] < 0 || (float) $payload['tax_rate'] > 100) {
            return $this->error(400, 'tax_rate must be between 0 and 100.');
        }

        // invoice_no is unique per pos_id.
        $dupKey = $payload['pos_id'].'|'.$payload['invoice_no'];
        if (isset($this->seenInvoices[$dupKey])) {
            return $this->error(400, 'invoice_no already exists for this pos_id.');
        }
        $this->seenInvoices[$dupKey] = true;

        return [
            'status' => 201,
            'body'   => [
                'status'  => 201,
                'message' => 'Invoice created successfully.',
                'data'    => [
                    'transaction_id' => random_int(1_000_000, 9_999_999),
                    'invoice_no'     => (string) $payload['invoice_no'],
                    'pos_id'         => (string) $payload['pos_id'],
                    'date_time'      => (string) $payload['date_time'],
                ],
            ],
        ];
    }

    // ---- Credit note (dedicated endpoint) ---------------------------------

    protected function creditNote(array $payload): array
    {
        $required = ['ntn', 'pos_id', 'key', 'invoice_no', 'credit_note_no', 'amount', 'tax_rate', 'tax_amount', 'total_amount'];
        if ($missing = $this->missing($payload, $required)) {
            return $this->error(400, 'Missing required field(s): '.implode(', ', $missing));
        }
        if ((string) $payload['pos_id'] === '' || (string) $payload['key'] === '') {
            return $this->error(401, 'Invalid POS ID or key.');
        }

        // The original sale must have been recorded for this pos_id.
        $invoiceKey = $payload['pos_id'].'|'.$payload['invoice_no'];
        if (! isset($this->seenInvoices[$invoiceKey])) {
            return $this->error(404, 'Original invoice not found for this branch.');
        }

        // Same credit_note_no returns the existing row with status 200.
        $cnKey = $payload['pos_id'].'|'.$payload['credit_note_no'];
        if (isset($this->seenCreditNotes[$cnKey])) {
            return ['status' => 200, 'body' => ['status' => 200, 'message' => 'Credit note already recorded', 'data' => $this->seenCreditNotes[$cnKey]]];
        }

        $data = [
            'credit_note_id' => count($this->seenCreditNotes) + 1,
            'transaction_id' => random_int(10_000_000, 99_999_999),
            'invoice_no'     => (string) $payload['invoice_no'],
            'credit_note_no' => (string) $payload['credit_note_no'],
            'note_type'      => $payload['note_type'] ?? 'full',
            'amount'         => (float) $payload['amount'],
            'tax_rate'       => (float) $payload['tax_rate'],
            'tax_amount'     => (float) $payload['tax_amount'],
            'total_amount'   => (float) $payload['total_amount'],
            'note_date'      => $payload['note_date'] ?? date('Y-m-d H:i:s'),
        ];
        $this->seenCreditNotes[$cnKey] = $data;

        return ['status' => 201, 'body' => ['status' => 201, 'message' => 'Credit note recorded', 'data' => $data]];
    }

    // ---- Helpers -----------------------------------------------------------

    /** @return string[] missing/blank required keys */
    protected function missing(array $payload, array $required): array
    {
        $missing = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    protected function validDateTime(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value);
    }

    protected function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => $status, 'message' => $message]];
    }
}
