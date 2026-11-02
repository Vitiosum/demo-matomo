<?php

declare(strict_types=1);

/*
 * A stand-in for the Matomo add-on, for the tests (php -S router).
 * Reporting API answers come from tests/fixtures/matomo.json, recorded from a real Matomo 5.14.2.
 * Every tracking hit received is appended to the file named by FAKE_MATOMO_LOG.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/matomo.json'), true);
$wrap = fn ($v) => is_scalar($v) ? ['value' => $v] : $v; // Matomo wraps scalars in bulk answers

if ($path === '/matomo.js') {
    header('Content-Type: application/javascript');
    echo '/* Matomo tracker (fake) */ window.Matomo = {};';
    return true;
}

if ($path === '/matomo.php') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET === []) {
        echo 'This resource is part of Matomo.';
        return true;
    }
    $raw = (string) file_get_contents('php://input');
    $hit = str_starts_with(trim($raw), '{') ? json_decode($raw, true) : $_POST + $_GET;
    $hit['_headers'] = ['ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'lang' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''];
    file_put_contents((string) getenv('FAKE_MATOMO_LOG'), json_encode($hit) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(isset($hit['requests']) ? 200 : 204);
    if (isset($hit['requests'])) {
        echo json_encode(['status' => 'success', 'tracked' => count($hit['requests']), 'invalid' => 0]);
    }
    return true;
}

if ($path === '/index.php' && ($_GET['method'] ?? '') === 'API.getBulkRequest') {
    header('Content-Type: application/json');
    if (($_POST['token_auth'] ?? '') !== 'test-token') {
        echo json_encode(['result' => 'error', 'message' => 'Unauthorized: token_auth is not valid.']);
        return true;
    }
    $out = [];
    foreach ($_POST['urls'] ?? [] as $url) {
        parse_str($url, $q);
        $method = $q['method'];
        $out[] = match ($method) {
            'Transitions.getTransitionsForPageTitle' => $fixtures[$method][$q['pageTitle']] ?? ['followingPages' => []],
            'SitesManager.getPatternMatchSites' => [['idsite' => 7, 'name' => 'The Small Museum']],
            'SitesManager.addSite' => ['value' => 7],
            default => isset($fixtures[$method]) ? $wrap($fixtures[$method]) : ['result' => 'error', 'message' => "fake: no fixture for $method"],
        };
    }
    echo json_encode($out);
    return true;
}

http_response_code(404);
return true;
