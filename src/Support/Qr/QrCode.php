<?php

namespace Nosh\OmniTax\Support\Qr;

use RuntimeException;

/**
 * The QR code printed on a fiscal receipt. It encodes the official fiscal
 * invoice number returned by the authority.
 *
 * payload() and dataUri()-of-SVG always work. PNG/SVG rendering to the exact
 * authority spec uses bacon/bacon-qr-code when installed (see composer
 * "suggest"); otherwise a clear exception tells the integrator to install it.
 */
class QrCode
{
    public function __construct(
        protected string $payload,
        protected int $size = 300,
    ) {
    }

    /** The exact string encoded in the QR (the fiscal invoice number). */
    public function payload(): string
    {
        return $this->payload;
    }

    public function size(int $pixels): self
    {
        $this->size = $pixels;

        return $this;
    }

    public function png(): string
    {
        return $this->render('png');
    }

    public function svg(): string
    {
        return $this->render('svg');
    }

    /** Inline <img src="…"> value for HTML / thermal receipts. */
    public function dataUri(string $format = 'svg'): string
    {
        $binary = $this->render($format);
        $mime = $format === 'png' ? 'image/png' : 'image/svg+xml';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    protected function render(string $format): string
    {
        if (! class_exists(\BaconQrCode\Writer::class)) {
            throw new RuntimeException(
                'QR rendering needs bacon/bacon-qr-code. Run: composer require bacon/bacon-qr-code. '
                .'The payload() (fiscal number) is available without it.'
            );
        }

        $rendererClass = $format === 'png'
            ? \BaconQrCode\Renderer\Image\ImagickImageBackEnd::class
            : \BaconQrCode\Renderer\Image\SvgImageBackEnd::class;

        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($this->size),
            new $rendererClass()
        );

        return (new \BaconQrCode\Writer($renderer))->writeString($this->payload);
    }
}
