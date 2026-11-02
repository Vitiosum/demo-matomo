<?php

declare(strict_types=1);

namespace Museum;

/**
 * Draws the five paintings as SVG. Everything is deterministic: same room, same seed, same painting.
 * Each method returns ['svg' => string, 'marks' => int, 'unit' => string] so the cartel can say what was drawn.
 */
final class Painter
{
    public static function paint(int $room): array
    {
        [$w, $h] = Museum::ROOMS[$room]['size'];
        $rng = new Rng(Museum::ROOMS[$room]['seed']);
        [$body, $marks, $unit] = match ($room) {
            1 => self::subdivision($rng, $w, $h),
            2 => self::flowField($rng, $w, $h),
            3 => self::truchet($rng, $w, $h),
            4 => self::nestedSquares($w, $h),
            5 => self::ridges($rng, $w, $h),
        };
        $title = htmlspecialchars(Museum::ROOMS[$room]['title']);
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 $w $h\" width=\"$w\" height=\"$h\" role=\"img\">"
            . "<title>$title</title>$body</svg>";
        return ['svg' => $svg, 'marks' => $marks, 'unit' => $unit];
    }

    /** Room 1: recursive subdivision, a few colour fields, heavy black lines. */
    private static function subdivision(Rng $rng, int $w, int $h): array
    {
        $leaves = [];
        $split = function (float $x, float $y, float $rw, float $rh, int $depth) use (&$split, &$leaves, $rng): void {
            $stop = $depth >= 5 || ($depth >= 2 && $rng->next() < 0.28) || min($rw, $rh) < 120;
            if ($stop) {
                $leaves[] = [$x, $y, $rw, $rh];
                return;
            }
            $vertical = $rw > $rh * 1.15 ? true : ($rh > $rw * 1.15 ? false : $rng->next() < 0.5);
            $ratio = round((0.3 + $rng->next() * 0.4) * 8) / 8; // cuts snap to eighths, like a drawn grid
            if ($vertical) {
                $split($x, $y, $rw * $ratio, $rh, $depth + 1);
                $split($x + $rw * $ratio, $y, $rw * (1 - $ratio), $rh, $depth + 1);
            } else {
                $split($x, $y, $rw, $rh * $ratio, $depth + 1);
                $split($x, $y + $rh * $ratio, $rw, $rh * (1 - $ratio), $depth + 1);
            }
        };
        $split(0, 0, $w, $h, 0);

        $paper = '#efece4';
        $colours = ['#d6402a', '#e4a72f', '#23427c', '#1b1b1d'];
        $fills = array_fill(0, count($leaves), $paper);
        // give each colour to one of the larger fields, then sprinkle a few more
        $bySize = array_keys($leaves);
        usort($bySize, fn ($a, $b) => $leaves[$b][2] * $leaves[$b][3] <=> $leaves[$a][2] * $leaves[$a][3]);
        foreach ($colours as $i => $c) {
            $fills[$bySize[1 + $i * 2] ?? 0] = $c;
        }
        foreach ($fills as $i => $f) {
            if ($f === $paper && $rng->next() < 0.12) {
                $fills[$i] = $colours[(int) ($rng->next() * 3)];
            }
        }
        $out = "<rect width=\"$w\" height=\"$h\" fill=\"#1b1b1d\"/>";
        foreach ($leaves as $i => [$x, $y, $rw, $rh]) {
            $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $x + 6, $y + 6, $rw - 12, $rh - 12, $fills[$i]);
        }
        return [$out, count($leaves), 'fields'];
    }

    /** Room 2: streamlines following a Perlin noise field. */
    private static function flowField(Rng $rng, int $w, int $h): array
    {
        $noise = new Noise($rng);
        $inks = ['#1d2b53', '#1d2b53', '#2e4a8b', '#2e4a8b', '#5f7fbf', '#9fb4dc'];
        $out = "<rect width=\"$w\" height=\"$h\" fill=\"#eef0ef\"/>";
        $lines = 0;
        for ($i = 0; $i < 620; $i++) {
            $x = $rng->next() * $w;
            $y = $rng->next() * $h;
            $points = [];
            for ($s = 0; $s < 34; $s++) {
                $points[] = (int) $x . ',' . (int) $y;
                $angle = $noise->at($x * 0.0018, $y * 0.0018) * M_PI * 2.4;
                $x += cos($angle) * 8;
                $y += sin($angle) * 8;
                if ($x < 0 || $y < 0 || $x > $w || $y > $h) {
                    break;
                }
            }
            if (count($points) < 6) {
                continue;
            }
            $accent = $rng->next() < 0.035;
            $ink = $accent ? '#d6402a' : $inks[(int) ($rng->next() * count($inks))];
            $width = $accent ? 2.6 : 0.8 + $rng->next() * 2.6;
            $out .= sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="%.1f" stroke-linecap="round" stroke-opacity="%.2f"/>', implode(' ', $points), $ink, $width, 0.6 + $rng->next() * 0.4);
            $lines++;
        }
        return [$out, $lines, 'streamlines'];
    }

    /** Room 3: Truchet tiles; the longest connected path is drawn in blue. */
    private static function truchet(Rng $rng, int $w, int $h): array
    {
        $n = 12;
        $t = $w / $n;
        $r = $t / 2;
        // A tile edge midpoint is shared by two tiles: give each one a stable id, then union the two ends of every arc.
        $parent = [];
        $find = function (string $a) use (&$parent, &$find): string {
            $parent[$a] ??= $a;
            return $parent[$a] === $a ? $a : ($parent[$a] = $find($parent[$a]));
        };
        $arcs = [];
        for ($row = 0; $row < $n; $row++) {
            for ($col = 0; $col < $n; $col++) {
                $top = "h$row,$col";
                $bottom = 'h' . ($row + 1) . ",$col";
                $left = "v$row,$col";
                $right = "v$row," . ($col + 1);
                $x = $col * $t;
                $y = $row * $t;
                if ($rng->next() < 0.5) { // arcs around the top-left and bottom-right corners
                    $arcs[] = [[$top, $left], sprintf('M%.1f %.1fA%.1f %.1f 0 0 1 %.1f %.1f', $x + $r, $y, $r, $r, $x, $y + $r)];
                    $arcs[] = [[$bottom, $right], sprintf('M%.1f %.1fA%.1f %.1f 0 0 1 %.1f %.1f', $x + $r, $y + $t, $r, $r, $x + $t, $y + $r)];
                } else { // around the top-right and bottom-left corners
                    $arcs[] = [[$top, $right], sprintf('M%.1f %.1fA%.1f %.1f 0 0 0 %.1f %.1f', $x + $r, $y, $r, $r, $x + $t, $y + $r)];
                    $arcs[] = [[$bottom, $left], sprintf('M%.1f %.1fA%.1f %.1f 0 0 0 %.1f %.1f', $x + $r, $y + $t, $r, $r, $x, $y + $r)];
                }
            }
        }
        foreach ($arcs as [[$a, $b]]) {
            $parent[$find($a)] = $find($b);
        }
        $sizes = [];
        foreach ($arcs as [[$a]]) {
            $root = $find($a);
            $sizes[$root] = ($sizes[$root] ?? 0) + 1;
        }
        arsort($sizes);
        $longest = array_key_first($sizes);

        $plain = '';
        $walk = '';
        foreach ($arcs as [[$a], $d]) {
            if ($find($a) === $longest) {
                $walk .= $d;
            } else {
                $plain .= $d;
            }
        }
        $out = "<rect width=\"$w\" height=\"$h\" fill=\"#f1efe9\"/>"
            . "<path d=\"$plain\" fill=\"none\" stroke=\"#22283a\" stroke-width=\"15\"/>"
            . "<path d=\"$plain\" fill=\"none\" stroke=\"#f1efe9\" stroke-width=\"5\"/>"
            . "<path d=\"$walk\" fill=\"none\" stroke=\"#2443c4\" stroke-width=\"15\"/>"
            . "<path d=\"$walk\" fill=\"none\" stroke=\"#f1efe9\" stroke-width=\"5\"/>";
        return [$out, count($arcs), 'quarter circles'];
    }

    /** Room 4: four nested squares, set low, with a light paper grain. */
    private static function nestedSquares(int $w, int $h): array
    {
        $colours = ['#262a52', '#5b4a86', '#c4637a', '#f0a868'];
        $out = '<defs><filter id="grain"><feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" seed="4"/>'
            . '<feColorMatrix values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 0.09 0"/><feComposite in2="SourceGraphic" operator="in"/></filter></defs>';
        $unit = $w / 10;
        foreach ($colours as $i => $c) {
            $side = $w - $i * 2 * $unit;
            $x = $i * $unit;
            $y = $i * $unit * 1.5; // three times more space above than below, as in the classic studies
            $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $x, $y, $side, $side, $c);
        }
        $out .= "<rect width=\"$w\" height=\"$h\" fill=\"#fff\" filter=\"url(#grain)\"/>";
        return [$out, 4, 'squares'];
    }

    /** Room 5: seven noise ridges, far to near, with a low sun. */
    private static function ridges(Rng $rng, int $w, int $h): array
    {
        $noise = new Noise($rng);
        $out = '<defs><linearGradient id="dawn" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#b8c6d8"/><stop offset="0.55" stop-color="#ead8cc"/><stop offset="1" stop-color="#f5dcc6"/>'
            . "</linearGradient></defs><rect width=\"$w\" height=\"$h\" fill=\"url(#dawn)\"/>"
            . sprintf('<circle cx="%d" cy="%d" r="%d" fill="#ef9f82" fill-opacity="0.92"/>', (int) ($w * 0.63), (int) ($h * 0.36), (int) ($h * 0.085));
        $far = [199, 204, 216];
        $near = [30, 42, 68];
        $vertices = 0;
        for ($i = 0; $i < 7; $i++) {
            $k = $i / 6;
            $rgb = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $k), $far, $near);
            $base = $h * (0.44 + $i * 0.07);
            $amp = $h * (0.05 + $i * 0.022); // near ridges rise higher than the far ones
            $freq = 0.0042 - $i * 0.00035;   // and their shapes are broader
            $points = ["0,$h"];
            for ($x = 0; $x <= $w; $x += 12) {
                $y = $base - $amp * (0.75 * $noise->at($x * $freq + $i * 7.3, $i * 1.7) + 0.25 * $noise->at($x * $freq * 4, $i * 3.1 + 10)) * 1.6;
                $points[] = $x . ',' . (int) $y;
                $vertices++;
            }
            $points[] = "$w,$h";
            $out .= sprintf('<polygon points="%s" fill="rgb(%d,%d,%d)"/>', implode(' ', $points), ...$rgb);
        }
        return [$out, $vertices, 'ridge vertices'];
    }
}

/** xorshift32: tiny, deterministic, good enough for art. */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 1;
    }

    public function next(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x;
        return $x / 4294967296;
    }
}

/** Classic 2D Perlin noise, values roughly in [-1, 1]. */
final class Noise
{
    /** @var int[] */
    private array $p;

    public function __construct(Rng $rng)
    {
        $p = range(0, 255);
        for ($i = 255; $i > 0; $i--) {
            $j = (int) ($rng->next() * ($i + 1));
            [$p[$i], $p[$j]] = [$p[$j], $p[$i]];
        }
        $this->p = array_merge($p, $p);
    }

    public function at(float $x, float $y): float
    {
        $xi = (int) floor($x) & 255;
        $yi = (int) floor($y) & 255;
        $x -= floor($x);
        $y -= floor($y);
        $u = $x * $x * $x * ($x * ($x * 6 - 15) + 10);
        $v = $y * $y * $y * ($y * ($y * 6 - 15) + 10);
        $p = $this->p;
        $a = $p[$xi] + $yi;
        $b = $p[$xi + 1] + $yi;
        $lerp = fn ($t, $m, $n) => $m + $t * ($n - $m);
        return $lerp(
            $v,
            $lerp($u, self::grad($p[$a], $x, $y), self::grad($p[$b], $x - 1, $y)),
            $lerp($u, self::grad($p[$a + 1], $x, $y - 1), self::grad($p[$b + 1], $x - 1, $y - 1)),
        );
    }

    private static function grad(int $hash, float $x, float $y): float
    {
        return match ($hash & 3) {
            0 => $x + $y,
            1 => -$x + $y,
            2 => $x - $y,
            default => -$x - $y,
        };
    }
}
