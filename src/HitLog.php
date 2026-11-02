<?php

declare(strict_types=1);

namespace Museum;

/**
 * The last hits this instance forwarded to Matomo (type, title, HTTP status, latency).
 * A small file in the instance's temp folder: enough to show the flow live, gone on redeploy.
 * Nothing personal is stored: no IP, no user agent, no URL query.
 */
final class HitLog
{
    private const KEEP = 12;

    public static function file(): string
    {
        return sys_get_temp_dir() . '/small-museum-hits.json';
    }

    public static function add(array $params, array $result): void
    {
        $entry = [
            'at' => gmdate('c'),
            'type' => self::type($params),
            'label' => self::label($params),
            'status' => $result['status'],
            'ms' => $result['ms'],
        ];
        $handle = fopen(self::file(), 'c+');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $hits = json_decode((string) stream_get_contents($handle), true) ?: [];
        array_unshift($hits, $entry);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode(array_slice($hits, 0, self::KEEP)));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return list<array{at: string, type: string, label: string, status: int, ms: int}> */
    public static function recent(): array
    {
        $raw = @file_get_contents(self::file());
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    public static function type(array $params): string
    {
        return match (true) {
            isset($params['e_c']) => 'event',
            isset($params['ping']) => 'heartbeat',
            default => 'page view',
        };
    }

    public static function label(array $params): string
    {
        if (isset($params['e_c'])) {
            return trim(implode(' / ', array_filter([$params['e_c'], $params['e_a'] ?? null, $params['e_n'] ?? null])));
        }
        return mb_substr((string) ($params['action_name'] ?? ''), 0, 60);
    }
}
