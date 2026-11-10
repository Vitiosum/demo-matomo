<?php
/** @var ?array $office @var ?string $setup */
use Museum\FloorPlan;
use Museum\Museum;
?>
<main class="hall">
  <section class="hall__entrance">
    <div class="cc-container hall__grid">
      <div class="hall__sign">
        <h1 class="wall-title">The Small Museum</h1>
        <p class="hall__lead">Five rooms, five paintings drawn by code. Walk from room to room: Matomo counts the footsteps, the minutes and the paintings you stop for. The curator reads it all back from an office at the back.</p>
        <a class="doorway" href="/room/1"><span>Start the tour in Room 1</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg></a>
      </div>
      <aside class="admission" aria-labelledby="admission-title">
        <h2 id="admission-title">Admission</h2>
        <dl>
          <div><dt>Entry</dt><dd>Free, open all hours</dd></div>
          <div><dt>Cookies</dt><dd>None served. The tracker runs with <code>disableCookies</code>.</dd></div>
          <div><dt>Visitor book</dt><dd>Matomo, hosted by Clever Cloud in Paris</dd></div>
          <?php if ($office !== null): ?>
            <div><dt>Visitors today</dt><dd class="admission__count"><?= number_format((int) $office['today']) ?></dd></div>
          <?php endif ?>
          <?php if ($setup !== null): ?>
            <div><dt>Curator's office</dt><dd>Waiting for one setup step. <?= h($setup) ?> <a href="/curator">Details in the office</a>.</dd></div>
          <?php endif ?>
        </dl>
      </aside>
    </div>
  </section>

  <section class="hall__plan" aria-labelledby="plan-title">
    <div class="cc-container">
      <div class="plan-head">
        <h2 id="plan-title">Floor plan</h2>
        <p>Choose a room, or follow the doors from 1 to 5.</p>
      </div>
      <div class="plan-wrap"><?= FloorPlan::svg(['links' => true, 'current' => 0, 'label' => 'Floor plan: the entrance hall in the middle, rooms 1 to 5 around it']) ?></div>
    </div>
  </section>

  <section class="hall__salon" aria-label="The collection">
    <div class="cc-container salon">
      <?php foreach (Museum::ROOMS as $n => $room): [$w, $hgt] = $room['size']; ?>
        <a class="salon__work" href="/room/<?= $n ?>">
          <span class="salon__frame"><img src="/painting/<?= $n ?>.svg" width="<?= $w ?>" height="<?= $hgt ?>" alt="<?= h($room['title']) ?>, the painting of room <?= $n ?>" loading="lazy"></span>
          <span class="salon__label">
            <span class="salon__room">Room <?= $n ?>, <?= h($room['theme']) ?></span>
            <i><?= h($room['title']) ?></i>
          </span>
        </a>
      <?php endforeach ?>
    </div>
  </section>
</main>
