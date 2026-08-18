<?php

namespace Nosh\OmniTax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant connection to an authority. Tokens are encrypted at rest.
 *
 * @property string $tenant_id
 * @property string $authority
 * @property bool   $sandbox
 * @property string $token
 * @property string $seller_ntncnic
 * @property string $seller_name
 * @property string $seller_province
 * @property string $seller_address
 */
class FiscalCredential extends Model
{
    protected $table = 'fiscal_credentials';

    protected $guarded = [];

    protected $casts = [
        'sandbox' => 'boolean',
        'token'   => 'encrypted', // encrypted at rest
    ];

    protected $hidden = ['token'];
}
