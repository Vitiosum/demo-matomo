<?php

declare(strict_types=1);

namespace Museum;

/**
 * The museum's floor plan as an architectural SVG drawing.
 * The same drawing is the hall's map, the room's "you are here" plan and the curator's flow chart.
 */
final class FloorPlan
{
    /** Room rectangles [x, y, width, height]; 0 is the entrance hall. */
    private const ROOMS = [
        0 => [340, 300, 280, 300],
        1 => [40, 300, 300, 300],
        2 => [40, 40, 300, 260],
        3 => [340, 40, 280, 260],
        4 => [620, 40, 300, 260],
        5 => [620, 300, 300, 300],
    ];

    /** Door openings on the walls [x1, y1, x2, y2]. */
    private const DOORS = [
        [340, 500, 340, 560], [620, 500, 620, 560], [450, 300, 510, 300], [160, 300, 220, 300],
        [340, 215, 340, 275], [620, 215, 620, 275], [740, 300, 800, 300], [450, 600, 510, 600],
    ];

    /** Where each painting hangs [x1, y1, x2, y2], and which way its red dots run [dx, dy]. */
    private const HANGING = [
        1 => [[49, 395, 49, 505], [18, 0]],
        2 => [[120, 49, 260, 49], [0, 18]],
        3 => [[410, 49, 550, 49], [0, 18]],
        4 => [[700, 49, 840, 49], [0, 18]],
        5 => [[911, 395, 911, 505], [-18, 0]],
    ];

    /**
     * @param array{current?: ?int, links?: bool, compact?: bool, flows?: list<array{from:int,to:int,count:int}>,
     *              dots?: array<int,int>, footfall?: array<int,int>, entries?: int, label?: string} $o
     */
    public static function svg(array $o = []): string
    {
        $current = $o['current'] ?? null;
        $compact = $o['compact'] ?? false;
        $class = 'plan' . ($compact ? ' plan--compact' : '');
        $label = h($o['label'] ?? 'Floor plan of the museum');
        $s = "<svg class=\"$class\" viewBox=\"0 0 960 660\" role=\"img\" aria-label=\"$label\" xmlns=\"http://www.w3.org/2000/svg\">";
        $s .= '<defs><marker id="arrow" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="4" markerHeight="4" orient="auto-start-reverse">'
            . '<path d="M0 0L10 5L0 10z" class="plan__arrow"/></marker></defs>';
        $s .= '<rect x="40" y="40" width="880" height="560" class="plan__floor"/>';

        foreach (self::ROOMS as $room => [$x, $y, $w, $hgt]) {
            $here = $room === $current ? ' is-here' : '';
            $shape = "<rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$hgt\" class=\"plan__room$here\"/>";
            if (!empty($o['links']) && $room !== $current) {
                $name = h($room === 0 ? 'Entrance hall' : "Room $room, " . Museum::ROOMS[$room]['theme']);
                $shape = '<a href="' . Museum::path($room) . "\" aria-label=\"$name\">$shape</a>";
            }
            $s .= $shape;
        }

        // walls first, then the door openings are cut out of them with the floor colour
        $s .= '<g class="plan__walls"><rect x="40" y="40" width="880" height="560"/>'
            . '<path d="M340 40V600M620 40V600M40 300H920"/></g>';
        $s .= '<g class="plan__doors">';
        foreach (self::DOORS as [$x1, $y1, $x2, $y2]) {
            $s .= "<line x1=\"$x1\" y1=\"$y1\" x2=\"$x2\" y2=\"$y2\" class=\"plan__gap\"/>";
            if (!$compact && empty($o['flows'])) {
                $s .= $x1 === $x2
                    ? "<path d=\"M{$x1} {$y1}h56A56 56 0 0 1 {$x2} {$y2}\" class=\"plan__swing\"/>"
                    : "<path d=\"M{$x1} {$y1}v-56A56 56 0 0 1 {$x2} {$y2}\" class=\"plan__swing\"/>";
            }
        }
        $s .= '</g>';

        foreach (self::HANGING as [[$x1, $y1, $x2, $y2]]) {
            $s .= "<line x1=\"$x1\" y1=\"$y1\" x2=\"$x2\" y2=\"$y2\" class=\"plan__painting\"/>";
        }

        $s .= self::labels($compact, $o['footfall'] ?? null, !empty($o['flows']));
        if (!$compact) {
            $entrance = isset($o['footfall']) ? 'Entrance hall: ' . self::visits($o['footfall'][0]) : 'Entrance';
            $s .= '<text x="480" y="640" text-anchor="middle" class="plan__entrance">' . h($entrance) . '</text>';
        }
        if (!empty($o['flows'])) {
            $s .= self::flows($o['flows']);
        }
        if (!empty($o['dots'])) {
            $s .= self::dots($o['dots']);
        }
        if ($current !== null) {
            [$x, $y, $w, $hgt] = self::ROOMS[$current];
            [$cx, $cy] = [$x + $w / 2, $y + $hgt / 2 + 22];
            $s .= "<circle cx=\"$cx\" cy=\"$cy\" r=\"22\" class=\"plan__here-ring\"/><circle cx=\"$cx\" cy=\"$cy\" r=\"9\" class=\"plan__here\"/>";
            if (!$compact) {
                $s .= '<text x="' . $cx . '" y="' . ($cy + 48) . '" text-anchor="middle" class="plan__here-label">You are here</text>';
            }
        }
        return $s . '</svg>';
    }

    /** Where flow arrows meet a room: away from its label, which sits in the top-left corner. */
    private static function anchor(int $room): array
    {
        [$x, $y, $w, $hgt] = self::ROOMS[$room];
        $across = match ($x) { 40 => 0.6, 340 => 0.5, default => 0.62 };
        return [$x + $w * $across, $y + $hgt * ($y === 40 ? 0.62 : 0.66)];
    }

    private static function labels(bool $compact, ?array $footfall, bool $quiet): string
    {
        $s = '';
        foreach (self::ROOMS as $room => [$x, $y]) {
            $tx = $x + 22;
            if ($room === 0) {
                // with data on the plan, the hall's name and count move under the entrance, out of the arrows' way
                $s .= $compact || $quiet ? '' : "<text x=\"$tx\" y=\"" . ($y + 40) . '" class="plan__theme">Entrance hall</text>';
                continue;
            }
            $s .= "<text x=\"$tx\" y=\"" . ($y + 74) . "\" class=\"plan__number\">$room</text>";
            if (!$compact) {
                $s .= "<text x=\"$tx\" y=\"" . ($y + 100) . '" class="plan__theme">' . h(Museum::ROOMS[$room]['theme']) . '</text>';
                $s .= $quiet ? '' : "<text x=\"$tx\" y=\"" . ($y + 120) . '" class="plan__title">' . h(Museum::ROOMS[$room]['title']) . '</text>';
            }
            if ($footfall !== null) {
                $s .= "<text x=\"$tx\" y=\"" . ($y + 124) . '" class="plan__count">' . h(self::visits($footfall[$room])) . '</text>';
            }
        }
        return $s;
    }

    private static function visits(int $n): string
    {
        return $n === 1 ? '1 page view' : number_format($n) . ' page views';
    }

    /** One curved arrow per room-to-room move; the thicker, the more visitors took it. */
    private static function flows(array $flows): string
    {
        $max = max(array_column($flows, 'count')) ?: 1;
        $s = '<g class="plan__flows">';
        foreach ($flows as $f) {
            [$ax, $ay] = self::anchor($f['from']);
            [$bx, $by] = self::anchor($f['to']);
            $len = hypot($bx - $ax, $by - $ay) ?: 1;
            // control point on the left of the direction of travel, so A→B and B→A never overlap
            $cx = ($ax + $bx) / 2 - ($by - $ay) / $len * 34;
            $cy = ($ay + $by) / 2 + ($bx - $ax) / $len * 34;
            // leave and reach each room a little off its centre, so arrows and arrowheads stay readable
            $sx = $ax + ($cx - $ax) / hypot($cx - $ax, $cy - $ay) * 26;
            $sy = $ay + ($cy - $ay) / hypot($cx - $ax, $cy - $ay) * 26;
            $ex = $bx - ($bx - $cx) / hypot($bx - $cx, $by - $cy) * 34;
            $ey = $by - ($by - $cy) / hypot($bx - $cx, $by - $cy) * 34;
            $width = round(2 + 7 * $f['count'] / $max, 1);
            $s .= sprintf('<path d="M%.0f %.0fQ%.0f %.0f %.0f %.0f" stroke-width="%s" marker-end="url(#arrow)"/>', $sx, $sy, $cx, $cy, $ex, $ey, $width);
            $s .= sprintf('<text x="%.0f" y="%.0f" text-anchor="middle" class="plan__flow-count">%d</text>', ($ax + 2 * $cx + $bx) / 4, ($ay + 2 * $cy + $by) / 4 + 5, $f['count']);
        }
        return $s . '</g>';
    }

    /** Red dot stickers next to each painting, one per "lingered" event (ten at most, then a number). */
    private static function dots(array $dots): string
    {
        $s = '<g class="plan__dots">';
        foreach (self::HANGING as $room => [[$x1, $y1], [$dx, $dy]]) {
            $n = $dots[$room] ?? 0;
            $shown = min($n, 10);
            $ox = $dy !== 0 ? 0 : $dx;                  // first dot sits off the wall, then the row runs along it
            for ($i = 0; $i < $shown; $i++) {
                $cx = $x1 + $ox + ($dy !== 0 ? $i * 15 + 8 : 0);
                $cy = $y1 + $dy + ($dx !== 0 ? $i * 15 + 8 : 0);
                $s .= "<circle cx=\"$cx\" cy=\"$cy\" r=\"6\"/>";
            }
            if ($n > 10) {
                $s .= '<text x="' . ($x1 + $ox + ($dy !== 0 ? 160 : 0)) . '" y="' . ($y1 + $dy + ($dx !== 0 ? 168 : 4)) . "\" class=\"plan__dots-more\">+" . ($n - 10) . '</text>';
            }
        }
        return $s . '</g>';
    }
}
