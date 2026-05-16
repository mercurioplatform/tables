@props([
    'values' => [],
    'width' => 72,
    'height' => 22,
    'color' => 'var(--tables-kpi-delta-good)',
    'filled' => false,
])

@php
    $points = '';
    $pathD  = '';
    $areaD  = '';
    if (!empty($values)) {
        $count = count($values);
        $min = min($values);
        $max = max($values);
        $range = max($max - $min, 1);
        $stepX = $count > 1 ? ($width / ($count - 1)) : $width;
        $coords = [];
        foreach (array_values($values) as $i => $v) {
            $x = round($i * $stepX, 2);
            $y = round($height - (($v - $min) / $range) * ($height - 4) - 2, 2);
            $coords[] = [$x, $y];
        }
        $pathD = 'M ' . implode(' L ', array_map(fn ($c) => "{$c[0]} {$c[1]}", $coords));
        if ($filled) {
            $first = $coords[0];
            $last  = end($coords);
            $areaD = "M {$first[0]} {$height} L " . implode(' L ', array_map(fn ($c) => "{$c[0]} {$c[1]}", $coords)) . " L {$last[0]} {$height} Z";
        }
    }
@endphp
<svg class="tables-kpi__spark" width="{{ $width }}" height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    @if($filled && $areaD)
        <path d="{{ $areaD }}" fill="{{ $color }}" fill-opacity="0.12" stroke="none"/>
    @endif
    @if($pathD)
        <path d="{{ $pathD }}" fill="none" stroke="{{ $color }}" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
    @endif
</svg>
