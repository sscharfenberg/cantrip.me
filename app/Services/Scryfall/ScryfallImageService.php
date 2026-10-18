<?php

namespace App\Services\Scryfall;

/**
 * Picks image URLs out of Scryfall card objects and owns the grammar of
 * the local image filenames.
 *
 * Scryfall moved its image exports to WebP (blog post of 2026-10-14). The
 * WebP versions come under new `image_uris` keys, and on 2027-01-01 the
 * old JPEG keys `small`, `large`, `border_crop` and `art_crop` disappear
 * while `normal` is renamed to `jpeg`. The key lists below prefer the WebP
 * keys and keep the JPEG ones as fallbacks for as long as they exist.
 *
 * Local filenames carry the extension of the file Scryfall served. Every
 * method that reads a filename back accepts each of {@see EXTENSIONS}: the
 * cache holds JPEGs from before the switch next to WebPs downloaded since.
 */
class ScryfallImageService
{
    /**
     * Image file extensions the local cache may hold.
     *
     * @var list<string>
     */
    public const EXTENSIONS = ['webp', 'jpg', 'png'];

    /**
     * Card image keys in descending priority. `display` is the WebP
     * successor of `large` (672 × 936), `grid` of `normal` (488 × 680),
     * `thumb` of `small`. `jpeg` is what `normal` is called from 2027 on.
     * PNG last: it is several times larger than any of the others.
     *
     * @var list<string>
     */
    protected array $imageFormats = ['display', 'large', 'grid', 'jpeg', 'normal', 'thumb', 'small', 'png'];

    /**
     * Art crop keys in descending priority: the WebP `art`, then the JPEG
     * `art_crop` it replaces.
     *
     * @var list<string>
     */
    protected array $artFormats = ['art', 'art_crop'];

    /**
     * Extract card image URLs from a Scryfall card object.
     *
     * Checks the card-level image_uris first, then falls back to
     * per-face image_uris for multi-faced cards (e.g. transform, modal DFC).
     * Returns the highest-priority format available per $imageFormats.
     *
     * @param  array  $card  A single card object from the Scryfall bulk JSON.
     * @return array{card_image_0: string|null, card_image_1: string|null}
     */
    public function getCardImages(array $card): array
    {
        $uris = [];
        if (array_key_exists('image_uris', $card) && count($card['image_uris']) > 0) {
            $uris[] = $this->getCardFaceImageUri($card);
        } elseif (array_key_exists('card_faces', $card) && count($card['card_faces']) > 1) {
            foreach ($card['card_faces'] as $face) {
                if (
                    array_key_exists('image_uris', $face)
                    && count($face['image_uris']) > 0
                    && strlen($this->getCardFaceImageUri($face)) > 0
                ) {
                    $uris[] = $this->getCardFaceImageUri($face);
                }
            }
        }

        return [
            'card_image_0' => $uris[0] ?? null,
            'card_image_1' => $uris[1] ?? null,
        ];
    }

    /**
     * Extract the first art crop URI from a Scryfall card object.
     *
     * Returns the card-level art crop if available, otherwise the first
     * face's art crop for multi-faced cards (e.g. transform, modal DFC).
     * Returns null if no art crop is available.
     *
     * @param  array  $card  A single card object from the Scryfall bulk JSON.
     */
    public function getArtCrop(array $card): ?string
    {
        $uri = $this->firstUri($card, $this->artFormats);
        if ($uri !== null) {
            return $uri;
        }
        if (array_key_exists('card_faces', $card) && count($card['card_faces']) > 1) {
            foreach ($card['card_faces'] as $face) {
                $uri = $this->firstUri($face, $this->artFormats);
                if ($uri !== null) {
                    return $uri;
                }
            }
        }

        return null;
    }

    /**
     * Extract the Unix timestamp from a Scryfall image URL query string.
     *
     * Scryfall appends a cache-busting timestamp to image URLs, e.g.
     *   https://cards.scryfall.io/art/front/a/b/uuid.webp?1709234567
     * The WebP and JPEG URLs of one image carry the same timestamp.
     * Returns null if no query string / timestamp is present.
     *
     * @param  string  $url  A Scryfall image URL.
     * @return string|null The timestamp portion, or null.
     */
    public function parseTimestamp(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ?: null;
    }

    /**
     * The file extension of a Scryfall image URL ("webp", "jpg", "png"),
     * falling back to "jpg" for a URL without one.
     */
    public function imageExtension(string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $extension === '' ? 'jpg' : $extension;
    }

    /**
     * Build the local filename for a cached art crop image.
     *
     * Embeds the Scryfall timestamp in the filename so that a filesystem
     * check alone can determine whether the cached version is current.
     *
     * Format: {uuid}--{timestamp}.{ext}  (or {uuid}.{ext} if no timestamp)
     *
     * @param  string  $uuid  The card UUID.
     * @param  string|null  $timestamp  The timestamp from the Scryfall URL.
     * @param  string  $extension  The extension of the Scryfall URL.
     * @return string e.g. "abcdef-1234--1709234567.webp"
     */
    public function buildArtCropFilename(string $uuid, ?string $timestamp, string $extension): string
    {
        if ($timestamp !== null) {
            return "$uuid--$timestamp.$extension";
        }

        return "$uuid.$extension";
    }

    /**
     * Build the local filename for a cached card image.
     *
     * Embeds the Scryfall timestamp and face index in the filename so that
     * a filesystem check alone can determine whether the cached version is current.
     *
     * Format: {uuid}--{timestamp}--{index}.{ext}  (or {uuid}--{index}.{ext} if no timestamp)
     *
     * @param  string  $uuid  The card UUID.
     * @param  string|null  $timestamp  The timestamp from the Scryfall URL.
     * @param  int  $index  The face index (0 = front, 1 = back).
     * @param  string  $extension  The extension of the Scryfall URL.
     * @return string e.g. "abcdef-1234--1709234567--0.webp"
     */
    public function buildCardImageFilename(string $uuid, ?string $timestamp, int $index, string $extension): string
    {
        if ($timestamp !== null) {
            return "$uuid--$timestamp--$index.$extension";
        }

        return "$uuid--$index.$extension";
    }

    /**
     * A filename without its image extension — the part that identifies the
     * image version, whatever format it was downloaded in.
     */
    public function stem(string $filename): string
    {
        return (string) preg_replace('/\.('.implode('|', self::EXTENSIONS).')$/', '', $filename);
    }

    /**
     * The card UUID a cached image belongs to: everything before the first
     * "--", or before the extension when there is no timestamp.
     */
    public function uuidFromFilename(string $basename): string
    {
        return (string) preg_replace('/--.*$/', '', $this->stem($basename));
    }

    /**
     * Whether a cached card image belongs to this card face, in any format.
     * Face 0 and face 1 of a card share a UUID, so the face suffix decides.
     */
    public function isCardImageFace(string $basename, string $uuid, int $faceIndex): bool
    {
        return str_starts_with($basename, "$uuid--") && str_ends_with($this->stem($basename), "--$faceIndex");
    }

    /**
     * Resolve the best image URI for a single card face.
     *
     * Iterates through $imageFormats in priority order and returns
     * the first match. Returns an empty string if none are available.
     *
     * @param  array  $face  A card or card_face object from the Scryfall bulk JSON.
     */
    public function getCardFaceImageUri(array $face): string
    {
        return $this->firstUri($face, $this->imageFormats) ?? '';
    }

    /**
     * The first of the given `image_uris` keys present on a card or face.
     *
     * @param  array  $face  A card or card_face object.
     * @param  list<string>  $formats  Keys in descending priority.
     */
    private function firstUri(array $face, array $formats): ?string
    {
        if (! array_key_exists('image_uris', $face) || ! is_array($face['image_uris'])) {
            return null;
        }
        foreach ($formats as $format) {
            if (array_key_exists($format, $face['image_uris'])) {
                return $face['image_uris'][$format];
            }
        }

        return null;
    }
}
