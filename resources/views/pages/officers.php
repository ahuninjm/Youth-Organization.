<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$member = $_SESSION['user'];
$currentPage = 'officers';

require_once dirname(__DIR__, 3) . '/db.php';

$columnCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'position'")->fetch();
if (!$columnCheck) {
    $pdo->exec("ALTER TABLE users ADD COLUMN position VARCHAR(80) NULL");
}

$officers = $pdo->query(
    "SELECT name, position, phone, email
     FROM users
     WHERE role = 'officer' AND status = 'active'
     ORDER BY position ASC, name ASC"
)->fetchAll();

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Officers - Youth Organization</title>
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
        .officer-card {
            transition: all 0.3s ease;
        }
        .officer-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">
        </aside>

        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">Officers</h1>
            </div>

            <div class="content">
                <div class="row g-4">
                    <?php if (!$officers): ?>
                        <div class="col-12">
                            <div class="card p-4">
                                <div class="text-center text-muted">No officers have been assigned yet.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($officers as $officer): ?>
                            <div class="col-md-6">
                                <div class="card officer-card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title mb-2"><?php echo legacyEscape($officer['position'] ?: 'Officer'); ?></h5>
                                        <p class="mb-1"><strong>Name:</strong> <?php echo legacyEscape($officer['name']); ?></p>
                                        <p class="mb-1"><strong>Role:</strong> Officer</p>
                                        <p class="mb-0"><strong>Contact:</strong> <?php echo legacyEscape($officer['phone'] ?: $officer['email'] ?: 'Not provided'); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
