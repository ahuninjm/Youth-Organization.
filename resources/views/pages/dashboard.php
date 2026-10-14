<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__, 3) . '/db.php';
require_once dirname(__DIR__, 3) . '/attendance_store.php';
require_once dirname(__DIR__, 3) . '/announcements_store.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$member = $_SESSION['user'];
$memberRecord = [];

if (isset($member['id'])) {
    $memberQuery = $pdo->prepare('SELECT id, name, username, role, email, phone, status, created_at FROM users WHERE id = ? LIMIT 1');
    $memberQuery->execute([$member['id']]);
    $memberRecord = $memberQuery->fetch() ?: [];
}

$memberName = $memberRecord['name'] ?? $member['name'];
$memberUsername = $memberRecord['username'] ?? $member['username'];
$memberRole = $memberRecord['role'] ?? $member['role'] ?? 'member';
$memberUsername = $memberRecord['username'] ?? $member['username'] ?? '';
$isPrivileged = in_array($memberRole, ['officer', 'admin'], true);

if (!$isPrivileged) {
    header('Location: homepage.php');
    exit;
}

$currentPage = 'dashboard';
$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'warning';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$attendanceRecords = loadAttendanceFromStorage();
$userAttendance = [];
foreach ($attendanceRecords as $record) {
    $matchesUser = false;
    if (isset($record['user_id']) && isset($member['id']) && (int)$record['user_id'] === (int)$member['id']) {
        $matchesUser = true;
    } elseif (isset($record['username']) && $memberUsername !== '' && strcasecmp($record['username'], $memberUsername) === 0) {
        $matchesUser = true;
    }

    if ($matchesUser) {
        $userAttendance[] = $record;
    }
}

$totalAttendanceRecords = count($userAttendance);
$presentCount = 0;
foreach ($userAttendance as $record) {
    if (strcasecmp(trim((string)($record['status'] ?? '')), 'present') === 0) {
        $presentCount++;
    }
}

$attendancePercentage = $totalAttendanceRecords > 0 ? (int)round($presentCount * 100 / $totalAttendanceRecords) : 0;
$attendanceSummary = sprintf('%d out of %d', $presentCount, $totalAttendanceRecords);

$agendas = json_decode(file_get_contents(dirname(__DIR__, 3) . '/meeting-agendas.json') ?: '[]', true);
$today = date('Y-m-d');
$upcomingEventsQuery = $pdo->prepare(
    "SELECT COUNT(*) FROM activities WHERE activity_date >= ? AND status IN ('open', 'planning')"
);
$upcomingEventsQuery->execute([$today]);
$upcomingEvents = (int) $upcomingEventsQuery->fetchColumn();

$announcements = loadAnnouncementsFromStorage();
$announcementCount = count($announcements);

$totalMembers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$totalActivities = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
$openActivities = (int) $pdo->query("SELECT COUNT(*) FROM activities WHERE status = 'open'")->fetchColumn();
$planningActivities = (int) $pdo->query("SELECT COUNT(*) FROM activities WHERE status = 'planning'")->fetchColumn();
$completedActivities = (int) $pdo->query("SELECT COUNT(*) FROM activities WHERE status = 'completed'")->fetchColumn();
$totalFeedback = (int) $pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();

$activityBreakdown = [
    'Open' => $openActivities,
    'Planning' => $planningActivities,
    'Completed' => $completedActivities,
];
$maxActivityBreakdown = max(1, max($activityBreakdown));

$recentActivityRows = [];
$recentActivities = $pdo->query(
    "SELECT title, status, activity_date FROM activities ORDER BY activity_date DESC, id DESC LIMIT 5"
)->fetchAll();
foreach ($recentActivities as $item) {
    $recentActivityRows[] = [
        'label' => 'Activity',
        'title' => $item['title'],
        'meta' => ucfirst((string) $item['status']) . ' • ' . date('M j, Y', strtotime((string) $item['activity_date'])),
    ];
}

$recentFeedback = $pdo->query(
    "SELECT subject, created_at FROM feedback ORDER BY created_at DESC LIMIT 5"
)->fetchAll();
foreach ($recentFeedback as $item) {
    $recentActivityRows[] = [
        'label' => 'Feedback',
        'title' => $item['subject'],
        'meta' => 'Submitted ' . date('M j, Y', strtotime((string) $item['created_at'])),
    ];
}
usort($recentActivityRows, static function ($left, $right) {
    $leftMeta = $left['meta'];
    $rightMeta = $right['meta'];
    return strcasecmp($rightMeta, $leftMeta);
});
$recentActivityRows = array_slice($recentActivityRows, 0, 6);

$auditLogs = $pdo->query(
    'SELECT a.*, COALESCE(u.name, a.actor_name) AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 8'
)->fetchAll();

$memberRegistrations = [];
$memberProposals = [];
if (isset($member['id'])) {
    $registrationQuery = $pdo->prepare(
        'SELECT a.title, a.activity_date, ar.registered_at
         FROM activity_registrations ar
         INNER JOIN activities a ON a.id = ar.activity_id
         WHERE ar.user_id = ?
         ORDER BY ar.registered_at DESC
         LIMIT 5'
    );
    $registrationQuery->execute([(int) $member['id']]);
    $memberRegistrations = $registrationQuery->fetchAll();

    $proposalQuery = $pdo->prepare(
        'SELECT title, status, submitted_at
         FROM project_proposals
         WHERE submitted_by = ?
         ORDER BY submitted_at DESC
         LIMIT 5'
    );
    $proposalQuery->execute([(int) $member['id']]);
    $memberProposals = $proposalQuery->fetchAll();
}

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --brand: #2454a6;
            --accent: #1f8a5f;
            --warm: #f2a541;
            --ink: #1d2530;
            --page: #f3f6fa;
        }
        body {
            background: var(--page);
            color: var(--ink);
            min-height: 100vh;
        }
        .app-shell {
            display: block;
            min-height: 100vh;
        }
        .sidebar {
            display: none;
        }
        .brand-lockup {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }
        .brand-mark {
            display: grid;
            place-items: center;
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: var(--accent);
            font-weight: 800;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.78);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 4px;
            text-decoration: none;
            display: block;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .member-chip {
            margin-top: 28px;
            padding: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
        }
        .topbar {
            padding: 20px 28px;
            background: white;
            border-bottom: 1px solid #dbe3ef;
        }
        .content {
            padding: 28px;
            overflow-y: auto;
            max-height: calc(100vh - 80px);
        }
        .welcome-pill {
            display: inline-block;
            margin-bottom: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.18);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.16em;
        }
        .welcome-section {
            background: linear-gradient(135deg, var(--brand), var(--accent));
            color: white;
            padding: 32px;
            border-radius: 12px;
            margin-bottom: 32px;
        }
        .welcome-section h2 {
            margin: 0 0 8px 0;
            font-size: 28px;
        }
        .welcome-section p {
            margin: 0;
            opacity: 0.95;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid #dbe3ef;
            text-align: center;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: var(--brand);
            margin: 10px 0;
        }
        .stat-label {
            font-size: 14px;
            color: #667085;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .section-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #dbe3ef;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
        }
        .section-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
            border-color: var(--accent);
        }
        .section-icon {
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }
        .section-icon.meetings { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .section-icon.activities { background: linear-gradient(135deg, #16a085 0%, #f4d03f 100%); }
        .section-icon.announcements { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .section-icon.profile { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .section-icon.attendance { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .section-icon.gallery { background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); }
        .section-content {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px 0;
        }
        .section-desc {
            font-size: 13px;
            color: #667085;
            flex-grow: 1;
            margin: 0;
        }
        .section-arrow {
            margin-top: 12px;
            color: var(--accent);
            font-weight: 600;
            font-size: 14px;
        }
        .main {
            display: flex;
            flex-direction: column;
        }
        .dashboard-link-card {
            display: block;
            height: 100%;
            color: inherit;
            text-decoration: none;
        }
        .dashboard-link-card .stat-card {
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .dashboard-link-card:hover .stat-card {
            border-color: var(--accent);
            box-shadow: 0 12px 28px rgba(18, 53, 42, 0.12);
            transform: translateY(-3px);
        }
        .dashboard-link-card:focus-visible {
            outline: 3px solid rgba(47, 138, 99, 0.3);
            outline-offset: 3px;
        }
        .dashboard-card-action {
            color: var(--accent);
            font-size: 0.82rem;
            font-weight: 800;
        }
        .executive-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin: 0 0 28px;
        }
        .chart-card,
        .activity-panel,
        .member-record {
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
        }
        .card-header-block {
            padding: 18px 20px;
            border-bottom: 1px solid #dbe3ef;
        }
        .card-header-block h3 {
            margin: 0 0 4px;
            color: var(--ink);
            font-size: 1.15rem;
        }
        .card-header-block p {
            margin: 0;
            color: #667085;
            font-size: 0.85rem;
        }
        .chart-body {
            padding: 20px 20px 12px;
        }
        .chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            height: 170px;
            padding-top: 12px;
        }
        .bar-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            height: 100%;
        }
        .mini-bar {
            width: 100%;
            max-width: 60px;
            border-radius: 10px 10px 0 0;
            background: linear-gradient(180deg, var(--brand), var(--accent));
            min-height: 10px;
            position: relative;
        }
        .bar-label {
            font-size: 0.72rem;
            color: #667085;
            font-weight: 600;
        }
        .bar-value {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--ink);
        }
        .activity-panel-body {
            padding: 12px 20px 20px;
        }
        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .activity-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border-bottom: 1px solid #eef1f4;
            padding-bottom: 10px;
        }
        .activity-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .activity-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent);
            margin-top: 7px;
            flex-shrink: 0;
        }
        .activity-item.feedback .activity-dot {
            background: var(--warm);
        }
        .activity-content strong {
            display: block;
            font-size: 0.92rem;
            color: var(--ink);
            margin-bottom: 3px;
        }
        .activity-content span {
            font-size: 0.78rem;
            color: #667085;
        }
        .member-record {
            margin-top: 28px;
        }
        .member-record-header {
            padding: 18px 20px;
            border-bottom: 1px solid #dbe3ef;
        }
        .member-record-header h3 {
            margin: 0 0 4px;
            color: var(--ink);
            font-size: 1.15rem;
        }
        .member-record-header p {
            margin: 0;
            color: #667085;
            font-size: 0.85rem;
        }
        .member-record-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }
        .member-record-table th,
        .member-record-table td {
            padding: 12px 20px;
            border-bottom: 1px solid #eef1f4;
            text-align: left;
            vertical-align: middle;
        }
        .member-record-table th {
            color: #667085;
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .member-record-table td {
            color: var(--ink);
            font-size: 0.88rem;
        }
        .member-record-table tr:last-child td {
            border-bottom: 0;
        }
        .record-empty {
            padding: 22px 20px;
            color: #667085;
            font-size: 0.88rem;
        }
        @media (max-width: 575.98px) {
            .member-record-table th,
            .member-record-table td {
                padding: 10px 14px;
            }
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">

        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">Dashboard</h1>
            </div>

            <div class="content">
                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert-<?php echo legacyEscape($flashType); ?> mb-3" role="alert">
                        <?php echo legacyEscape($flashMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="welcome-section">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div>
                            <div class="welcome-pill">Member portal</div>
                            <h2>Welcome back, <?php echo legacyEscape($memberName); ?>!</h2>
                            <p>Stay informed, stay engaged, and keep your participation visible with one organized dashboard.</p>
                        </div>
                        <div class="text-lg-end">
                            <div style="font-size: 12px; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.16em;">Today at a glance</div>
                            <div style="font-size: 18px; font-weight: 700;">Your next step is just one click away.</div>
                        </div>
                    </div>
                </div>

                <div class="stats-grid" style="margin-bottom: 20px;">
                    <div class="stat-card">
                        <div class="stat-label">Active Members</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$totalMembers); ?></div>
                        <div style="font-size: 12px; color: #667085;">Registered and active</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Activities</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$totalActivities); ?></div>
                        <div style="font-size: 12px; color: #667085;">Total activities tracked</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Open Activities</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$openActivities); ?></div>
                        <div style="font-size: 12px; color: #667085;">Currently accepting participation</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Feedback</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$totalFeedback); ?></div>
                        <div style="font-size: 12px; color: #667085;">Submissions received</div>
                    </div>
                </div>

                <div class="stats-grid">
                    <a href="attendance.php" class="dashboard-link-card">
                    <div class="stat-card">
                        <div class="stat-label">Your Attendance</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$attendancePercentage); ?>%</div>
                        <div style="font-size: 12px; color: #667085;">
                            <?php echo legacyEscape($attendanceSummary); ?>
                        </div>
                        <div class="dashboard-card-action mt-3">View attendance</div>
                    </div>
                    </a>
                    <a href="activities.php" class="dashboard-link-card">
                    <div class="stat-card">
                        <div class="stat-label">Upcoming Events</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$upcomingEvents); ?></div>
                        <div style="font-size: 12px; color: #667085;">Events from today onward</div>
                        <div class="dashboard-card-action mt-3">View activities</div>
                    </div>
                    </a>
                    <a href="announcements.php" class="dashboard-link-card">
                    <div class="stat-card">
                        <div class="stat-label">Announcements</div>
                        <div class="stat-value"><?php echo legacyEscape((string)$announcementCount); ?></div>
                        <div style="font-size: 12px; color: #667085;">Latest updates available</div>
                        <div class="dashboard-card-action mt-3">View announcements</div>
                    </div>
                    </a>
                </div>

                <div class="executive-grid">
                    <div class="chart-card">
                        <div class="card-header-block">
                            <h3>Program Status</h3>
                            <p>Activity pipeline overview</p>
                        </div>
                        <div class="chart-body">
                            <div class="chart-bars">
                                <?php foreach ($activityBreakdown as $label => $value): ?>
                                    <div class="bar-group">
                                        <div class="bar-value"><?php echo legacyEscape((string) $value); ?></div>
                                        <div class="mini-bar" style="height: <?php echo legacyEscape((string) round(($value / $maxActivityBreakdown) * 100)); ?>%;"></div>
                                        <div class="bar-label"><?php echo legacyEscape((string) $label); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="activity-panel">
                        <div class="card-header-block">
                            <h3>Recent Activity</h3>
                            <p>Latest updates across the system</p>
                        </div>
                        <div class="activity-panel-body">
                            <ul class="activity-list">
                                <?php foreach ($recentActivityRows as $activity): ?>
                                    <li class="activity-item <?php echo legacyEscape(strtolower((string) $activity['label'])) === 'feedback' ? 'feedback' : ''; ?>">
                                        <span class="activity-dot"></span>
                                        <div class="activity-content">
                                            <strong><?php echo legacyEscape((string) $activity['title']); ?></strong>
                                            <span><?php echo legacyEscape((string) $activity['label']); ?> • <?php echo legacyEscape((string) $activity['meta']); ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="member-record">
                    <div class="member-record-header">
                        <h3>My Member Record</h3>
                        <p>Your recent registrations, attendance, and project actions.</p>
                    </div>
                    <?php if ($userAttendance || $memberRegistrations || $memberProposals): ?>
                        <div class="table-responsive">
                            <table class="member-record-table">
                                <thead><tr><th>Action</th><th>Details</th><th>Date / status</th></tr></thead>
                                <tbody>
                                    <?php foreach (array_slice($userAttendance, 0, 4) as $record): ?>
                                        <tr><td>Attendance</td><td><?php echo legacyEscape((string) ($record['event'] ?? 'Activity')); ?></td><td><?php echo legacyEscape((string) ($record['status'] ?? 'Recorded')); ?></td></tr>
                                    <?php endforeach; ?>
                                    <?php foreach (array_slice($memberRegistrations, 0, 4) as $registration): ?>
                                        <tr><td>Registered</td><td><?php echo legacyEscape((string) $registration['title']); ?></td><td><?php echo legacyEscape(date('F j, Y', strtotime($registration['registered_at']))); ?></td></tr>
                                    <?php endforeach; ?>
                                    <?php foreach (array_slice($memberProposals, 0, 4) as $proposal): ?>
                                        <tr><td>Project proposal</td><td><?php echo legacyEscape((string) $proposal['title']); ?></td><td><?php echo legacyEscape(ucwords(str_replace('_', ' ', (string) $proposal['status']))); ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="record-empty">No member actions have been recorded yet.</div>
                    <?php endif; ?>
                </div>

                <div class="member-record mt-4">
                    <div class="member-record-header">
                        <h3>Audit Log</h3>
                        <p>Latest record changes and actions by users.</p>
                    </div>
                    <div class="table-responsive">
                        <table class="member-record-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Entity</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr>
                                        <td><?php echo legacyEscape((string) ($log['actor_name'] ?? 'System')); ?></td>
                                        <td><?php echo legacyEscape((string) ($log['entity_type'] ?? 'unknown')); ?></td>
                                        <td><?php echo legacyEscape((string) ($log['action'] ?? 'update')); ?></td>
                                        <td><?php echo legacyEscape((string) ($log['details'] ?? '-')); ?></td>
                                        <td><?php echo legacyEscape((string) date('M d, Y H:i', strtotime((string) ($log['created_at'] ?? date('c'))))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($auditLogs)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">No audit activity yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
