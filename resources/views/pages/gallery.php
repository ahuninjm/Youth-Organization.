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
$currentPage = 'gallery';

$message = '';
$messageType = 'success';

$uploadDir = public_path('uploads/gallery');
$uploadWebDir = 'uploads/gallery';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['photo'])) {
    $title = trim($_POST['title'] ?? '');
    $caption = trim($_POST['caption'] ?? '');
    $photo = $_FILES['photo'] ?? null;

    if ($title === '') {
        $message = 'Please enter a title for the photo.';
        $messageType = 'danger';
    } elseif (!isset($photo['tmp_name']) || !is_uploaded_file($photo['tmp_name'])) {
        $message = 'Please choose a photo to upload.';
        $messageType = 'danger';
    } else {
        $allowedExtensions = ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp'];
        $extension = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            $message = 'Only JPG, PNG, GIF, and WEBP files are allowed.';
            $messageType = 'danger';
        } else {
            $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($photo['name'], PATHINFO_FILENAME)) ?: 'photo';
            $fileName = $safeBaseName . '-' . uniqid('', true) . '.' . $extension;
            $targetPath = $uploadDir . '/' . $fileName;

            if (move_uploaded_file($photo['tmp_name'], $targetPath)) {
                $relativePath = $uploadWebDir . '/' . $fileName;
                $insertPhoto = $pdo->prepare(
                    'INSERT INTO gallery (title, caption, image_path, uploaded_by) VALUES (?, ?, ?, ?)'
                );
                $insertPhoto->execute([
                    $title,
                    $caption !== '' ? $caption : null,
                    $relativePath,
                    $member['id'] ?? null,
                ]);

                $message = 'Photo uploaded successfully.';
                $messageType = 'success';
            } else {
                $message = 'The photo could not be uploaded. Please try again.';
                $messageType = 'danger';
            }
        }
    }
}

$gallery = $pdo->query(
    'SELECT id, title, caption, image_path, created_at FROM gallery ORDER BY created_at DESC, id DESC'
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
    <title>Gallery - Youth Organization</title>
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
        .upload-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #dbe3ef;
        }
        .gallery-item {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .gallery-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        .gallery-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            display: block;
            background: #f3f6fa;
        }
        .gallery-info {
            padding: 16px;
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
                <a href="announcements.php" class="nav-link">Announcements</a>
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="attendance.php" class="nav-link">My Attendance</a>
                <a href="gallery.php" class="nav-link active">Gallery</a>
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
            <div class="topbar d-flex align-items-center justify-content-between">
                <h1 style="margin: 0; font-size: 24px;">Gallery</h1>
                <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">Back</button>
            </div>

            <div class="content">
                <div class="page-intro">
                    <div class="text-uppercase small fw-bold text-success mb-2">Capture the moments</div>
                    <h2 class="mb-2">Showcase events through photos and stories</h2>
                    <p class="mb-0 text-muted">Upload memorable snapshots and keep the organization's gallery fresh and inviting.</p>
                </div>
                <div class="upload-card">
                    <h5 class="mb-3">Upload a photo</h5>
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="title">Photo Title</label>
                                <input type="text" id="title" name="title" class="form-control" placeholder="Enter a title" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="caption">Caption</label>
                                <input type="text" id="caption" name="caption" class="form-control" placeholder="Optional caption">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="photo">Choose File</label>
                                <input type="file" id="photo" name="photo" class="form-control" accept="image/*" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success mt-3">Upload Photo</button>
                    </form>
                </div>

                <?php if (!$gallery): ?>
                    <div class="alert alert-light">No photos uploaded yet.</div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($gallery as $item): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="gallery-item">
                                <?php if (!empty($item['image_path'])): ?>
                                    <a href="<?php echo legacyEscape($item['image_path']); ?>" target="_blank" rel="noopener noreferrer">
                                        <img class="gallery-image" src="<?php echo legacyEscape($item['image_path']); ?>" alt="<?php echo legacyEscape($item['title']); ?>">
                                    </a>
                                <?php else: ?>
                                    <div class="gallery-image d-flex align-items-center justify-content-center text-muted">No preview available</div>
                                <?php endif; ?>
                                <div class="gallery-info">
                                    <h6 class="mb-2"><?php echo legacyEscape($item['title']); ?></h6>
                                    <?php if (!empty($item['caption'])): ?>
                                        <p class="mb-0 text-muted"><?php echo legacyEscape($item['caption']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
