<?php

namespace Tests\Unit\Traits;

use App\Traits\ReturnsToPage;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReturnsToPageTest extends TestCase
{
    /**
     * Each row: the `return` sent, and the path the action may redirect
     * to — null where it has to fall back to its default.
     *
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function returnCases(): array
    {
        return [
            'a search with filters and a page' => ['/collection?search=sol+ring&set=lea&page=2', '/collection?search=sol+ring&set=lea&page=2'],
            'a container' => ['/containers/9d6c0c3a-1f0b-4e8e-9d5e-2b1f3a4c5d6e', '/containers/9d6c0c3a-1f0b-4e8e-9d5e-2b1f3a4c5d6e'],
            'the site root' => ['/', '/'],
            'missing' => [null, null],
            'empty' => ['', null],
            'not a path' => ['collection', null],
            'another host, protocol-relative' => ['//evil.example/collection', null],
            'another host, by backslash' => ['/\\evil.example', null],
            'another host, absolute' => ['https://evil.example/', null],
            'a script' => ['javascript:alert(1)', null],
            'a tab browsers would strip' => ["/\t/evil.example", null],
            'a line break' => ["/collection\n", null],
            'a leading space' => [' /collection', null],
            'too long' => ['/'.str_repeat('a', 2048), null],
            'not a string' => [['/collection'], null],
        ];
    }

    #[Test]
    #[DataProvider('returnCases')]
    public function it_only_returns_to_a_page_on_this_site(mixed $return, ?string $expected): void
    {
        $request = new class extends FormRequest
        {
            use ReturnsToPage;
        };
        $request->merge(['return' => $return]);

        $this->assertSame($expected, $request->returnPath());
    }
}
