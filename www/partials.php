<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ApplicationUI.php';

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function header_html(string $title, array $options = []): void {
    ApplicationUI::headerHtml($title, $options);
}

function footer_html(): void {
    ApplicationUI::footerHtml();
}

// Stable avatar color for a person's name (child cards, rosters). Drawn
// around the BCM blue and coral (docs/app_spec.md "Design Notes") rather than
// a generic rainbow, and every one is dark enough to carry white initials.
function person_avatar_color(string $name): string {
    $palette = ['#0062DC', '#C2410C', '#6D28D9', '#0F766E', '#B45309', '#9D174D', '#166534', '#00132A'];
    return $palette[crc32($name) % count($palette)];
}

// Colored initials circle + name. Empty name renders the $emptyLabel fallback.
function person_chip_html(?string $first, ?string $last, string $emptyLabel = 'Unassigned'): string {
    $name = trim(($first ?? '') . ' ' . ($last ?? ''));
    if ($name === '') return '<span class="unassigned">' . h($emptyLabel) . '</span>';
    $initials = strtoupper(mb_substr($first ?? '', 0, 1) . mb_substr($last ?? '', 0, 1));
    if ($initials === '') $initials = strtoupper(mb_substr($name, 0, 1));
    return '<span class="assignee"><span class="assignee-avatar" style="background:' . h(person_avatar_color($name)) . '">' . h($initials)
        . '</span><span class="assignee-name">' . h($name) . '</span></span>';
}

// "Sat, Apr 11 · 9:00–9:30 AM" for a lesson row (start_datetime + duration_minutes).
function lesson_time_html(string $startDatetime, int $durationMinutes): string {
    $start = strtotime($startDatetime);
    $end = $start + $durationMinutes * 60;
    $sameMeridiem = date('A', $start) === date('A', $end);
    $startLabel = date('g:i', $start) . ($sameMeridiem ? '' : ' ' . date('A', $start));
    return h(date('D, M j', $start) . ' · ' . $startLabel . '–' . date('g:i A', $end));
}

// Where a lesson happens: "Online" (with icon, plus a "Join" link when the
// teacher's meeting link is set), "Room 12 · Bronx Community College", or an
// em dash when nothing is set. $lesson needs is_online, room, location_name
// keys. At an online location `room` holds the meeting link.
function lesson_place_html(array $lesson): string {
    if (!empty($lesson['is_online'])) {
        $link = trim((string)($lesson['room'] ?? ''));
        $html = '<span class="online-tag" title="Online lesson">&#128187; Online</span>';
        if (preg_match('#^https?://#i', $link)) {
            $html .= ' · <a href="' . h($link) . '" target="_blank" rel="noopener">Join</a>';
        } elseif ($link !== '') {
            $html .= ' · ' . h($link);
        }
        return $html;
    }
    $parts = array_filter([
        trim((string)($lesson['room'] ?? '')) !== '' ? 'Room ' . $lesson['room'] : '',
        (string)($lesson['location_name'] ?? ''),
    ]);
    return $parts ? h(implode(' · ', $parts)) : '<span class="small">—</span>';
}

// A lesson's display name: "Violin Lesson", or "Lesson" when no instrument
// is known. $lesson needs an instrument_name key.
function lesson_name_label(array $lesson): string {
    $instrument = trim((string)($lesson['instrument_name'] ?? ''));
    return $instrument !== '' ? $instrument . ' Lesson' : 'Lesson';
}

// A hold block's length as a form field: the usual lengths plus "Other",
// which reveals a minutes box. A block can be any multiple of 30 minutes up
// to HoldBlockManagement::MAX_DURATION_MINUTES (the CSV import derives the
// length from a start and end time), so a dropdown of only the usual lengths
// cannot show — or keep — a 2½-hour block.
//
// The value lives in a hidden input with id $id (and name $name, when given),
// so callers read it like any other field. Set it from script with
// bcmHoldDurationSet(id, minutes): a usual length selects its option, anything
// else selects "Other" and fills the box.
function hold_duration_field_html(string $id, string $name = ''): string {
    static $scriptEmitted = false;
    $label = static function (int $minutes): string {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours');
        if ($rest > 0 || $hours === 0) $parts[] = $rest . ' minutes';
        return implode(' ', $parts);
    };
    $max = HoldBlockManagement::MAX_DURATION_MINUTES;
    $first = HoldBlockManagement::DURATION_OPTIONS[0];

    $html = '<label>Duration' . "\n" . '<select id="' . h($id) . 'Choice" data-hold-duration="' . h($id) . '">';
    foreach (HoldBlockManagement::DURATION_OPTIONS as $minutes) {
        $html .= '<option value="' . (int)$minutes . '">' . h($label((int)$minutes)) . '</option>';
    }
    $html .= '<option value="other">Other…</option></select></label>' . "\n";
    $html .= '<label id="' . h($id) . 'OtherWrap" class="hidden">Length in minutes'
        . '<input type="number" id="' . h($id) . 'Other" data-hold-duration="' . h($id) . '" min="30" max="' . $max
        . '" step="30" inputmode="numeric">'
        . '<span class="small">For a length not in the list: any multiple of 30, up to ' . $max . ' ('
        . h($label($max)) . '). For example 150 for 2½ hours.</span></label>' . "\n";
    $html .= '<input type="hidden" id="' . h($id) . '"' . ($name !== '' ? ' name="' . h($name) . '"' : '')
        . ' value="' . (int)$first . '">' . "\n";

    if (!$scriptEmitted) {
        $scriptEmitted = true;
        $html .= <<<'HTML'
<script>
function bcmHoldDurationSync(id) {
  var choice = document.getElementById(id + 'Choice');
  var other = document.getElementById(id + 'Other');
  var isOther = choice.value === 'other';
  document.getElementById(id + 'OtherWrap').classList.toggle('hidden', !isOther);
  document.getElementById(id).value = isOther ? other.value : choice.value;
}
function bcmHoldDurationSet(id, minutes) {
  var choice = document.getElementById(id + 'Choice');
  var value = String(minutes || '');
  var usual = value !== '' && value !== 'other' && choice.querySelector('option[value="' + value + '"]');
  choice.value = usual ? value : (value === '' ? choice.options[0].value : 'other');
  document.getElementById(id + 'Other').value = usual ? '' : value;
  bcmHoldDurationSync(id);
}
document.addEventListener('input', function (e) {
  var id = e.target && e.target.dataset ? e.target.dataset.holdDuration : '';
  if (id) bcmHoldDurationSync(id);
});
document.addEventListener('change', function (e) {
  var id = e.target && e.target.dataset ? e.target.dataset.holdDuration : '';
  if (!id) return;
  bcmHoldDurationSync(id);
  if (e.target.tagName === 'SELECT' && e.target.value === 'other') {
    document.getElementById(id + 'Other').focus();
  }
});
</script>
HTML;
    }
    return $html;
}
