<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\ScryfallImageService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see ScryfallImageService}: which Scryfall image a card gets, and the
 * grammar of the local filenames.
 *
 * Scryfall's WebP switch (2026-10-14) adds new `image_uris` keys and, on
 * 2027-01-01, removes `small`, `large`, `border_crop` and `art_crop` and
 * renames `normal` to `jpeg`. The key-preference tests use the shape of
 * today (both sets of keys) and of 2027 (new keys only) — missing `art`
 * in the second would null every art crop on the first import after the
 * cut-over.
 */
class ScryfallImageServiceTest extends TestCase
{
    private const BASE = 'https://cards.scryfall.io';

    private ScryfallImageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScryfallImageService;
    }

    /**
     * `image_uris` as the API returns it today: the old JPEG keys and the
     * new WebP ones side by side.
     *
     * @return array<string, string>
     */
    private function todaysUris(): array
    {
        return [
            'small' => self::BASE.'/small/front/a/b/x.jpg?1',
            'normal' => self::BASE.'/normal/front/a/b/x.jpg?1',
            'large' => self::BASE.'/large/front/a/b/x.jpg?1',
            'png' => self::BASE.'/png/front/a/b/x.png?1',
            'art_crop' => self::BASE.'/art_crop/front/a/b/x.jpg?1',
            'border_crop' => self::BASE.'/border_crop/front/a/b/x.jpg?1',
            'thumb' => self::BASE.'/thumb/front/a/b/x.webp?1',
            'grid' => self::BASE.'/grid/front/a/b/x.webp?1',
            'display' => self::BASE.'/display/front/a/b/x.webp?1',
            'art' => self::BASE.'/art/front/a/b/x.webp?1',
            'crop' => self::BASE.'/crop/front/a/b/x.webp?1',
            'jpeg' => self::BASE.'/normal/front/a/b/x.jpg?1',
        ];
    }

    /**
     * `image_uris` after 2027-01-01, as announced.
     *
     * @return array<string, string>
     */
    private function urisFrom2027(): array
    {
        return array_diff_key($this->todaysUris(), array_flip(['small', 'normal', 'large', 'art_crop', 'border_crop']));
    }

    #[Test]
    public function it_prefers_the_webp_display_image_and_art_crop(): void
    {
        $card = ['image_uris' => $this->todaysUris()];

        $this->assertSame(self::BASE.'/display/front/a/b/x.webp?1', $this->service->getCardImages($card)['card_image_0']);
        $this->assertSame(self::BASE.'/art/front/a/b/x.webp?1', $this->service->getArtCrop($card));
    }

    #[Test]
    public function it_keeps_finding_both_images_once_the_jpeg_keys_are_gone(): void
    {
        $card = ['image_uris' => $this->urisFrom2027()];

        $this->assertSame(self::BASE.'/display/front/a/b/x.webp?1', $this->service->getCardImages($card)['card_image_0']);
        $this->assertSame(self::BASE.'/art/front/a/b/x.webp?1', $this->service->getArtCrop($card));
    }

    #[Test]
    public function it_falls_back_to_the_jpeg_keys_where_no_webp_is_offered(): void
    {
        $card = ['image_uris' => array_diff_key($this->todaysUris(), array_flip(['thumb', 'grid', 'display', 'art', 'crop', 'jpeg']))];

        $this->assertSame(self::BASE.'/large/front/a/b/x.jpg?1', $this->service->getCardImages($card)['card_image_0']);
        $this->assertSame(self::BASE.'/art_crop/front/a/b/x.jpg?1', $this->service->getArtCrop($card));
    }

    #[Test]
    public function it_reads_both_faces_of_a_double_faced_card(): void
    {
        $back = array_map(fn (string $uri) => str_replace('/front/', '/back/', $uri), $this->urisFrom2027());
        $card = ['card_faces' => [['image_uris' => $this->urisFrom2027()], ['image_uris' => $back]]];

        $this->assertSame([
            'card_image_0' => self::BASE.'/display/front/a/b/x.webp?1',
            'card_image_1' => self::BASE.'/display/back/a/b/x.webp?1',
        ], $this->service->getCardImages($card));
        $this->assertSame(self::BASE.'/art/front/a/b/x.webp?1', $this->service->getArtCrop($card));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function extensions(): array
    {
        return [
            'webp' => [self::BASE.'/display/front/a/b/x.webp?1789644446', 'webp'],
            'jpeg' => [self::BASE.'/large/front/a/b/x.jpg?1789644446', 'jpg'],
            'png' => [self::BASE.'/png/front/a/b/x.png', 'png'],
            'upper case' => [self::BASE.'/png/front/a/b/x.PNG', 'png'],
            'none' => [self::BASE.'/display/front/a/b/x', 'jpg'],
        ];
    }

    #[Test]
    #[DataProvider('extensions')]
    public function it_takes_the_extension_from_the_url_path(string $url, string $extension): void
    {
        $this->assertSame($extension, $this->service->imageExtension($url));
    }

    #[Test]
    public function it_builds_filenames_in_the_format_scryfall_served(): void
    {
        $this->assertSame('u--17--1.webp', $this->service->buildCardImageFilename('u', '17', 1, 'webp'));
        $this->assertSame('u--0.jpg', $this->service->buildCardImageFilename('u', null, 0, 'jpg'));
        $this->assertSame('u--17.webp', $this->service->buildArtCropFilename('u', '17', 'webp'));
        $this->assertSame('u.jpg', $this->service->buildArtCropFilename('u', null, 'jpg'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function filenames(): array
    {
        return [
            'webp card image' => ['8ee443cc-e17a-493b--1789644446--0.webp', '8ee443cc-e17a-493b'],
            'jpeg card image' => ['8ee443cc-e17a-493b--1789644446--1.jpg', '8ee443cc-e17a-493b'],
            'webp art crop' => ['8ee443cc-e17a-493b--1789644446.webp', '8ee443cc-e17a-493b'],
            'untimestamped webp' => ['8ee443cc-e17a-493b.webp', '8ee443cc-e17a-493b'],
            'untimestamped jpeg' => ['8ee443cc-e17a-493b.jpg', '8ee443cc-e17a-493b'],
        ];
    }

    #[Test]
    #[DataProvider('filenames')]
    public function it_reads_the_card_uuid_back_from_any_cached_filename(string $basename, string $uuid): void
    {
        $this->assertSame($uuid, $this->service->uuidFromFilename($basename));
    }

    #[Test]
    public function it_tells_card_faces_apart_in_any_format(): void
    {
        $this->assertTrue($this->service->isCardImageFace('u--17--0.webp', 'u', 0));
        $this->assertTrue($this->service->isCardImageFace('u--17--1.jpg', 'u', 1));
        $this->assertTrue($this->service->isCardImageFace('u--1.webp', 'u', 1));
        $this->assertFalse($this->service->isCardImageFace('u--17--1.webp', 'u', 0));
        $this->assertFalse($this->service->isCardImageFace('v--17--0.webp', 'u', 0));
    }

    #[Test]
    public function it_strips_only_image_extensions(): void
    {
        $this->assertSame('lea/u--17--0', $this->service->stem('lea/u--17--0.webp'));
        $this->assertSame('lea/u--17--0', $this->service->stem('lea/u--17--0.jpg'));
        $this->assertSame('lea/u--17--0.txt', $this->service->stem('lea/u--17--0.txt'));
    }
}
