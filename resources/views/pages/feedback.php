<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 3) . '/db.php';
require_once dirname(__DIR__, 3) . '/announcements_store.php';

$member = $_SESSION['user'];
$memberId = (int) ($member['id'] ?? 0);
$role = $member['role'] ?? 'member';
$canManage = in_array($role, ['officer', 'admin'], true);
$currentPage = 'feedback';
$message = '';
$type = 'success';

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parseRelatedItem(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return ['type' => 'general', 'id' => 0];
    }

    $parts = explode(':', $value, 2);
    $type = trim((string) ($parts[0] ?? ''));
    $idPart = trim((string) ($parts[1] ?? ''));

    if (!in_array($type, ['activity', 'announcement', 'agenda', 'event'], true) || $idPart === '' || !ctype_digit($idPart)) {
        return ['type' => 'general', 'id' => 0];
    }

    return ['type' => $type, 'id' => (int) $idPart];
}

function relatedPage(string $type): string {
    return match ($type) {
        'activity' => 'activities.php',
        'announcement' => 'announcements.php',
        'agenda' => 'meeting-agendas.php',
        'event' => 'events-calendar.php',
        default => 'feedback.php',
    };
}

$selectedRelated = parseRelatedItem((string) ($_GET['related_item'] ?? ''));

$activityRows = $pdo->query('SELECT id, title FROM activities ORDER BY activity_date DESC, id DESC')->fetchAll();
$announcementRows = loadAnnouncementsFromStorage();
$agendaRows = json_decode(file_get_contents(dirname(__DIR__, 3) . '/meeting-agendas.json') ?: '[]', true);
if (!is_array($agendaRows)) {
    $agendaRows = [];
}

$relatedOptions = [];
foreach ($activityRows as $row) {
    $relatedOptions[] = [
        'value' => 'activity:' . (int) $row['id'],
        'label' => 'Activity - ' . (string) $row['title'],
        'type' => 'activity',
        'id' => (int) $row['id'],
    ];
}
foreach ($announcementRows as $row) {
    if (!isset($row['id'])) {
        continue;
    }
    $relatedOptions[] = [
        'value' => 'announcement:' . (int) $row['id'],
        'label' => 'Announcement - ' . (string) ($row['title'] ?? 'Announcement'),
        'type' => 'announcement',
        'id' => (int) $row['id'],
    ];
}
foreach ($agendaRows as $row) {
    $agendaId = isset($row['id']) ? (int) $row['id'] : 0;
    $title = (string) ($row['meeting'] ?? 'Meeting Agenda');
    if ($agendaId <= 0 && $title === '') {
        continue;
    }
    $relatedOptions[] = [
        'value' => 'agenda:' . $agendaId,
        'label' => 'Agenda - ' . $title,
        'type' => 'agenda',
        'id' => $agendaId,
    ];
}
foreach ($activityRows as $row) {
    $relatedOptions[] = [
        'value' => 'event:' . (int) $row['id'],
        'label' => 'Event - ' . (string) $row['title'],
        'type' => 'event',
        'id' => (int) $row['id'],
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'submit';
    $id = (int) ($_POST['feedback_id'] ?? 0);

    if ($action === 'submit') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['message'] ?? ''));
        $related = parseRelatedItem((string) ($_POST['related_item'] ?? ''));
        $relatedType = $related['type'];
        $relatedId = $related['id'];

        $relatedLabel = '';
        foreach ($relatedOptions as $option) {
            if (($option['type'] ?? '') === $relatedType && (int) ($option['id'] ?? 0) === $relatedId) {
                $relatedLabel = (string) ($option['label'] ?? '');
                break;
            }
        }

        if ($subject === '' || $body === '') {
            $message = 'Please complete the subject and message.';
            $type = 'danger';
        } else {
            $q = $pdo->prepare('INSERT INTO feedback (user_id, subject, message, related_type, related_id, related_label) VALUES (?, ?, ?, ?, ?, ?)');
            $q->execute([$memberId, $subject, $body, $relatedType, $relatedId > 0 ? $relatedId : null, $relatedLabel !== '' ? $relatedLabel : null]);
            $feedbackId = (int) $pdo->lastInsertId();
            logAudit($pdo, 'feedback', $feedbackId, 'create', $memberId, (string) ($member['name'] ?? 'Member'), 'Submitted feedback: ' . $subject);
            $message = 'Feedback submitted successfully.';
        }
    } elseif ($canManage && $action === 'update_status' && $id > 0) {
        $status = in_array($_POST['status'] ?? '', ['new', 'reviewed', 'awaiting_response', 'resolved'], true) ? $_POST['status'] : 'reviewed';
        $q = $pdo->prepare('UPDATE feedback SET status=? WHERE id=?');
        $q->execute([$status, $id]);
        $message = 'Feedback status updated to ' . ucfirst(str_replace('_', ' ', $status)) . '.';
    } elseif ($canManage && $action === 'add_response' && $id > 0) {
        $responseMessage = trim((string) ($_POST['response_message'] ?? ''));
        $responderId = (int) ($member['id'] ?? 0);

        if ($responseMessage === '') {
            $message = 'Response message cannot be empty.';
            $type = 'danger';
        } else {
            $insertResponse = $pdo->prepare('INSERT INTO feedback_responses (feedback_id, responded_by, response_message) VALUES (?, ?, ?)');
            $insertResponse->execute([$id, $responderId, $responseMessage]);

            logAudit($pdo, 'feedback', $id, 'response', $responderId, (string) ($member['name'] ?? 'Officer'), 'Added feedback response');

            $updateFeedback = $pdo->prepare('UPDATE feedback SET status=? WHERE id=?');
            $updateFeedback->execute(['reviewed', $id]);

            $message = 'Response added and feedback marked as reviewed.';
        }
    }
}

$q = $canManage
    ? $pdo->query('SELECT f.*, u.name FROM feedback f JOIN users u ON u.id = f.user_id ORDER BY f.created_at DESC')
    : $pdo->prepare('SELECT f.*, u.name FROM feedback f JOIN users u ON u.id = f.user_id WHERE f.user_id = ? ORDER BY f.created_at DESC');
if (!$canManage) {
    $q->execute([$memberId]);
}
$items = $q->fetchAll();
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$relatedFilter = trim((string) ($_GET['related'] ?? 'all'));
if ($searchTerm !== '' || $statusFilter !== 'all' || $relatedFilter !== 'all') {
    $items = array_values(array_filter($items, static function (array $item) use ($searchTerm, $statusFilter, $relatedFilter): bool {
        $matchesSearch = $searchTerm === ''
            || stripos((string) ($item['subject'] ?? ''), $searchTerm) !== false
            || stripos((string) ($item['message'] ?? ''), $searchTerm) !== false
            || stripos((string) ($item['name'] ?? ''), $searchTerm) !== false;
        $matchesStatus = $statusFilter === 'all' || (string) ($item['status'] ?? 'new') === $statusFilter;
        $matchesRelated = $relatedFilter === 'all' || (string) ($item['related_type'] ?? 'general') === $relatedFilter;
        return $matchesSearch && $matchesStatus && $matchesRelated;
    }));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feedback - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <main class="container-fluid px-4 py-4">
        <p class="text-success text-uppercase small fw-bold mb-1">Member voice</p>
        <h1 class="h3">Feedback and Suggestions</h1>
        <p class="text-muted">Share ideas, concerns, or suggestions with the organization.</p>
        <?php if (!$canManage): ?><div class="text-end mb-3"><button class="btn btn-success" type="button" id="toggleFeedbackForm" aria-expanded="false">+ Submit feedback</button></div><?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo legacyEscape($type); ?>"><?php echo legacyEscape($message); ?></div>
        <?php endif; ?>

        <form method="get" class="row g-2 mb-4" style="max-width: 780px;">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" value="<?php echo legacyEscape($searchTerm); ?>" placeholder="Search feedback">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All status</option>
                    <option value="new" <?php echo $statusFilter === 'new' ? 'selected' : ''; ?>>New</option>
                    <option value="reviewed" <?php echo $statusFilter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    <option value="awaiting_response" <?php echo $statusFilter === 'awaiting_response' ? 'selected' : ''; ?>>Awaiting Response</option>
                    <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="related" class="form-select">
                    <option value="all" <?php echo $relatedFilter === 'all' ? 'selected' : ''; ?>>All posts</option>
                    <option value="activity" <?php echo $relatedFilter === 'activity' ? 'selected' : ''; ?>>Activity</option>
                    <option value="announcement" <?php echo $relatedFilter === 'announcement' ? 'selected' : ''; ?>>Announcement</option>
                    <option value="agenda" <?php echo $relatedFilter === 'agenda' ? 'selected' : ''; ?>>Agenda</option>
                    <option value="event" <?php echo $relatedFilter === 'event' ? 'selected' : ''; ?>>Event</option>
                    <option value="general" <?php echo $relatedFilter === 'general' ? 'selected' : ''; ?>>General</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>

        <div class="row g-4 mt-1">
            <div class="col-lg-5 workflow-form-modal" id="feedbackFormPanel" hidden>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Send feedback</h5>
                    </div>
                    <div class="card-body">
                        <form method="post"><?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="submit">

                            <label class="form-label" for="subject">Subject</label>
                            <input id="subject" name="subject" class="form-control mb-3" required>

                            <label class="form-label" for="related_item">Related post</label>
                            <select id="related_item" name="related_item" class="form-select mb-3">
                                <option value="">General feedback</option>
                                <?php foreach ($relatedOptions as $option): ?>
                                    <option value="<?php echo legacyEscape((string) ($option['value'] ?? '')); ?>" <?php echo (($selectedRelated['type'] ?? 'general') === ($option['type'] ?? '') && ($selectedRelated['id'] ?? 0) === (int) ($option['id'] ?? 0)) ? 'selected' : ''; ?>>
                                        <?php echo legacyEscape((string) ($option['label'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label" for="message">Message</label>
                            <textarea id="message" name="message" rows="6" class="form-control mb-3" required></textarea>

                            <button class="btn btn-primary w-100">Submit Feedback</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo $canManage ? 'Submitted Feedback' : 'My Feedback'; ?></h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <?php if ($canManage): ?>
                                            <th>From</th>
                                        <?php endif; ?>
                                        <th>Related</th>
                                        <th>Status</th>
                                        <?php if ($canManage): ?>
                                            <th>Update</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                        $relatedType = (string) ($item['related_type'] ?? 'general');
                                        $relatedId = (int) ($item['related_id'] ?? 0);
                                        $relatedLabel = trim((string) ($item['related_label'] ?? ''));
                                        $relatedLink = '';
                                        if ($relatedType !== 'general' && $relatedId > 0) {
                                            $relatedLink = relatedPage($relatedType);
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo legacyEscape((string) $item['subject']); ?></strong>
                                                <div class="small text-muted"><?php echo legacyEscape((string) $item['message']); ?></div>
                                            </td>
                                            <?php if ($canManage): ?>
                                                <td><?php echo legacyEscape((string) $item['name']); ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <?php if ($relatedType !== 'general' && $relatedLink !== ''): ?>
                                                    <a href="<?php echo legacyEscape($relatedLink); ?>" class="small text-decoration-none">
                                                        <?php echo legacyEscape($relatedLabel !== '' ? $relatedLabel : ucfirst($relatedType)); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="small text-muted">General</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['status'] === 'resolved' ? 'success' : ($item['status'] === 'reviewed' ? 'info' : 'warning'); ?>">
                                                    <?php echo legacyEscape(ucfirst((string) $item['status'])); ?>
                                                </span>
                                            </td>
                                            <?php if ($canManage): ?>
                                                <td>
                                                    <form method="post" class="d-flex gap-1"><?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="feedback_id" value="<?php echo legacyEscape((string) $item['id']); ?>">
                                                        <select name="status" class="form-select form-select-sm">
                                                            <option value="new" <?php echo (($item['status'] ?? 'new') === 'new') ? 'selected' : ''; ?>>New</option>
                                                            <option value="reviewed" <?php echo (($item['status'] ?? 'new') === 'reviewed') ? 'selected' : ''; ?>>Reviewed</option>
                                                            <option value="awaiting_response" <?php echo (($item['status'] ?? 'new') === 'awaiting_response') ? 'selected' : ''; ?>>Awaiting Response</option>
                                                            <option value="resolved" <?php echo (($item['status'] ?? 'new') === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#feedbackModal<?php echo $item['id']; ?>">Respond</button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (!$items): ?>
                                        <tr>
                                            <td colspan="<?php echo $canManage ? 5 : 4; ?>" class="text-center text-muted py-4">No feedback submitted yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($canManage): ?>
                            <?php foreach ($items as $item): ?>
                                <div class="modal fade" id="feedbackModal<?php echo $item['id']; ?>" tabindex="-1" aria-labelledby="feedbackModalLabel<?php echo $item['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <div>
                                                    <h5 class="modal-title" id="feedbackModalLabel<?php echo $item['id']; ?>"><?php echo legacyEscape($item['subject']); ?></h5>
                                                    <small class="text-muted">From: <?php echo legacyEscape($item['name']); ?></small>
                                                </div>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-4">
                                                    <h6 class="text-muted">Message</h6>
                                                    <p><?php echo nl2br(legacyEscape($item['message'])); ?></p>
                                                </div>

                                                <?php 
                                                $responses = $pdo->prepare('SELECT fr.*, u.name FROM feedback_responses fr JOIN users u ON u.id = fr.responded_by WHERE fr.feedback_id = ? ORDER BY fr.created_at ASC');
                                                $responses->execute([$item['id']]);
                                                $responseList = $responses->fetchAll();
                                                ?>

                                                <?php if ($responseList): ?>
                                                    <div class="mb-4">
                                                        <h6 class="text-muted mb-3">Responses</h6>
                                                        <?php foreach ($responseList as $response): ?>
                                                            <div class="card mb-2" style="background-color: #f8f9fa;">
                                                                <div class="card-body py-2">
                                                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                                                        <strong><?php echo legacyEscape($response['name']); ?></strong>
                                                                        <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($response['created_at'])); ?></small>
                                                                    </div>
                                                                    <p class="mb-0"><?php echo nl2br(legacyEscape($response['response_message'])); ?></p>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <form method="post"><?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="add_response">
                                                    <input type="hidden" name="feedback_id" value="<?php echo $item['id']; ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label">Add Response</label>
                                                        <textarea name="response_message" class="form-control" rows="3" placeholder="Type your response here..." required></textarea>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" class="btn btn-primary">Send Response</button>
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const feedbackFormPanel = document.getElementById('feedbackFormPanel');
    const toggleFeedbackForm = document.getElementById('toggleFeedbackForm');
    if (feedbackFormPanel && toggleFeedbackForm) toggleFeedbackForm.addEventListener('click', function () {
        feedbackFormPanel.hidden = !feedbackFormPanel.hidden;
        document.body.style.overflow = feedbackFormPanel.hidden ? '' : 'hidden';
        toggleFeedbackForm.textContent = feedbackFormPanel.hidden ? '+ Submit feedback' : 'Hide form';
    });
</script>
</body>
</html>

