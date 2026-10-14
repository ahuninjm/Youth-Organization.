<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__, 3) . '/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$member = $_SESSION['user'];
$memberQuery = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id = ? LIMIT 1');
$memberQuery->execute([$member['id'] ?? 0]);
$memberRecord = $memberQuery->fetch() ?: [];
$memberName = $memberRecord['name'] ?? $member['name'];
$memberRole = $memberRecord['role'] ?? $member['role'] ?? 'member';
$canManageActivities = in_array($memberRole, ['officer', 'admin'], true);

$currentPage = 'activities';

$message = '';
$messageType = 'success';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'create_activity';

    if ($action === 'update_status' && $canManageActivities) {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $allowedStatuses = ['open', 'planning', 'completed'];

        if ($activityId <= 0 || !in_array($status, $allowedStatuses, true)) {
            $message = 'Please choose a valid activity status.';
            $messageType = 'danger';
        } else {
            $currentActivityQuery = $pdo->prepare('SELECT status FROM activities WHERE id = ? LIMIT 1');
            $currentActivityQuery->execute([$activityId]);
            $currentStatus = $currentActivityQuery->fetchColumn();

            if ($currentStatus === 'completed') {
                $message = 'This activity is completed and its status cannot be changed.';
                $messageType = 'info';
            } else {
                $updateActivity = $pdo->prepare('UPDATE activities SET status = ? WHERE id = ?');
                $updateActivity->execute([$status, $activityId]);
                logAudit($pdo, 'activity', $activityId, 'update', (int) ($memberRecord['id'] ?? $member['id'] ?? 0), (string) ($memberRecord['name'] ?? $member['name'] ?? 'System'), 'Updated activity status to ' . $status);
                $message = 'Activity status updated to ' . ucfirst($status) . '.';
            }
        }
    } elseif ($action === 'delete_activity' && $canManageActivities) {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $deleteReason = trim((string) ($_POST['delete_reason'] ?? ''));
        $activityQuery = $pdo->prepare('SELECT title, status FROM activities WHERE id = ? LIMIT 1');
        $activityQuery->execute([$activityId]);
        $activity = $activityQuery->fetch();

        if (!$activity || !in_array($activity['status'], ['planning', 'open'], true)) {
            $message = 'Only planning or open activities can be deleted. Completed activities are protected.';
            $messageType = 'danger';
        } elseif ($deleteReason === '') {
            $message = 'Please provide a reason before deleting the activity.';
            $messageType = 'danger';
        } else {
            logAudit($pdo, 'activity', $activityId, 'delete', (int) ($memberRecord['id'] ?? $member['id'] ?? 0), (string) ($memberRecord['name'] ?? $member['name'] ?? 'System'), 'Deleted activity: ' . $activity['title'] . '. Reason: ' . $deleteReason);
            $deleteActivity = $pdo->prepare('DELETE FROM activities WHERE id = ? AND status IN (\'planning\', \'open\')');
            $deleteActivity->execute([$activityId]);
            $message = 'Activity deleted and the reason was recorded.';
        }
    } elseif ($action === 'register_activity') {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $memberId = (int) ($memberRecord['id'] ?? $member['id'] ?? 0);
        $activityQuery = $pdo->prepare('SELECT id, title, status FROM activities WHERE id = ? LIMIT 1');
        $activityQuery->execute([$activityId]);
        $activity = $activityQuery->fetch();

        if (!$activity || $activity['status'] !== 'open') {
            $message = 'This activity is not open for registration.';
            $messageType = 'danger';
        } else {
            $registerActivity = $pdo->prepare(
                'INSERT INTO activity_registrations (activity_id, user_id) VALUES (?, ?)'
            );
            try {
                $registerActivity->execute([$activityId, $memberId]);
                $message = 'You are registered for ' . $activity['title'] . '.';
            } catch (PDOException $exception) {
                $message = 'You are already registered for this activity.';
                $messageType = 'info';
            }
        }
    } elseif ($canManageActivities) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $activityDate = trim($_POST['activity_date'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $budgetAmount = trim((string) ($_POST['budget_amount'] ?? ''));
        $status = 'planning';

        $allowedStatuses = ['open', 'planning', 'completed'];

        if ($title === '' || $activityDate === '' || ($budgetAmount !== '' && (!is_numeric($budgetAmount) || (float) $budgetAmount < 0))) {
            $message = 'Please complete the required activity fields.';
            $messageType = 'danger';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $message = 'Please choose a valid activity status.';
            $messageType = 'danger';
        } else {
            $insertActivity = $pdo->prepare(
                'INSERT INTO activities (title, description, activity_date, venue, budget_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertActivity->execute([
                $title,
                $description !== '' ? $description : null,
                $activityDate,
                $venue !== '' ? $venue : null,
                $budgetAmount !== '' ? (float) $budgetAmount : null,
                $status,
                $memberRecord['id'] ?? null,
            ]);

            $activityId = (int) $pdo->lastInsertId();
            logAudit($pdo, 'activity', $activityId, 'create', (int) ($memberRecord['id'] ?? $member['id'] ?? 0), (string) ($memberRecord['name'] ?? $member['name'] ?? 'System'), 'Created activity: ' . $title);

            $message = 'Activity published successfully.';
            $messageType = 'success';
        }
    }
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$dateFilter = trim((string) ($_GET['date'] ?? ''));

$activities = $pdo->query(
    'SELECT a.id, a.title, a.description, a.activity_date, a.venue, a.budget_amount, a.status,
            (SELECT COUNT(*) FROM activity_registrations ar WHERE ar.activity_id = a.id) AS registration_count,
            CASE
                WHEN TRIM(COALESCE(u.position, "")) <> "" THEN u.position
                WHEN u.role = "admin" THEN "Administrator"
                ELSE u.name
            END AS creator_label
     FROM activities a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.activity_date ASC, a.id DESC'
)->fetchAll();

if ($searchTerm !== '' || $statusFilter !== 'all' || $dateFilter !== '') {
    $activities = array_values(array_filter($activities, static function (array $activity) use ($searchTerm, $statusFilter, $dateFilter): bool {
        $matchesSearch = $searchTerm === ''
            || stripos((string) ($activity['title'] ?? ''), $searchTerm) !== false
            || stripos((string) ($activity['description'] ?? ''), $searchTerm) !== false
            || stripos((string) ($activity['venue'] ?? ''), $searchTerm) !== false;
        $matchesStatus = $statusFilter === 'all' || (string) ($activity['status'] ?? '') === $statusFilter;
        $matchesDate = $dateFilter === '' || date('Y-m-d', strtotime((string) ($activity['activity_date'] ?? ''))) === $dateFilter;
        return $matchesSearch && $matchesStatus && $matchesDate;
    }));
}

$memberRegistrations = [];
$memberId = (int) ($memberRecord['id'] ?? $member['id'] ?? 0);
if ($memberId > 0) {
    $registrationQuery = $pdo->prepare('SELECT activity_id FROM activity_registrations WHERE user_id = ?');
    $registrationQuery->execute([$memberId]);
    $memberRegistrations = array_map('intval', $registrationQuery->fetchAll(PDO::FETCH_COLUMN));
}

$activityRegistrants = [];
$registrantQuery = $pdo->query(
    'SELECT ar.activity_id, u.id, u.name, u.username
     FROM activity_registrations ar
     INNER JOIN users u ON u.id = ar.user_id
     ORDER BY ar.activity_id, u.name'
);
foreach ($registrantQuery->fetchAll() as $registrant) {
    $activityRegistrants[(int) $registrant['activity_id']][] = $registrant;
}

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function statusLabel(string $status): string
{
    return match ($status) {
        'registration' => 'Open',
        'planning' => 'Planning',
        'completed' => 'Completed',
        'cancelled' => 'Completed',
        default => 'Open',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'registration' => 'success',
        'planning' => 'warning',
        'completed' => 'success',
        'cancelled' => 'success',
        default => 'success',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f3f6fa;
            color: #1d2530;
        }
        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 24px;
            background: #111827;
            color: #fff;
            overflow-y: auto;
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
            background: #1f8a5f;
            font-weight: 800;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.78);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 4px;
            text-decoration: none;
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
            background: #fff;
            border-bottom: 1px solid #dbe3ef;
        }
        .content {
            padding: 28px;
        }
        .page-intro {
            background: linear-gradient(135deg, #ffffff 0%, #eef7f2 100%);
            border: 1px solid #dcefe3;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(20, 56, 43, 0.06);
        }
        .page-intro h2 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.77rem;
            font-weight: 700;
            color: #2f835c;
            margin-bottom: 6px;
        }
        .activity-card {
            transition: all 0.3s;
        }
        .activity-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .member-activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .member-activity-list .member-activity-row {
            display: grid;
            grid-template-columns: minmax(180px, 1.4fr) minmax(150px, 1fr) minmax(170px, 1.2fr) auto;
            align-items: center;
            gap: 18px;
            padding: 16px 18px;
            border: 1px solid #dfe8e3;
            border-radius: 8px;
            background: #fff;
        }
        .member-activity-list .member-activity-row:hover {
            border-color: #2f835c;
            box-shadow: 0 5px 16px rgba(20, 56, 43, 0.08);
        }
        .member-activity-title {
            font-weight: 800;
            color: #12352a;
        }
        .member-activity-meta {
            color: #66746e;
            font-size: 0.9rem;
        }
        @media (max-width: 767.98px) {
            .member-activity-list .member-activity-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }
        }
        .member-view .saved-activities-grid > [class*="col-"] {
            width: auto;
            flex: none;
            padding: 0;
        }
        .member-view .row.g-4.mb-4 > .col-lg-7 {
            width: 100%;
            flex: 0 0 100%;
        }
        .member-view .content {
            width: 100%;
            max-width: none;
        }
        .member-view .saved-activities-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin: 0;
        }
        .member-view .saved-activities-grid .activity-card {
            height: auto !important;
        }
        .member-view .saved-activities-grid .activity-card .card-body {
            display: block;
            padding: 16px 18px;
        }
        .member-view .saved-activities-grid .activity-card .card-body > * {
            margin-bottom: 0 !important;
        }
        .member-view .saved-activities-grid .activity-card .card-body > .alert {
            margin-top: 8px;
        }
        .member-view .saved-activities-grid .activity-card .card-body > .mt-3 {
            margin-top: 0 !important;
        }
        .member-view .member-registration-count {
            display: none;
        }
        @media (max-width: 767.98px) {
            .member-view .saved-activities-grid {
                grid-template-columns: 1fr;
            }
            .member-view .saved-activities-grid .activity-card .card-body {
                display: block;
                padding: 16px;
            }
            .member-view .saved-activities-grid .activity-card .card-body > * {
                margin-bottom: 8px !important;
            }
        }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
            margin-bottom: 20px;
        }
        .filter-bar .form-control,
        .filter-bar .form-select {
            max-width: 220px;
        }
        .registered-member-list {
            max-height: 280px;
            overflow-y: auto;
        }
        .registered-member-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #e8efeb;
        }
        .registered-member-row:last-child {
            border-bottom: 0;
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="<?php echo $canManageActivities ? 'manager-view' : 'member-view'; ?>">
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">
        <!-- Main Content -->
        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">Activity Management</h1>
            </div>

            <div class="content">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div>
                <?php endif; ?>

                <div class="page-intro">
                    <div class="eyebrow">Organize your events</div>
                    <h2>Plan activities with clarity and purpose</h2>
                    <p class="mb-0 text-muted">Keep each activity visible, well described, and easy for members to follow.</p>
                </div>
                <?php if ($canManageActivities): ?><div class="text-end mb-3"><button class="btn btn-success" type="button" id="toggleActivityForm" aria-expanded="false">+ Create activity</button></div><?php endif; ?>

                <div class="row g-4 mb-4">
<?php if ($canManageActivities): ?>
            <div class="col-lg-5 workflow-form-modal" id="activityFormPanel" hidden>
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Create Activity</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST"><?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="create_activity">
                            <div class="mb-3">
                                <label class="form-label" for="title">Title</label>
                                <input type="text" id="title" name="title" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="description">Description</label>
                                <textarea id="description" name="description" class="form-control" rows="4"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="activity_date">Activity Date</label>
                                <input type="date" id="activity_date" name="activity_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="venue">Venue</label>
                                <input type="text" id="venue" name="venue" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="budget_amount">Estimated Budget (PHP)</label>
                                <input type="number" id="budget_amount" name="budget_amount" class="form-control" min="0" step="0.01">
                                <div class="form-text">This is a plan only. Record an expense after the activity is approved and paid.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="status">Activity Status</label>
                                <select id="status" name="status" class="form-select">
                                    <option value="planning">Planning</option>
                                    <option value="open">Open</option>
                                    <option value="completed">Completed</option>
                                </select>
                                <div class="form-text">Planning activities are being prepared. Open activities accept member registrations. Budgeting is optional for directed activities.</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Publish Activity</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

                    <div class="col-lg-7">
                        <div class="card h-100">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">Saved Activities</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!$activities): ?>
                                    <div class="text-muted">No activities have been created yet.</div>
                                <?php else: ?>
                                    <div class="row g-3 saved-activities-grid">
                                        <?php foreach ($activities as $activity): ?>
                                            <div class="col-md-6">
                                                <div class="card activity-card h-100">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between gap-2 mb-2">
                                                            <h5 class="card-title mb-0"><?php echo legacyEscape($activity['title']); ?></h5>
                                                            <span class="badge bg-<?php echo legacyEscape(statusClass($activity['status'])); ?>">
                                                                <?php echo legacyEscape(statusLabel($activity['status'])); ?>
                                                            </span>
                                                        </div>
                                                        <p class="mb-2"><strong>Date:</strong> <?php echo legacyEscape(date('F j, Y', strtotime($activity['activity_date']))); ?></p>
                                                        <p class="mb-2"><strong>Venue:</strong> <?php echo legacyEscape($activity['venue'] ?: 'Not set'); ?></p>
                                                        <p class="mb-2"><strong>Estimated budget:</strong> <?php echo (float) ($activity['budget_amount'] ?? 0) > 0 ? 'PHP ' . number_format((float) $activity['budget_amount'], 2) : 'Not set'; ?></p>
                                                        <p class="mb-0"><?php echo legacyEscape($activity['description'] ?: 'No description provided.'); ?></p>
                                                        <div class="mt-3 d-flex justify-content-between align-items-center gap-2">
                                                            <span class="small text-muted member-registration-count"><?php echo legacyEscape((string) ($activity['registration_count'] ?? 0)); ?> registered</span>
                                                            <?php if (!$canManageActivities && in_array((int) $activity['id'], $memberRegistrations, true)): ?>
                                                                <span class="badge bg-success">Registered</span>
                                                            <?php elseif (!$canManageActivities && $activity['status'] === 'open'): ?>
                                                                <form method="POST" class="m-0"><?php echo csrf_field(); ?>
                                                                    <input type="hidden" name="action" value="register_activity">
                                                                    <input type="hidden" name="activity_id" value="<?php echo legacyEscape((string) $activity['id']); ?>">
                                                                    <button type="submit" class="btn btn-sm btn-primary">Register</button>
                                                                </form>
                                                            <?php elseif (!$canManageActivities): ?>
                                                                <span class="badge bg-secondary">Not registered</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($canManageActivities): ?>
                                                            <div class="mt-3 pt-3 border-top">
                                                                <?php if ($activity['status'] !== 'completed'): ?>
                                                                    <div class="small text-muted mb-2">
                                                                        <?php echo $activity['status'] === 'planning' ? 'Preparation is ongoing.' : 'Members can register now.'; ?>
                                                                    </div>
                                                                    <form method="POST" class="d-flex gap-2 align-items-center mb-2"><?php echo csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="update_status">
                                                                        <input type="hidden" name="activity_id" value="<?php echo legacyEscape((string) $activity['id']); ?>">
                                                                        <label class="visually-hidden" for="activity_status_<?php echo legacyEscape((string) $activity['id']); ?>">Update activity status</label>
                                                                        <select id="activity_status_<?php echo legacyEscape((string) $activity['id']); ?>" name="status" class="form-select form-select-sm">
                                                                            <?php foreach (['planning', 'open', 'completed'] as $activityStatus): ?>
                                                                                <option value="<?php echo legacyEscape($activityStatus); ?>" <?php echo $activity['status'] === $activityStatus ? 'selected' : ''; ?>><?php echo legacyEscape(ucfirst($activityStatus)); ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Update Status</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <div class="small text-success fw-bold">Completed - status locked.</div>
                                                                <?php endif; ?>
                                                                <?php if (in_array($activity['status'], ['planning', 'open'], true)): ?>
                                                                    <form method="POST" class="mt-3" onsubmit="return confirm('Delete this activity? The deletion reason will be recorded.');"><?php echo csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="delete_activity">
                                                                        <input type="hidden" name="activity_id" value="<?php echo legacyEscape((string) $activity['id']); ?>">
                                                                        <label class="form-label small" for="delete_reason_<?php echo legacyEscape((string) $activity['id']); ?>">Reason for deletion</label>
                                                                        <textarea id="delete_reason_<?php echo legacyEscape((string) $activity['id']); ?>" name="delete_reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Why is this activity being deleted?" required></textarea>
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete Activity</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#registeredMembersModal<?php echo legacyEscape((string) $activity['id']); ?>">View Registered Members</button>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="mt-3 small text-muted">
                                                            Created by <?php echo legacyEscape($activity['creator_label'] ?? 'System'); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($canManageActivities): ?>
                                                <div class="modal fade" id="registeredMembersModal<?php echo legacyEscape((string) $activity['id']); ?>" tabindex="-1" aria-labelledby="registeredMembersModalLabel<?php echo legacyEscape((string) $activity['id']); ?>" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <div>
                                                                    <div class="text-success text-uppercase small fw-bold mb-1">Activity registration</div>
                                                                    <h5 class="modal-title" id="registeredMembersModalLabel<?php echo legacyEscape((string) $activity['id']); ?>">Registered members</h5>
                                                                    <div class="small text-muted"><?php echo legacyEscape($activity['title']); ?></div>
                                                                </div>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p class="small text-muted">Only members listed here may be marked present for this activity.</p>
                                                                <div class="registered-member-list">
                                                                    <?php if (!empty($activityRegistrants[(int) $activity['id']])): ?>
                                                                        <?php foreach ($activityRegistrants[(int) $activity['id']] as $registrant): ?>
                                                                            <div class="registered-member-row">
                                                                                <div>
                                                                                    <div><?php echo legacyEscape($registrant['name']); ?></div>
                                                                                    <span class="badge bg-success">Registered</span>
                                                                                </div>
                                                                                <?php if ($activity['status'] !== 'completed'): ?>
                                                                                    <a href="attendance.php?activity_id=<?php echo legacyEscape((string) $activity['id']); ?>&amp;member_id=<?php echo legacyEscape((string) $registrant['id']); ?>" class="btn btn-sm btn-primary">Take Attendance</a>
                                                                                <?php else: ?>
                                                                                    <span class="small text-muted">Activity completed</span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        <div class="text-muted">No members registered yet.</div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const activityFormPanel = document.getElementById('activityFormPanel');
    const toggleActivityForm = document.getElementById('toggleActivityForm');
    if (activityFormPanel && toggleActivityForm) toggleActivityForm.addEventListener('click', function () {
        activityFormPanel.hidden = !activityFormPanel.hidden;
        document.body.style.overflow = activityFormPanel.hidden ? '' : 'hidden';
        toggleActivityForm.textContent = activityFormPanel.hidden ? '+ Create activity' : 'Hide form';
    });
</script>
</body>
</html>
