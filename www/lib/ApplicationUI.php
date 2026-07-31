<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/Application.php';

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
     * The role-based navigation for the left sidebar. A user sees a section
     * for each role they hold (admin / teacher / parent / student — roles are
     * derived from profile rows, see Application::rolesForUser). Most users
     * hold exactly one role and see a short, simple list.
     */
    private static function roleNavHtml(array $user): string {
        $userId = (int)$user['id'];
        $roles = Application::rolesForUser($userId);
        $script = $_SERVER['SCRIPT_NAME'] ?? '';

        $item = function (string $path, string $label, bool $active) {
            return '<a href="' . h($path) . '" class="sidebar-item' . ($active ? ' active' : '') . '">' . h($label) . '</a>';
        };
        $sectionTitle = fn(string $label) => '<div class="sidebar-section-title">' . h($label) . '</div>';
        $showTitles = count($roles) > 1;

        $html = '';

        if (in_array('admin', $roles, true)) {
            if ($showTitles) $html .= $sectionTitle('Admin');
            $html .= $item('/admin/index.php', 'Action Queue', $script === '/admin/index.php');
            $html .= $item('/admin/families.php', 'Families', strpos($script, '/admin/famil') === 0 && $script !== '/admin/index.php');
            $html .= $item('/admin/lessons.php', 'Lessons', strpos($script, '/admin/lesson') === 0);
            $html .= $item('/admin/recurring_lessons.php', 'Recurring Lessons', strpos($script, '/admin/recurring') === 0);
            $html .= $item('/admin/locations.php', 'Locations', strpos($script, '/admin/location') === 0);
            $html .= $item('/admin/announcements.php', 'Announcements', strpos($script, '/admin/announcement') === 0);
        }

        if (in_array('teacher', $roles, true)) {
            if ($showTitles) $html .= $sectionTitle('Teaching');
            $html .= $item('/teacher/index.php', "Today's Lessons", strpos($script, '/teacher/') === 0);
        }

        if (in_array('parent', $roles, true)) {
            if ($showTitles) $html .= $sectionTitle('My Family');
            $html .= $item('/parent/index.php', 'My Children', strpos($script, '/parent/') === 0);
        }

        if (in_array('student', $roles, true)) {
            if ($showTitles) $html .= $sectionTitle('My Lessons');
            $html .= $item('/student/index.php', 'My Schedule', strpos($script, '/student/') === 0);
        }

        if (!in_array('admin', $roles, true)) {
            $html .= $item('/announcements.php', 'Announcements', $script === '/announcements.php');
        }

        return $html;
    }

    /**
     * Page shell with left sidebar navigation:
     * - the user's role navigation at the top of the sidebar (scrolls if too long)
     * - Admin menu and profile photo anchored at the bottom
     * - on narrow screens the sidebar collapses behind a top-left menu icon
     */
    public static function headerHtml(string $title): void {
        $u = current_user();
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $cur = basename($script);
        $siteTitle = Settings::siteTitle();

        // On admin pages the admin submenu renders open (desktop keeps it as a
        // persistent rail so users can navigate between admin sections).
        $inAdminSection = !empty($u['is_admin']) && strpos($script, '/admin/') === 0;

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.h($title).' - '.h($siteTitle).'</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body'.($inAdminSection ? ' class="admin-submenu-open"' : '').'>';

        if ($u) {
            // Mobile top bar: menu icon + site title
            echo '<div class="mobile-topbar">'
               . '<button id="sidebarToggle" class="sidebar-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">'
               . '<span></span><span></span><span></span>'
               . '</button>'
               . '<a class="mobile-topbar-title" href="/index.php">'.h($siteTitle).'</a>'
               . '</div>';
            echo '<div id="sidebarBackdrop" class="sidebar-backdrop"></div>';

            echo '<aside class="sidebar" id="sidebar">';
            echo '<div class="sidebar-title"><a href="/index.php">'.h($siteTitle).'</a></div>';

            echo '<nav class="sidebar-nav">';
            echo self::roleNavHtml($u);
            echo '</nav>';

            // Bottom-anchored: Admin menu (peer of the profile photo) + profile
            echo '<div class="sidebar-bottom">';

            if (!empty($u['is_admin'])) {
                $adminItems = [
                    ['path' => '/admin/users.php', 'label' => 'Users'],
                    ['path' => '/admin/settings.php', 'label' => 'Settings'],
                    ['path' => '/admin/activity_log.php', 'label' => 'Activity Log'],
                    ['path' => '/admin/email_log.php', 'label' => 'Email Log'],
                ];
                $adminLinks = '';
                foreach ($adminItems as $item) {
                    $active = $inAdminSection && ($cur === basename($item['path']));
                    $adminLinks .= '<a href="'.h($item['path']).'" role="menuitem"'.($active ? ' class="active"' : '').'>'.h($item['label']).'</a>';
                }
                echo '<div class="sidebar-menu-wrap">'
                   . '<button type="button" id="adminToggle" class="sidebar-item sidebar-menu-toggle" aria-expanded="'.($inAdminSection ? 'true' : 'false').'" aria-controls="adminMenu">'
                   . '<span class="sidebar-item-icon" aria-hidden="true">&#9881;</span> Admin'
                   . '</button>'
                   . '<div id="adminMenu" class="popup-menu admin-submenu'.($inAdminSection ? '' : ' hidden').'" role="menu" aria-hidden="'.($inAdminSection ? 'false' : 'true').'">'
                   .   '<div class="admin-submenu-title">Admin</div>'
                   .   $adminLinks
                   . '</div>'
                   . '</div>';
            }

            $name = trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''));
            $initials = strtoupper((string)substr((string)($u['first_name'] ?? ''), 0, 1).(string)substr((string)($u['last_name'] ?? ''), 0, 1));
            $photoUrl = Files::profilePhotoUrl($u['photo_public_file_id'] ?? null, 32);

            if ($photoUrl !== '') {
                $avatar = '<img class="sidebar-avatar" src="'.h($photoUrl).'" alt="">';
            } else {
                $avatar = '<span class="sidebar-avatar sidebar-avatar-initials" aria-hidden="true">'.h($initials).'</span>';
            }

            echo '<div class="sidebar-menu-wrap">'
               . '<button type="button" id="profileToggle" class="sidebar-item sidebar-menu-toggle sidebar-profile" aria-expanded="false" aria-controls="profileMenu" title="'.h($name).'" aria-label="Account menu for '.h($name).'">'
               . $avatar
               . '</button>'
               . '<div id="profileMenu" class="popup-menu hidden" role="menu" aria-hidden="true">'
               .   '<a href="/profile/" role="menuitem">My Profile</a>'
               .   '<a href="/profile/change_password.php" role="menuitem">Change Password</a>'
               .   '<a href="/logout.php" role="menuitem">Logout</a>'
               . '</div>'
               . '</div>';

            echo '</div>'; // .sidebar-bottom
            echo '</aside>';

            // Sidebar behavior: mobile drawer, persistent admin rail (desktop) /
            // accordion (mobile), and the profile popup menu.
            echo '<script>document.addEventListener("DOMContentLoaded",function(){'
               . 'var sidebar=document.getElementById("sidebar");'
               . 'var toggle=document.getElementById("sidebarToggle");'
               . 'var backdrop=document.getElementById("sidebarBackdrop");'
               . 'function isDesktop(){return window.matchMedia("(min-width: 769px)").matches;}'
               . 'function closeSidebar(){sidebar.classList.remove("open");backdrop.classList.remove("show");if(toggle)toggle.setAttribute("aria-expanded","false");}'
               . 'function openSidebar(){sidebar.classList.add("open");backdrop.classList.add("show");if(toggle)toggle.setAttribute("aria-expanded","true");}'
               . 'if(toggle){toggle.addEventListener("click",function(){sidebar.classList.contains("open")?closeSidebar():openSidebar();});}'
               . 'if(backdrop){backdrop.addEventListener("click",closeSidebar);}'
               // Admin submenu: toggled by its button; stays open on desktop (a
               // navigation rail), so outside clicks only close it on mobile.
               . 'var adminBtn=document.getElementById("adminToggle");'
               . 'var adminMenu=document.getElementById("adminMenu");'
               . 'function setAdmin(open){if(!adminMenu)return;adminMenu.classList.toggle("hidden",!open);adminMenu.setAttribute("aria-hidden",open?"false":"true");if(adminBtn)adminBtn.setAttribute("aria-expanded",open?"true":"false");document.body.classList.toggle("admin-submenu-open",open);}'
               . 'if(adminBtn&&adminMenu){adminBtn.addEventListener("click",function(e){e.preventDefault();setAdmin(adminMenu.classList.contains("hidden"));});}'
               // Profile popup: standard popup behavior everywhere.
               . 'var profileBtn=document.getElementById("profileToggle");'
               . 'var profileMenu=document.getElementById("profileMenu");'
               . 'function setProfile(open){if(!profileMenu)return;profileMenu.classList.toggle("hidden",!open);profileMenu.setAttribute("aria-hidden",open?"false":"true");if(profileBtn)profileBtn.setAttribute("aria-expanded",open?"true":"false");}'
               . 'if(profileBtn&&profileMenu){profileBtn.addEventListener("click",function(e){e.preventDefault();setProfile(profileMenu.classList.contains("hidden"));});}'
               . 'document.addEventListener("click",function(e){'
               .   'if(profileBtn&&profileMenu&&!profileBtn.contains(e.target)&&!profileMenu.contains(e.target))setProfile(false);'
               . '});'
               . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){setProfile(false);closeSidebar();}});'
               . '});</script>';

            echo '<div class="content"><main>';
        } else {
            // Logged-out shell (rarely used; auth pages render their own layout)
            echo '<div class="mobile-topbar always"><a class="mobile-topbar-title" href="/login.php">'.h($siteTitle).'</a></div>';
            echo '<div class="content no-sidebar"><main>';
        }
    }

    /**
     * The BCM wordmark (treble clef + name). The real white logo only exists
     * as a CMYK EPS; until a converted PNG is uploaded via admin settings,
     * this styled text mark stands in everywhere the logo belongs.
     */
    public static function brandWordmarkHtml(): string {
        return '<span class="brand-mark" aria-hidden="true">&#119070;</span><span class="brand-name">Bronx Conservatory of Music</span>';
    }

    // Footer shown on every page: navy band with the conservatory's phone
    // number, so a real person is always one call away.
    private static function siteFooterHtml(): string {
        return '<footer class="site-footer">'
            . '<span class="site-footer-name">Bronx Conservatory of Music</span>'
            . '<span class="site-footer-phone">Questions? Call us at <a href="tel:+17188417415">' . h(Settings::contactPhone()) . '</a></span>'
            . '</footer>';
    }

    public static function footerHtml(): void {
        echo '</main>' . self::siteFooterHtml() . '</div>' . self::jsScript('/main.js') . '</body></html>';
    }

    /**
     * Minimal chrome for pages outside the signed-in shell (public
     * registration, the /f/ token-link flow): no sidebar, no navigation —
     * a centered column with the BCM wordmark, and the phone number footer.
     */
    public static function minimalHeaderHtml(string $title): void {
        $siteTitle = Settings::siteTitle();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.h($title).' - '.h($siteTitle).'</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body class="minimal">';
        echo '<div class="minimal-header">'.self::brandWordmarkHtml().'</div>';
        echo '<div class="minimal-content"><main>';
    }

    public static function minimalFooterHtml(): void {
        echo '</main>' . self::siteFooterHtml() . '</div>' . self::jsScript('/main.js') . '</body></html>';
    }
}
