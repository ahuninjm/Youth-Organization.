<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 3) . '/db.php';
require_once dirname(__DIR__, 3) . '/attendance_store.php';

$member = $_SESSION['user'];
$memberRole = $member['role'] ?? 'member';
$currentPage = 'activity-insights';

if (!in_array($memberRole, ['admin', 'officer'], true)) {
    $_SESSION['flash_message'] = 'Access denied. Only officers and administrators can view activity insights.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: dashboard.php');
    exit;
}

function legacyEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$attendance = loadAttendanceFromStorage();

$activityRows = $pdo->query('SELECT id, title FROM activities')->fetchAll();
$validActivityIds = [];
$validActivityTitles = [];
foreach ($activityRows as $activityRow) {
    $activityId = (int) ($activityRow['id'] ?? 0);
    $activityTitle = strtolower(trim((string) ($activityRow['title'] ?? '')));
    if ($activityId > 0) {
        $validActivityIds[$activityId] = true;
    }
    if ($activityTitle !== '') {
        $validActivityTitles[] = $activityTitle;
    }
}

$ratingsByActivity = [];
$ratingsByMonth = [];
$allRatings = [];

foreach ($attendance as $record) {
    $activityId = (int) ($record['activity_id'] ?? 0);
    $activityName = trim((string) ($record['event_name'] ?? $record['event'] ?? $record['title'] ?? $record['activity_name'] ?? ''));

    if ($activityId > 0 && !isset($validActivityIds[$activityId])) {
        continue;
    }

    if ($activityId <= 0 && $activityName !== '' && !in_array(strtolower($activityName), $validActivityTitles, true)) {
        continue;
    }

    if ($activityName === '') {
        continue;
    }

    $ratingValue = $record['participation_rating'] ?? $record['rating'] ?? 5;
    $rating = (int) $ratingValue;
    if ($rating < 1 || $rating > 5) {
        $rating = 5;
    }

    $allRatings[] = $rating;

    if (!isset($ratingsByActivity[$activityName])) {
        $ratingsByActivity[$activityName] = [];
    }
    $ratingsByActivity[$activityName][] = $rating;

    $recordDate = (string) ($record['date'] ?? $record['event_date'] ?? date('Y-m-d'));
    $monthKey = date('Y-m', strtotime($recordDate));
    if (!isset($ratingsByMonth[$monthKey])) {
        $ratingsByMonth[$monthKey] = [];
    }
    $ratingsByMonth[$monthKey][] = $rating;
}

$activityStats = [];
foreach ($ratingsByActivity as $activity => $ratings) {
    $count = count($ratings);
    $avg = array_sum($ratings) / $count;
    $min = min($ratings);
    $max = max($ratings);
    $activityStats[] = [
        'name' => $activity,
        'count' => $count,
        'average' => round($avg, 2),
        'min' => $min,
        'max' => $max,
    ];
}

usort($activityStats, fn($a, $b) => $b['average'] <=> $a['average']);

$monthStats = [];
foreach ($ratingsByMonth as $month => $ratings) {
    $count = count($ratings);
    $avg = array_sum($ratings) / $count;
    $monthStats[$month] = [
        'average' => round($avg, 2),
        'count' => $count,
    ];
}

ksort($monthStats);

$overallAverage = count($allRatings) > 0 ? round(array_sum($allRatings) / count($allRatings), 2) : 0;
$overallCount = count($allRatings);

function ratingLabel(float $rating): string
{
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Very Good';
    if ($rating >= 3.0) return 'Good';
    if ($rating >= 2.0) return 'Fair';
    return 'Needs Improvement';
}

function ratingColor(float $rating): string
{
    if ($rating >= 4.5) return '#28a745';
    if ($rating >= 4.0) return '#17a2b8';
    if ($rating >= 3.0) return '#ffc107';
    if ($rating >= 2.0) return '#fd7e14';
    return '#dc3545';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activity Insights - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        .insight-card { border-left: 4px solid #007bff; }
        .chart-container { position: relative; height: 300px; margin-bottom: 20px; }
        .rating-badge { font-size: 2rem; font-weight: bold; }
        .trend-table { font-size: 0.9rem; }

        @media print {
            @page {
                size: A4 portrait;
                margin: 14mm;
            }

            body {
                background: #ffffff !important;
                color: #111827 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .sidebar,
            .topbar,
            .navbar,
            .btn,
            .alert,
            .breadcrumb,
            a[href="activity-report.php"],
            .mb-3 {
                display: none !important;
            }

            main.container-fluid {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .card,
            .table,
            .chart-container {
                box-shadow: none !important;
                border: 1px solid #dfe3e8 !important;
                break-inside: avoid;
            }

            .card-header {
                background: #f3f4f6 !important;
                color: #111827 !important;
            }

            .row,
            .col-lg-3,
            .col-lg-6 {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                float: none !important;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <main class="container-fluid px-4 py-4">
        <div class="mb-3">
            <a href="activity-report.php" class="btn btn-outline-secondary btn-sm">← Back to Reports</a>
        </div>

        <p class="text-success text-uppercase small fw-bold mb-1">Performance Analytics</p>
        <h1 class="h3">Activity Participation Insights</h1>
        <p class="text-muted">Track participation quality across activities and identify trends for better program planning.</p>

        <div class="row g-4 mb-4">
            <div class="col-lg-3">
                <div class="card insight-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-2">Overall Participation</h6>
                        <div class="rating-badge" style="color: <?php echo ratingColor($overallAverage); ?>">
                            <?php echo $overallAverage; ?>
                        </div>
                        <p class="mb-0 text-muted small mt-2"><?php echo ratingLabel($overallAverage); ?></p>
                        <p class="mb-0 text-muted small">From <?php echo $overallCount; ?> records</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3">
                <div class="card insight-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-2">Excellent (5.0)</h6>
                        <div class="rating-badge" style="color: #28a745;">
                            <?php echo count(array_filter($allRatings, fn($r) => $r === 5)); ?>
                        </div>
                        <p class="mb-0 text-muted small mt-2">
                            <?php echo $overallCount > 0 ? round(count(array_filter($allRatings, fn($r) => $r === 5)) / $overallCount * 100, 1) : 0; ?>%
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3">
                <div class="card insight-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-2">Good (3.0-4.0)</h6>
                        <div class="rating-badge" style="color: #ffc107;">
                            <?php echo count(array_filter($allRatings, fn($r) => $r >= 3 && $r <= 4)); ?>
                        </div>
                        <p class="mb-0 text-muted small mt-2">
                            <?php echo $overallCount > 0 ? round(count(array_filter($allRatings, fn($r) => $r >= 3 && $r <= 4)) / $overallCount * 100, 1) : 0; ?>%
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3">
                <div class="card insight-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-2">Needs Improvement (1-2)</h6>
                        <div class="rating-badge" style="color: #dc3545;">
                            <?php echo count(array_filter($allRatings, fn($r) => $r <= 2)); ?>
                        </div>
                        <p class="mb-0 text-muted small mt-2">
                            <?php echo $overallCount > 0 ? round(count(array_filter($allRatings, fn($r) => $r <= 2)) / $overallCount * 100, 1) : 0; ?>%
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Monthly Average Ratings</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Top Activities by Average Rating</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="activitiesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Activity Performance Summary</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm trend-table">
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Participants</th>
                                <th>Average Rating</th>
                                <th>Min</th>
                                <th>Max</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityStats as $stat): ?>
                                <tr>
                                    <td><strong><?php echo legacyEscape($stat['name']); ?></strong></td>
                                    <td><?php echo $stat['count']; ?></td>
                                    <td>
                                        <span class="badge" style="background-color: <?php echo ratingColor($stat['average']); ?>;">
                                            <?php echo $stat['average']; ?> - <?php echo ratingLabel($stat['average']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger"><?php echo $stat['min']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?php echo $stat['max']; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $trend = '';
                                        if ($stat['average'] >= 4.5) {
                                            $trend = '📈 Strong';
                                        } elseif ($stat['average'] >= 3.5) {
                                            $trend = '→ Stable';
                                        } else {
                                            $trend = '📉 Needs Attention';
                                        }
                                        echo $trend;
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$activityStats): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No activity data available yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const monthlyData = <?php echo json_encode($monthStats, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const monthLabels = Object.keys(monthlyData);
        const monthAverages = monthLabels.map(m => monthlyData[m].average);

        const monthCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthCtx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'Average Participation Rating',
                    data: monthAverages,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 5,
                    pointBackgroundColor: '#007bff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, min: 0, max: 5 } }
            }
        });

        const activityData = <?php echo json_encode(array_slice($activityStats, 0, 8), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const activityLabels = activityData.map(a => a.name);
        const activityAverages = activityData.map(a => a.average);
        const activityColors = activityAverages.map(r => 
            r >= 4.5 ? '#28a745' : (r >= 4 ? '#17a2b8' : (r >= 3 ? '#ffc107' : (r >= 2 ? '#fd7e14' : '#dc3545')))
        );

        const activitiesCtx = document.getElementById('activitiesChart').getContext('2d');
        new Chart(activitiesCtx, {
            type: 'bar',
            data: {
                labels: activityLabels,
                datasets: [{
                    label: 'Average Rating',
                    data: activityAverages,
                    backgroundColor: activityColors,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, min: 0, max: 5 } }
            }
        });
    </script>
</body>
</html>
