<?php

declare(strict_types=1);

namespace Museum;

/**
 * Idempotent start-up seed, run by CC_PRE_RUN_HOOK (bin/seed.php):
 *  1. find the "The Small Museum" site in Matomo, or create it (Europe/Paris, no e-commerce);
 *  2. when the site is new, send the opening night: seven visitors walking the rooms,
 *     tracked server-side in one bulk request, so the curator's office is never empty.
 * The site id is cached in var/site.json for the web workers.
 */
final class Seed
{
    /** Opening night: the rooms each visitor walked through, and the paintings they lingered in front of. */
    public const VERNISSAGE = [
        [[0, 1, 2, 3, 4, 5], [3, 5]],
        [[0, 1, 3, 5], [3]],
        [[0, 1, 2, 3], [2, 3]],
        [[0, 5, 4, 3], [4, 3]],
        [[0, 1, 2, 3, 4], [3]],
        [[0, 3, 4, 5], [5]],
        [[0, 5], [5]],
    ];

    private const AGENTS = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:143.0) Gecko/20100101 Firefox/143.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
    ];

    public static function cacheFile(): string
    {
        return dirname(__DIR__) . '/var/site.json';
    }

    /** Site id for tracking: MATOMO_SITE_ID, else the one found or created by the seed, else 1. */
    public static function siteId(): int
    {
        $forced = (int) getenv('MATOMO_SITE_ID');
        if ($forced > 0) {
            return $forced;
        }
        $cached = json_decode((string) @file_get_contents(self::cacheFile()), true);
        return (int) ($cached['siteId'] ?? 1) ?: 1;
    }

    /** @return array{siteId: int, created: bool, vernissage: ?array} */
    public static function run(Matomo $matomo, string $siteUrl, bool $openingNight): array
    {
        $forced = (int) getenv('MATOMO_SITE_ID');
        $siteId = $forced > 0 ? $forced : null;
        $created = false;

        if ($siteId === null) {
            foreach ($matomo->api('SitesManager.getPatternMatchSites', ['pattern' => Museum::NAME, 'limit' => 50]) as $site) {
                if ($site['name'] === Museum::NAME) {
                    $siteId = (int) $site['idsite'];
                    break;
                }
            }
        }
        if ($siteId === null) {
            $siteId = (int) $matomo->api('SitesManager.addSite', [
                'siteName' => Museum::NAME,
                'urls' => [$siteUrl],
                'ecommerce' => 0,
                'siteSearch' => 0,
                'timezone' => 'Europe/Paris',
                'currency' => 'EUR',
            ]);
            $created = true;
        }
        @mkdir(dirname(self::cacheFile()), 0775, true);
        file_put_contents(self::cacheFile(), json_encode(['siteId' => $siteId, 'at' => gmdate('c')]), LOCK_EX);

        $vernissage = $created && $openingNight ? $matomo->trackBulk(self::openingNight($siteId, $siteUrl, time())) : null;
        return ['siteId' => $siteId, 'created' => $created, 'vernissage' => $vernissage];
    }

    /** Builds the opening-night hits: page views, "Lingered" events and a closing heartbeat, back-dated over the last hour. */
    public static function openingNight(int $siteId, string $siteUrl, int $now): array
    {
        $requests = [];
        foreach (self::VERNISSAGE as $i => [$rooms, $lingered]) {
            $visitor = substr(hash('sha256', "vernissage-$i"), 0, 16);
            $time = $now - 3600 + $i * 420;
            $previousUrl = '';
            foreach ($rooms as $step => $room) {
                $url = rtrim($siteUrl, '/') . Museum::path($room);
                $common = [
                    'idsite' => $siteId, 'rec' => 1, 'apiv' => 1, 'cid' => $visitor,
                    'cip' => '192.0.2.' . (10 + $i), // documentation range: anonymised by Matomo anyway
                    'ua' => self::AGENTS[$i % count(self::AGENTS)], 'lang' => 'fr-FR,fr;q=0.9,en;q=0.6',
                    'url' => $url, 'pv_id' => substr($visitor, $step, 6),
                ];
                $requests[] = '?' . http_build_query($common + [
                    'action_name' => Museum::pageTitle($room),
                    'urlref' => $previousUrl,
                    'new_visit' => $step === 0 ? 1 : 0,
                    'cdt' => $time,
                ]);
                if (in_array($room, $lingered, true)) {
                    $requests[] = '?' . http_build_query($common + [
                        'e_c' => Museum::EVENT_CATEGORY, 'e_a' => Museum::EVENT_ACTION,
                        'e_n' => Museum::ROOMS[$room]['title'], 'e_v' => Museum::LINGER_SECONDS,
                        'cdt' => $time + Museum::LINGER_SECONDS,
                    ]);
                }
                $time += $room === 0 ? 25 : (in_array($room, $lingered, true) ? 75 : 35) + ($i * 7 + $step * 11) % 30;
                $previousUrl = $url;
            }
            $requests[] = '?' . http_build_query($common + ['ping' => 1, 'cdt' => $time]); // time spent on the last room
        }
        return $requests;
    }
}
