<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeService
{
    /**
     * Generate an inline-embeddable SVG QR code for the given URL.
     *
     * Uses bacon/bacon-qr-code (same approach as Fortify's 2FA QR).
     * Strips the XML declaration so the SVG can be embedded via v-html.
     */
    public static function generateSvg(string $url, int $size = 256): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle($size, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
                new SvgImageBackEnd
            )
        ))->writeString($url);

        // Strip XML declaration so the SVG can be embedded via v-html.
        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * Generate a QR SVG with a caption painted directly onto the QR
     * pattern — used for the printable sticker sheet so the sticker
     * stays self-identifying after it's been cut and applied.
     *
     * Uses error-correction level H (≈30% redundancy) so the white
     * caption strip across the QR's centre doesn't break scanning.
     * The caption rect height stays constant at ≈12% of the QR; the
     * width auto-fits the text (snug rect for short names) up to a
     * cap of 86% of the QR. If the cap kicks in, the font size is
     * shrunk to fit instead of compressing letter spacing — looks
     * better than `textLength` and avoids relying on lengthAdjust
     * support in dompdf's SVG renderer.
     *
     * Text-width estimation is a char-count × glyph-width heuristic
     * (PHP has no font metrics). The 0.62 factor errs slightly wide
     * so the rect never overflows even with all-uppercase or wide-
     * glyph names.
     *
     * @param  string  $url  URL the QR encodes.
     * @param  string  $label  Caption text rendered onto the QR.
     * @param  int  $size  SVG side length in pixel space.
     */
    public static function generateSvgWithLabel(string $url, string $label, int $size = 600): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle($size, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
                new SvgImageBackEnd
            )
        ))->writeString($url, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::H());

        // Strip XML declaration so the SVG can be embedded.
        $svg = trim(substr($svg, strpos($svg, "\n") + 1));

        $rectHeight = $size * 0.12;
        $maxRectWidth = $size * 0.86;
        $padding = $size * 0.025; // horizontal breathing room inside the rect
        $baseFontSize = $rectHeight * 0.55;
        $glyphWidth = 0.62; // generous estimate — see method docblock

        $charCount = max(1, mb_strlen($label));
        $estimatedTextWidth = $charCount * $baseFontSize * $glyphWidth;
        $desiredRectWidth = $estimatedTextWidth + 2 * $padding;

        if ($desiredRectWidth <= $maxRectWidth) {
            // Short caption: snug rect, full-size font.
            $rectWidth = $desiredRectWidth;
            $fontSize = $baseFontSize;
        } else {
            // Long caption: cap rect width, shrink font so text fits.
            $rectWidth = $maxRectWidth;
            $availableTextWidth = $rectWidth - 2 * $padding;
            $fontSize = $baseFontSize * ($availableTextWidth / $estimatedTextWidth);
        }

        $rectX = ($size - $rectWidth) / 2;
        $rectY = ($size - $rectHeight) / 2;
        $textX = $size / 2;
        $textY = $rectY + $rectHeight * 0.72;

        $labelEscaped = htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $overlay = sprintf(
            '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="white"/>'
            .'<text x="%.1f" y="%.1f" text-anchor="middle" font-family="DejaVu Sans, Helvetica, Arial, sans-serif" font-size="%.1f" font-weight="bold" fill="black">%s</text>',
            $rectX, $rectY, $rectWidth, $rectHeight,
            $textX, $textY, $fontSize, $labelEscaped
        );

        return str_replace('</svg>', $overlay.'</svg>', $svg);
    }
}
