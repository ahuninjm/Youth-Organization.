<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 3) . '/db.php';

$member = $_SESSION['user'];
$memberId = (int) ($member['id'] ?? 0);
$role = $member['role'] ?? 'member';
$currentPage = 'events-calendar';

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventType = trim((string) ($_GET['type'] ?? 'activity'));

if (!in_array($eventType, ['activity', 'meeting'], true)) {
    $_SESSION['flash_message'] = 'Invalid event type.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: events-calendar.php');
    exit;
}

$event = null;
$rsvpStatus = null;

if ($eventType === 'activity' && $eventId > 0) {
    $query = $pdo->prepare('SELECT id, title, description, activity_date AS event_date, venue, status FROM activities WHERE id = ? LIMIT 1');
    $query->execute([$eventId]);
    $event = $query->fetch();
} elseif ($eventType === 'meeting' && $eventId > 0) {
    $agendaData = json_decode(file_get_contents(dirname(__DIR__, 3) . '/meeting-agendas.json') ?: '[]', true);
    if (is_array($agendaData)) {
        foreach ($agendaData as $row) {
            if ((int) ($row['id'] ?? 0) === $eventId) {
                $event = [
                    'id' => $eventId,
                    'title' => $row['meeting'] ?? 'Meeting',
                    'description' => $row['agenda_item'] ?? '',
                    'event_date' => $row['date'] ?? date('Y-m-d'),
                    'venue' => $row['venue'] ?? '',
                    'status' => 'scheduled',
                ];
                break;
            }
        }
    }
}

if (!$event) {
    $_SESSION['flash_message'] = 'Event not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: events-calendar.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $memberId > 0) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $status = trim((string) ($_POST['rsvp_status'] ?? 'confirmed'));

    if (!in_array($status, ['confirmed', 'pending', 'declined'], true)) {
        $status = 'confirmed';
    }

    if ($action === 'rsvp' && ($eventType !== 'activity' || $event['status'] === 'open')) {
        $checkRsvp = $pdo->prepare('SELECT id, rsvp_status FROM event_rsvps WHERE event_id = ? AND event_type = ? AND user_id = ? LIMIT 1');
        $checkRsvp->execute([$eventId, $eventType, $memberId]);
        $existingRsvp = $checkRsvp->fetch();

        if ($existingRsvp) {
            $updateRsvp = $pdo->prepare('UPDATE event_rsvps SET rsvp_status = ? WHERE id = ?');
            $updateRsvp->execute([$status, $existingRsvp['id']]);
            $message = 'RSVP status updated to ' . ucfirst($status) . '.';
        } else {
            $insertRsvp = $pdo->prepare('INSERT INTO event_rsvps (event_id, event_type, user_id, rsvp_status) VALUES (?, ?, ?, ?)');
            $insertRsvp->execute([$eventId, $eventType, $memberId, $status]);
            $message = 'RSVP received! Status: ' . ucfirst($status) . '.';
        }

        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'success';
        header('Location: events-detail.php?event_id=' . $eventId . '&type=' . $eventType);
        exit;
    } elseif ($action === 'rsvp') {
        $_SESSION['flash_message'] = 'This activity is still being planned and is not open for registration.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: events-detail.php?event_id=' . $eventId . '&type=' . $eventType);
        exit;
    }
}

$rsvpQuery = $pdo->prepare('SELECT rsvp_status FROM event_rsvps WHERE event_id = ? AND event_type = ? AND user_id = ? LIMIT 1');
$rsvpQuery->execute([$eventId, $eventType, $memberId]);
$rsvpRecord = $rsvpQuery->fetch();
$rsvpStatus = $rsvpRecord['rsvp_status'] ?? null;

$rsvpCounts = $pdo->prepare('SELECT rsvp_status, COUNT(*) AS count FROM event_rsvps WHERE event_id = ? AND event_type = ? GROUP BY rsvp_status');
$rsvpCounts->execute([$eventId, $eventType]);
$rsvpStats = $rsvpCounts->fetchAll(PDO::FETCH_KEY_PAIR);

$confirmedCount = (int) ($rsvpStats['confirmed'] ?? 0);
$pendingCount = (int) ($rsvpStats['pending'] ?? 0);
$declinedCount = (int) ($rsvpStats['declined'] ?? 0);

function legacyEscape(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo legacyEscape($event['title']); ?> - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <main class="container-fluid px-4 py-4">
        <div class="mb-3">
            <a href="events-calendar.php" class="btn btn-outline-secondary btn-sm">← Back to Events</a>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h1 class="h2 mb-2"><?php echo legacyEscape($event['title']); ?></h1>
                        <div class="mb-3">
                            <span class="badge bg-info"><?php echo ucfirst($eventType); ?></span>
                            <span class="badge bg-success"><?php echo ucfirst($event['status']); ?></span>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6 class="text-muted">Date & Time</h6>
                                <p class="mb-0"><?php echo date('F j, Y', strtotime($event['event_date'])); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Venue</h6>
                                <p class="mb-0"><?php echo legacyEscape($event['venue'] ?: 'Not specified'); ?></p>
                            </div>
                        </div>

                        <h5 class="mb-2">Description</h5>
                        <p><?php echo nl2br(legacyEscape($event['description'] ?? 'No description provided.')); ?></p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">RSVP Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['flash_message'])): ?>
                            <div class="alert alert-<?php echo legacyEscape($_SESSION['flash_type'] ?? 'info'); ?> mb-3">
                                <?php echo legacyEscape($_SESSION['flash_message']); ?>
                                <?php unset($_SESSION['flash_message']); unset($_SESSION['flash_type']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($memberId > 0 && ($eventType !== 'activity' || $event['status'] === 'open')): ?>
                            <form method="post"><?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="rsvp">
                                <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">

                                <?php if ($rsvpStatus): ?>
                                    <p class="mb-3">
                                        <strong>Your response:</strong> <span class="badge bg-info"><?php echo ucfirst($rsvpStatus); ?></span>
                                    </p>
                                <?php else: ?>
                                    <p class="text-muted mb-3">You haven't responded yet.</p>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label">Update RSVP</label>
                                    <select name="rsvp_status" class="form-select">
                                        <option value="confirmed" <?php echo $rsvpStatus === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="pending" <?php echo $rsvpStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="declined" <?php echo $rsvpStatus === 'declined' ? 'selected' : ''; ?>>Declined</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary w-100">Submit RSVP</button>
                            </form>
                        <?php elseif ($eventType === 'activity'): ?>
                            <p class="text-warning">This activity is still being planned. Registration will open when the activity status changes to Open.</p>
                        <?php else: ?>
                            <p class="text-danger">Please log in to RSVP for this event.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Attendance Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Confirmed</span>
                                <span class="badge bg-success"><?php echo $confirmedCount; ?></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Pending</span>
                                <span class="badge bg-warning"><?php echo $pendingCount; ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Declined</span>
                                <span class="badge bg-danger"><?php echo $declinedCount; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
