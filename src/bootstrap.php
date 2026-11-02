<?php

declare(strict_types=1);

// No framework, no Composer: the whole app is these few files.
foreach (['Museum', 'Painter', 'Matomo', 'HitLog', 'Seed', 'Curator', 'Platform', 'FloorPlan'] as $file) {
    require_once __DIR__ . "/$file.php";
}

/** HTML-escape for templates. */
function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
