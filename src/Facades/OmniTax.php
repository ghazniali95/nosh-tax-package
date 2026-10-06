<?php

namespace Nosh\OmniTax\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Nosh\OmniTax\FiscalManager authority(string $authority)
 * @method static \Nosh\OmniTax\FiscalManager for(mixed $tenant)
 * @method static \Nosh\OmniTax\FiscalManager sandbox(bool $on = true)
 * @method static \Nosh\OmniTax\FiscalManager timeout(float $seconds)
 * @method static \Nosh\OmniTax\Models\FiscalInvoice report(\Nosh\OmniTax\Data\Invoice $invoice, ?string $reference = null, ?bool $realtime = null)
 * @method static bool supports(string $feature)
 * @method static bool isConfigured()
 * @method static \Nosh\OmniTax\Responses\HealthCheck check()
 * @method static bool realtimeEnabled()
 * @method static float realtimeTimeout()
 * @method static void resolveCredentialsUsing(\Closure $callback)
 * @method static \Nosh\OmniTax\Responses\FiscalResponse validate(\Nosh\OmniTax\Data\Invoice $invoice)
 * @method static \Nosh\OmniTax\Responses\FiscalResponse submit(\Nosh\OmniTax\Data\Invoice $invoice)
 * @method static \Nosh\OmniTax\Support\Qr\Logo logo(?string $authority = null)
 * @method static array provinces()
 * @method static array units()
 * @method static array hsCodes()
 * @method static array taxRates()
 * @method static array checkStatl(string $ntn, ?string $date = null)
 * @method static \Nosh\OmniTax\Contracts\FiscalAuthorityDriver driver()
 *
 * @see \Nosh\OmniTax\FiscalManager
 */
class OmniTax extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'omnitax';
    }
}
