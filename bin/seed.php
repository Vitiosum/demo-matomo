<?php

declare(strict_types=1);

/*
 * Start-up seed, run by CC_PRE_RUN_HOOK on every instance before it serves traffic:
 * find or create the "The Small Museum" site in Matomo; when it is new, send the opening night.
 * It never fails the deployment: without a token, or if Matomo is down, it only says so.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$matomo = Museum\Matomo::fromEnv();
if ($matomo === null || !$matomo->hasToken()) {
    echo "seed: MATOMO_URL or MATOMO_TOKEN is not set, nothing to seed\n";
    exit(0);
}

// The site URL registered in Matomo: the app's default cleverapps.io domain on Clever Cloud.
$appId = (string) getenv('APP_ID');
$siteUrl = $appId !== '' ? 'https://' . str_replace('_', '-', $appId) . '.cleverapps.io' : 'http://localhost:8100';
$firstInstance = (getenv('INSTANCE_NUMBER') ?: '0') === '0';

try {
    $result = Museum\Seed::run($matomo, $siteUrl, $firstInstance);
    $night = $result['vernissage'] === null ? 'no opening night (site already there)' : "opening night sent, HTTP {$result['vernissage']['status']}";
    echo "seed: Matomo site {$result['siteId']}" . ($result['created'] ? ' created' : ' found') . ", $night\n";
} catch (Throwable $e) {
    echo 'seed: skipped, ' . $e->getMessage() . "\n";
}
exit(0);
