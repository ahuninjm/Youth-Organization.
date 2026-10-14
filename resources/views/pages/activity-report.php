<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$member = $_SESSION['user'];
$memberRole = $member['role'] ?? 'member';
if (!in_array($memberRole, ['officer', 'admin'], true)) {
    $_SESSION['flash_message'] = 'Access denied. Only officers and administrators can view activity reports.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: dashboard.php');
    exit;
}

require_once dirname(__DIR__, 3) . '/db.php';
require_once dirname(__DIR__, 3) . '/attendance_store.php';

$currentPage = 'activity-report';
$activities = $pdo->query(
    'SELECT a.id, a.title, a.description, a.activity_date, a.venue, a.status,
            CASE
                WHEN TRIM(COALESCE(u.position, "")) <> "" THEN u.position
                WHEN u.role = "admin" THEN "Administrator"
                ELSE u.name
            END AS creator_label
     FROM activities a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.activity_date DESC, a.id DESC'
)->fetchAll();

$attendance = loadAttendanceFromStorage();
$registrationCounts = [];
$registrationQuery = $pdo->query('SELECT activity_id, COUNT(*) AS total FROM activity_registrations GROUP BY activity_id');
foreach ($registrationQuery->fetchAll() as $registration) {
    $registrationCounts[(int) $registration['activity_id']] = (int) $registration['total'];
}
$reports = [];
$totalAttendance = 0;
$totalRegistered = 0;
$totalPresent = 0;
$totalLate = 0;
$totalAbsent = 0;
$totalRating = 0;
$totalRatedParticipants = 0;

foreach ($activities as $activity) {
    $title = strtolower(trim((string) $activity['title']));
    $date = (string) $activity['activity_date'];
    $records = [];

    foreach ($attendance as $record) {
        $event = strtolower(trim((string) ($record['event'] ?? $record['event_name'] ?? '')));
        $recordDate = trim((string) ($record['date'] ?? $record['event_date'] ?? ''));
        $recordActivityId = (int) ($record['activity_id'] ?? 0);
        if ($recordActivityId === (int) $activity['id'] || ($recordActivityId === 0 && ($event === $title || ($event === '' && $recordDate === $date)))) {
            $records[] = $record;
        }
    }

    $present = 0;
    $late = 0;
    $absent = 0;
    $ratingTotal = 0;
    $ratingCount = 0;
    foreach ($records as $record) {
        $status = strtolower(trim((string) ($record['status'] ?? '')));
        if ($status === 'present') {
            $present++;
        } elseif ($status === 'late') {
            $late++;
        } elseif ($status === 'absent') {
            $absent++;
        }

        $ratingValue = max(1, min(5, (int) ($record['rating'] ?? 0)));
        if ($ratingValue > 0) {
            $ratingTotal += $ratingValue;
            $ratingCount++;
        }
    }

    $recordCount = count($records);
    $attended = $present + $late;
    $registered = $registrationCounts[(int) $activity['id']] ?? 0;
    $rateBase = $registered > 0 ? $registered : $recordCount;
    $rate = $rateBase > 0 ? min(100, (int) round(($attended / $rateBase) * 100)) : 0;
    $averageRating = $ratingCount > 0 ? (int) round($ratingTotal / $ratingCount) : 0;
    $reports[] = [
        'activity' => $activity,
        'registered' => $registered,
        'records' => $recordCount,
        'present' => $present,
        'late' => $late,
        'absent' => $absent,
        'rate' => $rate,
        'average_rating' => $averageRating,
    ];

    $totalAttendance += $recordCount;
    $totalRegistered += $registered;
    $totalPresent += $present;
    $totalLate += $late;
    $totalAbsent += $absent;
    $totalRating += $averageRating * max(1, $ratingCount);
    $totalRatedParticipants += max(1, $ratingCount);
}

$totalMarked = $totalPresent + $totalLate + $totalAbsent;
$overallRateBase = $totalRegistered > 0 ? $totalRegistered : $totalMarked;
$overallRate = $overallRateBase > 0 ? min(100, (int) round((($totalPresent + $totalLate) / $overallRateBase) * 100)) : 0;

if (isset($_GET['export']) && $_GET['export'] === 'activity_report') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-report-export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Activity', 'Date', 'Venue', 'Status', 'Registered', 'Present', 'Late', 'Absent', 'Participation Rate', 'Average Rating']);
    foreach ($reports as $report) {
        $activity = $report['activity'];
        fputcsv($output, [
            (string) ($activity['title'] ?? ''),
            (string) ($activity['activity_date'] ?? ''),
            (string) ($activity['venue'] ?? ''),
            (string) ($activity['status'] ?? ''),
            (string) $report['registered'],
            (string) $report['present'],
            (string) $report['late'],
            (string) $report['absent'],
            (string) ($report['rate'] . '%'),
            (string) ($report['average_rating'] . '/5'),
        ]);
    }
    fclose($output);
    exit;
}

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function statusClass(string $status): string {
    return match ($status) {
        'completed' => 'success',
        'cancelled' => 'danger',
        'planning' => 'info',
        default => 'secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Reports - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .report-hero {
            background: linear-gradient(135deg, #12352a, #2f8a63);
            border-radius: 10px;
            color: #fff;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 16px 32px rgba(18, 53, 42, 0.15);
        }
        .report-hero .eyebrow {
            color: #f2b94b;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .report-hero h2 {
            margin: 0.45rem 0;
            font-weight: 800;
        }
        .report-hero p {
            max-width: 680px;
            margin: 0;
            color: rgba(255, 255, 255, 0.78);
        }
        .report-stat {
            height: 100%;
            border-left: 4px solid #2f8a63;
        }
        .report-stat .value {
            color: #12352a;
            font-size: 1.8rem;
            font-weight: 800;
        }
        .report-card {
            overflow: hidden;
            border: 1px solid #dfe8e3;
            border-radius: 10px;
            box-shadow: 0 8px 22px rgba(18, 53, 42, 0.07);
        }
        .report-card .card-header {
            background: #f8fbf9;
            border-bottom: 1px solid #dfe8e3;
        }
        .report-card .table {
            margin: 0;
        }
        .report-card .table th,
        .report-card .table td {
            padding: 0.9rem 0.8rem;
            vertical-align: middle;
        }
        .report-card .table th {
            color: #12352a;
            font-size: 0.74rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .activity-name {
            color: #12352a;
            font-weight: 800;
        }
        .activity-meta {
            color: #66746e;
            font-size: 0.8rem;
        }
        .attendance-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .attendance-summary span {
            padding: 0.25rem 0.45rem;
            border-radius: 5px;
            background: #f3f7f5;
            color: #38564a;
            font-size: 0.75rem;
            font-weight: 700;
        }
        @media print {
            .sidebar-shell,
            .app-header,
            .report-hero p,
            .btn {
                display: none !important;
            }
            body {
                padding: 0 !important;
            }
            .report-hero {
                color: #000;
                background: #fff;
                box-shadow: none;
                padding: 0 0 1rem;
            }
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <main class="container-fluid px-4 py-4">
        <div class="section-toolbar">
            <div>
                <p class="text-success text-uppercase small fw-bold mb-1">Activity conducted</p>
                <h1 class="h3 mb-0">Activity Reports</h1>
            </div>
            <div class="toolbar-actions">
                <a href="activity-insights.php" class="btn btn-outline-info">📊 View Insights</a>
                <a href="activity-report.php?export=activity_report" class="btn btn-primary">Export Excel</a>
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Export PDF</button>
            </div>
        </div>
        <p class="text-muted mb-3">Review published activities and their recorded attendance.</p>

        <section class="report-hero">
            <div class="eyebrow">Activity report</div>
            <h2>From planning to participation</h2>
            <p>Use this report to see which activities were conducted and how many attendance records were captured for each one.</p>
        </section>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card report-stat"><div class="card-body"><div class="text-muted small">Activities</div><div class="value"><?php echo legacyEscape((string) count($activities)); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card report-stat"><div class="card-body"><div class="text-muted small">Attendance records</div><div class="value"><?php echo legacyEscape((string) $totalAttendance); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card report-stat"><div class="card-body"><div class="text-muted small">Present / late</div><div class="value"><?php echo legacyEscape((string) ($totalPresent + $totalLate)); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card report-stat"><div class="card-body"><div class="text-muted small">Participation rate</div><div class="value"><?php echo legacyEscape((string) $overallRate); ?>%</div></div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-sm-12 col-md-6">
                <div class="card report-stat">
                    <div class="card-body">
                        <div class="text-muted small">Average participation rating</div>
                        <div class="value"><?php echo legacyEscape((string) ($totalRatedParticipants > 0 ? (int) round($totalRating / $totalRatedParticipants) : 0)); ?>/5</div>
                    </div>
                </div>
            </div>
        </div>

        <section class="card report-card">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class="mb-0">Activity performance</h5>
                <span class="text-muted small">Generated <?php echo legacyEscape(date('F j, Y')); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover readable-hover-table align-middle">
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Status</th>
                            <th>Date &amp; venue</th>
                            <th>Registration &amp; attendance</th>
                            <th>Rating</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $report): ?>
                            <?php $activity = $report['activity']; ?>
                            <tr>
                                <td>
                                    <div class="activity-name"><?php echo legacyEscape((string) $activity['title']); ?></div>
                                    <div class="activity-meta">Created by <?php echo legacyEscape((string) ($activity['creator_label'] ?? 'System')); ?></div>
                                </td>
                                <td><span class="badge bg-<?php echo legacyEscape(statusClass((string) $activity['status'])); ?>"><?php echo legacyEscape(ucfirst((string) $activity['status'])); ?></span></td>
                                <td>
                                    <div><?php echo legacyEscape(date('F j, Y', strtotime((string) $activity['activity_date']))); ?></div>
                                    <div class="activity-meta"><?php echo legacyEscape((string) ($activity['venue'] ?: 'Venue not set')); ?></div>
                                </td>
                                <td>
                                    <div class="attendance-summary">
                                        <span>Registered <?php echo legacyEscape((string) $report['registered']); ?></span>
                                        <span>Present <?php echo legacyEscape((string) $report['present']); ?></span>
                                        <span>Late <?php echo legacyEscape((string) $report['late']); ?></span>
                                        <span>Absent <?php echo legacyEscape((string) $report['absent']); ?></span>
                                    </div>
                                    <div class="activity-meta mt-1"><?php echo legacyEscape((string) max(0, $report['registered'] - $report['present'] - $report['late'])); ?> registered members not checked in</div>
                                </td>
                                <td>
                                    <?php $activityRating = (int) $report['average_rating']; ?>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo str_repeat('★', $activityRating ?: 0) . str_repeat('☆', max(0, 5 - ($activityRating ?: 0))); ?>
                                        <?php echo ' ' . legacyEscape((string) ($activityRating ?: 0)); ?>/5
                                    </span>
                                </td>
                                <td><strong><?php echo legacyEscape((string) $report['rate']); ?>%</strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$reports): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No activities have been created yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
