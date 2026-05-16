<!doctype html>
{{--
    Printable A4 QR sticker sheet, rendered by dompdf via
    ContainerController::qrSheetPdf().

    Layout (A4 portrait, 210×297 mm, 9-up):
      - Sticker         : 60 × 70 mm (60×60 QR + 10 mm label band)
      - Gutter          : 5 mm horizontal + vertical
      - Page margin     : 10 mm L/R, 38.5 mm T/B (centred)
      - Grid origin     : x = 10 mm, y = 38.5 mm
      - Tile (col,row)  : x = 10 + col*65, y = 38.5 + row*75

    Cut lines (red, 0.4 mm) sit directly on each QR's edge so the cut
    blade slices through the line itself, leaving a clean 60×60 mm QR
    sticker. The label band below each QR is discarded along with the
    gutters — it's only there during cut-time so you can identify which
    QR maps to which container.
      - 6 horizontal at y = 38.5 / 98.5 / 113.5 / 173.5 / 188.5 / 248.5 mm
        (top and bottom edge of each row's QRs)
      - 6 vertical   at x = 10 / 70 / 75 / 135 / 140 / 200 mm
        (left and right edge of each column)

    SVGs are inlined as base64 data URIs — most reliable form for
    dompdf 3.x, which has uneven inline-<svg> support.
--}}
<html>
<head>
    <meta charset="utf-8">
    <title>Container QR sticker sheet</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
        .page { position: relative; width: 210mm; height: 297mm; page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .tile { position: absolute; width: 60mm; height: 70mm; overflow: hidden; }
        .tile__qr { width: 60mm; height: 60mm; display: block; }
        .tile__label { width: 60mm; height: 10mm; text-align: center; overflow: hidden; }
        .tile__name { font-size: 8pt; font-weight: bold; line-height: 1.1;
                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-top: 1mm; }
        .tile__type { font-size: 6.5pt; color: #666; line-height: 1.1;
                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        /* Cut lines sit fully OUTSIDE each QR's edge so cutting along
           them leaves the QR pristine — line + 0.4mm of trim get
           consumed. Each line gets a per-direction modifier class
           that pushes it onto the trim side of its edge. */
        .cut-h { position: absolute; left: 9mm; width: 192mm; height: 0.4mm; background-color: #c00; }
        .cut-v { position: absolute; top: 37.5mm; width: 0.4mm; height: 212mm; background-color: #c00; }
        .cut-h--above { margin-top: -0.4mm; } /* line sits ABOVE the QR's top edge */
        .cut-h--below { margin-top: 0;     } /* line sits BELOW the QR's bottom edge */
        .cut-v--left  { margin-left: -0.4mm; } /* line sits LEFT of the QR's left edge */
        .cut-v--right { margin-left: 0;     } /* line sits RIGHT of the QR's right edge */
    </style>
</head>
<body>
    @php
        $cols = 3;
        $rows = 3;
        $perPage = $cols * $rows;
        $pages = $tiles->chunk($perPage);
    @endphp

    @foreach ($pages as $pageTiles)
        <div class="page">
            @foreach ($pageTiles->values() as $i => $tile)
                @php
                    $col = $i % $cols;
                    $row = intdiv($i, $cols);
                    $x = 10 + $col * 65;     // 60mm tile + 5mm gutter
                    $y = 38.5 + $row * 75;   // 70mm tile + 5mm gutter
                    $svgDataUri = 'data:image/svg+xml;base64,' . base64_encode($tile['svg']);
                @endphp
                <div class="tile" style="left: {{ $x }}mm; top: {{ $y }}mm;">
                    <img class="tile__qr" src="{{ $svgDataUri }}" alt="">
                    <div class="tile__label">
                        <div class="tile__name">{{ $tile['name'] }}</div>
                        <div class="tile__type">{{ $tile['type_label'] }}</div>
                    </div>
                </div>
            @endforeach

            {{-- Cut lines render AFTER tiles. Each line sits fully
                 outside its QR's edge — cutting along the line removes
                 the line + 0.4mm of trim, leaving a pristine QR. Always
                 drawn full-grid even on a partial last page; surplus
                 lines cross empty whitespace harmlessly. --}}
            <div class="cut-h cut-h--above" style="top: 38.5mm;"></div>
            <div class="cut-h cut-h--below" style="top: 98.5mm;"></div>
            <div class="cut-h cut-h--above" style="top: 113.5mm;"></div>
            <div class="cut-h cut-h--below" style="top: 173.5mm;"></div>
            <div class="cut-h cut-h--above" style="top: 188.5mm;"></div>
            <div class="cut-h cut-h--below" style="top: 248.5mm;"></div>
            <div class="cut-v cut-v--left"  style="left: 10mm;"></div>
            <div class="cut-v cut-v--right" style="left: 70mm;"></div>
            <div class="cut-v cut-v--left"  style="left: 75mm;"></div>
            <div class="cut-v cut-v--right" style="left: 135mm;"></div>
            <div class="cut-v cut-v--left"  style="left: 140mm;"></div>
            <div class="cut-v cut-v--right" style="left: 200mm;"></div>
        </div>
    @endforeach
</body>
</html>
