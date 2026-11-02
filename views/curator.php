<?php
/** @var array $office @var ?\Museum\Matomo $matomo */
?>
<main class="office">
  <section class="office__head">
    <div class="cc-container office__head-grid">
      <div>
        <h1 class="wall-title wall-title--office">Curator's office</h1>
        <p class="office__lead">Everything on this desk is read server-side from the Matomo Reporting API, with a token that never leaves the server. Only aggregated reports: no visitor is ever looked at alone.</p>
      </div>
      <dl class="office__id">
        <div><dt>Matomo site id</dt><dd><?= (int) ($office['siteId'] ?? \Museum\Seed::siteId()) ?></dd></div>
        <div><dt>Matomo</dt><dd><?= h(isset($office['version']) ? 'version ' . $office['version'] : 'add-on') ?></dd></div>
        <div><dt>Hosted by</dt><dd>Clever Cloud, Paris zone</dd></div>
      </dl>
    </div>
  </section>

  <div id="office-live" data-refresh="/curator/live" aria-live="off">
    <?php view('curator-live', ['office' => $office, 'matomo' => $matomo]); ?>
  </div>

  <section class="office__wire" aria-labelledby="wire-title">
    <div class="cc-container">
      <h2 id="wire-title" class="desk-title">What Matomo did for this page</h2>
      <div class="wire">
        <article class="wire__card">
          <h3>The tracking call this page just sent</h3>
          <p class="wire__route">Your browser <span>→</span> <code>POST /visitor-book</code> on this app <span>→</span> <code>matomo.php</code> on the add-on</p>
          <dl class="wire__params" id="just-sent"><div><dt>Waiting</dt><dd>The tracker has not sent anything yet.</dd></div></dl>
        </article>
        <article class="wire__card">
          <h3>Cookies on this page</h3>
          <p class="wire__cookies" id="cookie-check">Checking <code>document.cookie</code>…</p>
          <p class="wire__note">The tracker runs with <code>disableCookies</code>. Matomo tells visits apart with a hash of browser settings computed on its server, salted with a key that changes every day.</p>
        </article>
      </div>
    </div>
  </section>
</main>
