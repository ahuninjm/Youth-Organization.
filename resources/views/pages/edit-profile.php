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
$userId = (int) ($member['id'] ?? 0);
$memberQuery = $pdo->prepare('SELECT id, name, username, role, email, phone FROM users WHERE id = ? LIMIT 1');
$memberQuery->execute([$userId]);
$memberRecord = $memberQuery->fetch() ?: [];

if (!$memberRecord) {
    header('Location: profile.php');
    exit;
}

$currentPage = 'profile';
$message = '';
$messageType = 'success';
$profileAlert = null;
$formValues = [
    'name' => $memberRecord['name'] ?? '',
    'username' => $memberRecord['username'] ?? '',
    'email' => $memberRecord['email'] ?? '',
    'phone' => $memberRecord['phone'] ?? '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formValues = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'username' => trim((string) ($_POST['username'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
    ];
    $errors = [];

    if ($formValues['name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if ($formValues['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($formValues['username']) < 4) {
        $errors[] = 'Username must be at least 4 characters long.';
    }
    if ($formValues['email'] !== '' && filter_var($formValues['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $duplicateUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
        $duplicateUser->execute([$formValues['username'], $userId]);
        if ($duplicateUser->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (empty($errors)) {
        $updateProfile = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, phone = ? WHERE id = ?');
        $updateProfile->execute([
            $formValues['name'],
            $formValues['username'],
            $formValues['email'],
            $formValues['phone'],
            $userId,
        ]);

        $member['name'] = $formValues['name'];
        $member['username'] = $formValues['username'];
        $member['email'] = $formValues['email'];
        $member['phone'] = $formValues['phone'];
        $_SESSION['user'] = $member;
        $message = 'Your personal information was updated successfully.';
        $profileAlert = [
            'type' => 'success',
            'title' => 'Profile Updated',
            'text' => 'Your personal information was updated successfully.',
        ];
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'danger';
        $profileAlert = [
            'type' => 'error',
            'title' => 'Profile Not Updated',
            'text' => implode("\n", $errors),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>

    <main class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-success text-uppercase small fw-bold mb-1">Account settings</p>
                <h1 class="h3 mb-1">Edit Profile</h1>
                <p class="text-muted mb-0">Update your personal information.</p>
            </div>
            <a href="profile.php" class="btn btn-outline-secondary">Back to Profile</a>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4 p-lg-5">
                <form method="post" class="mx-auto" style="max-width: 760px;"><?php echo csrf_field(); ?>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="profile_name">Full Name</label>
                            <input type="text" id="profile_name" name="name" class="form-control" value="<?php echo htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="profile_username">Username</label>
                            <input type="text" id="profile_username" name="username" class="form-control" value="<?php echo htmlspecialchars($formValues['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="profile_email">Email</label>
                            <input type="email" id="profile_email" name="email" class="form-control" value="<?php echo htmlspecialchars($formValues['email'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="profile_phone">Phone</label>
                            <input type="text" id="profile_phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($formValues['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="profile.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const profileAlert = <?php echo json_encode($profileAlert, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        if (profileAlert && window.Swal) {
            Swal.fire({
                icon: profileAlert.type,
                title: profileAlert.title,
                text: profileAlert.text,
                confirmButtonColor: '#0d6efd'
            });
        }
    </script>
</body>
</html>
