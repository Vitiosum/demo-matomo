<?php

declare(strict_types=1);

/*
 * The Small Museum: a front controller in plain PHP.
 *   /                 entrance hall and floor plan
 *   /room/1 … /room/5 the five rooms
 *   /curator          the curator's office (Matomo Reporting API, server-side)
 *   /visitor-book     first-party tracking endpoint, forwarded to the Matomo add-on
 *   /visitor-book.js  Matomo's JavaScript tracker, served from this domain
 *   /health, /health/deep
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Museum\Curator;
use Museum\HitLog;
use Museum\Matomo;
use Museum\MatomoError;
use Museum\Museum;
use Museum\Painter;
use Museum\Seed;

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false; // local dev server: static files are served as they are
}

$matomo = Matomo::fromEnv();
$siteId = Seed::siteId();

switch (true) {
    case $path === '/health':
        json(['status' => 'ok']);
        break;

    case $path === '/health/deep':
        deepHealth($matomo, $siteId);
        break;

    case $path === '/':
        page('hall', Museum::HALL_TITLE, ['office' => Curator::cached($siteId), 'setup' => setupStep($matomo)]);
        break;

    case preg_match('#^/room/([1-5])$#', $path, $m) === 1:
        $room = (int) $m[1];
        page('room', Museum::pageTitle($room), ['room' => $room, 'painting' => Painter::paint($room), 'office' => Curator::cached($siteId)]);
        break;

    case $path === '/curator':
    case $path === '/curator/live':
        $office = office($matomo, $siteId);
        if ($path === '/curator/live') {
            view('curator-live', ['office' => $office, 'matomo' => $matomo]);
        } else {
            page('curator', Museum::OFFICE_TITLE, ['office' => $office, 'matomo' => $matomo]);
        }
        break;

    case $path === '/visitor-book':
        visitorBook($matomo, $siteId);
        break;

    case $path === '/visitor-book.js':
        $script = $matomo?->trackerScript();
        header('Content-Type: application/javascript; charset=utf-8');
        if ($script === null) {
            http_response_code(503);
            echo '/* The Matomo tracker is not reachable right now. */';
            break;
        }
        header('Cache-Control: public, max-age=3600');
        echo $script;
        break;

    case preg_match('#^/painting/([1-5])\.svg$#', $path, $m) === 1:
        ob_start('ob_gzhandler');
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        echo Painter::paint((int) $m[1])['svg'];
        break;

    default:
        http_response_code(404);
        page('lost', 'Not found', []);
}

// ------------------------------------------------------------------ helpers

function json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** Renders a view without the layout. */
function view(string $name, array $vars): void
{
    extract($vars);
    require dirname(__DIR__) . "/views/$name.php";
}

/** Renders a full page: the view inside the layout (Clever frame, tracker, visitor book). */
function page(string $name, string $trackTitle, array $vars): void
{
    header('Content-Security-Policy: ' . implode('; ', [
        "default-src 'self'", "script-src 'self'", "style-src 'self' https://fonts.googleapis.com",
        'font-src https://fonts.gstatic.com', "img-src 'self' data:", "connect-src 'self'",
        "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'",
    ]));
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    ob_start();
    view($name, $vars);
    $content = ob_get_clean();
    view('layout', ['page' => $name, 'trackTitle' => $trackTitle, 'content' => $content, 'siteId' => Seed::siteId()]);
}

function baseUrl(): string
{
    $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTPS'] ?? '') === 'on';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Office data, or the reason it is not available. Runs the seed if the start-up hook could not. */
function office(?Matomo $matomo, int &$siteId): array
{
    if ($matomo === null) {
        return ['state' => 'no-matomo'];
    }
    if (!$matomo->hasToken()) {
        return ['state' => 'no-token', 'hits' => HitLog::recent()];
    }
    try {
        if (!is_file(Seed::cacheFile()) && !getenv('MATOMO_SITE_ID')) {
            $siteId = Seed::run($matomo, baseUrl(), (getenv('INSTANCE_NUMBER') ?: '0') === '0')['siteId'];
        }
        return ['state' => 'ok', 'hits' => HitLog::recent()] + Curator::gather($matomo, $siteId);
    } catch (MatomoError $e) {
        return ['state' => 'error', 'error' => $e->getMessage(), 'hits' => HitLog::recent()];
    }
}

/** The setup step still to do, or null when MATOMO_URL and MATOMO_TOKEN are both set. */
function setupStep(?Matomo $matomo): ?string
{
    if ($matomo === null) {
        return 'Set MATOMO_URL to the address of the Matomo add-on, then restart the app.';
    }
    if (!$matomo->hasToken()) {
        return 'Open Matomo from the Clever Cloud Console, create an API token (Administration > Personal > Security), set it as MATOMO_TOKEN, then restart the app.';
    }
    return null;
}

/** First-party tracking endpoint: what the browser's tracker sends, Matomo receives. */
function visitorBook(?Matomo $matomo, int $siteId): void
{
    if ($matomo === null) {
        http_response_code(503);
        return;
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        return;
    }
    $params = $_GET + $_POST; // matomo.js sends a GET, or a POST through navigator.sendBeacon()
    if (!isset($params['rec'])) {
        http_response_code(400);
        return;
    }
    $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
    $clientIp = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : ($_SERVER['REMOTE_ADDR'] ?? '');
    $result = $matomo->forwardHit($params, $siteId, $clientIp, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    HitLog::add($params, $result);
    header('Cache-Control: no-store');
    http_response_code($result['status'] >= 200 && $result['status'] < 300 ? 204 : 502);
}

/** Checks the Matomo add-on: the tracking endpoint answers, and the Reporting API accepts the token. */
function deepHealth(?Matomo $matomo, int $siteId): void
{
    if ($matomo === null) {
        json(['status' => 'fail', 'matomo' => 'MATOMO_URL is not set', 'nextStep' => setupStep($matomo)], 503);
        return;
    }
    $start = microtime(true);
    $tracker = $matomo->trackerAnswers();
    $report = ['status' => 'ok', 'matomo' => $matomo->host(), 'tracker' => $tracker, 'site' => $siteId];
    $ok = $tracker >= 200 && $tracker < 500;
    if (!$matomo->hasToken()) {
        $report['reportingApi'] = 'MATOMO_TOKEN is not set';
        $report['nextStep'] = setupStep($matomo);
        $ok = false;
    } else {
        try {
            $report['reportingApi'] = 'Matomo ' . $matomo->api('API.getMatomoVersion');
        } catch (MatomoError $e) {
            $report['reportingApi'] = $e->getMessage();
            $ok = false;
        }
    }
    $report['ms'] = (int) round((microtime(true) - $start) * 1000);
    $report['status'] = $ok ? 'ok' : 'fail';
    json($report, $ok ? 200 : 503);
}
