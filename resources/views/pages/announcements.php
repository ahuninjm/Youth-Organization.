<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$member = $_SESSION['user'];

require_once dirname(__DIR__, 3) . '/db.php';
require_once dirname(__DIR__, 3) . '/announcements_store.php';

$memberQuery = $pdo->prepare('SELECT id, name, role, position FROM users WHERE id = ? LIMIT 1');
$memberQuery->execute([$member['id'] ?? 0]);
$memberRecord = $memberQuery->fetch() ?: [];

$memberName = $memberRecord['name'] ?? $member['name'] ?? 'Member';
$memberRole = $memberRecord['role'] ?? $member['role'] ?? 'member';
$memberPosition = strtolower(trim((string)($memberRecord['position'] ?? $member['position'] ?? '')));

$announcements = loadAnnouncementsFromStorage();
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? 'all'));
$dateFilter = trim((string) ($_GET['date'] ?? ''));
if ($searchTerm !== '' || $typeFilter !== 'all' || $dateFilter !== '') {
    $announcements = array_values(array_filter($announcements, static function (array $announcement) use ($searchTerm, $typeFilter, $dateFilter): bool {
        $matchesSearch = $searchTerm === ''
            || stripos((string) ($announcement['title'] ?? ''), $searchTerm) !== false
            || stripos((string) ($announcement['body'] ?? ''), $searchTerm) !== false;
        $matchesType = $typeFilter === 'all' || (string) ($announcement['type'] ?? 'info') === $typeFilter;
        $matchesDate = $dateFilter === '' || date('Y-m-d', strtotime((string) ($announcement['date'] ?? date('F j, Y')))) === $dateFilter;
        return $matchesSearch && $matchesType && $matchesDate;
    }));
}

$currentPage = 'announcements';

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Youth Organization</title>
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
        .announcement-editor {
            overflow: hidden;
            border: 1px solid #dbe8e0;
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(20, 56, 43, 0.07);
        }
        .announcement-editor .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            background: #f8fbf9 !important;
        }
        .editor-kicker {
            margin-bottom: 4px;
            color: #2f8a63;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .editor-title {
            margin: 0;
            color: #12352a;
            font-size: 1.1rem;
            font-weight: 800;
        }
        .editor-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #66746e;
            font-size: 0.78rem;
            white-space: nowrap;
        }
        .editor-status::before {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #f2b94b;
            content: '';
        }
        .announcement-editor .card-body {
            padding: 22px;
        }
        .announcement-editor .form-label {
            margin-bottom: 7px;
        }
        .announcement-editor .form-control,
        .announcement-editor .form-select {
            min-height: 46px;
            padding: 11px 13px;
        }
        .announcement-editor textarea.form-control {
            min-height: 118px;
            resize: vertical;
        }
        .announcement-editor .form-control::placeholder {
            color: #9aa9a2;
        }
        .announcement-editor .table-responsive {
            margin-top: 24px;
            border-top: 1px solid #e4ede7;
            padding-top: 18px;
        }
        .announcement-editor .table {
            margin-bottom: 0;
        }
        .announcement-editor .table thead th {
            border-top: 0;
            padding: 10px 8px;
        }
        .announcement-editor .table tbody td {
            padding: 12px 8px;
        }
        .announcement-editor .table tbody tr:last-child td {
            border-bottom: 0;
        }
        .announcement-editor .btn-success {
            min-height: 44px;
        }
        @media (max-width: 575.98px) {
            .announcement-editor .card-header,
            .announcement-editor .card-body {
                padding: 16px;
            }
            .editor-status {
                display: none;
            }
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="brand-lockup">
                <div class="brand-mark">yo</div>
                <div>
                    <div style="font-weight: 600;">Youth Org</div>
                    <div style="font-size: 12px; opacity: 0.7;">Purok New Binangcaan</div>
                </div>
            </div>

            <nav class="nav flex-column">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <?php if (($member['role'] ?? 'member') === 'admin'): ?>
                <a href="members.php" class="nav-link">Members Management</a>
                <?php endif; ?>
                <a href="meeting-agendas.php" class="nav-link">Meeting Agendas</a>
                <a href="announcements.php" class="nav-link active">Announcements</a>
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="attendance.php" class="nav-link">My Attendance</a>
                <a href="gallery.php" class="nav-link">Gallery</a>
                <a href="officers.php" class="nav-link">Officers</a>
            </nav>

            <div class="member-chip">
                <div style="font-size: 13px; opacity: 0.7;">Logged in as</div>
                <div style="font-weight: 600;"><?php echo legacyEscape($member['name']); ?></div>
                <a href="login.php?logout=1" style="font-size: 13px; color: #f2a541; text-decoration: none; margin-top: 10px; display: inline-block;">Logout</a>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">Announcements</h1>
            </div>

            <div class="content">
                <div class="page-intro">
                    <div class="text-uppercase small fw-bold text-success mb-2">Share the latest updates</div>
                    <h2 class="mb-2">Keep your members informed and inspired</h2>
                    <p class="mb-0 text-muted">Announcements help the group stay connected, prepared, and engaged with every new update.</p>
                </div>

                <form method="get" class="filter-bar" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:20px;">
                    <input type="text" name="q" class="form-control" value="<?php echo legacyEscape($searchTerm); ?>" placeholder="Search announcements" style="max-width:220px;">
                    <select name="type" class="form-select" style="max-width:180px;">
                        <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All types</option>
                        <option value="success" <?php echo $typeFilter === 'success' ? 'selected' : ''; ?>>Success</option>
                        <option value="warning" <?php echo $typeFilter === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="info" <?php echo $typeFilter === 'info' ? 'selected' : ''; ?>>Info</option>
                        <option value="danger" <?php echo $typeFilter === 'danger' ? 'selected' : ''; ?>>Danger</option>
                    </select>
                    <input type="date" name="date" class="form-control" value="<?php echo legacyEscape($dateFilter); ?>" style="max-width:180px;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <?php if ($searchTerm !== '' || $typeFilter !== 'all' || $dateFilter !== ''): ?>
                        <a href="announcements.php" class="btn btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </form>

            <?php
                $canEditAnnouncements = $memberRole === 'admin'
                    || in_array($memberPosition, ['president', 'secretary'], true);

                $message = '';
                $messageType = 'success';

                // Edit announcements (create/update)
                if ($canEditAnnouncements && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                    $action = $_POST['action'] ?? 'create';
                    $id = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;

                    $title = trim((string)($_POST['title'] ?? ''));
                    $body = trim((string)($_POST['body'] ?? ''));
                    $type = trim((string)($_POST['type'] ?? 'info'));

                    $allowedTypes = ['success', 'warning', 'info', 'danger'];
                    $date = trim((string)($_POST['date'] ?? ''));

                    if ($title === '' || $body === '' || $type === '' || !in_array($type, $allowedTypes, true)) {
                        $message = 'Please provide a valid title, body, and announcement type.';
                        $messageType = 'danger';
                    } else {
                        // Use current date if blank
                        if ($date === '') {
                            $date = date('F j, Y');
                        }

                        $existing = loadAnnouncementsFromStorage();

                        if ($action === 'update' && $id > 0) {
                            $updated = false;
                            foreach ($existing as &$ann) {
                                if (isset($ann['id']) && (int)$ann['id'] === $id) {
                                    $ann['title'] = $title;
                                    $ann['body'] = $body;
                                    $ann['type'] = $type;
                                    $ann['date'] = $date;
                                    $updated = true;
                                    break;
                                }
                            }
                            unset($ann);

                            if ($updated) {
                                saveAnnouncementsToStorage($existing);
                                $message = 'Announcement updated successfully.';
                                $messageType = 'success';
                            } else {
                                $message = 'Announcement not found.';
                                $messageType = 'danger';
                            }
                        } else {
                            // create
                            $maxId = 0;
                            foreach ($existing as $ann) {
                                if (isset($ann['id'])) {
                                    $maxId = max($maxId, (int)$ann['id']);
                                }
                            }

                            $existing[] = [
                                'id' => $maxId + 1,
                                'type' => $type,
                                'title' => $title,
                                'body' => $body,
                                'date' => $date,
                            ];

                            saveAnnouncementsToStorage($existing);
                            $message = 'Announcement added successfully.';
                            $messageType = 'success';
                        }

                        $announcements = loadAnnouncementsFromStorage();
                    }
                }

                // Preload selected announcement for edit
                $selectedId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
                $editTarget = null;
                if ($canEditAnnouncements && $selectedId > 0) {
                    foreach ($announcements as $a) {
                        if (isset($a['id']) && (int)$a['id'] === $selectedId) {
                            $editTarget = $a;
                            break;
                        }
                    }
                }
            ?>

            <?php if ($canEditAnnouncements): ?>
                <?php if (!$editTarget): ?><div class="text-end mb-3"><button class="btn btn-success" type="button" id="toggleAnnouncementForm" aria-expanded="false">+ Create announcement</button></div><?php endif; ?>
                <div class="card announcement-editor mb-4 <?php echo $editTarget ? '' : 'workflow-form-modal'; ?>" <?php echo $editTarget ? '' : 'hidden id="announcementFormPanel"'; ?>>
                    <div class="card-header">
                        <div>
                            <div class="editor-kicker"><?php echo $editTarget ? 'Update an announcement' : 'New announcement'; ?></div>
                            <h5 class="editor-title"><?php echo $editTarget ? 'Edit announcement' : 'Create an announcement'; ?></h5>
                        </div>
                        <span class="editor-status">Draft ready</span>
                    </div>
                    <div class="card-body">
                        <?php if ($message !== ''): ?>
                            <div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div>
                        <?php endif; ?>

                        <form method="POST" class="mb-4"><?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $editTarget ? 'update' : 'create'; ?>">
                            <input type="hidden" name="announcement_id" value="<?php echo legacyEscape((string)($editTarget['id'] ?? 0)); ?>">

                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label" for="type">Type</label>
                                    <select id="type" name="type" class="form-select" required>
                                        <?php
                                            $types = ['success', 'warning', 'info', 'danger'];
                                            $currentType = $editTarget['type'] ?? 'info';
                                        ?>
                                        <?php foreach ($types as $t): ?>
                                            <option value="<?php echo legacyEscape($t); ?>" <?php echo $currentType === $t ? 'selected' : ''; ?>>
                                                <?php echo legacyEscape(ucfirst($t)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="date">Date</label>
                                    <input id="date" name="date" class="form-control" value="<?php echo legacyEscape((string)($editTarget['date'] ?? '')); ?>" placeholder="e.g. July 1, 2026" autocomplete="off">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="title">Title</label>
                                    <input id="title" name="title" class="form-control" value="<?php echo legacyEscape((string)($editTarget['title'] ?? '')); ?>" placeholder="Write a clear title" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="body">Body</label>
                                    <textarea id="body" name="body" class="form-control" rows="3" placeholder="Share the details your members need to know" required><?php echo legacyEscape((string)($editTarget['body'] ?? '')); ?></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success mt-3 w-100">
                                <?php echo $editTarget ? 'Update Announcement' : 'Add Announcement'; ?>
                            </button>

                            <?php if ($editTarget): ?>
                                <div class="text-center mt-2">
                                    <a href="announcements.php" class="btn btn-outline-secondary btn-sm">Cancel Edit</a>
                                </div>
                            <?php endif; ?>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th style="width: 160px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($announcements as $a): ?>
                                        <tr>
                                            <td><?php echo legacyEscape($a['title'] ?? '-'); ?></td>
                                            <td><span class="badge bg-<?php echo legacyEscape($a['type'] ?? 'info'); ?>"><?php echo legacyEscape(ucfirst((string)($a['type'] ?? 'info'))); ?></span></td>
                                            <td><?php echo legacyEscape($a['date'] ?? ''); ?></td>
                                            <td>
                                                <?php if (isset($a['id'])): ?>
                                                    <a class="btn btn-primary btn-sm" href="announcements.php?edit_id=<?php echo legacyEscape((string)$a['id']); ?>">Edit</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$announcements): ?>
                                        <tr><td colspan="4" class="text-muted text-center">No announcements yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row">
                <?php foreach ($announcements as $announcement): ?>
                    <div class="col-12 mb-3">
                        <div class="alert alert-<?php echo legacyEscape($announcement['type']); ?> alert-dismissible fade show" role="alert">
                            <h5 class="alert-heading"><?php echo legacyEscape($announcement['title']); ?></h5>
                            <p class="mb-1"><?php echo legacyEscape($announcement['body']); ?></p>
                            <small class="text-muted"><?php echo legacyEscape($announcement['date']); ?></small>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($canEditAnnouncements && !$editTarget): ?><script>
    const announcementFormPanel = document.getElementById('announcementFormPanel');
    const toggleAnnouncementForm = document.getElementById('toggleAnnouncementForm');
    toggleAnnouncementForm.addEventListener('click', function () {
        announcementFormPanel.hidden = !announcementFormPanel.hidden;
        document.body.style.overflow = announcementFormPanel.hidden ? '' : 'hidden';
        toggleAnnouncementForm.textContent = announcementFormPanel.hidden ? '+ Create announcement' : 'Hide form';
    });
</script><?php endif; ?>
</body>
</html>
