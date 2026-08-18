<?php

namespace Nosh\OmniTax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached authority reference lists (provinces, units, hsCodes, taxRates...).
 *
 * @property string $authority
 * @property string $type
 * @property array  $data
 * @property \Illuminate\Support\Carbon $synced_at
 */
class FiscalReferenceData extends Model
{
    protected $table = 'fiscal_reference_data';

    protected $guarded = [];

    protected $casts = [
        'data'      => 'array',
        'synced_at' => 'datetime',
    ];
}
