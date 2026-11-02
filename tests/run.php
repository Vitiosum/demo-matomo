<?php

declare(strict_types=1);

/*
 * Tests, no dependency: php tests/run.php
 * Unit checks on the museum and the report parsing, then HTTP checks against the real app
 * served by `php -S`, talking to a fake Matomo (tests/fake-matomo.php).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Museum\Curator;
use Museum\FloorPlan;
use Museum\HitLog;
use Museum\Museum;
use Museum\Painter;
use Museum\Seed;

$failures = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
    $failures += $ok ? 0 : 1;
}

echo "Unit\n";
check('paintings are deterministic', Painter::paint(3)['svg'] === Painter::paint(3)['svg']);
foreach (array_keys(Museum::ROOMS) as $n) {
    $p = Painter::paint($n);
    check("painting $n is a valid SVG with marks", $p['marks'] > 0 && simplexml_load_string($p['svg']) !== false);
}
check('page titles round-trip', Museum::roomForTitle(Museum::pageTitle(4)) === 4 && Museum::roomForTitle('Entrance hall') === 0);
check('tour order', Museum::next(5) === 0 && Museum::previous(1) === 0 && Museum::next(2) === 3);

$fx = json_decode((string) file_get_contents(__DIR__ . '/fixtures/matomo.json'), true);
$loved = Curator::loved($fx['Events.getName']);
check('most loved painting is read from Events.getName', $loved[0]['title'] === 'Meanders' && $loved[0]['lingers'] === 5);
$results = [];
foreach ([0, 1, 2, 3, 4, 5] as $r) {
    $results["from$r"] = $fx['Transitions.getTransitionsForPageTitle'][Museum::pageTitle($r)];
}
$flows = Curator::flows($results);
$twoToThree = array_values(array_filter($flows, fn ($f) => $f['from'] === 2 && $f['to'] === 3));
check('flows are read from Transitions', $twoToThree !== [] && $twoToThree[0]['count'] === 3);
$room3 = current(array_filter($fx['Actions.getPageTitles'], fn ($row) => $row['label'] === 'Room 3 - Paths'));
check('footfall per room', Curator::footfall($fx['Actions.getPageTitles'])[3] === $room3['nb_hits'] && $room3['nb_hits'] > 0);
check('CNIL status is summarised', Curator::cnil($fx['PrivacyManager.getComplianceStatus'])['enforced'] === false);
check('floor plan draws one arrow per flow', substr_count(FloorPlan::svg(['flows' => $flows]), 'marker-end') === count($flows));

$night = Seed::openingNight(7, 'https://museum.test', 1_800_000_000);
parse_str(ltrim($night[0], '?'), $first);
check('opening night: 7 visitors, back-dated, with a token-only IP', count(array_filter($night, fn ($r) => str_contains($r, 'new_visit=1'))) === 7
    && $first['idsite'] === '7' && (int) $first['cdt'] < 1_800_000_000 && str_starts_with($first['cip'], '192.0.2.'));
check('hit types', HitLog::type(['e_c' => 'x']) === 'event' && HitLog::type(['ping' => 1]) === 'heartbeat' && HitLog::type([]) === 'page view');

// ------------------------------------------------------------------ HTTP
echo "HTTP (app + fake Matomo)\n";

function freePort(): int
{
    for ($port = 8800 + random_int(0, 150); $port < 8999; $port++) {
        $socket = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($socket === false) {
            return $port;
        }
        fclose($socket);
    }
    throw new RuntimeException('no free port');
}

function serve(int $port, string $docroot, string $router, array $env): mixed
{
    $env += ['PATH' => getenv('PATH')];
    $process = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $docroot, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100_000);
    }
    return $process;
}

function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $out = (string) curl_exec($curl);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $out, 'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)];
}

$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'fake-matomo');
$matomoPort = freePort();
$fake = serve($matomoPort, __DIR__, __DIR__ . '/fake-matomo.php', ['FAKE_MATOMO_LOG' => $log]);
$appPort = freePort();
$base = "http://127.0.0.1:$appPort";
$env = ['MATOMO_URL' => "http://127.0.0.1:$matomoPort", 'MATOMO_TOKEN' => 'test-token', 'MATOMO_SITE_ID' => '7'];
$app = serve($appPort, "$root/public", "$root/public/index.php", $env);

try {
    $r = http('GET', "$base/");
    check('GET / shows the hall', $r['status'] === 200 && str_contains($r['body'], 'The Small Museum') && str_contains($r['body'], 'data-site-id="7"'));
    $r = http('GET', "$base/room/3");
    check('GET /room/3 shows Meanders and its cartel', $r['status'] === 200 && str_contains($r['body'], 'Meanders') && str_contains($r['body'], 'seed 137'));
    check('GET /room/9 is a 404', http('GET', "$base/room/9")['status'] === 404);
    $r = http('GET', "$base/health");
    check('GET /health is 200 without touching Matomo', $r['status'] === 200 && json_decode($r['body'], true)['status'] === 'ok');
    $r = http('GET', "$base/health/deep");
    check('GET /health/deep checks tracker and Reporting API', $r['status'] === 200 && str_contains($r['body'], 'Matomo 5.14.2'), $r['body']);
    $r = http('GET', "$base/curator");
    check('GET /curator renders the office from the Reporting API', $r['status'] === 200 && str_contains($r['body'], 'The most loved painting') && str_contains($r['body'], 'Meanders'));
    check('the token never reaches the browser', !str_contains($r['body'], 'test-token'));
    check('GET /painting/2.svg', ($p = http('GET', "$base/painting/2.svg"))['status'] === 200 && str_contains($p['type'], 'image/svg+xml'));
    check('GET /visitor-book.js serves the tracker', str_contains(http('GET', "$base/visitor-book.js")['body'], 'Matomo tracker'));

    // a browser trying to set privileged parameters
    $r = http('POST', "$base/visitor-book?idsite=99&cip=1.2.3.4", ['Content-Type: application/x-www-form-urlencoded', 'X-Forwarded-For: 203.0.113.9', 'User-Agent: TestBrowser/1.0'],
        http_build_query(['rec' => 1, 'action_name' => 'Room 3 - Paths', 'token_auth' => 'evil', 'cdt' => 1]));
    $hits = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($log))));
    $hit = end($hits) ?: [];
    check('POST /visitor-book answers 204', $r['status'] === 204);
    check('hit forwarded with the site id forced', ($hit['idsite'] ?? null) === '7' && ($hit['action_name'] ?? null) === 'Room 3 - Paths');
    check('privileged parameters replaced by the server', ($hit['token_auth'] ?? null) === 'test-token' && ($hit['cip'] ?? null) === '203.0.113.9' && !isset($hit['cdt']));
    check('user agent forwarded', ($hit['_headers']['ua'] ?? '') === 'TestBrowser/1.0');
    check('GET /visitor-book without a hit is a 400', http('GET', "$base/visitor-book")['status'] === 400);

    // the seed, through the fake Matomo (keeps the developer's var/site.json intact)
    $backup = @file_get_contents(Seed::cacheFile());
    putenv("MATOMO_URL=http://127.0.0.1:$matomoPort");
    putenv('MATOMO_TOKEN=test-token');
    putenv('MATOMO_SITE_ID');
    $result = Seed::run(\Museum\Matomo::fromEnv(), 'https://museum.test', true);
    check('seed finds the existing site and does not replay the opening night', $result['siteId'] === 7 && $result['vernissage'] === null);
    $backup === false ? @unlink(Seed::cacheFile()) : file_put_contents(Seed::cacheFile(), $backup);
} finally {
    proc_terminate($app);
}

// the same app without a token: the museum works, the office explains what is missing
$appPort = freePort();
$app = serve($appPort, "$root/public", "$root/public/index.php", ['MATOMO_URL' => "http://127.0.0.1:$matomoPort", 'MATOMO_SITE_ID' => '7']);
try {
    $r = http('GET', "http://127.0.0.1:$appPort/curator");
    check('without a token the office explains how to get one', $r['status'] === 200 && str_contains($r['body'], "The curator's keys are missing"));
    check('without a token /health/deep is 503', http('GET', "http://127.0.0.1:$appPort/health/deep")['status'] === 503);
    check('without a token hits are still forwarded', http('GET', "http://127.0.0.1:$appPort/visitor-book?rec=1&action_name=x")['status'] === 204);
} finally {
    proc_terminate($app);
    proc_terminate($fake);
    @unlink($log);
}

echo $failures === 0 ? "\nAll $count checks passed.\n" : "\n$failures of $count checks failed.\n";
exit($failures === 0 ? 0 : 1);
