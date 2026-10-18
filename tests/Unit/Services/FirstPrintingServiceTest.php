<?php

namespace Tests\Unit\Services;

use App\Services\FirstPrintingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FirstPrintingServiceTest extends TestCase
{
    /**
     * Each row: two printings of one card, and which of them the lookup
     * should show. Taken from what a plain "oldest first" rule got wrong on
     * real data.
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function printingCases(): array
    {
        return [
            'the older regular printing' => [
                self::printing('lea', '1993-08-05', '269', 'core'),
                self::printing('sld', '2019-12-02', '1492', 'box'),
                'lea',
            ],
            'paper beats a 2002-dated MTGO promo' => [
                self::printing('mh2', '2021-06-18', '138', 'draft_innovation'),
                self::printing('prm', '2002-06-24', '91273', 'promo', digital: true),
                'mh2',
            ],
            'a regular set beats an older store promo' => [
                self::printing('mh3', '2024-06-14', '199', 'draft_innovation'),
                self::printing('sch', '2022-07-09', '49', 'promo'),
                'mh3',
            ],
            'a paper promo beats a digital printing' => [
                self::printing('pbook', '1995-01-01', '1', 'promo'),
                self::printing('prm', '2002-06-24', '1', 'core', digital: true),
                'pbook',
            ],
            'the plain number beats the serialized #0' => [
                self::printing('ltr-246', '2023-06-23', '246', 'draft_innovation'),
                self::printing('ltr-0', '2023-06-23', '0', 'draft_innovation'),
                'ltr-246',
            ],
            'the lower number beats a showcase variant' => [
                self::printing('neo-357', '2022-02-18', '357', 'expansion'),
                self::printing('neo-141', '2022-02-18', '141', 'expansion'),
                'neo-141',
            ],
            'by value, not by string' => [
                self::printing('n-12', '2022-02-18', '12', 'expansion'),
                self::printing('n-9', '2022-02-18', '9', 'expansion'),
                'n-9',
            ],
            'the plain number beats its ★ promo' => [
                self::printing('war-star', '2019-05-03', '83★', 'expansion'),
                self::printing('war', '2019-05-03', '83', 'expansion'),
                'war',
            ],
            'a missing release date sorts last' => [
                self::printing('undated', null, '1', 'expansion'),
                self::printing('dated', '2030-01-01', '1', 'expansion'),
                'dated',
            ],
        ];
    }

    /**
     * @param  array{id: string, collector_number: string, digital: bool, set_type: string, released_at: string|null}  $a
     * @param  array{id: string, collector_number: string, digital: bool, set_type: string, released_at: string|null}  $b
     */
    #[Test]
    #[DataProvider('printingCases')]
    public function it_shows_the_first_regular_paper_printing(array $a, array $b, string $expected): void
    {
        $this->assertSame($expected, FirstPrintingService::compare($a, $b) < 0 ? $a['id'] : $b['id']);
        $this->assertSame($expected, FirstPrintingService::compare($b, $a) < 0 ? $b['id'] : $a['id']);
    }

    /**
     * @return array{id: string, collector_number: string, digital: bool, set_type: string, released_at: string|null}
     */
    private static function printing(string $id, ?string $releasedAt, string $collectorNumber, string $setType, bool $digital = false): array
    {
        return [
            'id' => $id,
            'collector_number' => $collectorNumber,
            'digital' => $digital,
            'set_type' => $setType,
            'released_at' => $releasedAt,
        ];
    }
}
