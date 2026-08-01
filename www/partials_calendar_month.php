<?php
// Shared monthly calendar renderer. Every role's monthly view feeds it a
// month ("YYYY-MM"), a map of day content, and its own navigation URLs:
//
//   calendar_month_html('2026-09', [
//       '2026-09-07' => ['<span class="cal-chip">...</span>', ...],
//   ], ['prev' => '?month=2026-08', 'next' => '?month=2026-10'],
//   ['dayLinkFn' => fn(string $date): string => '/admin/calendar_week.php?date=' . $date])
//
// Pass 'prev'/'next' as null (key present, value null) to hide that arrow
// (e.g. when clamped to the semester's range).
require_once __DIR__ . '/partials.php';

function calendar_month_html(string $month, array $dayContentByDate, array $navUrls = [], array $options = []): string {
    $firstTs = strtotime($month . '-01');
    if ($firstTs === false) {
        return '<p class="error">Bad month.</p>';
    }
    $daysInMonth = (int)date('t', $firstTs);
    $startWeekday = (int)date('w', $firstTs); // 0=Sunday
    $today = date('Y-m-d');
    $dayLinkFn = $options['dayLinkFn'] ?? null;

    $html = '<div class="cal-header">';
    $html .= '<span class="cal-nav">';
    if (!empty($navUrls['prev'])) {
        $html .= '<a class="button" href="' . h($navUrls['prev']) . '" aria-label="Previous month">&larr;</a>';
    }
    $html .= '</span>';
    $html .= '<h3 class="cal-title">' . h(date('F Y', $firstTs)) . '</h3>';
    $html .= '<span class="cal-nav">';
    if (!empty($navUrls['next'])) {
        $html .= '<a class="button" href="' . h($navUrls['next']) . '" aria-label="Next month">&rarr;</a>';
    }
    $html .= '</span></div>';

    $html .= '<div class="cal-scroll"><table class="cal-month"><thead><tr>';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName) {
        $html .= '<th>' . $dayName . '</th>';
    }
    $html .= '</tr></thead><tbody><tr>';

    for ($i = 0; $i < $startWeekday; $i++) {
        $html .= '<td class="cal-empty"></td>';
    }
    $cell = $startWeekday;
    for ($day = 1; $day <= $daysInMonth; $day++) {
        if ($cell === 7) {
            $html .= '</tr><tr>';
            $cell = 0;
        }
        $date = date('Y-m-d', strtotime($month . '-' . sprintf('%02d', $day)));
        $classes = 'cal-day' . ($date === $today ? ' cal-today' : '');
        $dayLabel = (string)$day;
        $body = '<span class="cal-day-num">' . $dayLabel . '</span>';
        foreach ($dayContentByDate[$date] ?? [] as $chipHtml) {
            $body .= $chipHtml;
        }
        // When the view has a week to link to, the WHOLE cell is the link —
        // chips are plain spans, so nesting them in the anchor is safe.
        if (is_callable($dayLinkFn)) {
            $html .= '<td class="' . $classes . ' cal-day-linked">'
                . '<a class="cal-day-link" href="' . h($dayLinkFn($date)) . '">' . $body . '</a></td>';
        } else {
            $html .= '<td class="' . $classes . '">' . $body . '</td>';
        }
        $cell++;
    }
    while ($cell < 7) {
        $html .= '<td class="cal-empty"></td>';
        $cell++;
    }
    $html .= '</tr></tbody></table></div>';
    return $html;
}
