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
 * @property ?string $pos_id    SRB registered POS ID
 * @property ?string $pos_user  SRB cloud username (encrypted at rest)
 * @property ?string $pos_pass  SRB cloud password (encrypted at rest)
 * @property ?string $mode      SRB mode: 'cloud' | 'offline'
 */
class FiscalCredential extends Model
{
    protected $table = 'fiscal_credentials';

    protected $guarded = [];

    protected $casts = [
        'sandbox'  => 'boolean',
        'token'    => 'encrypted', // encrypted at rest
        'pos_user' => 'encrypted', // SRB cloud credential
        'pos_pass' => 'encrypted', // SRB cloud credential
    ];

    protected $hidden = ['token', 'pos_user', 'pos_pass'];
}
