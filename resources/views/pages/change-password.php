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
$memberRole = $member['role'] ?? 'member';
$currentPage = 'profile';
$passwordAlert = null;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($currentPassword === '') {
        $errors[] = 'Current password is required.';
    }
    if ($newPassword === '') {
        $errors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    }
    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (empty($errors)) {
        $currentUser = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $currentUser->execute([$userId]);
        $currentUserRecord = $currentUser->fetch();

        if (!$currentUserRecord || !password_verify($currentPassword, $currentUserRecord['password'])) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePassword = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $updatePassword->execute([$passwordHash, $userId]);
            $passwordAlert = [
                'type' => 'success',
                'title' => 'Password Changed',
                'text' => 'Your password has been updated successfully.',
            ];
        }
    }

    if (!empty($errors)) {
        $passwordAlert = [
            'type' => 'error',
            'title' => 'Password Not Changed',
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
    <title>Change Password - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>

    <main class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-success text-uppercase small fw-bold mb-1">Account security</p>
                <h1 class="h3 mb-1">Change Password</h1>
                <p class="text-muted mb-0">Create a new password for your account.</p>
            </div>
            <a href="profile.php" class="btn btn-outline-secondary">Back to Profile</a>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4 p-lg-5">
                <form method="post" id="passwordChangeForm" class="mx-auto" style="max-width: 760px;"><?php echo csrf_field(); ?>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" minlength="8" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="profile.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Password</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const passwordAlert = <?php echo json_encode($passwordAlert, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const passwordChangeForm = document.getElementById('passwordChangeForm');

        if (passwordAlert && window.Swal) {
            Swal.fire({
                icon: passwordAlert.type,
                title: passwordAlert.title,
                text: passwordAlert.text,
                confirmButtonColor: '#0d6efd'
            });
        }

        if (passwordChangeForm) {
            passwordChangeForm.addEventListener('submit', (event) => {
                if (!window.Swal || passwordChangeForm.dataset.confirmed === 'true') {
                    return;
                }
                event.preventDefault();
                Swal.fire({
                    icon: 'question',
                    title: 'Change password?',
                    text: 'After this change, use the new password the next time you log in.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, change it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#0d6efd'
                }).then((result) => {
                    if (result.isConfirmed) {
                        passwordChangeForm.dataset.confirmed = 'true';
                        passwordChangeForm.submit();
                    }
                });
            });
        }
    </script>
</body>
</html>
