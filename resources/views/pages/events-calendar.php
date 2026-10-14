<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
} if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
require_once dirname(__DIR__, 3) . '/db.php';
$member = $_SESSION['user']; 
$memberId = (int) ($member['id'] ?? 0);
$currentPage = 'events-calendar';

$events = [];
foreach ($pdo->query("SELECT id, title, activity_date AS event_date, venue, status, 'Activity' AS event_type FROM activities WHERE status <> 'completed'")->fetchAll() as $row) {
    $row['event_type_key'] = 'activity';
    $events[] = $row;
}

$agendaData = json_decode(file_get_contents(dirname(__DIR__, 3) . '/meeting-agendas.json') ?: '[]', true);
if (is_array($agendaData)) {
    $meetingId = 1;
    foreach ($agendaData as $row) {
        $events[] = [
            'id' => $meetingId,
            'title' => $row['meeting'] ?? 'Meeting',
            'event_date' => $row['date'] ?? date('Y-m-d'),
            'venue' => $row['venue'] ?? '',
            'status' => 'scheduled',
            'event_type' => 'Meeting',
            'event_type_key' => 'meeting',
        ];
        $meetingId++;
    }
}

usort($events, fn($a, $b) => strcmp($a['event_date'], $b['event_date']));

$rsvpData = $pdo->query('SELECT event_id, event_type, user_id, rsvp_status FROM event_rsvps')->fetchAll();
$rsvpByEvent = [];
foreach ($rsvpData as $rsvp) {
    $key = $rsvp['event_type'] . ':' . $rsvp['event_id'];
    if (!isset($rsvpByEvent[$key])) {
        $rsvpByEvent[$key] = ['confirmed' => 0, 'pending' => 0, 'declined' => 0, 'member_status' => null];
    }
    $rsvpByEvent[$key][$rsvp['rsvp_status']]++;
    if ($rsvp['user_id'] === $memberId) {
        $rsvpByEvent[$key]['member_status'] = $rsvp['rsvp_status'];
    }
}

function legacyEscape(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Events Calendar - Youth Organization</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/app.css" rel="stylesheet"><style>
.event-row { cursor: pointer; transition: all 0.3s ease; }
.event-row:hover { background-color: #f8f9fa; }
.rsvp-badge { font-size: 0.75rem; padding: 3px 8px; }
</style></head><body><?php require_once __DIR__ . '/topnav.php'; ?><main class="container-fluid px-4 py-4"><p class="text-success text-uppercase small fw-bold mb-1">Organization schedule</p><h1 class="h3">Events Calendar</h1><p class="text-muted">View upcoming meetings and activities, and confirm your attendance.</p><div class="card mt-4"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Event</th><th>Type</th><th>Date</th><th>Venue</th><th>Status</th><th>RSVPs</th></tr></thead><tbody><?php foreach ($events as $event): 
    $eventKey = $event['event_type_key'] . ':' . $event['id'];
    $rsvp = $rsvpByEvent[$eventKey] ?? ['confirmed' => 0, 'pending' => 0, 'declined' => 0, 'member_status' => null];
    $memberRsvp = $rsvp['member_status'] ?? null;
    $eventUrl = 'events-detail.php?event_id=' . (int)$event['id'] . '&type=' . legacyEscape($event['event_type_key']);
?>
<tr class="event-row" onclick="window.location.href='<?php echo $eventUrl; ?>';" style="cursor: pointer;">
    <td><a href="<?php echo $eventUrl; ?>" style="text-decoration: none; color: inherit;"><strong><?php echo legacyEscape($event['title']); ?></strong></a></td>
    <td><?php echo legacyEscape($event['event_type']); ?></td>
    <td><?php echo date('F j, Y', strtotime($event['event_date'])); ?></td>
    <td><?php echo legacyEscape($event['venue'] ?: 'Not set'); ?></td>
    <td><span class="badge bg-info"><?php echo ucfirst($event['status']); ?></span></td>
    <td>
        <span class="rsvp-badge badge bg-success">✓ <?php echo $rsvp['confirmed']; ?></span>
        <span class="rsvp-badge badge bg-warning">⊙ <?php echo $rsvp['pending']; ?></span>
        <span class="rsvp-badge badge bg-danger">✗ <?php echo $rsvp['declined']; ?></span>
        <?php if ($memberRsvp): ?><span class="rsvp-badge badge" style="background-color: #6c757d;">You: <?php echo ucfirst($memberRsvp); ?></span><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$events): ?><tr><td colspan="6" class="text-center text-muted py-4">No upcoming events.</td></tr><?php endif; ?></tbody></table></div></div></div></main></body></html>
