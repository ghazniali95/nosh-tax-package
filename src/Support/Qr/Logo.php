<?php

namespace Nosh\OmniTax\Support\Qr;

/**
 * The authority's official "digital invoicing" logo that must print beside
 * the QR code on every fiscal receipt. Place your authority logo files in
 * the package's resources/logos/{authority}.png (published on install).
 */
class Logo
{
    public function __construct(
        protected string $authority,
    ) {
    }

    protected function path(string $ext): string
    {
        $base = dirname(__DIR__, 3).'/resources/logos/'.$this->authority.'.'.$ext;

        // Allow apps to override with a published copy.
        $published = function_exists('resource_path')
            ? resource_path('vendor/omnitax/logos/'.$this->authority.'.'.$ext)
            : null;

        return ($published && is_file($published)) ? $published : $base;
    }

    public function png(): string
    {
        $path = $this->path('png');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function dataUri(): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png());
    }

    public function path_(): string
    {
        return $this->path('png');
    }
}
