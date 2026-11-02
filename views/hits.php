<?php /** @var list<array{at: string, type: string, label: string, status: int, ms: int}> $hits */ ?>
<?php if (!$hits): ?>
  <p class="desk__empty">No hit forwarded by this instance since it started. Walk into a room.</p>
<?php else: ?>
  <ol class="hits">
    <?php foreach (array_slice($hits, 0, 8) as $hit): ?>
      <li>
        <time datetime="<?= h($hit['at']) ?>"><?= h((new DateTimeImmutable($hit['at']))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i:s')) ?></time>
        <span class="hits__what"><b class="hits__type--<?= h(str_replace(' ', '-', $hit['type'])) ?>"><?= h($hit['type']) ?></b> <?= h($hit['label']) ?></span>
        <span class="hits__status<?= $hit['status'] >= 200 && $hit['status'] < 300 ? '' : ' is-bad' ?>">HTTP <?= (int) $hit['status'] ?>, <?= (int) $hit['ms'] ?> ms</span>
      </li>
    <?php endforeach ?>
  </ol>
<?php endif ?>
