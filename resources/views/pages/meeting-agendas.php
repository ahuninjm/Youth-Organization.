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

$memberQuery = $pdo->prepare('SELECT id, name, role, position FROM users WHERE id = ? LIMIT 1');
$memberQuery->execute([$member['id'] ?? 0]);
$memberRecord = $memberQuery->fetch() ?: [];
$memberName = $memberRecord['name'] ?? $member['name'] ?? 'Member';
$memberRole = $memberRecord['role'] ?? $member['role'] ?? 'member';
$memberPosition = trim((string)($memberRecord['position'] ?? $member['position'] ?? ''));
$normalizedPosition = strtolower($memberPosition);
$canManageAgendas = in_array($normalizedPosition, ['president', 'secretary'], true) || $memberRole === 'admin';
$canViewAgendas = true;
$message = '';
$messageType = 'success';

$currentPage = 'meeting-agendas';

function loadAgendasFromStorage(): array {
    $storagePath = dirname(__DIR__, 3) . '/meeting-agendas.json';
    if (!file_exists($storagePath)) {
        return [];
    }

    $contents = file_get_contents($storagePath);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    foreach ($decoded as $index => &$agenda) {
        if (!isset($agenda['id'])) {
            $agenda['id'] = $index + 1;
        }
    }
    unset($agenda);

    return $decoded;
}

function saveAgendasToStorage(array $agendas): void {
    $storagePath = dirname(__DIR__, 3) . '/meeting-agendas.json';
    file_put_contents($storagePath, json_encode($agendas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManageAgendas) {
    $meeting = trim($_POST['meeting'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');
    $venue = trim($_POST['venue'] ?? '');
    $items = trim($_POST['items'] ?? '');

    if ($meeting !== '' && $date !== '' && $time !== '' && $venue !== '') {
        $agendaItems = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $items) ?: []), static function ($item): bool {
            return $item !== '';
        }));

        $agendas = loadAgendasFromStorage();
        $maxId = 0;
        foreach ($agendas as $agenda) {
            if (isset($agenda['id'])) {
                $maxId = max($maxId, (int)$agenda['id']);
            }
        }

        $agendas[] = [
            'id' => $maxId + 1,
            'meeting' => $meeting,
            'date' => $date,
            'time' => $time,
            'venue' => $venue,
            'items' => $agendaItems,
        ];
        saveAgendasToStorage($agendas);
        $message = 'Agenda saved successfully.';
        $messageType = 'success';
    }
}

$agendas = loadAgendasFromStorage();

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Agendas - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f3f6fa;
            color: #1d2530;
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
        .agenda-card {
            border-left: 4px solid #2454a6;
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">

        <!-- Main Content -->
        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">Meeting Agendas</h1>
            </div>

            <div class="content">
                <div class="page-intro">
                    <div class="text-uppercase small fw-bold text-success mb-2">Meeting coordination</div>
                    <h2 class="mb-2">Bring structure to every gathering</h2>
                    <p class="mb-0 text-muted">Clear agendas make meetings more focused, productive, and easier for everyone to follow.</p>
                </div>
                <?php if ($canManageAgendas): ?><div class="text-end mb-3"><button class="btn btn-success" type="button" id="toggleAgendaForm" aria-expanded="false">+ Set agenda</button></div><?php endif; ?>
                <?php if ($message): ?>
                <div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div>
                <?php endif; ?>
                <?php if (!$canViewAgendas): ?>
                <div class="alert alert-warning">You do not have permission to view meeting agendas.</div>
                <?php else: ?>
                <?php if ($canManageAgendas): ?>
                <div class="card mb-4 workflow-form-modal" id="agendaFormPanel" hidden>
                    <div class="card-body">
                        <h5 class="card-title mb-3">Set Agendas</h5>
                        <form method="POST"><?php echo csrf_field(); ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="meeting">Meeting Title</label>
                                    <input type="text" id="meeting" name="meeting" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="date">Date</label>
                                    <input type="date" id="date" name="date" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="time">Time</label>
                                    <input type="text" id="time" name="time" class="form-control" placeholder="e.g. 6:00 PM" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="venue">Venue</label>
                                    <input type="text" id="venue" name="venue" class="form-control" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="items">Agenda Items</label>
                                    <textarea id="items" name="items" class="form-control" rows="4" placeholder="Enter each agenda item on a new line"></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success mt-3">Save Agenda</button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    Only the President and Secretary can set meeting agendas.
                </div>
                <?php endif; ?>

                <?php if (!$agendas): ?>
                <div class="alert alert-secondary">No agendas have been set yet.</div>
                <?php else: ?>
                <?php foreach ($agendas as $agenda): ?>
                <div class="card agenda-card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="card-title"><?php echo legacyEscape($agenda['meeting']); ?></h5>
                                <p class="text-muted mb-1">
                                    <strong>Date:</strong> <?php echo legacyEscape($agenda['date']); ?>
                                </p>
                                <p class="text-muted mb-1">
                                    <strong>Time:</strong> <?php echo legacyEscape($agenda['time']); ?>
                                </p>
                                <p class="text-muted mb-1">
                                    <strong>Venue:</strong> <?php echo legacyEscape($agenda['venue']); ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ($canManageAgendas && isset($agenda['id'])): ?>
                                <a href="attendance.php?agenda_id=<?php echo legacyEscape((string)$agenda['id']); ?>" class="btn btn-outline-primary btn-sm">Take Attendance</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        </div>

                        <h6 class="mt-3 mb-2">Agenda Items:</h6>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($agenda['items'] as $item): ?>
                            <li class="list-group-item border-0 ps-0">
                                <?php echo legacyEscape($item); ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const agendaFormPanel = document.getElementById('agendaFormPanel');
    const toggleAgendaForm = document.getElementById('toggleAgendaForm');
    if (agendaFormPanel && toggleAgendaForm) toggleAgendaForm.addEventListener('click', function () {
        agendaFormPanel.hidden = !agendaFormPanel.hidden;
        document.body.style.overflow = agendaFormPanel.hidden ? '' : 'hidden';
        toggleAgendaForm.textContent = agendaFormPanel.hidden ? '+ Set agenda' : 'Hide form';
    });
</script>
</body>
</html>
