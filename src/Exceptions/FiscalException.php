<?php

namespace Nosh\OmniTax\Exceptions;

use RuntimeException;

/** Transport / auth / configuration problems (network, 401, 500, misconfig). */
class FiscalException extends RuntimeException
{
}
