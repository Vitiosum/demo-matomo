<?php

declare(strict_types=1);

namespace Museum;

/**
 * The collection: one entrance hall, five rooms, one painting per room.
 * Page titles and painting titles are what Matomo stores, so they stay plain ASCII.
 */
final class Museum
{
    public const NAME = 'The Small Museum';
    public const HALL_TITLE = 'Entrance hall';
    public const OFFICE_TITLE = "Curator's office";

    /** Matomo custom event sent when a visitor stays in front of a painting. */
    public const EVENT_CATEGORY = 'Painting';
    public const EVENT_ACTION = 'Lingered';
    public const LINGER_SECONDS = 8;

    public const ROOMS = [
        1 => [
            'theme' => 'Order',
            'title' => 'Composition for Five Doors',
            'seed' => 1001,
            'artist' => 'The Subdivider',
            'born' => 'recursive function, PHP',
            'medium' => 'Rectangles and black lines, SVG',
            'size' => [880, 1100],
            'text' => 'A rectangle is cut in two, then each half again, until chance says stop. The black lines are the record of every decision the program took.',
            'wall' => 'Every cut is a choice.',
        ],
        2 => [
            'theme' => 'Flow',
            'title' => 'Wind over Water',
            'seed' => 1002,
            'artist' => 'A Perlin Field',
            'born' => 'gradient noise, PHP',
            'medium' => 'Streamlines in ink, SVG',
            'size' => [1200, 860],
            'text' => 'Hundreds of particles follow a wind made of noise. Where the field turns, the ink gathers. Where it runs straight, the paper breathes.',
            'wall' => 'An invisible wind, made visible.',
        ],
        3 => [
            'theme' => 'Paths',
            'title' => 'Meanders',
            'seed' => 137,
            'artist' => 'Truchet Tiling',
            'born' => 'after Sébastien Truchet, 1704',
            'medium' => 'Quarter circles on square tiles, SVG',
            'size' => [960, 960],
            'text' => 'Each tile holds two quarter circles, turned one way or the other at random. The lines join their neighbours and wander off like visitors between rooms. The longest walk is drawn in blue.',
            'wall' => 'Where do the lines go?',
        ],
        4 => [
            'theme' => 'Colour',
            'title' => 'Four Squares at Dusk',
            'seed' => 1004,
            'artist' => 'Nested Squares',
            'born' => 'four colours, PHP',
            'medium' => 'Flat colour with paper grain, SVG',
            'size' => [1000, 1000],
            'text' => 'Four squares, nested and set slightly low, in the colours of the minutes after sunset. Stay a little: the edges start to vibrate.',
            'wall' => 'Colour changes its neighbour.',
        ],
        5 => [
            'theme' => 'Horizon',
            'title' => 'Seven Ridges at First Light',
            'seed' => 1005,
            'artist' => 'Layered Noise',
            'born' => 'one-dimensional noise, PHP',
            'medium' => 'Seven silhouettes and a sun, SVG',
            'size' => [1200, 800],
            'text' => 'Seven lines of noise stacked from the far valley to the nearest slope. Each ridge is paler than the one in front of it, as mountains are at dawn.',
            'wall' => 'Distance is a colour.',
        ],
    ];

    /** The suggested tour: hall, then rooms 1 to 5, then back to the hall. */
    public static function next(int $room): int
    {
        return $room >= 5 ? 0 : $room + 1;
    }

    public static function previous(int $room): int
    {
        return $room <= 1 ? 0 : $room - 1;
    }

    /** Title tracked by Matomo for a page view (0 is the hall). */
    public static function pageTitle(int $room): string
    {
        return $room === 0 ? self::HALL_TITLE : "Room $room - " . self::ROOMS[$room]['theme'];
    }

    public static function path(int $room): string
    {
        return $room === 0 ? '/' : "/room/$room";
    }

    /** Room number for a tracked page title, or null when the title is not a gallery. */
    public static function roomForTitle(string $title): ?int
    {
        if ($title === self::HALL_TITLE) {
            return 0;
        }
        foreach (array_keys(self::ROOMS) as $n) {
            if ($title === self::pageTitle($n)) {
                return $n;
            }
        }
        return null;
    }

    public static function roomForPainting(string $title): ?int
    {
        foreach (self::ROOMS as $n => $room) {
            if ($room['title'] === $title) {
                return $n;
            }
        }
        return null;
    }
}
