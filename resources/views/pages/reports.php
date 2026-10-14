<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
require_once dirname(__DIR__, 3) . '/db.php';
require_once dirname(__DIR__, 3) . '/attendance_store.php';
require_once dirname(__DIR__, 3) . '/announcements_store.php';

$member = $_SESSION['user'];
$role = $member['role'] ?? 'member';
if (!in_array($role, ['officer', 'admin'], true)) {
    $_SESSION['flash_message'] = 'Access denied. Officers and administrators can view reports.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: dashboard.php');
    exit;
}
$currentPage = 'reports';

$members = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'member'")->fetchColumn();
$activities = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
$projects = (int) $pdo->query('SELECT COUNT(*) FROM project_proposals')->fetchColumn();
$announcements = count(loadAnnouncementsFromStorage());
$attendance = count(loadAttendanceFromStorage());
$memberRecords = $pdo->query("SELECT name, username, role, position, status, created_at FROM users WHERE role <> 'admin' ORDER BY name")->fetchAll();
$activityRecords = $pdo->query('SELECT title, activity_date, venue, status FROM activities ORDER BY activity_date DESC, id DESC')->fetchAll();
$projectRecords = $pdo->query('SELECT title, budget, status, submitted_at, completion_report FROM project_proposals ORDER BY submitted_at DESC, id DESC')->fetchAll();
$projectStatuses = $pdo->query('SELECT status, COUNT(*) total FROM project_proposals GROUP BY status')->fetchAll();

$attendanceEntries = loadAttendanceFromStorage();
$attendanceByMonth = [];
foreach ($attendanceEntries as $entry) {
    $date = trim((string) ($entry['date'] ?? $entry['event_date'] ?? ''));
    if ($date === '') {
        continue;
    }
    $month = date('M', strtotime($date));
    $attendanceByMonth[$month] = ($attendanceByMonth[$month] ?? 0) + 1;
}
$attendanceChartLabels = [];
$attendanceChartValues = [];
foreach (array_slice(array_keys($attendanceByMonth), 0, 6) as $month) {
    $attendanceChartLabels[] = $month;
    $attendanceChartValues[] = (int) $attendanceByMonth[$month];
}
if (!$attendanceChartLabels) {
    $attendanceChartLabels = ['Jan', 'Feb', 'Mar'];
    $attendanceChartValues = [0, 0, 0];
}

$activityParticipation = [];
foreach ($activityRecords as $activity) {
    $activityTitle = (string) $activity['title'];
    $activityParticipation[$activityTitle] = ($activityParticipation[$activityTitle] ?? 0) + 1;
}
$participationChartLabels = [];
$participationChartValues = [];
foreach (array_slice(array_keys($activityParticipation), 0, 5) as $title) {
    $participationChartLabels[] = strlen($title) > 12 ? substr($title, 0, 12) . '…' : $title;
    $participationChartValues[] = (int) $activityParticipation[$title];
}
if (!$participationChartLabels) {
    $participationChartLabels = ['No data'];
    $participationChartValues = [0];
}

$engagementCounts = ['Active' => 0, 'Pending' => 0, 'Inactive' => 0];
foreach ($memberRecords as $memberRecord) {
    $status = strtolower((string) ($memberRecord['status'] ?? 'inactive'));
    if ($status === 'active') {
        $engagementCounts['Active']++;
    } elseif ($status === 'inactive') {
        $engagementCounts['Inactive']++;
    } else {
        $engagementCounts['Pending']++;
    }
}
$engagementChartLabels = array_keys($engagementCounts);
$engagementChartValues = array_values($engagementCounts);

if (isset($_GET['export']) && $_GET['export'] === 'reports') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="organization-report-export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Active members', $members]);
    fputcsv($output, ['Activities', $activities]);
    fputcsv($output, ['Attendance records', $attendance]);
    fputcsv($output, ['Projects', $projects]);
    fputcsv($output, ['Announcements', $announcements]);
    fputcsv($output, ['Engagement: Active', $engagementCounts['Active'] ?? 0]);
    fputcsv($output, ['Engagement: Pending', $engagementCounts['Pending'] ?? 0]);
    fputcsv($output, ['Engagement: Inactive', $engagementCounts['Inactive'] ?? 0]);
    fclose($output);
    exit;
}

function renderBarChart(array $labels, array $values, string $color = '#2b8a66'): string {
    if (count($labels) !== count($values)) {
        return '';
    }
    $maxValue = max(1, max($values));
    $bars = [];
    foreach ($labels as $index => $label) {
        $height = $maxValue > 0 ? (int) round(($values[$index] / $maxValue) * 100) : 0;
        $bars[] = '<div class="mini-chart-item"><span class="mini-chart-value">' . htmlspecialchars((string) $values[$index], ENT_QUOTES, 'UTF-8') . '</span><span class="mini-chart-bar" style="height: ' . $height . '%; background: ' . $color . ';"></span><span class="mini-chart-label">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</span></div>';
    }
    return '<div class="mini-chart-grid">' . implode('', $bars) . '</div>';
}

function renderDonutChart(array $labels, array $values, array $colors): string {
    $total = array_sum($values);
    $start = 0;
    $segments = [];
    foreach ($values as $index => $value) {
        $portion = $total > 0 ? ($value / $total) * 360 : 0;
        $end = $start + $portion;
        $segments[] = '<span class="donut-segment" style="background: conic-gradient(' . $colors[$index] . ' ' . $start . 'deg ' . $end . 'deg, #edf4f0 ' . $end . 'deg 360deg);"></span>';
        $start = $end;
    }
    return '<div class="donut-wrap">' . implode('', $segments) . '<span class="donut-center">' . $total . '</span></div>';
}

function legacyEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function label(string $value): string { return ucwords(str_replace('_', ' ', $value)); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        .report-record { border: 1px solid var(--yo-border); border-radius: 8px; overflow: hidden; }
        .report-record .card-header { background: var(--yo-surface-soft); }
        .report-record table { margin: 0; }
        .report-record th, .report-record td { padding: 0.75rem 0.9rem; vertical-align: middle; }
        .report-record th { color: var(--yo-green-900); font-size: 0.74rem; letter-spacing: 0.08em; text-transform: uppercase; }
        .report-record .table-responsive { max-height: 360px; }
        .visual-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-top: 1.25rem; }
        .visual-card { background: #fff; border: 1px solid var(--yo-border); border-radius: 14px; padding: 1rem; }
        .visual-card h5 { margin: 0 0 0.9rem; font-size: 1rem; }
        .mini-chart-grid { display: flex; align-items: end; gap: 0.9rem; height: 170px; }
        .mini-chart-item { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: end; gap: 0.4rem; min-width: 0; }
        .mini-chart-value { font-size: 0.72rem; font-weight: 700; color: #1d2d2b; }
        .mini-chart-bar { width: 100%; min-height: 8px; border-radius: 8px 8px 0 0; display: block; }
        .mini-chart-label { font-size: 0.68rem; color: #667085; text-align: center; }
        .donut-wrap { position: relative; width: 150px; height: 150px; margin: 0 auto; display: grid; place-items: center; }
        .donut-segment { position: absolute; inset: 0; border-radius: 50%; }
        .donut-center { position: relative; display: grid; place-items: center; width: 74px; height: 74px; border-radius: 50%; background: #fff; font-size: 1.1rem; font-weight: 800; color: #12352a; box-shadow: inset 0 0 0 1px #dfe8e3; }
        .legend-list { list-style: none; padding: 0; margin: 1rem 0 0; display: grid; gap: 0.45rem; }
        .legend-item { display: flex; align-items: center; gap: 0.55rem; color: #3b4a46; font-size: 0.78rem; }
        .legend-swatch { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }
        @media (max-width: 768px) { .mini-chart-grid { gap: 0.5rem; } }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/topnav.php'; ?>
<main class="container-fluid px-4 py-4">
    <div class="section-toolbar">
        <div>
            <p class="text-success text-uppercase small fw-bold mb-1">Organization overview</p>
            <h1 class="h3 mb-0">Reports</h1>
        </div>
        <div class="toolbar-actions">
            <a href="reports.php?export=reports" class="btn btn-primary">Export Excel</a>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Export PDF</button>
        </div>
    </div>
    <p class="text-muted">Review membership, attendance, activity, project, and announcement totals.</p>

    <div class="row g-3 my-2">
        <?php foreach ([['Active members', $members], ['Activities', $activities], ['Attendance records', $attendance], ['Projects', $projects]] as $stat): ?>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-muted small"><?php echo legacyEscape($stat[0]); ?></div><h2><?php echo legacyEscape((string) $stat[1]); ?></h2></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="visual-grid">
        <div class="visual-card">
            <h5>Attendance trend</h5>
            <?php echo renderBarChart($attendanceChartLabels, $attendanceChartValues, '#2f7ae5'); ?>
        </div>
        <div class="visual-card">
            <h5>Activity participation</h5>
            <?php echo renderBarChart($participationChartLabels, $participationChartValues, '#2b8a66'); ?>
        </div>
        <div class="visual-card">
            <h5>Member engagement</h5>
            <?php echo renderDonutChart($engagementChartLabels, $engagementChartValues, ['#2b8a66', '#e7a62a', '#d95c5c']); ?>
            <ul class="legend-list">
                <?php foreach ($engagementChartLabels as $index => $label): ?>
                    <li class="legend-item"><span class="legend-swatch" style="background: <?php echo ['#2b8a66', '#e7a62a', '#d95c5c'][$index % 3]; ?>;"></span><?php echo legacyEscape((string) $label); ?>: <?php echo legacyEscape((string) $engagementChartValues[$index]); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-7">
            <div class="card report-record">
                <div class="card-header"><h5 class="mb-0">Project status summary</h5></div>
                <div class="table-responsive"><table class="table readable-hover-table mb-0"><thead><tr><th>Status</th><th>Projects</th></tr></thead><tbody>
                    <?php foreach ($projectStatuses as $status): ?><tr><td><?php echo legacyEscape(label((string) $status['status'])); ?></td><td><?php echo legacyEscape((string) $status['total']); ?></td></tr><?php endforeach; ?>
                    <?php if (!$projectStatuses): ?><tr><td colspan="2" class="text-muted text-center py-4">No project data yet.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </div>
        <div class="col-lg-5"><div class="card"><div class="card-header"><h5 class="mb-0">Detailed reports</h5></div><div class="card-body d-grid gap-2"><a class="btn btn-outline-primary" href="activity-report.php">Activity Report</a><a class="btn btn-outline-primary" href="members.php">Membership and verification</a><a class="btn btn-outline-primary" href="funds-budget.php">Financial records</a></div></div></div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card report-record"><div class="card-header d-flex justify-content-between"><h5 class="mb-0">Member records</h5><span class="text-muted small"><?php echo count($memberRecords); ?> records</span></div><div class="table-responsive"><table class="table table-hover readable-hover-table mb-0"><thead><tr><th>Name</th><th>Username</th><th>Role / position</th><th>Status</th><th>Joined</th></tr></thead><tbody>
                <?php foreach ($memberRecords as $record): ?><tr><td><?php echo legacyEscape($record['name']); ?></td><td><?php echo legacyEscape($record['username']); ?></td><td><?php echo legacyEscape(label($record['position'] ?: $record['role'])); ?></td><td><?php echo legacyEscape(label($record['status'])); ?></td><td><?php echo legacyEscape(date('F j, Y', strtotime($record['created_at']))); ?></td></tr><?php endforeach; ?>
                <?php if (!$memberRecords): ?><tr><td colspan="5" class="text-muted text-center py-4">No member records yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card report-record"><div class="card-header d-flex justify-content-between"><h5 class="mb-0">Activity records</h5><a href="activity-report.php" class="small">Full report</a></div><div class="table-responsive"><table class="table table-hover readable-hover-table mb-0"><thead><tr><th>Activity</th><th>Date</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($activityRecords as $record): ?><tr><td><strong><?php echo legacyEscape($record['title']); ?></strong><div class="small text-muted"><?php echo legacyEscape($record['venue'] ?: 'Venue not set'); ?></div></td><td><?php echo legacyEscape(date('F j, Y', strtotime($record['activity_date']))); ?></td><td><?php echo legacyEscape(label($record['status'])); ?></td></tr><?php endforeach; ?>
                <?php if (!$activityRecords): ?><tr><td colspan="3" class="text-muted text-center py-4">No activity records yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card report-record"><div class="card-header d-flex justify-content-between"><h5 class="mb-0">Project records</h5><a href="project-proposals.php" class="small">Open projects</a></div><div class="table-responsive"><table class="table table-hover readable-hover-table mb-0"><thead><tr><th>Project</th><th>Budget</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($projectRecords as $record): ?><tr><td><strong><?php echo legacyEscape($record['title']); ?></strong><?php if ($record['completion_report']): ?><div class="small text-success">Completion report recorded</div><?php endif; ?></td><td><?php echo legacyEscape($record['budget'] ?: 'Not set'); ?></td><td><?php echo legacyEscape(label($record['status'])); ?></td></tr><?php endforeach; ?>
                <?php if (!$projectRecords): ?><tr><td colspan="3" class="text-muted text-center py-4">No project records yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </div>
    </div>
</main>
</body>
</html>
