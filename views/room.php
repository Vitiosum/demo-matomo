<?php
/** @var int $room @var array $painting @var ?array $office */
use Museum\FloorPlan;
use Museum\Museum;

$r = Museum::ROOMS[$room];
[$w, $hgt] = $r['size'];
$prev = Museum::previous($room);
$next = Museum::next($room);
$doorName = fn (int $n) => $n === 0 ? 'Entrance hall' : "Room $n, " . Museum::ROOMS[$n]['theme'];
$weekly = null;
foreach ($office['loved'] ?? [] as $row) {
    if ($row['room'] === $room) {
        $weekly = (int) $row['lingers'];
    }
}
$shape = $w > $hgt * 1.1 ? 'landscape' : ($hgt > $w * 1.1 ? 'portrait' : 'square');
?>
<main class="room">
  <div class="room__wall">
    <div class="cc-container room__top">
      <header class="room__sign">
        <p class="room__number" aria-hidden="true"><?= $room ?></p>
        <div>
          <p class="room__kicker">Room <?= $room ?></p>
          <h1 class="room__theme"><?= h($r['theme']) ?></h1>
          <p class="room__motto"><?= h($r['wall']) ?></p>
        </div>
      </header>
      <nav class="room__nav" aria-label="Doors">
        <a class="door" href="<?= Museum::path($prev) ?>" rel="prev">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12H5M11 6l-6 6 6 6"/></svg><span><?= h($doorName($prev)) ?></span>
        </a>
        <a class="door door--next" href="<?= Museum::path($next) ?>" rel="next">
          <span><?= $next === 0 ? 'Back to the hall' : h($doorName($next)) ?></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg>
        </a>
        <a class="minimap" href="/" aria-label="Floor plan, you are in room <?= $room ?>"><?= FloorPlan::svg(['compact' => true, 'current' => $room, 'label' => "You are in room $room"]) ?></a>
      </nav>
    </div>

    <div class="cc-container room__hang">
      <figure class="artwork artwork--<?= $shape ?>" data-painting="<?= h($r['title']) ?>" data-room="<?= $room ?>">
        <div class="artwork__frame">
          <img src="/painting/<?= $room ?>.svg" width="<?= $w ?>" height="<?= $hgt ?>" alt="<?= h($r['title']) ?>: <?= h(strtolower($r['medium'])) ?>.">
        </div>
      </figure>

      <aside class="cartel" aria-label="Label of the work">
        <p class="cartel__artist"><b><?= h($r['artist']) ?></b> <span>(<?= h($r['born']) ?>)</span></p>
        <p class="cartel__title"><i><?= h($r['title']) ?></i>, 2026</p>
        <p class="cartel__medium"><?= h($r['medium']) ?><br><?= number_format($w) ?> × <?= number_format($hgt) ?> px</p>
        <p class="cartel__text"><?= h($r['text']) ?></p>
        <p class="cartel__detail"><?= number_format($painting['marks']) ?> <?= h($painting['unit']) ?>, seed <?= (int) $r['seed'] ?></p>

        <div class="cartel__love">
          <div class="dots" data-weekly="<?= (int) $weekly ?>">
            <?php for ($i = 0; $i < min((int) $weekly, 12); $i++): ?><span class="dot"></span><?php endfor ?>
          </div>
          <p class="cartel__love-label" data-love-label>
            <?php if ($weekly === null): ?>Red dots mark the visitors who lingered here.
            <?php elseif ($weekly === 0): ?>No one has lingered here this week yet.
            <?php else: ?><?= $weekly ?> <?= $weekly === 1 ? 'visitor' : 'visitors' ?> lingered here this week.<?php endif ?>
          </p>
        </div>

        <div class="linger" data-state="waiting" aria-live="polite">
          <svg class="linger__ring" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="16"/><circle cx="20" cy="20" r="16" class="linger__fill"/></svg>
          <span class="linger__text">Stay <?= Museum::LINGER_SECONDS ?> seconds in front of the painting.</span>
        </div>
        <p class="cartel__credit">Collection of The Small Museum</p>
      </aside>
    </div>
  </div>
  <div class="room__floor" aria-hidden="true"></div>
</main>
