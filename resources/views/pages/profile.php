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
$memberRecord = [];

if (isset($member['id'])) {
    $memberQuery = $pdo->prepare('SELECT id, name, username, role, email, phone, status, birthdate, address, skills, committee_assignment, created_at FROM users WHERE id = ? LIMIT 1');
    $memberQuery->execute([$member['id']]);
    $memberRecord = $memberQuery->fetch() ?: [];
}

$memberName = $memberRecord['name'] ?? $member['name'] ?? '';
$memberUsername = $memberRecord['username'] ?? $member['username'] ?? '';
$memberRole = $memberRecord['role'] ?? $member['role'] ?? 'member';
$memberEmail = $memberRecord['email'] ?? '';
$memberPhone = $memberRecord['phone'] ?? '';
$memberBirthdate = $memberRecord['birthdate'] ?? '';
$memberAddress = $memberRecord['address'] ?? '';
$memberSkills = $memberRecord['skills'] ?? '';
$memberCommittee = $memberRecord['committee_assignment'] ?? '';
$currentPage = 'profile';

$profileMessage = '';
$profileMessageType = 'success';
$passwordAlert = null;
$showEditor = false;
$showPasswordEditor = false;
$formValues = [
    'name' => $memberName,
    'username' => $memberUsername,
    'email' => $memberEmail,
    'phone' => $memberPhone,
    'birthdate' => $memberBirthdate,
    'address' => $memberAddress,
    'skills' => $memberSkills,
    'committee_assignment' => $memberCommittee,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['update_profile'])) {
    $submittedName = trim((string) ($_POST['name'] ?? ''));
    $submittedUsername = trim((string) ($_POST['username'] ?? ''));
    $submittedEmail = trim((string) ($_POST['email'] ?? ''));
    $submittedPhone = trim((string) ($_POST['phone'] ?? ''));
    $submittedBirthdate = trim((string) ($_POST['birthdate'] ?? ''));
    $submittedAddress = trim((string) ($_POST['address'] ?? ''));
    $submittedSkills = trim((string) ($_POST['skills'] ?? ''));
    $submittedCommittee = trim((string) ($_POST['committee_assignment'] ?? ''));

    $formValues = [
        'name' => $submittedName,
        'username' => $submittedUsername,
        'email' => $submittedEmail,
        'phone' => $submittedPhone,
        'birthdate' => $submittedBirthdate,
        'address' => $submittedAddress,
        'skills' => $submittedSkills,
        'committee_assignment' => $submittedCommittee,
    ];

    $errors = [];

    if ($submittedName === '') {
        $errors[] = 'Full name is required.';
    }

    if ($submittedUsername === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($submittedUsername) < 4) {
        $errors[] = 'Username must be at least 4 characters long.';
    }

    if ($submittedEmail !== '' && filter_var($submittedEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $userId = (int) ($member['id'] ?? 0);

        if ($userId > 0) {
            try {
                $pdo->beginTransaction();

                $duplicateUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
                $duplicateUser->execute([$submittedUsername, $userId]);

                if ($duplicateUser->fetch()) {
                    $errors[] = 'That username is already taken.';
                }

                if (empty($errors)) {
                    $updateProfile = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, phone = ?, birthdate = ?, address = ?, skills = ?, committee_assignment = ? WHERE id = ?');
                    $updateProfile->execute([
                        $submittedName,
                        $submittedUsername,
                        $submittedEmail !== '' ? $submittedEmail : null,
                        $submittedPhone !== '' ? $submittedPhone : null,
                        $submittedBirthdate !== '' ? $submittedBirthdate : null,
                        $submittedAddress !== '' ? $submittedAddress : null,
                        $submittedSkills !== '' ? $submittedSkills : null,
                        $submittedCommittee !== '' ? $submittedCommittee : null,
                        $userId,
                    ]);
                    logAudit($pdo, 'profile', $userId, 'update', $userId, $submittedName, 'Updated profile information');
                }

                if (empty($errors)) {
                    $pdo->commit();

                    $member['name'] = $submittedName;
                    $member['username'] = $submittedUsername;
                    $member['email'] = $submittedEmail;
                    $member['phone'] = $submittedPhone;
                    $_SESSION['user'] = $member;

                    $memberQuery = $pdo->prepare('SELECT id, name, username, role, email, phone, status, birthdate, address, skills, committee_assignment, created_at FROM users WHERE id = ? LIMIT 1');
                    $memberQuery->execute([$userId]);
                    $memberRecord = $memberQuery->fetch() ?: [];

                    $memberName = $memberRecord['name'] ?? $submittedName;
                    $memberUsername = $memberRecord['username'] ?? $submittedUsername;
                    $memberEmail = $memberRecord['email'] ?? $submittedEmail;
                    $memberPhone = $memberRecord['phone'] ?? $submittedPhone;
                    $memberBirthdate = $memberRecord['birthdate'] ?? $submittedBirthdate;
                    $memberAddress = $memberRecord['address'] ?? $submittedAddress;
                    $memberSkills = $memberRecord['skills'] ?? $submittedSkills;
                    $memberCommittee = $memberRecord['committee_assignment'] ?? $submittedCommittee;
                    $formValues = [
                        'name' => $memberName,
                        'username' => $memberUsername,
                        'email' => $memberEmail,
                        'phone' => $memberPhone,
                        'birthdate' => $memberBirthdate,
                        'address' => $memberAddress,
                        'skills' => $memberSkills,
                        'committee_assignment' => $memberCommittee,
                    ];
                    $profileMessage = 'Your profile was updated successfully.';
                    $profileMessageType = 'success';
                } else {
                    $pdo->rollBack();
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Unable to save your profile right now.';
            }
        }
    }

    if (!empty($errors)) {
        $profileMessage = implode('<br>', $errors);
        $profileMessageType = 'danger';
        $showEditor = true;
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['update_profile'])) {
        $showEditor = false;
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['update_password'])) {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $errors = [];

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
        $userId = (int) ($member['id'] ?? 0);

        if ($userId > 0) {
            try {
                $currentUser = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
                $currentUser->execute([$userId]);
                $currentUserRecord = $currentUser->fetch();

                if (!$currentUserRecord || !password_verify($currentPassword, $currentUserRecord['password'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updatePassword = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $updatePassword->execute([$passwordHash, $userId]);
                    logAudit($pdo, 'profile', $userId, 'update', $userId, $memberName, 'Changed account password');
                    $profileMessage = 'Your password was changed successfully.';
                    $profileMessageType = 'success';
                    $passwordAlert = [
                        'type' => 'success',
                        'title' => 'Password Changed',
                        'text' => 'Your password has been updated. Use your new password the next time you log in.',
                    ];
                }
            } catch (PDOException $exception) {
                $errors[] = 'Unable to change your password right now.';
            }
        } else {
            $errors[] = 'Unable to identify your account.';
        }
    }

    if (!empty($errors)) {
        $profileMessage = implode('<br>', $errors);
        $profileMessageType = 'danger';
        $passwordAlert = [
            'type' => 'error',
            'title' => 'Password Not Changed',
            'text' => implode("\n", $errors),
        ];
        $showPasswordEditor = true;
    }
}

$qrPayload = json_encode([
    'type' => 'member_profile',
    'user_id' => (int) ($memberRecord['id'] ?? $member['id'] ?? 0),
    'username' => $memberUsername,
    'name' => $memberName,
    'role' => $memberRole,
]);

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Youth Organization</title>
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
        .profile-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1f8a5f, #2454a6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
            margin: 0 auto 20px;
        }
        .profile-editor-form {
            width: 100%;
            max-width: 760px;
            margin-left: auto;
            margin-right: auto;
        }
        .profile-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .profile-actions .btn {
            white-space: nowrap;
        }
        .profile-modal .modal-content {
            overflow: hidden;
            border: 1px solid #dfe8e3;
            border-radius: 12px;
            box-shadow: 0 20px 55px rgba(18, 53, 42, 0.2);
        }
        .profile-modal .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e3ece6;
            background: #f8fbf9;
        }
        .profile-modal .modal-title {
            color: #12352a;
            font-weight: 800;
        }
        .profile-modal .modal-body {
            padding: 24px;
        }
        .profile-modal .form-control {
            min-height: 46px;
        }
        .profile-modal .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e3ece6;
        }
        .password-help {
            color: #66746e;
            font-size: 0.8rem;
        }
        @media (max-width: 575.98px) {
            .profile-modal .modal-body,
            .profile-modal .modal-header,
            .profile-modal .modal-footer {
                padding: 18px;
            }
            .profile-actions {
                width: 100%;
            }
            .profile-actions .btn {
                flex: 1 1 100%;
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
                <a href="announcements.php" class="nav-link">Announcements</a>
                <a href="profile.php" class="nav-link active">My Profile</a>
                <a href="attendance.php" class="nav-link">My Attendance</a>
                <a href="gallery.php" class="nav-link">Gallery</a>
                <a href="officers.php" class="nav-link">Officers</a>
            </nav>

            <div class="member-chip">
                <div style="font-size: 13px; opacity: 0.7;">Logged in as</div>
                <div style="font-weight: 600;"><?php echo legacyEscape($memberName); ?></div>
                <a href="login.php?logout=1" style="font-size: 13px; color: #f2a541; text-decoration: none; margin-top: 10px; display: inline-block;">Logout</a>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">My Profile</h1>
            </div>

            <div class="content">
                <div class="page-intro text-center">
                    <div class="text-uppercase small fw-bold text-success mb-2">Your member profile</div>
                    <h2 class="mb-2">A polished view of your organization presence</h2>
                    <p class="mb-0 text-muted">Keep your details organized, accessible, and ready whenever you need to participate or identify yourself.</p>
                </div>
                <div class="profile-header">
                    <div class="profile-avatar"><?php echo substr(legacyEscape($memberName), 0, 1); ?></div>
                    <h2><?php echo legacyEscape($memberName); ?></h2>
                </div>

                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-primary text-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <h5 class="mb-0">Profile Information</h5>
                                <div class="profile-actions justify-content-end">
                                    <button type="button" class="btn btn-sm btn-light text-dark" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit Profile</button>
                                    <button type="button" class="btn btn-sm btn-light text-dark" data-bs-toggle="modal" data-bs-target="#changePasswordModal">Change Password</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($profileMessage !== ''): ?>
                                    <div class="alert alert-<?php echo legacyEscape($profileMessageType); ?>" role="alert">
                                        <?php echo $profileMessage; ?>
                                    </div>
                                <?php endif; ?>

                                <div id="profileSummary" class="mb-4">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Username</label>
                                            <p><?php echo legacyEscape($memberUsername); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Full Name</label>
                                            <p><?php echo legacyEscape($memberName); ?></p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Email</label>
                                            <p><?php echo isset($memberRecord['email']) && $memberRecord['email'] !== '' ? legacyEscape($memberRecord['email']) : 'Not provided'; ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Phone</label>
                                            <p><?php echo isset($memberRecord['phone']) && $memberRecord['phone'] !== '' ? legacyEscape($memberRecord['phone']) : 'Not provided'; ?></p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Birthdate</label>
                                            <p><?php echo isset($memberRecord['birthdate']) && $memberRecord['birthdate'] !== '' ? date('F j, Y', strtotime($memberRecord['birthdate'])) : 'Not provided'; ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Committee Assignment</label>
                                            <p><?php echo isset($memberRecord['committee_assignment']) && $memberRecord['committee_assignment'] !== '' ? legacyEscape($memberRecord['committee_assignment']) : 'Not assigned'; ?></p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Address</label>
                                            <p><?php echo isset($memberRecord['address']) && $memberRecord['address'] !== '' ? legacyEscape($memberRecord['address']) : 'Not provided'; ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Member Since</label>
                                            <p><?php echo isset($memberRecord['created_at']) ? date('F j, Y', strtotime($memberRecord['created_at'])) : 'Member record pending'; ?></p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-12">
                                            <label class="form-label text-muted">Skills</label>
                                            <p><?php echo isset($memberRecord['skills']) && $memberRecord['skills'] !== '' ? legacyEscape($memberRecord['skills']) : 'Not provided'; ?></p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Role</label>
                                            <p><span class="badge bg-success"><?php echo legacyEscape(ucfirst($memberRole)); ?></span></p>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">Attendance QR</h5>
                            </div>
                            <div class="card-body text-center">
                                <div id="userQrCode" class="d-flex justify-content-center mb-3"></div>
                                <p class="mb-0 text-muted">Scan this QR code to identify this member for attendance.</p>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">Member Stats</h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <h6 class="text-muted">Events Attended</h6>
                                    <h3 class="mb-0">8</h3>
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <h6 class="text-muted">Attendance Rate</h6>
                                    <h3 class="mb-0">85%</h3>
                                </div>
                                <hr>
                                <div>
                                    <h6 class="text-muted">Active Since</h6>
                                    <p class="mb-0">6 months</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade profile-modal" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post"><?php echo csrf_field(); ?>
                    <div class="modal-header">
                        <div>
                            <div class="text-success text-uppercase small fw-bold mb-1">Account settings</div>
                            <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="modal_profile_name">Full Name</label>
                                <input type="text" id="modal_profile_name" name="name" class="form-control" value="<?php echo legacyEscape($formValues['name']); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="modal_profile_username">Username</label>
                                <input type="text" id="modal_profile_username" name="username" class="form-control" value="<?php echo legacyEscape($formValues['username']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="modal_profile_email">Email</label>
                                <input type="email" id="modal_profile_email" name="email" class="form-control" value="<?php echo legacyEscape($formValues['email']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="modal_profile_phone">Phone</label>
                                <input type="text" id="modal_profile_phone" name="phone" class="form-control" value="<?php echo legacyEscape($formValues['phone']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="modal_profile_birthdate">Birthdate</label>
                                <input type="date" id="modal_profile_birthdate" name="birthdate" class="form-control" value="<?php echo legacyEscape($formValues['birthdate']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="modal_profile_committee">Committee Assignment</label>
                                <select id="modal_profile_committee" name="committee_assignment" class="form-select">
                                    <option value="">No assignment</option>
                                    <option value="Documentation" <?php echo $formValues['committee_assignment'] === 'Documentation' ? 'selected' : ''; ?>>Documentation</option>
                                    <option value="Events" <?php echo $formValues['committee_assignment'] === 'Events' ? 'selected' : ''; ?>>Events</option>
                                    <option value="Finance" <?php echo $formValues['committee_assignment'] === 'Finance' ? 'selected' : ''; ?>>Finance</option>
                                    <option value="Programs" <?php echo $formValues['committee_assignment'] === 'Programs' ? 'selected' : ''; ?>>Programs</option>
                                    <option value="Outreach" <?php echo $formValues['committee_assignment'] === 'Outreach' ? 'selected' : ''; ?>>Outreach</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="modal_profile_address">Address</label>
                                <input type="text" id="modal_profile_address" name="address" class="form-control" value="<?php echo legacyEscape($formValues['address']); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="modal_profile_skills">Skills</label>
                                <textarea id="modal_profile_skills" name="skills" class="form-control" rows="3" placeholder="E.g., Photography, Event Planning, Software Development"><?php echo legacyEscape($formValues['skills']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade profile-modal" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post"><?php echo csrf_field(); ?>
                    <div class="modal-header">
                        <div>
                            <div class="text-success text-uppercase small fw-bold mb-1">Account security</div>
                            <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="update_password" value="1">
                        <p class="password-help mb-3">Use at least 8 characters for your new password.</p>
                        <div class="mb-3">
                            <label class="form-label" for="modal_current_password">Current Password</label>
                            <input type="password" id="modal_current_password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="modal_new_password">New Password</label>
                            <input type="password" id="modal_new_password" name="new_password" class="form-control" minlength="8" required>
                        </div>
                        <div>
                            <label class="form-label" for="modal_confirm_password">Confirm New Password</label>
                            <input type="password" id="modal_confirm_password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const passwordAlert = <?php echo json_encode($passwordAlert, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        if (passwordAlert && window.Swal) {
            Swal.fire({
                icon: passwordAlert.type,
                title: passwordAlert.title,
                text: passwordAlert.text,
                confirmButtonColor: '#0d6efd'
            });
        }

        const modalToOpen = <?php echo json_encode($showEditor ? 'editProfileModal' : ($showPasswordEditor ? 'changePasswordModal' : '')); ?>;
        if (modalToOpen && window.bootstrap) {
            const modal = document.getElementById(modalToOpen);
            if (modal) {
                new bootstrap.Modal(modal).show();
            }
        }

        const qrPayload = <?php echo json_encode($qrPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
        const qrContainer = document.getElementById('userQrCode');
        if (qrContainer) {
            new QRCode(qrContainer, {
                text: qrPayload,
                width: 220,
                height: 220,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }

    </script>
</body>
</html>
