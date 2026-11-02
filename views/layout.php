<?php
/** @var string $page @var string $trackTitle @var string $content @var int $siteId */
use Museum\Museum;
use Museum\Platform;

$logo = file_get_contents(__DIR__ . '/brand/cc-logo.svg');
$titles = ['hall' => Museum::NAME, 'room' => "$trackTitle | " . Museum::NAME, 'curator' => Museum::OFFICE_TITLE . ' | ' . Museum::NAME, 'lost' => 'Not found | ' . Museum::NAME];
?>
<!doctype html>
<html lang="en">
<head>
<?= file_get_contents(__DIR__ . '/brand/head.html') ?>
<link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:ital,wght@0,200..700;1,300..500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/museum.css">
<title><?= h($titles[$page] ?? Museum::NAME) ?></title>
<meta name="description" content="A tiny generative-art museum whose visits are measured by the Matomo add-on on Clever Cloud.">
<script src="/assets/museum.js" defer></script>
</head>
<body class="museum page-<?= h($page) ?>" data-site-id="<?= (int) $siteId ?>" data-track-title="<?= h($trackTitle) ?>" data-linger="<?= Museum::LINGER_SECONDS ?>">
<header class="cc-topbar">
  <div class="cc-container cc-topbar__inner">
    <a href="https://www.clever.cloud" target="_blank" rel="noopener" aria-label="Clever Cloud"><?= $logo ?></a>
    <span class="cc-topbar__sep"></span>
    <div class="cc-topbar__demo"><small>Demo</small>Matomo analytics</div>
    <span class="cc-topbar__spacer"></span>
    <nav class="cc-topbar__nav" aria-label="Museum">
      <a class="cc-topbar__link<?= $page !== 'curator' ? ' is-active' : '' ?>" href="/">The museum</a>
      <a class="cc-topbar__link<?= $page === 'curator' ? ' is-active' : '' ?>" href="/curator">Curator's office</a>
      <span class="cc-pill cc-pill--live cc-hide-sm"><span class="cc-dot"></span>Live on Clever Cloud</span>
    </nav>
  </div>
</header>

<?= $content ?>

<aside class="vbook" aria-label="Visitor book">
  <button class="vbook__toggle" type="button" aria-expanded="false" aria-controls="vbook-panel">
    <span class="vbook__pen" aria-hidden="true"></span>Visitor book <span class="vbook__count">0</span>
  </button>
  <div class="vbook__panel" id="vbook-panel" hidden>
    <p class="vbook__intro">Each step of your visit, as the Matomo tracker wrote it. It goes to <code>/visitor-book</code> on this app, which hands it to Matomo in Paris. No cookie is set.</p>
    <ol class="vbook__list"></ol>
    <p class="vbook__empty">Nothing sent yet.</p>
    <a class="vbook__office" href="/curator">See what the curator sees</a>
  </div>
</aside>

<section class="service" aria-label="Running on Clever Cloud">
  <div class="cc-container">
    <div class="cc-platform">
      <div class="cc-platform__head">
        <h3>Running on Clever Cloud</h3>
        <?php if (Platform::onClever()): ?><span class="cc-pill cc-pill--ok"><span class="cc-dot"></span>Production</span><?php else: ?><span class="cc-pill cc-pill--muted">Local</span><?php endif ?>
      </div>
      <dl class="cc-kv">
        <?php foreach (Platform::values() as $label => $value): ?>
          <div><dt><?= h($label) ?></dt><dd<?= $value === null ? ' class="is-empty"' : '' ?>><?= h($value ?? 'set at deployment') ?></dd></div>
        <?php endforeach ?>
      </dl>
      <div class="cc-platform__foot">Injected by the platform at deployment: <a href="https://www.clever.cloud/developers/doc/develop/common-configuration/environment-variables/reference/" target="_blank" rel="noopener">environment variables reference</a>.</div>
    </div>
  </div>
</section>

<footer class="cc-footer">
  <div class="cc-container">
    <div class="cc-footer__inner">
      <div class="cc-footer__brand">
        <?= $logo ?>
        <p>European, fully managed PaaS. Deploy with git push, the platform runs the rest.</p>
        <div class="cc-trust"><span>ISO 27001</span><span>HDS</span><span>HOSTED IN FRANCE</span></div>
        <a class="footer-badge" href="https://academy.clever.cloud/" target="_blank" rel="noopener" aria-label="Get certified at Clever Cloud Academy">
          <?= file_get_contents(__DIR__ . '/brand/cc-badge.svg') ?>
          <span>Get certified at <b>Clever Cloud Academy</b></span>
        </a>
      </div>
      <div>
        <h4>Documentation</h4>
        <ul>
          <li><a href="https://www.clever.cloud/developers/doc/deploy/services/matomo/" target="_blank" rel="noopener">Matomo add-on</a></li>
          <li><a href="https://www.clever.cloud/developers/doc/deploy/applications/php/" target="_blank" rel="noopener">PHP runtime</a></li>
          <li><a href="https://developer.matomo.org/api-reference/reporting-api" target="_blank" rel="noopener">Matomo Reporting API</a></li>
        </ul>
      </div>
      <div>
        <h4>Go further</h4>
        <ul>
          <li><a class="is-cert" href="https://academy.clever.cloud/" target="_blank" rel="noopener">Clever Cloud certification</a></li>
          <li><a href="https://github.com/Vitiosum/demo-matomo" target="_blank" rel="noopener">Source code on GitHub</a></li>
          <li><button class="optout" type="button" data-optout>Don't count my visits</button></li>
        </ul>
      </div>
    </div>
    <div class="cc-footer__bottom"><span>A demo by the Clever Cloud presales team. Paintings drawn by code.</span><span>© 2026 Clever Cloud</span></div>
  </div>
</footer>
</body>
</html>
