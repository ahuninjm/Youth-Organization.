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
$memberRole = $member['role'] ?? 'member';
$currentPage = 'members';

if (!in_array($memberRole, ['admin', 'officer'], true)) {
    $_SESSION['flash_message'] = 'Access denied. Only officers and administrators can manage members.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: dashboard.php');
    exit;
}

$message = '';
$messageType = 'success';

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ensurePositionColumn(PDO $pdo): void {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'position'")->fetch();
    if (!$columnCheck) {
        $pdo->exec("ALTER TABLE users ADD COLUMN position VARCHAR(80) NULL");
    }
}

ensurePositionColumn($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        $userQuery = $pdo->prepare('SELECT id, name, username, role, status FROM users WHERE id = ? LIMIT 1');
        $userQuery->execute([$userId]);
        $targetUser = $userQuery->fetch();

        if (!$targetUser) {
            $message = 'Member not found.';
            $messageType = 'danger';
        } elseif ($targetUser['role'] === 'admin') {
            $message = 'Cannot modify an administrator account.';
            $messageType = 'danger';
        } else {
            if ($action === 'approve_member') {
                $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')
                    ->execute(['active', $userId]);
                logAudit($pdo, 'member', $userId, 'update', (int) ($member['id'] ?? 0), (string) ($member['name'] ?? 'System'), 'Approved member account');
                $message = 'Member approved successfully.';
                $messageType = 'success';
            } elseif ($action === 'assign_position') {
                if ($memberRole !== 'admin') {
                    $message = 'Only administrators can assign officer positions.';
                    $messageType = 'danger';
                } else {
                    $position = trim($_POST['position'] ?? '');
                    $allowedPositions = [
                        'President',
                        'Purok President',
                        'Vice President',
                        'Secretary',
                        'Treasurer',
                        'Auditor',
                        'PRO',
                        'Committee Head',
                    ];

                    if ($position === '' || !in_array($position, $allowedPositions, true)) {
                        $message = 'Please select a valid officer position.';
                        $messageType = 'danger';
                    } else {
                        $pdo->prepare('UPDATE users SET role = ?, position = ?, status = ? WHERE id = ?')
                            ->execute(['officer', $position, 'active', $userId]);
                        logAudit($pdo, 'member', $userId, 'update', (int) ($member['id'] ?? 0), (string) ($member['name'] ?? 'System'), 'Assigned officer position: ' . $position);
                        $message = 'Position assigned successfully.';
                        $messageType = 'success';
                    }
                }
            }
        }
    }
}

$members = $pdo->query(
    'SELECT id, name, username, role, status, position, email, phone
     FROM users
     WHERE role != "admin"
     ORDER BY status DESC, role ASC, name ASC'
)->fetchAll();

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if ($searchTerm !== '' || $roleFilter !== 'all' || $statusFilter !== 'all') {
    $members = array_values(array_filter($members, static function (array $user) use ($searchTerm, $roleFilter, $statusFilter): bool {
        $matchesSearch = $searchTerm === ''
            || stripos((string) ($user['name'] ?? ''), $searchTerm) !== false
            || stripos((string) ($user['username'] ?? ''), $searchTerm) !== false;
        $matchesRole = $roleFilter === 'all' || strtolower((string) ($user['role'] ?? 'member')) === strtolower($roleFilter);
        $matchesStatus = $statusFilter === 'all' || strtolower((string) ($user['status'] ?? 'inactive')) === strtolower($statusFilter);
        return $matchesSearch && $matchesRole && $matchesStatus;
    }));
}

$selectedUser = null;
if (isset($_GET['assign_id']) && is_numeric($_GET['assign_id'])) {
    $selectedId = intval($_GET['assign_id']);
    $selectedStmt = $pdo->prepare('SELECT id, name, username, role, status, position FROM users WHERE id = ? LIMIT 1');
    $selectedStmt->execute([$selectedId]);
    $selectedUser = $selectedStmt->fetch();
}

if (isset($_GET['export']) && $_GET['export'] === 'members') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="member-list-export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Username', 'Role', 'Position', 'Status', 'Email', 'Phone']);
    foreach ($members as $user) {
        fputcsv($output, [
            (string) ($user['name'] ?? ''),
            (string) ($user['username'] ?? ''),
            (string) ($user['role'] ?? 'member'),
            (string) ($user['position'] ?? '-'),
            (string) ($user['status'] ?? 'inactive'),
            (string) ($user['email'] ?? ''),
            (string) ($user['phone'] ?? ''),
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members Management - Youth Organization</title>
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
        .member-card {
            transition: all 0.3s ease;
        }
        .member-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">

        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">Members Management</h1>
            </div>

            <div class="content">
                <div class="registration-flow mb-4" aria-label="Youth registration process">
                    <div class="registration-step"><span class="registration-step-number">1</span><span class="registration-step-label">Youth Registration</span></div>
                    <div class="registration-step"><span class="registration-step-number">2</span><span class="registration-step-label">Personal Information</span></div>
                    <div class="registration-step"><span class="registration-step-number">3</span><span class="registration-step-label">Application Submitted</span></div>
                    <div class="registration-step"><span class="registration-step-number">4</span><span class="registration-step-label">Admin/Officer Verification</span></div>
                    <div class="registration-step"><span class="registration-step-number">5</span><span class="registration-step-label">Approved</span></div>
                    <div class="registration-step"><span class="registration-step-number">6</span><span class="registration-step-label">Member Account Created</span></div>
                </div>
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div>
                <?php endif; ?>

                <div class="section-toolbar">
                    <div>
                        <p class="text-success text-uppercase small fw-bold mb-1">Membership</p>
                        <h2 class="h4 mb-0">Members and verification</h2>
                    </div>
                    <div class="toolbar-actions">
                        <a href="members.php?export=members<?php echo $searchTerm !== '' ? '&q=' . rawurlencode($searchTerm) : ''; ?><?php echo $roleFilter !== 'all' ? '&role=' . rawurlencode($roleFilter) : ''; ?><?php echo $statusFilter !== 'all' ? '&status=' . rawurlencode($statusFilter) : ''; ?>" class="btn btn-primary">Export Excel</a>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Export PDF</button>
                    </div>
                </div>

                <form method="get" class="row g-2 mb-4" style="max-width: 760px;">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" value="<?php echo legacyEscape($searchTerm); ?>" placeholder="Search member name or username">
                    </div>
                    <div class="col-md-3">
                        <select name="role" class="form-select">
                            <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All roles</option>
                            <option value="member" <?php echo $roleFilter === 'member' ? 'selected' : ''; ?>>Member</option>
                            <option value="officer" <?php echo $roleFilter === 'officer' ? 'selected' : ''; ?>>Officer</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All status</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                </form>

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card member-card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Applications and Members</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover readable-hover-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Role</th>
                                                <th>Application Status</th>
                                                <th>Position</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $user): ?>
                                                <tr>
                                                    <td><?php echo legacyEscape($user['name']); ?></td>
                                                    <td><?php echo legacyEscape($user['username']); ?></td>
                                                    <td><?php echo legacyEscape(ucfirst($user['role'])); ?></td>
                                                    <td>
                                                        <?php if ($user['status'] === 'inactive'): ?>
                                                            <span class="badge bg-warning text-dark">Pending verification</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">Approved - Account active</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo legacyEscape($user['position'] ?? '-'); ?></td>
                                                    <td>
                                                        <?php if ($user['status'] === 'inactive'): ?>
                                                            <form method="POST" class="d-inline"><?php echo csrf_field(); ?>
                                                                <input type="hidden" name="action" value="approve_member">
                                                                <input type="hidden" name="user_id" value="<?php echo legacyEscape((string)$user['id']); ?>">
                                                                <button type="submit" class="btn btn-sm btn-success">Verify &amp; Approve</button>
                                                            </form>
                                                        <?php else: ?>
                                                                <?php if ($memberRole === 'admin'): ?>
                                                                    <a href="members.php?assign_id=<?php echo legacyEscape((string)$user['id']); ?>" class="btn btn-sm btn-outline-primary">Assign Position</a>
                                                                <?php else: ?>
                                                                    <span class="text-muted small">Account active</span>
                                                                <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card h-100 member-card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><?php echo $selectedUser ? 'Assign Officer Position' : 'Select a Member'; ?></h5>
                            </div>
                            <div class="card-body">
                                <?php if ($selectedUser): ?>
                                    <p><strong>Name:</strong> <?php echo legacyEscape($selectedUser['name']); ?></p>
                                    <p><strong>Username:</strong> <?php echo legacyEscape($selectedUser['username']); ?></p>
                                    <p><strong>Status:</strong> <?php echo legacyEscape(ucfirst($selectedUser['status'])); ?></p>
                                    <?php if ($selectedUser['status'] !== 'active'): ?>
                                        <div class="alert alert-warning">
                                            This member must be approved before assigning an officer position.
                                        </div>
                                    <?php else: ?>
                                        <form method="POST"><?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="assign_position">
                                            <input type="hidden" name="user_id" value="<?php echo legacyEscape((string)$selectedUser['id']); ?>">

                                            <div class="mb-3">
                                                <label class="form-label" for="position">Officer Position</label>
                                                <select id="position" name="position" class="form-select" required>
                                                    <option value="">Select position</option>
                                                    <?php foreach (['President', 'Purok President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'PRO', 'Committee Head'] as $position): ?>
                                                        <option value="<?php echo legacyEscape($position); ?>" <?php echo $selectedUser['position'] === $position ? 'selected' : ''; ?>><?php echo legacyEscape($position); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <button type="submit" class="btn btn-primary w-100">Save Position</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="text-muted">Choose a member from the list to assign an officer position.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
