<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/Application.php';
require_once __DIR__ . '/SemesterManagement.php';

class ApplicationUI {

    /**
     * Generate a cache-busted URL for a static resource
     */
    public static function staticResourceUrl(string $path): string {
        $filePath = __DIR__ . '/../' . ltrim($path, '/');
        $version = @filemtime($filePath);
        if (!$version) {
            $version = date('Ymd');
        }
        return $path . '?v=' . $version;
    }

    /**
     * Generate a complete CSS link tag with cache-busting
     */
    public static function cssLink(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate a complete JS script tag with cache-busting
     */
    public static function jsScript(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    /**
     * The top-bar navigation items for a user, one entry per link:
     * ['path' => ..., 'label' => ..., 'active' => bool]. A user sees a group
     * of items per role they hold (admin first); duplicate labels keep the
     * higher-priority role's link.
     */
    private static function topNavItems(array $user): array {
        $roles = Application::rolesForUser((int)$user['id']);
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $hasSemesters = SemesterManagement::hasAnySemester();

        $groups = [];
        if (in_array('admin', $roles, true)) {
            $adminItems = [];
            if ($hasSemesters) {
                $adminItems[] = ['path' => '/admin/schedule.php', 'label' => 'Schedule',
                    'prefixes' => ['/admin/schedule', '/admin/reservation_']];
                $adminItems[] = ['path' => '/admin/calendar.php', 'label' => 'Calendar',
                    'prefixes' => ['/admin/calendar', '/admin/lesson_']];
            }
            $adminItems[] = ['path' => '/admin/students.php', 'label' => 'Students',
                'prefixes' => ['/admin/students', '/admin/student_', '/admin/parent_']];
            $adminItems[] = ['path' => '/admin/teachers.php', 'label' => 'Teachers',
                'prefixes' => ['/admin/teachers', '/admin/teacher_']];
            $adminItems[] = ['path' => '/admin/announcements.php', 'label' => 'Admin',
                'prefixes' => self::adminSectionPrefixes()];
            $groups[] = $adminItems;
        }
        if (in_array('teacher', $roles, true)) {
            $groups[] = [
                ['path' => '/teacher/calendar.php', 'label' => 'Calendar', 'prefixes' => ['/teacher/']],
            ];
        }
        if (in_array('parent', $roles, true)) {
            $groups[] = [
                ['path' => '/parent/calendar.php', 'label' => 'Calendar', 'prefixes' => ['/parent/calendar']],
                ['path' => '/parent/billing.php', 'label' => 'Billing', 'prefixes' => ['/parent/billing', '/parent/checkout']],
            ];
        }
        if (in_array('student', $roles, true)) {
            $groups[] = [
                ['path' => '/student/calendar.php', 'label' => 'Calendar', 'prefixes' => ['/student/calendar']],
                ['path' => '/student/materials.php', 'label' => 'Materials', 'prefixes' => ['/student/materials']],
            ];
        }

        $items = [];
        $seenLabels = [];
        foreach ($groups as $group) {
            foreach ($group as $item) {
                if (isset($seenLabels[$item['label']])) {
                    continue;
                }
                $seenLabels[$item['label']] = true;
                $active = false;
                foreach ($item['prefixes'] as $prefix) {
                    if (strpos($script, $prefix) === 0) {
                        $active = true;
                        break;
                    }
                }
                $items[] = ['path' => $item['path'], 'label' => $item['label'], 'active' => $active];
            }
        }
        return $items;
    }

    /** Script-name prefixes that count as "the Admin section" (subnav shown). */
    private static function adminSectionPrefixes(): array {
        return [
            '/admin/announcements.php', '/admin/announcement_',
            '/admin/locations.php', '/admin/location_',
            '/admin/semesters.php', '/admin/semester/',
            '/admin/users.php', '/admin/user_',
            '/admin/settings.php',
            '/admin/maintenance.php',
            '/admin/migrations',
            '/admin/activity_log.php', '/admin/email_log.php',
            '/admin/import/',
            '/admin/setup/',
        ];
    }

    /**
     * The Admin section's submenu links. The developer tools (migrations and
     * the logs) collapse into a single "Maintenance" entry, and only a
     * developer sees it — see require_developer().
     */
    private static function adminSubnavItems(): array {
        $items = [
            ['path' => '/admin/announcements.php', 'label' => 'Announcements', 'prefixes' => ['/admin/announcement']],
            ['path' => '/admin/locations.php', 'label' => 'Locations', 'prefixes' => ['/admin/location']],
            ['path' => '/admin/semesters.php', 'label' => 'Semesters', 'prefixes' => ['/admin/semester', '/admin/setup/', '/admin/import/']],
            ['path' => '/admin/users.php', 'label' => 'Users', 'prefixes' => ['/admin/user']],
            ['path' => '/admin/settings.php', 'label' => 'Settings', 'prefixes' => ['/admin/settings.php']],
        ];
        if (is_developer()) {
            $items[] = ['path' => '/admin/maintenance.php', 'label' => 'Maintenance',
                'prefixes' => ['/admin/maintenance.php', '/admin/migrations',
                               '/admin/activity_log.php', '/admin/email_log.php']];
        }
        return $items;
    }

    /**
     * The page's submenu bars. Each entry:
     *   ['id' => element id, 'owner' => top-nav label that toggles it,
     *    'items' => [['path','label','active'], ...]]
     * A submenu renders as a full-width bar in normal document flow (it
     * pushes the content down, never overlays it) but stays HIDDEN until the
     * user clicks its top-level menu item — every page load starts closed.
     * The same items repeat inside the mobile drawer.
     */
    private static function subnavGroups(array $user): array {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $groups = [];

        // Admin submenu: available on every page for admins, toggled by the
        // "Admin" top-nav item (cub_scouts admin-bar behavior).
        if (!empty($user['is_admin'])) {
            $items = [];
            foreach (self::adminSubnavItems() as $item) {
                $active = false;
                foreach ($item['prefixes'] as $p) {
                    if (strpos($script, $p) === 0) { $active = true; break; }
                }
                $items[] = ['path' => $item['path'], 'label' => $item['label'], 'active' => $active];
            }
            $groups[] = ['id' => 'subnavAdmin', 'owner' => 'Admin', 'items' => $items];
        }

        // Calendar pages: a whole-semester overview / Week pair, toggled by
        // the "Calendar" top-nav item. Neither overview is a month grid any
        // more, so there is no month to carry between them.
        $calendarPairs = [
            '/admin/calendar' => ['/admin/calendar.php', '/admin/calendar_week.php'],
            '/teacher/calendar' => ['/teacher/calendar.php', '/teacher/calendar_week.php'],
        ];
        foreach ($calendarPairs as $prefix => [$overviewPath, $weekPath]) {
            if (strpos($script, $prefix) === 0) {
                $date = (string)($_GET['date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $date = '';
                }
                $groups[] = ['id' => 'subnavCalendar', 'owner' => 'Calendar', 'items' => [
                    ['path' => $overviewPath, 'label' => 'Semester', 'active' => $script === $overviewPath],
                    ['path' => $weekPath . ($date !== '' ? '?date=' . $date : ''),
                     'label' => 'Week', 'active' => $script === $weekPath],
                ]];
                break;
            }
        }

        return $groups;
    }

    /** The admin semester selector (only when at least one semester exists). */
    private static function semesterSelectorHtml(array $user, string $cssClass): string {
        if (empty($user['is_admin'])) {
            return '';
        }
        $semesters = SemesterManagement::listSemesters();
        if (!$semesters) {
            return '';
        }
        $selectedId = Application::adminSelectedSemesterId();
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/index.php';

        $html = '<form class="' . h($cssClass) . '" method="get" action="/admin/semester_select.php">'
              . '<input type="hidden" name="return" value="' . h($returnTo) . '">'
              . '<select name="semester_id" aria-label="Semester" onchange="this.form.submit()">';
        foreach ($semesters as $s) {
            $sel = ((int)$s['id'] === $selectedId) ? ' selected' : '';
            $html .= '<option value="' . (int)$s['id'] . '"' . $sel . '>' . h(SemesterManagement::label($s)) . '</option>';
        }
        $html .= '</select></form>';
        return $html;
    }

    /** The avatar button + dropdown (My Profile / Change Password / Logout). */
    private static function avatarMenuHtml(array $user): string {
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $initials = strtoupper((string)substr((string)($user['first_name'] ?? ''), 0, 1)
            . (string)substr((string)($user['last_name'] ?? ''), 0, 1));
        $photoUrl = Files::profilePhotoUrl($user['photo_public_file_id'] ?? null, 32);

        if ($photoUrl !== '') {
            $avatar = '<img class="nav-avatar" src="' . h($photoUrl) . '" alt="">';
        } else {
            $avatar = '<span class="nav-avatar nav-avatar-initials" aria-hidden="true">' . h($initials) . '</span>';
        }

        return '<div class="nav-avatar-wrap">'
             . '<button type="button" id="avatarToggle" class="nav-avatar-btn" aria-expanded="false"'
             . ' aria-controls="avatarMenu" title="' . h($name) . '" aria-label="Account menu for ' . h($name) . '">'
             . $avatar
             . '</button>'
             . '<div id="avatarMenu" class="avatar-menu hidden" role="menu" aria-hidden="true">'
             .   '<a href="/profile/" role="menuitem">My Profile</a>'
             .   '<a href="/profile/change_password.php" role="menuitem">Change Password</a>'
             .   '<a href="/logout.php" role="menuitem">Logout</a>'
             . '</div>'
             . '</div>';
    }

    /**
     * Page shell: navy top bar (brand left, role navigation + semester
     * selector + avatar right), an optional full-width contextual submenu bar
     * that pushes content down, <main>, and the site footer. On narrow
     * screens the navigation collapses behind a hamburger into a full-width
     * panel (also in normal flow — no overlay).
     *
     * $options: ['wide' => true] lets a page (the Semester Schedule grid) use
     * the full viewport width instead of the centered column.
     */
    public static function headerHtml(string $title, array $options = []): void {
        $u = current_user();
        $siteTitle = Settings::siteTitle();

        $bodyClasses = [];
        if (!empty($options['wide'])) {
            $bodyClasses[] = 'page-wide';
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . h($title) . ' - ' . h($siteTitle) . '</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body' . ($bodyClasses ? ' class="' . h(implode(' ', $bodyClasses)) . '"' : '') . '>';

        if ($u) {
            $navItems = self::topNavItems($u);
            $subnavGroups = self::subnavGroups($u);
            $semesterSelector = self::semesterSelectorHtml($u, 'semester-select');

            // Which top-nav label toggles which submenu bar.
            $togglesByOwner = [];
            foreach ($subnavGroups as $group) {
                $togglesByOwner[$group['owner']] = $group['id'];
            }

            $link = function (array $item) use ($togglesByOwner) {
                $subnavId = $togglesByOwner[$item['label']] ?? null;
                $attrs = '';
                if ($subnavId !== null) {
                    // Clicking toggles the submenu bar instead of navigating
                    // (main.js setupTopNav intercepts data-subnav-toggle).
                    $attrs = ' data-subnav-toggle="' . h($subnavId) . '"'
                        . ' aria-expanded="false" aria-controls="' . h($subnavId) . '" role="button"';
                }
                return '<a href="' . h($item['path']) . '"' . ($item['active'] ? ' class="active"' : '') . $attrs . '>'
                    . h($item['label']) . '</a>';
            };
            $plainLink = function (array $item) {
                return '<a href="' . h($item['path']) . '"' . ($item['active'] ? ' class="active"' : '') . '>'
                    . h($item['label']) . '</a>';
            };

            echo '<header class="topbar">';
            echo '<a class="topbar-brand" href="/index.php" aria-label="Bronx Conservatory of Music — home">'
               . '<img class="brand-logo" src="' . h(self::staticResourceUrl('/images/logo.png')) . '" alt="">'
               . '<span class="brand-full">Bronx Conservatory of Music</span>'
               . '</a>';

            echo '<nav class="topbar-nav" aria-label="Main">';
            foreach ($navItems as $item) {
                echo $link($item);
            }
            echo $semesterSelector;
            echo '</nav>';

            echo self::avatarMenuHtml($u);

            echo '<button type="button" id="navToggle" class="nav-toggle" aria-label="Open menu"'
               . ' aria-expanded="false" aria-controls="mobileNav">'
               . '<span></span><span></span><span></span>'
               . '</button>';
            echo '</header>';

            // Mobile drawer: full-width panel in normal flow under the header.
            // The drawer itself is user-opened, so submenu items just show
            // indented beneath the main links.
            echo '<div id="mobileNav" class="mobile-nav">';
            foreach ($navItems as $item) {
                echo $plainLink($item);
            }
            foreach ($subnavGroups as $group) {
                echo '<div class="mobile-nav-sub">';
                foreach ($group['items'] as $item) {
                    echo $plainLink($item);
                }
                echo '</div>';
            }
            echo self::semesterSelectorHtml($u, 'semester-select mobile');
            echo '</div>';

            // Contextual submenu bars: full-width, in normal flow (they push
            // the content down), hidden until their top-nav item is clicked.
            foreach ($subnavGroups as $group) {
                echo '<div class="subnav hidden" id="' . h($group['id']) . '" aria-label="Section">';
                foreach ($group['items'] as $item) {
                    echo $plainLink($item);
                }
                echo '</div>';
            }

            echo '<main>';
        } else {
            // Logged-out shell (rarely used; auth pages render their own layout)
            echo '<header class="topbar"><a class="topbar-brand" href="/login.php" aria-label="' . h($siteTitle) . '">'
               . '<img class="brand-logo" src="' . h(self::staticResourceUrl('/images/logo.png')) . '" alt="">'
               . '<span class="brand-full">' . h($siteTitle) . '</span>'
               . '</a></header>';
            echo '<main>';
        }
    }

    /** The BCM wordmark: the white treble-clef logo beside the name. */
    public static function brandWordmarkHtml(): string {
        return '<img class="brand-logo" src="' . h(self::staticResourceUrl('/images/logo.png')) . '" alt="">'
            . '<span class="brand-name">Bronx Conservatory of Music</span>';
    }

    // Footer shown on every page: navy band with the copyright line and the
    // conservatory's phone number, so a real person is always one call away.
    private static function siteFooterHtml(): string {
        return '<footer class="site-footer">'
            . '<span class="site-footer-name">Copyright Bronx Conservatory of Music ' . date('Y') . '</span>'
            . '<span class="site-footer-phone">Questions? Call us at <a href="tel:+17188417415">' . h(Settings::contactPhone()) . '</a></span>'
            . '</footer>';
    }

    public static function footerHtml(): void {
        echo '</main>' . self::siteFooterHtml() . self::jsScript('/main.js') . '</body></html>';
    }

    /**
     * Minimal chrome for pages outside the signed-in shell: no navigation —
     * a centered column with the BCM wordmark, and the phone number footer.
     */
    public static function minimalHeaderHtml(string $title): void {
        $siteTitle = Settings::siteTitle();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . h($title) . ' - ' . h($siteTitle) . '</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body class="minimal">';
        echo '<div class="minimal-header">' . self::brandWordmarkHtml() . '</div>';
        echo '<div class="minimal-content"><main>';
    }

    public static function minimalFooterHtml(): void {
        echo '</main>' . self::siteFooterHtml() . '</div>' . self::jsScript('/main.js') . '</body></html>';
    }
}
