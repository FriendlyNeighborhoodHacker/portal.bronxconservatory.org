<?php
// Prev/Next paging for list pages, in the shape Admin > Activity Log
// established. Named pager_* so it can be included alongside those pages,
// which still have their own local build_url().
require_once __DIR__ . '/partials.php';

/** The current query string with $overrides applied; a null value drops a key. */
function pager_url(string $basePath, array $params, array $overrides = []): string {
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $params ? $basePath . '?' . http_build_query($params) : $basePath;
}

/**
 * "N results · Page X of Y" with Prev/Next. Renders nothing when everything
 * fits on one page — a pager that can never move is just noise.
 */
function pager_html(string $basePath, array $params, int $page, int $totalPages, int $total): string {
    if ($totalPages <= 1) {
        return '<p class="small" style="text-align:right;">' . (int)$total . ' result'
            . ($total === 1 ? '' : 's') . '</p>';
    }

    $prev = $page > 1
        ? '<a class="button" href="' . h(pager_url($basePath, $params, ['page' => $page - 1])) . '">Prev</a>'
        : '<span class="button disabled" aria-disabled="true">Prev</span>';
    $next = $page < $totalPages
        ? '<a class="button" href="' . h(pager_url($basePath, $params, ['page' => $page + 1])) . '">Next</a>'
        : '<span class="button disabled" aria-disabled="true">Next</span>';

    return '<div class="actions" style="margin-top:8px;display:flex;align-items:center;gap:8px;justify-content:flex-end;">'
        . '<span class="small">' . (int)$total . ' result' . ($total === 1 ? '' : 's')
        . ' · Page ' . (int)$page . ' of ' . (int)$totalPages . '</span>'
        . $prev . $next
        . '</div>';
}
