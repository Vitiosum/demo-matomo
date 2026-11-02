<?php

declare(strict_types=1);

namespace Museum;

/**
 * The curator's office: everything it shows comes from one Reporting API bulk request.
 * Only aggregated reports are used (no visits log), so the office keeps working when
 * Matomo's CNIL compliance mode is enforced.
 */
final class Curator
{
    private const CACHE_SECONDS = 10;

    private static function cacheFile(int $siteId): string
    {
        return sys_get_temp_dir() . "/small-museum-office-$siteId.json";
    }

    /** The office data from its last refresh (no network), if it is less than an hour old. Used by the rooms. */
    public static function cached(int $siteId): ?array
    {
        $cache = self::cacheFile($siteId);
        if (!is_file($cache) || filemtime($cache) < time() - 3600) {
            return null;
        }
        $data = json_decode((string) file_get_contents($cache), true);
        return is_array($data) ? $data : null;
    }

    public static function gather(Matomo $matomo, int $siteId): array
    {
        $cache = self::cacheFile($siteId);
        if (is_file($cache) && filemtime($cache) > time() - self::CACHE_SECONDS) {
            $cached = json_decode((string) file_get_contents($cache), true);
            if (is_array($cached)) {
                return $cached + ['cached' => true];
            }
        }

        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $sinceMidnight = max(1, intdiv($paris->getTimestamp() - $paris->setTime(0, 0)->getTimestamp(), 60));
        $week = ['idSite' => $siteId, 'period' => 'range', 'date' => 'last7'];

        $calls = [
            'version' => ['API.getMatomoVersion', []],
            'today' => ['Live.getCounters', ['idSite' => $siteId, 'lastMinutes' => $sinceMidnight]],
            'now' => ['Live.getCounters', ['idSite' => $siteId, 'lastMinutes' => 30]],
            'days' => ['VisitsSummary.get', ['idSite' => $siteId, 'period' => 'day', 'date' => 'last7', 'showColumns' => 'nb_visits,avg_time_on_site']],
            'events' => ['Events.getName', $week + ['flat' => 1]],
            'titles' => ['Actions.getPageTitles', $week + ['flat' => 1, 'showColumns' => 'nb_hits']],
            'cnil' => ['PrivacyManager.getComplianceStatus', ['idSite' => $siteId, 'complianceType' => 'cnil_v1']],
        ];
        foreach ([0, 1, 2, 3, 4, 5] as $room) {
            $calls["from$room"] = ['Transitions.getTransitionsForPageTitle', $week + ['pageTitle' => Museum::pageTitle($room), 'limitBeforeGrouping' => 20]];
        }

        $results = array_combine(array_keys($calls), $matomo->bulk(array_values($calls)));
        $errors = [];
        foreach ($results as $key => $value) {
            if ($value instanceof MatomoError) {
                $errors[$key] = $value->getMessage();
                $results[$key] = null;
            }
        }

        $data = [
            'siteId' => $siteId,
            'version' => is_string($results['version']) ? $results['version'] : null,
            'today' => (int) ($results['today'][0]['visits'] ?? 0),
            'actionsToday' => (int) ($results['today'][0]['actions'] ?? 0),
            'now' => (int) ($results['now'][0]['visits'] ?? 0),
            'days' => self::days($results['days']),
            'loved' => self::loved($results['events']),
            'footfall' => self::footfall($results['titles']),
            'flows' => self::flows($results),
            'entries' => (int) ($results['from0']['pageMetrics']['entries'] ?? 0),
            'cnil' => self::cnil($results['cnil']),
            'calls' => array_map(fn ($c) => ['method' => $c[0], 'params' => $c[1]], array_values($calls)),
            'ms' => (int) round($matomo->apiMs),
            'errors' => $errors,
            'at' => gmdate('c'),
        ];
        file_put_contents($cache, json_encode($data), LOCK_EX);
        return $data + ['cached' => false];
    }

    /** @return list<array{date: string, visits: int, avg: int}> */
    public static function days(?array $days): array
    {
        $out = [];
        foreach ($days ?? [] as $date => $row) {
            $out[] = ['date' => (string) $date, 'visits' => (int) ($row['nb_visits'] ?? 0), 'avg' => (int) ($row['avg_time_on_site'] ?? 0)];
        }
        return $out;
    }

    /** Linger events per painting, most loved first. @return list<array{room: int, title: string, lingers: int}> */
    public static function loved(?array $events): array
    {
        $byRoom = [];
        foreach ($events ?? [] as $row) {
            $room = Museum::roomForPainting((string) ($row['Events_EventName'] ?? ''));
            if ($room !== null && ($row['Events_EventAction'] ?? '') === Museum::EVENT_ACTION) {
                $byRoom[$room] = ($byRoom[$room] ?? 0) + (int) $row['nb_events'];
            }
        }
        $out = [];
        foreach (array_keys(Museum::ROOMS) as $room) {
            $out[] = ['room' => $room, 'title' => Museum::ROOMS[$room]['title'], 'lingers' => $byRoom[$room] ?? 0];
        }
        usort($out, fn ($a, $b) => [$b['lingers'], $a['room']] <=> [$a['lingers'], $b['room']]);
        return $out;
    }

    /** Page views per room (0 is the hall). @return array<int, int> */
    public static function footfall(?array $titles): array
    {
        $out = array_fill(0, 6, 0);
        foreach ($titles ?? [] as $row) {
            $room = Museum::roomForTitle((string) ($row['label'] ?? ''));
            if ($room !== null) {
                $out[$room] += (int) ($row['nb_hits'] ?? 0);
            }
        }
        return $out;
    }

    /** Room-to-room moves from the Transitions reports. @return list<array{from: int, to: int, count: int}> */
    public static function flows(array $results): array
    {
        $flows = [];
        foreach ([0, 1, 2, 3, 4, 5] as $from) {
            foreach ($results["from$from"]['followingPages'] ?? [] as $next) {
                $to = Museum::roomForTitle((string) $next['label']);
                if ($to !== null && $to !== $from && (int) $next['referrals'] > 0) {
                    $flows[] = ['from' => $from, 'to' => $to, 'count' => (int) $next['referrals']];
                }
            }
        }
        usort($flows, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $flows;
    }

    /** CNIL consent-exemption status as Matomo reports it (needs a super user token). */
    public static function cnil(?array $status): ?array
    {
        if ($status === null) {
            return null;
        }
        $requirements = $status['complianceRequirements'] ?? [];
        $met = count(array_filter($requirements, fn ($r) => $r['value'] === 'compliant'));
        $find = fn (string $name) => current(array_filter($requirements, fn ($r) => $r['name'] === $name)) ?: null;
        return [
            'enforced' => (bool) ($status['complianceModeEnforced'] ?? false),
            'met' => $met,
            'total' => count($requirements),
            'ip' => $find('IP Address Mask Length Configured')['notes'] ?? null,
            'ipOn' => ($find('IP Anonymisation Enabled')['value'] ?? '') === 'compliant',
        ];
    }
}
