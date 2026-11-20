<?php
/**
 * charts.php — dependency-free SVG charts.
 * -------------------------------------------------------
 * The dashboards need real charts without pulling in a
 * JavaScript charting library: the office works on weak
 * connections and every asset has to load offline. These
 * helpers return accessible inline SVG that inherits the
 * application's typography and colour tokens.
 * -------------------------------------------------------
 */

/** Chart palette lifted from the office Excel workbook. */
function chart_palette(): array
{
    return [
        'primary' => '#0249E1',
        'soft'    => '#80B0EC',
        'accent'  => '#DAFB71',
        'danger'  => '#EE3D5A',
        'deep'    => '#0137A6',
        'ink'     => '#111111',
        'grey'    => '#CBD6E6',

        // Legacy keys kept for older report pages.
        'blue'    => '#0249E1',
        'red'     => '#EE3D5A',
        'green'   => '#DAFB71',
        'purple'  => '#80B0EC',
        'teal'    => '#0137A6',
        'gold'    => '#DAFB71',
    ];
}

/** Compact money for axis labels: 13.7M, 850K, 420. */
function chart_money(float $value): string
{
    $value = (float) $value;
    $abs   = abs($value);

    if ($abs >= 1000000000) {
        return rtrim(rtrim(number_format($value / 1000000000, 1, '.', ''), '0'), '.') . 'B';
    }

    if ($abs >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }

    if ($abs >= 1000) {
        return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'K';
    }

    return number_format($value, 0);
}

/**
 * Vertical column chart.
 *
 * Each series item: ['label' => string, 'value' => float, 'color' => '#RRGGBB'].
 *
 * @param array $series
 * @param array $options height, value_prefix, show_values, gradient
 */
function svg_column_chart(array $series, array $options = []): string
{
    $width        = (int) ($options['width'] ?? 640);
    $height       = (int) ($options['height'] ?? 260);
    $padLeft      = (int) ($options['pad_left'] ?? 54);
    $padRight     = (int) ($options['pad_right'] ?? 14);
    $padTop       = (int) ($options['pad_top'] ?? 18);
    $padBottom    = (int) ($options['pad_bottom'] ?? 42);
    $showValues   = (bool) ($options['show_values'] ?? false);
    $valuePrefix  = (string) ($options['value_prefix'] ?? '');
    $emptyText    = (string) ($options['empty_text'] ?? t('No data yet'));
    $title        = (string) ($options['title'] ?? '');

    $plotWidth  = max(1, $width - $padLeft - $padRight);
    $plotHeight = max(1, $height - $padTop - $padBottom);

    $max = 0.0;
    foreach ($series as $item) {
        $max = max($max, (float) ($item['value'] ?? 0));
    }

    if ($max <= 0) {
        return '<div class="chart-empty"><i class="bi bi-bar-chart"></i><span>'
            . e($emptyText) . '</span></div>';
    }

    // Round the axis up to a friendly number.
    $magnitude = 10 ** max(0, strlen((string) (int) $max) - 2);
    $step      = max(1.0, ceil($max / 4 / $magnitude) * $magnitude);
    $axisMax   = $step * 4;

    $count   = max(1, count($series));
    $slot    = $plotWidth / $count;
    $barGap  = min(26.0, $slot * 0.34);
    $barW    = max(4.0, $slot - $barGap);
    $palette = chart_palette();
    $default = $options['color'] ?? $palette['primary'];

    $svg  = '<svg class="chart-svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img"'
          . ' aria-label="' . e($title !== '' ? $title : t('Chart')) . '" preserveAspectRatio="xMidYMid meet">';

    if (!empty($options['gradient'])) {
        $svg .= '<defs><linearGradient id="' . e((string) $options['gradient']) . '" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0%" stop-color="' . e($default) . '" stop-opacity="1"/>'
              . '<stop offset="100%" stop-color="' . e($default) . '" stop-opacity=".55"/>'
              . '</linearGradient></defs>';
    }

    // Grid + Y labels.
    for ($i = 0; $i <= 4; $i++) {
        $value = $axisMax - ($step * $i);
        $y     = $padTop + ($plotHeight * $i / 4);

        $svg .= '<line x1="' . $padLeft . '" y1="' . round($y, 1) . '" x2="' . ($width - $padRight)
              . '" y2="' . round($y, 1) . '" stroke="#e4e9f2" stroke-width="1"/>'
              . '<text x="' . ($padLeft - 9) . '" y="' . round($y + 3.5, 1)
              . '" text-anchor="end" font-size="10" fill="#6b7480">'
              . e(chart_money($value)) . '</text>';
    }

    $grid = (int) ($options['label_every'] ?? 1);

    foreach (array_values($series) as $index => $item) {
        $value = max(0.0, (float) ($item['value'] ?? 0));
        $barH  = $axisMax > 0 ? ($value / $axisMax) * $plotHeight : 0;
        $x     = $padLeft + ($slot * $index) + ($barGap / 2);
        $y     = $padTop + $plotHeight - $barH;
        $color = (string) ($item['color'] ?? $default);

        $delay = min(1200, $index * 65);

        $svg .= '<rect class="chart-bar" x="' . round($x, 1) . '" y="' . round($y, 1)
              . '" width="' . round($barW, 1) . '" height="' . round($barH, 1)
              . '" rx="3" style="animation-delay:' . $delay . 'ms"'
              . ' fill="' . ($options['gradient'] ?? null ? 'url(#' . e((string) $options['gradient']) . ')' : e($color)) . '">'
              . '<title>' . e((string) ($item['label'] ?? '')) . ': ' . e($valuePrefix . num($value)) . '</title>'
              . '</rect>';

        if ($showValues) {
            $svg .= '<text class="chart-value" style="animation-delay:' . ($delay + 120) . 'ms"'
                  . ' x="' . round($x + ($barW / 2), 1) . '" y="' . round(max(12, $y - 7), 1)
                  . '" text-anchor="middle" font-size="10.5" font-weight="700" fill="#282d35">'
                  . e(chart_money($value)) . '</text>';
        }

        if ($index % $grid === 0) {
            $label = (string) ($item['label'] ?? '');

            $svg .= '<text x="' . round($x + ($barW / 2), 1) . '" y="' . ($height - $padBottom + 18)
                  . '" text-anchor="middle" font-size="9.5" fill="#5f6773">' . e($label) . '</text>';
        }
    }

    $svg .= '</svg>';

    return $svg;
}

/**
 * Donut chart with a legend.
 *
 * Each segment: ['label' => string, 'value' => int|float, 'color' => '#RRGGBB'].
 */
function svg_donut_chart(array $segments, array $options = []): string
{
    $size   = (int) ($options['size'] ?? 190);
    $stroke = (int) ($options['stroke'] ?? 26);
    $center = $size / 2;
    $radius = ($size - $stroke) / 2;
    $circ   = 2 * M_PI * $radius;

    $total = 0.0;
    foreach ($segments as $segment) {
        $total += max(0.0, (float) ($segment['value'] ?? 0));
    }

    $title = (string) ($options['title'] ?? t('Distribution'));

    $svg = '<svg class="chart-svg chart-donut" viewBox="0 0 ' . $size . ' ' . $size
         . '" role="img" aria-label="' . e($title) . '">'
         . '<circle cx="' . $center . '" cy="' . $center . '" r="' . $radius
         . '" fill="none" stroke="#edf1f7" stroke-width="' . $stroke . '"/>';

    if ($total > 0) {
        $offset       = $circ * 0.25; // start at 12 o'clock
        $segmentIndex = 0;

        foreach ($segments as $segment) {
            $value = max(0.0, (float) ($segment['value'] ?? 0));

            if ($value <= 0) {
                continue;
            }

            $length = ($value / $total) * $circ;
            $color  = (string) ($segment['color'] ?? '#c3cee0');

            $delay = 220 + ($segmentIndex * 170);
            $segmentIndex++;

            $svg .= '<circle class="chart-donut-seg" cx="' . $center . '" cy="' . $center . '" r="' . $radius
                  . '" fill="none" stroke="' . e($color) . '" stroke-width="' . $stroke
                  . '" stroke-dasharray="' . round($length, 2) . ' ' . round($circ - $length, 2)
                  . '" stroke-dashoffset="' . round($offset, 2) . '" stroke-linecap="butt"'
                  . ' style="--seg-len:' . round($length, 2) . ';--seg-gap:' . round($circ - $length, 2)
                  . ';--seg-circ:' . round($circ, 2) . ';animation-delay:' . $delay . 'ms">'
                  . '<title>' . e((string) ($segment['label'] ?? '')) . ': ' . e(num($value))
                  . ' (' . e(percent($value, $total)) . '%)</title></circle>';

            $offset -= $length;
        }
    }

    $svg .= '<text class="chart-donut-total" x="' . $center . '" y="' . ($center - 3) . '" text-anchor="middle"'
          . ' font-size="24" font-weight="800" fill="#111111">' . e(num($total)) . '</text>'
          . '<text x="' . $center . '" y="' . ($center + 17) . '" text-anchor="middle"'
          . ' font-size="9.5" fill="#5f6773" letter-spacing=".08em">' . e(strtoupper(t('loans'))) . '</text>'
          . '</svg>';

    $legend = '<div class="chart-legend">';

    foreach ($segments as $segment) {
        $value = max(0.0, (float) ($segment['value'] ?? 0));

        $legend .= '<span class="chart-legend-item">'
                 . '<span class="chart-dot" style="background:' . e((string) ($segment['color'] ?? '#c3cee0')) . '"></span>'
                 . '<span>' . e((string) ($segment['label'] ?? '')) . '</span>'
                 . '<strong>' . e(num($value)) . '</strong>'
                 . '</span>';
    }

    $legend .= '</div>';

    return '<div class="chart-donut-wrap">' . $svg . $legend . '</div>';
}
