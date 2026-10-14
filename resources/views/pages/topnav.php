<?php
$memberRole = $member['role'] ?? 'member';
$memberDisplayName = $memberRole === 'member'
    ? 'Member'
    : ($member['name'] ?? 'Member');
$currentPage = $currentPage ?? '';

function topnavLink(string $page, string $href, string $label, string $currentPage, string $extraClass = ''): string {
    $activeClass = $currentPage === $page ? ' active' : '';
    $classes = 'nav-link' . $activeClass;
    if ($extraClass !== '') {
        $classes .= ' ' . $extraClass;
    }
    return '<li class="nav-item"><a class="' . $classes . '" href="' . $href . '">' . $label . '</a></li>';
}

function userInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = array_map(fn($part) => mb_substr($part, 0, 1), array_filter($parts));
    $initials = array_slice($initials, 0, 2);
    return strtoupper(implode('', $initials)) ?: 'U';
}

$topnavNotifications = [];
$announcementPath = dirname(__DIR__, 3) . '/announcements.json';
if (file_exists($announcementPath)) {
    $announcementData = json_decode(file_get_contents($announcementPath) ?: '[]', true);
    if (is_array($announcementData)) {
        foreach (array_slice(array_reverse($announcementData), 0, 3) as $announcement) {
            $announcementTitle = trim((string) ($announcement['title'] ?? 'Latest update'));
            $announcementPublished = isset($announcement['published_at']) ? trim((string) $announcement['published_at']) : '';
            $topnavNotifications[] = [
                'title' => 'New announcement: ' . $announcementTitle,
                'text' => $announcementPublished !== '' ? 'Published ' . date('M j, Y', strtotime($announcementPublished)) : 'Read the latest organization update.',
                'href' => 'announcements.php',
                'type' => 'announcement',
            ];
        }
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $activityUpdates = $pdo->query(
            "SELECT id, title, status, activity_date FROM activities
             WHERE status IN ('open', 'registration', 'planning')
             ORDER BY activity_date ASC, id DESC LIMIT 3"
        )->fetchAll();
        foreach ($activityUpdates as $activityUpdate) {
            $activityDate = trim((string) ($activityUpdate['activity_date'] ?? ''));
            if ($activityUpdate['status'] === 'planning') {
                $eventText = 'Planning in progress. Registration will open after the budget is spent.';
            } else {
                $eventText = $activityDate !== ''
                    ? 'Open for member registration. Scheduled for ' . date('M j, Y', strtotime($activityDate))
                    : 'Open for member registration.';
            }
            $topnavNotifications[] = [
                'title' => 'Reminder: ' . (string) $activityUpdate['title'],
                'text' => $eventText,
                'href' => 'activities.php',
                'type' => 'activity',
            ];
        }

        $feedbackAlerts = $pdo->query(
            "SELECT subject, created_at FROM feedback
             ORDER BY created_at DESC LIMIT 3"
        )->fetchAll();
        foreach ($feedbackAlerts as $feedbackAlert) {
            $subject = trim((string) ($feedbackAlert['subject'] ?? 'Feedback update'));
            $topnavNotifications[] = [
                'title' => 'Feedback: ' . $subject,
                'text' => 'New feedback received by the team.',
                'href' => 'feedback.php',
                'type' => 'feedback',
            ];
        }

        if (isset($member['id']) && function_exists('loadAttendanceFromStorage')) {
            $memberId = (int) $member['id'];
            $recentAttendance = loadAttendanceFromStorage();
            $memberAttendanceUpdates = [];
            foreach ($recentAttendance as $attendanceEntry) {
                $entryUserId = isset($attendanceEntry['user_id']) ? (int) $attendanceEntry['user_id'] : 0;
                if ($entryUserId === $memberId) {
                    $memberAttendanceUpdates[] = $attendanceEntry;
                }
            }
            foreach (array_slice(array_reverse($memberAttendanceUpdates), 0, 2) as $attendanceEntry) {
                $status = trim((string) ($attendanceEntry['status'] ?? 'recorded'));
                $entryDate = trim((string) ($attendanceEntry['date'] ?? $attendanceEntry['event_date'] ?? ''));
                $topnavNotifications[] = [
                    'title' => 'Attendance updated: ' . ucfirst($status),
                    'text' => $entryDate !== '' ? 'Recorded on ' . date('M j, Y', strtotime($entryDate)) : 'Your attendance status was updated.',
                    'href' => 'attendance.php',
                    'type' => 'attendance',
                ];
            }
        }

        if (in_array($memberRole, ['officer', 'admin'], true)) {
            $pendingApplications = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'inactive' AND role = 'member'")->fetchColumn();
            if ($pendingApplications > 0) {
                $topnavNotifications[] = [
                    'title' => $pendingApplications . ' application' . ($pendingApplications === 1 ? '' : 's') . ' awaiting verification',
                    'text' => 'Review pending youth member applications.',
                    'href' => 'members.php',
                    'type' => 'application',
                ];
            }
        }
    } catch (PDOException $exception) {
        $topnavNotifications = $topnavNotifications;
    }
}

$topnavNotifications = array_slice($topnavNotifications, 0, 7);
$topnavNotificationCount = count($topnavNotifications);
?>
<script>
    (function () {
        const savedTheme = window.localStorage.getItem('youth-theme');
        if (savedTheme === 'dark' || savedTheme === 'light') {
            document.documentElement.dataset.theme = savedTheme;
        }
    }());
</script>
<style>
    body {
        padding-left: 240px;
        padding-top: 72px;
        transition: padding-left 0.25s ease, padding-top 0.25s ease;
    }

    body.sidebar-collapsed {
        padding-left: 0;
        padding-top: 58px;
    }

    .sidebar-shell {
        position: fixed;
        left: 0;
        top: 0;
        width: 240px;
        height: 100vh;
        display: flex;
        flex-direction: column;
        background: linear-gradient(180deg, #0d2b1f 0%, #15422f 55%, #1f5a3d 100%);
        color: #ffffff;
        box-shadow: 10px 0 30px rgba(0, 0, 0, 0.16);
        z-index: 1040;
        overflow-y: auto;
        overflow-x: hidden;
        transition: transform 0.25s ease;
    }

    body.sidebar-collapsed .sidebar-shell {
        transform: translateX(-100%);
    }

    .sidebar-shell .brand-block {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.8rem 0.95rem 0.6rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.16);
        flex-shrink: 0;
    }

    .sidebar-shell .brand-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, #f7c948 0%, #e9a92b 100%);
        color: #1d2c1f;
        font-size: 1rem;
        overflow: hidden;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18);
    }

    .sidebar-shell .brand-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .sidebar-shell .brand-label {
        font-weight: 800;
        letter-spacing: 0.02em;
        font-size: 0.95rem;
        line-height: 1.2;
    }

    .sidebar-shell .nav-list {
        list-style: none;
        padding: 0.6rem 0.8rem 0.45rem;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        flex: 1 1 auto;
    }

    .sidebar-shell .nav-item {
        margin: 0;
    }

    .sidebar-shell .nav-section-label {
        padding: 0.8rem 0.8rem 0.2rem;
        color: rgba(247, 201, 72, 0.82);
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .sidebar-shell .nav-section-label:first-child {
        padding-top: 0.2rem;
    }

    .sidebar-shell .nav-link {
        display: flex;
        align-items: center;
        width: 100%;
        color: #f6fff9;
        padding: 0.62rem 0.8rem;
        border-radius: 0.8rem;
        font-weight: 700;
        transition: all 0.18s ease;
        white-space: nowrap;
        font-size: 0.92rem;
        text-decoration: none;
        letter-spacing: 0.01em;
    }

    .sidebar-shell .nav-link:hover,
    .sidebar-shell .nav-link.active {
        background: rgba(247, 201, 72, 0.2);
        color: #fff6d8;
        transform: translateX(2px);
        box-shadow: inset 0 0 0 1px rgba(247, 201, 72, 0.3);
    }

    .sidebar-shell .nav-footer {
        padding: 0.6rem 0.9rem 0.8rem;
        border-top: 1px solid rgba(255, 255, 255, 0.16);
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        flex-shrink: 0;
    }

    .sidebar-shell .action-buttons {
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .sidebar-shell .action-buttons .action-link {
        justify-content: center;
        text-align: center;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.24);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.12);
        font-weight: 800;
        letter-spacing: 0.02em;
        font-size: 0.9rem;
        padding: 0.6rem 0.8rem;
    }

    .sidebar-shell .action-buttons .action-link:hover {
        transform: translateY(-1px);
    }

    .sidebar-shell .action-buttons .profile-link {
        background: linear-gradient(135deg, #f7c948 0%, #e9a92b 100%);
        color: #23341e;
        border-color: rgba(255, 255, 255, 0.24);
    }

    .sidebar-shell .action-buttons .profile-link:hover,
    .sidebar-shell .action-buttons .profile-link.active {
        background: linear-gradient(135deg, #ffd96a 0%, #f2b72b 100%);
        color: #23341e;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25);
    }

    .sidebar-shell .action-buttons .settings-link {
        background: rgba(255, 255, 255, 0.12);
        color: #f6fff9;
    }

    .sidebar-shell .action-buttons .settings-link:hover,
    .sidebar-shell .action-buttons .settings-link.active {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
    }

    .sidebar-shell .action-buttons .logout-link {
        background: linear-gradient(135deg, #ff6b6b 0%, #d84545 100%);
        color: #ffffff;
        border-color: rgba(255, 255, 255, 0.35);
        box-shadow: 0 10px 18px rgba(216, 69, 69, 0.28);
    }

    .sidebar-shell .action-buttons .logout-link:hover {
        background: linear-gradient(135deg, #ff8a8a 0%, #ef4d4d 100%);
        color: #ffffff;
        border-color: #ffb0b0;
        box-shadow: 0 12px 22px rgba(216, 69, 69, 0.34);
    }

    .sidebar-shell .user-badge {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 999px;
        padding: 0.45rem 0.7rem;
    }

    .sidebar-shell .user-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #f7c948 0%, #e9a92b 100%);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #21311f;
        font-size: 0.85rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        flex-shrink: 0;
    }

    .sidebar-shell .user-role {
        color: rgba(255, 255, 255, 0.95);
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        line-height: 1.2;
    }

    .app-header {
        position: fixed;
        top: 0;
        left: 240px;
        right: 0;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 0.85rem;
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid rgba(13, 43, 31, 0.12);
        box-shadow: 0 8px 24px rgba(8, 24, 18, 0.06);
        z-index: 1030;
        transition: left 0.25s ease;
    }

    body.sidebar-collapsed .app-header {
        left: 0;
    }

    .sidebar-open-toggle {
        position: fixed;
        top: 0.75rem;
        left: 0.75rem;
        border: 0;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, #f7c948 0%, #e9a92b 100%);
        color: #17311f;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        font-weight: 800;
        box-shadow: 0 8px 18px rgba(13, 43, 31, 0.18);
        cursor: pointer;
        z-index: 1050;
    }

    body.sidebar-collapsed .sidebar-open-toggle {
        display: inline-flex;
    }

    .sidebar-shell .sidebar-toggle {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        border: 0;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #f7c948 0%, #e9a92b 100%);
        color: #17311f;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        font-weight: 800;
        box-shadow: 0 6px 12px rgba(13, 43, 31, 0.12);
        cursor: pointer;
        z-index: 3;
    }

    .app-header .header-title {
        font-size: 0.95rem;
        font-weight: 900;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #163625;
        text-align: center;
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
    }

    .app-header .header-profile {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: auto;
        border-radius: 999px;
        padding: 0.25rem 0.45rem;
        background: rgba(13, 43, 31, 0.06);
        position: absolute;
        right: 1.25rem;
    }

    .header-tools {
        position: absolute;
        right: 5.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .notification-control {
        position: relative;
    }

    .notification-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        padding: 0;
        border: 1px solid rgba(18, 53, 42, 0.12);
        border-radius: 8px;
        color: #12352a;
        background: #fff;
        cursor: pointer;
    }

    .notification-button:hover,
    .notification-button:focus-visible {
        color: #226147;
        background: #f2f7f4;
        outline: 2px solid rgba(47, 138, 99, 0.25);
        outline-offset: 2px;
    }

    .notification-button svg {
        width: 19px;
        height: 19px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.8;
    }

    .notification-count {
        position: absolute;
        top: -5px;
        right: -5px;
        min-width: 18px;
        height: 18px;
        padding: 0 4px;
        border: 2px solid #fff;
        border-radius: 999px;
        color: #fff;
        background: #d94c4c;
        font-size: 0.65rem;
        font-weight: 800;
        line-height: 14px;
        text-align: center;
    }

    .notification-menu {
        position: absolute;
        top: calc(100% + 12px);
        right: 0;
        z-index: 1100;
        display: none;
        width: min(360px, calc(100vw - 2rem));
        overflow: hidden;
        border: 1px solid #dfe8e3;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 16px 35px rgba(18, 53, 42, 0.18);
    }

    .notification-menu.is-open {
        display: block;
    }

    .notification-menu-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px;
        border-bottom: 1px solid #e3ece6;
        color: #12352a;
        font-size: 0.9rem;
        font-weight: 800;
    }

    .notification-item {
        display: block;
        padding: 12px 16px;
        border-bottom: 1px solid #eef3ef;
        color: #18251f;
        text-decoration: none;
    }

    .notification-item:hover,
    .notification-item:focus-visible {
        background: #f5faf7;
        color: #12352a;
    }

    .notification-item-title {
        display: block;
        font-size: 0.82rem;
        font-weight: 800;
        line-height: 1.35;
    }

    .notification-item-text {
        display: block;
        margin-top: 3px;
        color: #66746e;
        font-size: 0.75rem;
    }

    .notification-empty {
        padding: 18px 16px;
        color: #66746e;
        font-size: 0.82rem;
        text-align: center;
    }

    .theme-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        padding: 0;
        border: 1px solid rgba(18, 53, 42, 0.12);
        border-radius: 8px;
        color: #12352a;
        background: #fff;
        cursor: pointer;
    }

    .theme-toggle:hover,
    .theme-toggle:focus-visible {
        color: #226147;
        background: #f2f7f4;
        outline: 2px solid rgba(47, 138, 99, 0.25);
        outline-offset: 2px;
    }

    .theme-toggle svg {
        width: 19px;
        height: 19px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.8;
    }

    .theme-moon {
        display: none;
    }

    html[data-theme="dark"] .theme-moon {
        display: block;
    }

    html[data-theme="dark"] .theme-toggle svg {
        stroke: #ffffff;
    }

    html[data-theme="dark"] .theme-toggle,
    html[data-theme="dark"] .notification-button {
        border-color: #40554a;
        color: #f2c75e;
        background: #22312b;
    }

    html[data-theme="dark"] .theme-toggle:hover,
    html[data-theme="dark"] .theme-toggle:focus-visible,
    html[data-theme="dark"] .notification-button:hover,
    html[data-theme="dark"] .notification-button:focus-visible {
        color: #ffe39a;
        background: #2b4035;
    }

    html[data-theme="dark"] .notification-menu {
        border-color: var(--yo-border);
        background: var(--yo-surface);
    }

    html[data-theme="dark"] .notification-menu-header {
        border-bottom-color: var(--yo-border);
        color: var(--yo-ink);
    }

    html[data-theme="dark"] .notification-item {
        border-bottom-color: var(--yo-border);
        color: var(--yo-ink);
    }

    html[data-theme="dark"] .notification-item:hover,
    html[data-theme="dark"] .notification-item:focus-visible {
        background: #26372f;
        color: var(--yo-ink);
    }

    html[data-theme="dark"] .notification-item-text,
    html[data-theme="dark"] .notification-empty {
        color: var(--yo-muted);
    }

    @media (max-width: 575.98px) {
        .header-tools {
            right: 4.5rem;
        }
    }

    .app-header .header-profile .user-icon {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #f7c948 0%, #e9a92b 100%);
        color: #21311f;
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.08em;
    }

    .sidebar-shell {
        background: linear-gradient(180deg, #12352a 0%, #194334 58%, #226147 100%);
        box-shadow: 16px 0 40px rgba(18, 53, 42, 0.18);
    }

    .sidebar-shell .brand-block {
        min-height: 72px;
        padding: 1rem 1rem 0.9rem;
        border-bottom-color: rgba(255, 255, 255, 0.12);
    }

    .sidebar-shell .brand-icon {
        border-radius: 8px;
        background: #f2b94b;
        font-size: 0.82rem;
        font-weight: 900;
        letter-spacing: 0;
    }

    .sidebar-shell .brand-icon img {
        border-radius: 8px;
    }

    .sidebar-shell .brand-label {
        font-size: 0.9rem;
        letter-spacing: 0;
    }

    .sidebar-shell .nav-list {
        padding: 0.85rem;
        gap: 0.2rem;
    }

    .sidebar-shell .nav-link {
        border-radius: 7px;
        padding: 0.68rem 0.75rem;
        color: rgba(255, 255, 255, 0.82);
        font-size: 0.91rem;
        font-weight: 700;
        letter-spacing: 0;
    }

    .sidebar-shell .nav-link:hover,
    .sidebar-shell .nav-link.active {
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        transform: none;
        box-shadow: inset 3px 0 0 #f2b94b;
    }

    .sidebar-shell .nav-footer {
        border-top-color: rgba(255, 255, 255, 0.12);
    }

    .sidebar-shell .action-buttons .action-link {
        border-radius: 7px;
        box-shadow: none;
    }

    .sidebar-shell .action-buttons .profile-link {
        background: #f2b94b;
        color: #17311f;
    }

    .sidebar-shell .action-buttons .settings-link {
        background: rgba(255, 255, 255, 0.09);
    }

    .sidebar-shell .action-buttons .logout-link {
        background: #d94c4c;
        box-shadow: none;
    }

    .sidebar-shell .user-badge {
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.09);
    }

    .sidebar-shell .user-icon,
    .app-header .header-profile .user-icon {
        background: #f2b94b;
        color: #17311f;
    }

    .sidebar-shell .sidebar-toggle,
    .sidebar-open-toggle {
        border-radius: 7px;
        background: #f2b94b;
        box-shadow: 0 10px 18px rgba(18, 53, 42, 0.18);
    }

    .app-header {
        height: 58px;
        background: rgba(255, 255, 255, 0.9);
        border-bottom-color: rgba(18, 53, 42, 0.1);
    }

    .app-header .header-title {
        color: #12352a;
        letter-spacing: 0.08em;
    }

    .app-header .header-profile {
        background: rgba(18, 53, 42, 0.06);
    }

    html[data-theme="dark"] .app-header .header-title {
        color: #ffffff !important;
    }

    html[data-theme="dark"] .app-header .header-tools,
    html[data-theme="dark"] .app-header .header-tools svg {
        color: #ffffff;
    }

    @media (max-width: 991.98px) {
        body {
            padding-left: 0;
            padding-top: 58px;
        }

        .sidebar-shell {
            width: min(88vw, 280px);
            position: fixed;
            transform: translateX(-100%);
        }

        body:not(.sidebar-collapsed) .sidebar-shell {
            transform: translateX(0);
        }

        .sidebar-open-toggle {
            display: inline-flex;
        }

        body:not(.sidebar-collapsed) .sidebar-open-toggle {
            display: none;
        }

        .app-header {
            left: 0;
        }

        .app-header .header-title {
            font-size: 0.78rem;
            max-width: calc(100vw - 120px);
            overflow: hidden;
            text-overflow: ellipsis;
        }
    }
</style>

<div class="sidebar-shell">
    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Hide sidebar" title="Hide sidebar">
        <span class="toggle-icon">&#9664;</span>
    </button>
    <div class="brand-block" aria-label="Youth Organization" style="padding-right: 3rem;">
        <span class="brand-icon">
            <img src="uploads/gallery/P1.jpg" alt="Youth Organization logo" onerror="this.style.display='none'; this.parentElement.textContent='YO';">
        </span>
        <span class="brand-label">Youth Organization</span>
    </div>

    <ul class="nav-list">
        <li class="nav-section-label">Updates</li>
        <?php echo topnavLink('home', 'homepage.php', 'Home', $currentPage); ?>
        <?php echo topnavLink('announcements', 'announcements.php', 'Announcements', $currentPage); ?>
        <?php echo topnavLink('activities', 'activities.php', 'Activities', $currentPage); ?>
        <?php echo topnavLink('events-calendar', 'events-calendar.php', 'Events Calendar', $currentPage); ?>
        <?php echo topnavLink('meeting-agendas', 'meeting-agendas.php', 'Meeting Agendas', $currentPage); ?>

        <li class="nav-section-label">Participation</li>
        <?php echo topnavLink('feedback', 'feedback.php', 'Feedback', $currentPage); ?>
        <?php echo topnavLink('attendance', 'attendance.php', 'My Attendance', $currentPage); ?>
        <?php echo topnavLink('gallery', 'gallery.php', 'Gallery', $currentPage); ?>

        <?php if (in_array($memberRole, ['officer', 'admin'], true)): ?>
            <li class="nav-section-label">Management</li>
            <?php echo topnavLink('dashboard', 'dashboard.php', 'Dashboard', $currentPage); ?>
            <?php echo topnavLink('funds-budget', 'funds-budget.php', 'Funds / Budget', $currentPage); ?>
            <?php echo topnavLink('members', 'members.php', 'Members Management', $currentPage); ?>
            <?php echo topnavLink('project-proposals', 'project-proposals.php', 'Project Proposals', $currentPage); ?>
            <?php echo topnavLink('documents', 'documents.php', 'Documents', $currentPage); ?>

            <li class="nav-section-label">Reports</li>
            <?php echo topnavLink('activity-report', 'activity-report.php', 'Activity Reports', $currentPage); ?>
            <?php echo topnavLink('activity-insights', 'activity-insights.php', 'Activity Insights', $currentPage); ?>
            <?php echo topnavLink('reports', 'reports.php', 'Reports', $currentPage); ?>
        <?php endif; ?>
    </ul>

    <div class="nav-footer">
        <div class="action-buttons">
            <?php echo topnavLink('profile', 'profile.php', 'My Profile', $currentPage, 'action-link profile-link'); ?>
            <?php if (file_exists(dirname(__DIR__, 3) . '/settings.php')): ?>
                <?php echo topnavLink('settings', 'settings.php', 'Settings', $currentPage, 'action-link settings-link'); ?>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link action-link logout-link" href="login.php?logout=1">Logout</a></li>
        </div>
        <div class="user-badge" style="margin-top: 0.85rem;">
            <div class="user-icon">
                <?php echo htmlspecialchars(userInitials($memberDisplayName), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="user-role">
                <?php echo htmlspecialchars(strtoupper($memberRole), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    </div>
</div>

<button type="button" class="sidebar-open-toggle" id="sidebarOpenToggle" aria-label="Show sidebar" title="Show sidebar" aria-expanded="false">
    <span aria-hidden="true">&#9776;</span>
</button>

<header class="app-header">
    <div class="header-title">Youth Organization</div>
    <div class="header-tools">
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="Enable dark mode" title="Enable dark mode">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path class="theme-sun" d="M12 3v2M12 19v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M3 12h2M19 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"></path>
                <circle class="theme-sun" cx="12" cy="12" r="3.5"></circle>
                <path class="theme-moon" d="M20 15.5A7.5 7.5 0 0 1 8.5 4 7.5 7.5 0 1 0 20 15.5Z"></path>
            </svg>
        </button>
        <div class="notification-control">
            <button type="button" class="notification-button" id="notificationButton" aria-label="Notifications" aria-expanded="false" title="Notifications">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                    <path d="M10 21h4"></path>
                </svg>
                <?php if ($topnavNotificationCount > 0): ?>
                    <span class="notification-count"><?php echo $topnavNotificationCount > 9 ? '9+' : $topnavNotificationCount; ?></span>
                <?php endif; ?>
            </button>
            <div class="notification-menu" id="notificationMenu" role="menu" aria-label="Notifications">
                <div class="notification-menu-header">
                    <span>Notifications</span>
                    <span class="text-muted small"><?php echo $topnavNotificationCount; ?> update<?php echo $topnavNotificationCount === 1 ? '' : 's'; ?></span>
                </div>
                <?php if ($topnavNotifications): ?>
                    <?php foreach ($topnavNotifications as $notification): ?>
                        <a class="notification-item" href="<?php echo htmlspecialchars($notification['href'], ENT_QUOTES, 'UTF-8'); ?>" role="menuitem">
                            <span class="notification-item-title"><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="notification-item-text"><?php echo htmlspecialchars($notification['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notification-empty">You are all caught up.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="header-profile">
        <div class="user-icon">
            <?php echo htmlspecialchars(userInitials($memberDisplayName), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
</header>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggleButton = document.getElementById('sidebarToggle');
        const openButton = document.getElementById('sidebarOpenToggle');
        if (!toggleButton || !openButton) {
            return;
        }

        function setSidebarCollapsed(collapsed) {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            const icon = toggleButton.querySelector('.toggle-icon');
            if (icon) {
                icon.innerHTML = collapsed ? '&#9776;' : '&#9664;';
            }
            toggleButton.setAttribute('aria-label', collapsed ? 'Show sidebar' : 'Hide sidebar');
            toggleButton.setAttribute('title', collapsed ? 'Show sidebar' : 'Hide sidebar');
            toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            openButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }

        toggleButton.addEventListener('click', function () {
            setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        });

        openButton.addEventListener('click', function () {
            setSidebarCollapsed(false);
        });

        if (window.matchMedia('(max-width: 991.98px)').matches) {
            setSidebarCollapsed(true);
        }

        const notificationButton = document.getElementById('notificationButton');
        const notificationMenu = document.getElementById('notificationMenu');
        if (notificationButton && notificationMenu) {
            notificationButton.addEventListener('click', function (event) {
                event.stopPropagation();
                const isOpen = notificationMenu.classList.toggle('is-open');
                notificationButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function () {
                notificationMenu.classList.remove('is-open');
                notificationButton.setAttribute('aria-expanded', 'false');
            });
        }

        const themeButton = document.getElementById('themeToggle');
        if (themeButton) {
            function updateThemeButton() {
                const isDark = document.documentElement.dataset.theme === 'dark';
                themeButton.setAttribute('aria-label', isDark ? 'Enable light mode' : 'Enable dark mode');
                themeButton.setAttribute('title', isDark ? 'Enable light mode' : 'Enable dark mode');
                themeButton.querySelectorAll('.theme-sun').forEach(function (icon) {
                    icon.style.display = isDark ? 'none' : '';
                });
                themeButton.querySelectorAll('.theme-moon').forEach(function (icon) {
                    icon.style.display = isDark ? '' : 'none';
                });
            }

            themeButton.addEventListener('click', function () {
                const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                document.documentElement.dataset.theme = nextTheme;
                window.localStorage.setItem('youth-theme', nextTheme);
                updateThemeButton();
            });
            updateThemeButton();
        }
    });
</script>
