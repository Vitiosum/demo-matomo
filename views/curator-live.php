<?php
/** @var array $office @var ?\Museum\Matomo $matomo */
use Museum\FloorPlan;
use Museum\Museum;

$duration = function (int $s): string {
    $unit = fn (string $u) => "<small>$u</small>";
    return $s < 60 ? $s . $unit('s') : intdiv($s, 60) . $unit('min') . ' ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . $unit('s');
};
$state = $office['state'];
?>
<?php if ($state === 'no-matomo'): ?>
  <section class="desk"><div class="cc-container"><div class="notice">
    <h2>The museum has no visitor book yet</h2>
    <p>Set <code>MATOMO_URL</code> to the address of the Matomo add-on, then restart the app.</p>
  </div></div></section>
<?php elseif ($state === 'no-token' || $state === 'error'): ?>
  <section class="desk"><div class="cc-container"><div class="notice">
    <?php if ($state === 'no-token'): ?>
      <h2>The curator's keys are missing</h2>
      <p>Matomo already receives every visit (see the visitor book below). To read the reports, this office needs a Matomo API token:</p>
      <ol>
        <li>Open <a href="<?= h($matomo->url) ?>" target="_blank" rel="noopener">your Matomo add-on</a> and sign in with your Clever Cloud account.</li>
        <li>In Matomo, open <b>Administration › Personal › Security</b> and create a token.</li>
        <li>Run <code>clever env set MATOMO_TOKEN &lt;token&gt;</code>, then <code>clever restart</code>. At start-up the app creates the site <i>The Small Museum</i> and its opening night.</li>
      </ol>
    <?php else: ?>
      <h2>Matomo did not answer the office</h2>
      <p><?= h($office['error']) ?></p>
      <p>Check that the token is valid and that its user can read and create sites, then reload this page.</p>
    <?php endif ?>
  </div></div></section>
  <?php if (!empty($office['hits'])): ?>
    <section class="desk"><div class="cc-container">
      <h2 class="desk-title">Hits this instance forwarded to Matomo</h2>
      <?php view('hits', ['hits' => $office['hits']]); ?>
    </div></section>
  <?php endif ?>
<?php else: ?>
  <?php
    $loved = $office['loved'];
    $top = $loved[0];
    $dots = array_column($loved, 'lingers', 'room');
    $week = array_sum(array_column($office['days'], 'visits'));
    $avg = $office['days'] ? (int) round(array_sum(array_map(fn ($d) => $d['avg'] * $d['visits'], $office['days'])) / max(1, $week)) : 0;
    $peak = max(1, ...array_column($office['days'], 'visits'));
  ?>
  <section class="desk desk--ledger">
    <div class="cc-container">
      <dl class="ledger">
        <div><dt>Visitors today</dt><dd><?= number_format($office['today']) ?></dd><p class="api">Live.getCounters, since midnight</p></div>
        <div><dt>In the galleries now</dt><dd><?= number_format($office['now']) ?></dd><p class="api">Live.getCounters, last 30 min</p></div>
        <div><dt>Steps today</dt><dd><?= number_format($office['actionsToday']) ?></dd><p class="api">page views and events</p></div>
        <div><dt>Average visit, 7 days</dt><dd><?= $duration($avg) ?></dd><p class="api">VisitsSummary.get</p></div>
      </dl>
    </div>
  </section>

  <section class="desk">
    <div class="cc-container desk__grid">
      <article class="desk__plan">
        <header class="desk__head">
          <h2 class="desk-title">The path between rooms</h2>
          <p>Last 7 days. Each arrow is a move from one room to the next; red dots are visitors who lingered.</p>
        </header>
        <div class="plan-wrap plan-wrap--office">
          <?= FloorPlan::svg(['flows' => $office['flows'], 'dots' => $dots, 'footfall' => $office['footfall'], 'label' => 'Visitor flows between the rooms over the last 7 days']) ?>
        </div>
        <p class="api">Transitions.getTransitionsForPageTitle, one call per room · Actions.getPageTitles</p>
        <?php if ($office['entries'] > 0): ?><p class="desk__note"><?= number_format($office['entries']) ?> visits started at the front door.</p><?php endif ?>
      </article>

      <article class="desk__loved">
        <header class="desk__head">
          <h2 class="desk-title">The most loved painting</h2>
          <p>Visitors who stayed <?= Museum::LINGER_SECONDS ?> seconds in front of it, last 7 days.</p>
        </header>
        <?php if ($top['lingers'] > 0): $tr = Museum::ROOMS[$top['room']]; ?>
          <a class="loved" href="/room/<?= $top['room'] ?>">
            <span class="loved__frame"><img src="/painting/<?= $top['room'] ?>.svg" width="<?= $tr['size'][0] ?>" height="<?= $tr['size'][1] ?>" alt="<?= h($tr['title']) ?>"></span>
            <span class="loved__label">
              <i><?= h($tr['title']) ?></i>
              <span>Room <?= $top['room'] ?>, <?= h($tr['theme']) ?></span>
              <span class="dots"><?php for ($i = 0; $i < min($top['lingers'], 12); $i++): ?><span class="dot"></span><?php endfor ?></span>
              <b><?= $top['lingers'] ?> <?= $top['lingers'] === 1 ? 'visitor' : 'visitors' ?> lingered</b>
            </span>
          </a>
        <?php else: ?>
          <p class="desk__empty">No one has lingered yet this week. Go and stand in front of a painting for <?= Museum::LINGER_SECONDS ?> seconds.</p>
        <?php endif ?>
        <ol class="ranking">
          <?php foreach ($loved as $row): ?>
            <li><span class="ranking__title"><i><?= h($row['title']) ?></i></span><span class="ranking__dots"><?php for ($i = 0; $i < min($row['lingers'], 8); $i++): ?><span class="dot dot--small"></span><?php endfor ?></span><span class="ranking__n"><?= $row['lingers'] ?></span></li>
          <?php endforeach ?>
        </ol>
        <p class="api">Events.getName, event <?= Museum::EVENT_CATEGORY ?> / <?= Museum::EVENT_ACTION ?></p>
      </article>
    </div>
  </section>

  <section class="desk">
    <div class="cc-container desk__grid desk__grid--even">
      <article>
        <header class="desk__head">
          <h2 class="desk-title">Attendance, 7 days</h2>
          <p><?= number_format($week) ?> visits in all.</p>
        </header>
        <ol class="week">
          <?php foreach ($office['days'] as $d): $day = new DateTimeImmutable($d['date']); ?>
            <li class="week__day<?= $d === end($office['days']) ? ' is-today' : '' ?>">
              <span class="week__bar" data-h="<?= (int) round(100 * $d['visits'] / $peak) ?>"><span><?= $d['visits'] ?></span></span>
              <span class="week__label"><?= $day->format('D j') ?></span>
            </li>
          <?php endforeach ?>
        </ol>
        <p class="api">VisitsSummary.get, period day, archived report</p>
      </article>

      <article>
        <header class="desk__head">
          <h2 class="desk-title">Privacy, as Matomo reports it</h2>
          <p>No cookie, data kept in France.</p>
        </header>
        <ul class="checks">
          <li class="is-ok">No tracking cookie: the tracker runs with <code>disableCookies</code>.</li>
          <li class="is-ok">Data stays in France: the Matomo add-on runs in Clever Cloud's Paris zone.</li>
          <?php if ($office['cnil'] !== null): $c = $office['cnil']; ?>
            <li class="<?= $c['ipOn'] ? 'is-ok' : 'is-todo' ?>">IP addresses anonymised<?= $c['ip'] ? ': ' . h(lcfirst($c['ip'])) : '.' ?></li>
            <li class="is-ok">Visitors can opt out: “Don't count my visits” in the footer.</li>
            <?php if ($c['enforced']): ?>
              <li class="is-ok"><b>CNIL compliance mode enforced in Matomo</b> (<?= $c['met'] ?> of <?= $c['total'] ?> requirements met). Matomo's CNIL guide says a site set up this way can measure its audience without a consent banner.</li>
            <?php else: ?>
              <li class="is-todo"><b>CNIL compliance mode not enforced yet</b> (<?= $c['met'] ?> of <?= $c['total'] ?> requirements met). Turn it on in Matomo, <i>Administration › Privacy › Compliance</i>: then, per Matomo's CNIL guide, no consent banner is needed.</li>
            <?php endif ?>
          <?php endif ?>
        </ul>
        <p class="api">PrivacyManager.getComplianceStatus, policy cnil_v1</p>
      </article>
    </div>
  </section>

  <section class="desk">
    <div class="cc-container desk__grid desk__grid--even">
      <article>
        <header class="desk__head">
          <h2 class="desk-title">Hits this instance forwarded to Matomo</h2>
          <p>The tracking endpoint answers before the hit is processed.</p>
        </header>
        <?php view('hits', ['hits' => $office['hits']]); ?>
      </article>
      <article>
        <header class="desk__head">
          <h2 class="desk-title">Behind this desk</h2>
          <p>One <code>API.getBulkRequest</code> with <?= count($office['calls']) ?> methods, <?= $office['ms'] ?> ms<?= !empty($office['cached']) ? ', served from a 10-second cache' : '' ?>.</p>
        </header>
        <ol class="calls">
          <?php foreach ($office['calls'] as $call): ?>
            <li><code><b><?= h($call['method']) ?></b><?php foreach ($call['params'] as $k => $v): ?> <?= h($k) ?>=<?= h(is_array($v) ? implode(',', $v) : $v) ?><?php endforeach ?></code></li>
          <?php endforeach ?>
          <li><code>token_auth=••••</code> <span>stays on the server, in the POST body</span></li>
        </ol>
        <?php if ($office['errors']): ?>
          <p class="desk__note">Not available with this token: <?= h(implode(', ', array_keys($office['errors']))) ?>.</p>
        <?php endif ?>
      </article>
    </div>
  </section>
<?php endif ?>
