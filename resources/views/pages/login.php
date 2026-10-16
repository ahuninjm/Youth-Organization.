<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__, 3) . '/db.php';

$message = '';
$messageType = 'danger';
$activeForm = $_GET['form'] ?? 'login';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

unset($_SESSION['user']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'register') {
        $activeForm = 'register';
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = 'member';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($name === '' || $username === '' || $password === '' || $confirmPassword === '') {
            $message = 'Please complete all registration fields.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            $message = 'Username must be 3-30 characters and may only contain letters, numbers, and underscores.';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match.';
        } else {
            $checkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $checkUser->execute([$username]);

            if ($checkUser->fetch()) {
                $message = 'That username is already registered.';
            } else {
                $createUser = $pdo->prepare(
                    'INSERT INTO users (name, username, role, password, status) VALUES (?, ?, ?, ?, ?)'
                );
                $createUser->execute([
                    $name,
                    $username,
                    $role,
                    password_hash($password, PASSWORD_DEFAULT),
                    'inactive',
                ]);

                $message = 'Application submitted successfully. Please wait for Admin/Officer verification and approval before signing in.';
                $messageType = 'success';
                $activeForm = 'login';
            }
        }
    } else {
        $activeForm = 'login';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $findUser = $pdo->prepare('SELECT id, name, username, role, password, status FROM users WHERE username = ? LIMIT 1');
        $findUser->execute([$username]);
        $user = $findUser->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                $message = 'Your account is not activated yet. Please wait for admin approval.';
            } else {
                $role = $user['role'] ?? 'member';
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'username' => $user['username'],
                    'role' => $role,
                ];
                $redirectPage = in_array($role, ['officer', 'admin'], true)
                    ? 'dashboard.php'
                    : 'homepage.php';
                header('Location: ' . $redirectPage);
                exit;
            }
        } else {
            $message = 'Invalid username or password.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Youth Organization Portal</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --brand: #2454a6;
            --brand-dark: #17366c;
            --brand-light: #f0f4ff;
            --surface: #ffffff;
            --ink: #1d2530;
            --ink-light: #5a6b7a;
            --border-color: #e8ecf1;
            --success: #10b981;
            --danger: #ef4444;
        }

        * {
            transition: all 0.2s ease-in-out;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(36, 84, 166, 0.12), transparent 32rem),
                linear-gradient(135deg, #f5f8fc 0%, #fafbfd 50%, #f0f7f5 100%);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        .auth-shell {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .auth-card {
            width: min(100%, 460px);
            padding: 40px;
            border: none;
            border-radius: 12px;
            box-shadow: 
                0 2px 8px rgba(23, 54, 108, 0.08),
                0 12px 32px rgba(23, 54, 108, 0.12),
                0 1px 1px rgba(0, 0, 0, 0.02);
            background: var(--surface);
            backdrop-filter: blur(10px);
        }

        .logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--brand-light);
            box-shadow: 0 8px 24px rgba(36, 84, 166, 0.15);
            animation: slideDown 0.6s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1 {
            font-weight: 700;
            font-size: 1.75rem;
            color: var(--ink);
            letter-spacing: -0.5px;
        }

        .text-muted {
            color: var(--ink-light) !important;
            font-size: 0.95rem;
        }

        .btn-primary {
            --bs-btn-bg: var(--brand);
            --bs-btn-border-color: var(--brand);
            --bs-btn-hover-bg: var(--brand-dark);
            --bs-btn-hover-border-color: var(--brand-dark);
            --bs-btn-active-bg: var(--brand-dark);
            --bs-btn-active-border-color: var(--brand-dark);
            font-weight: 600;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 12px rgba(36, 84, 166, 0.25);
            border: none;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(36, 84, 166, 0.35);
            transform: translateY(-2px);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-outline-danger {
            color: var(--danger);
            border-color: var(--danger);
            font-weight: 600;
        }

        .btn-outline-danger:hover {
            background-color: var(--danger);
            border-color: var(--danger);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        .form-label {
            font-weight: 600;
            color: var(--ink);
            font-size: 0.9rem;
            margin-bottom: 8px;
            letter-spacing: 0.2px;
        }

        .form-control {
            min-height: 48px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 1rem;
            color: var(--ink);
            background-color: #fafbfc;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--brand);
            background-color: var(--brand-light);
            box-shadow: 0 0 0 3px rgba(36, 84, 166, 0.1);
            outline: none;
        }

        .form-control::placeholder {
            color: #a0aab8;
        }

        .nav-pills {
            gap: 8px;
            background-color: #f5f7fb;
            padding: 6px;
            border-radius: 10px;
        }

        .nav-pills .nav-link {
            color: var(--ink-light);
            font-weight: 600;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .nav-pills .nav-link:hover {
            background-color: rgba(36, 84, 166, 0.08);
            color: var(--brand);
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(36, 84, 166, 0.3);
        }

        .alert {
            border: none;
            border-radius: 10px;
            padding: 14px 16px;
            font-weight: 500;
            animation: fadeInUp 0.4s ease-out;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #7f1d1d;
            border-left: 4px solid var(--danger);
        }

        .dashboard-actions {
            display: grid;
            gap: 12px;
            animation: fadeInUp 0.6s ease-out;
        }

        .dashboard-actions .p-3 {
            background: linear-gradient(135deg, #f5f7fb 0%, #eef3f8 100%);
            border: 1.5px solid var(--border-color) !important;
            border-radius: 10px !important;
            transition: all 0.3s ease;
        }

        .dashboard-actions .p-3:hover {
            box-shadow: 0 4px 12px rgba(36, 84, 166, 0.1);
            border-color: var(--brand) !important;
        }

        strong {
            color: var(--ink);
            font-weight: 700;
        }

        a:not(.btn) {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        a:not(.btn):hover {
            color: var(--brand-dark);
            text-decoration: underline;
        }

        .text-center.mt-3 a {
            font-size: 0.95rem;
        }

        @media (max-width: 576px) {
            .auth-card {
                padding: 32px 24px;
            }

            h1 {
                font-size: 1.5rem;
            }

            .form-control {
                min-height: 44px;
            }
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        .auth-shell {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 15% 15%, rgba(242, 185, 75, 0.22), transparent 22rem),
                radial-gradient(circle at 90% 85%, rgba(47, 138, 99, 0.15), transparent 26rem),
                linear-gradient(135deg, #f7faf8 0%, #eef5f0 100%);
        }

        .auth-layout {
            display: grid;
            grid-template-columns: minmax(260px, 0.82fr) minmax(360px, 1fr);
            width: min(100%, 900px);
            overflow: hidden;
            border: 1px solid rgba(18, 53, 42, 0.12);
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(18, 53, 42, 0.14);
            background: #fff;
            animation: fadeInUp 0.5s ease-out;
        }

        .auth-aside {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 42px 36px;
            color: #fff;
            background:
                linear-gradient(145deg, rgba(18, 53, 42, 0.96), rgba(34, 97, 71, 0.94)),
                url('img/P1.jpg') center / cover;
        }

        .auth-aside .logo {
            width: 86px;
            height: 86px;
            border: 4px solid rgba(242, 185, 75, 0.85);
            box-shadow: 0 10px 26px rgba(0, 0, 0, 0.2);
        }

        .auth-aside h2 {
            max-width: 260px;
            margin: 28px 0 12px;
            color: #fff;
            font-size: 2rem;
            line-height: 1.08;
            font-weight: 800;
        }

        .auth-aside p {
            max-width: 270px;
            margin: 0;
            color: rgba(255, 255, 255, 0.78);
            line-height: 1.6;
        }

        .auth-aside .location {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--yo-gold);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .auth-card {
            width: auto;
            padding: 42px 44px;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .auth-card > .text-center .logo {
            display: none;
        }

        .auth-card h1 {
            color: var(--yo-green-900);
            font-size: 1.65rem;
        }

        .auth-card .text-muted {
            color: var(--yo-muted) !important;
        }

        .form-control {
            background: #fbfdfb;
        }

        .form-control:focus {
            background: #fff;
        }

        @media (max-width: 700px) {
            .auth-layout {
                display: block;
                width: min(100%, 480px);
            }

            .auth-aside {
                min-height: 180px;
                padding: 26px 28px;
            }

            .auth-aside .logo {
                width: 64px;
                height: 64px;
            }

            .auth-aside h2 {
                margin: 14px 0 0;
                font-size: 1.5rem;
            }

            .auth-aside p {
                display: none;
            }

            .auth-card {
                padding: 30px 24px;
            }
        }

        .password-field {
            position: relative;
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-field .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 6px;
            color: var(--yo-muted);
            background: transparent;
            transform: translateY(-50%);
        }

        .password-toggle:hover,
        .password-toggle:focus-visible {
            color: var(--yo-green-700);
            background: #edf5f0;
            outline: none;
        }

        .password-toggle svg {
            width: 19px;
            height: 19px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
        }

        .password-toggle .eye-off {
            display: none;
        }

        .password-toggle.is-visible .eye {
            display: none;
        }

        .password-toggle.is-visible .eye-off {
            display: block;
        }
    </style>
</head>
<body>

<main class="auth-shell">
    <div class="auth-layout">
        <aside class="auth-aside">
            <div>
                <img src="img/P1.jpg" alt="Purok New Binangcaan seal" class="logo">
                <h2>Growing together, serving with purpose.</h2>
                <p>A shared space for youth members and officers to stay connected with the community.</p>
            </div>
            <span class="location">Purok New Binangcaan</span>
        </aside>

        <section class="auth-card">
        <div class="text-center mb-4">
            <h1 class="h3 mt-3 mb-1">Youth Organization</h1>
            <p class="text-muted mb-0">Purok New Binangcaan</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($activeForm === 'register'): ?>
                <div class="registration-flow" aria-label="Youth registration process">
                    <div class="registration-step"><span class="registration-step-number">1</span><span class="registration-step-label">Youth Registration</span></div>
                    <div class="registration-step"><span class="registration-step-number">2</span><span class="registration-step-label">Enter Personal Information</span></div>
                    <div class="registration-step"><span class="registration-step-number">3</span><span class="registration-step-label">Submit Application</span></div>
                    <div class="registration-step"><span class="registration-step-number">4</span><span class="registration-step-label">Admin/Officer Verification</span></div>
                    <div class="registration-step"><span class="registration-step-number">5</span><span class="registration-step-label">Approved</span></div>
                    <div class="registration-step"><span class="registration-step-number">6</span><span class="registration-step-label">Member Account Created</span></div>
                </div>
                <form action="login.php?form=register" method="POST" novalidate><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="register">

                    <div class="mb-3">
                        <label class="form-label" for="name">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" placeholder="Enter full name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="register_username">Username</label>
                        <input type="text" id="register_username" name="username" class="form-control" placeholder="Choose username" minlength="3" maxlength="30" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="register_password">Password</label>
                        <input type="password" id="register_password" name="password" class="form-control" placeholder="Create password" minlength="8" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Repeat password" minlength="8" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Create Account</button>
                </form>
                <div class="text-center mt-3">
                    <a href="login.php?form=login">Back to login</a>
                </div>
            <?php else: ?>
                <form action="login.php" method="POST" novalidate><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="login">

                    <div class="mb-3">
                        <label class="form-label" for="login_username">Username</label>
                        <input type="text" id="login_username" name="username" class="form-control" placeholder="Enter username" required>
                    </div>

                    <div class="mb-4 password-field">
                        <label class="form-label" for="login_password">Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="login_password" name="password" class="form-control" placeholder="Enter password" required>
                            <button type="button" class="password-toggle" data-password-toggle="login_password" aria-label="Show password" title="Show password">
                                <svg class="eye" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                    <circle cx="12" cy="12" r="2.5"></circle>
                                </svg>
                                <svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="m3 3 18 18"></path>
                                    <path d="M10.6 6.2A10.8 10.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16.7 16.7 0 0 1-3.1 3.8"></path>
                                    <path d="M6.5 8.1C4 9.7 2.5 12 2.5 12s3.5 6 9.5 6c1.2 0 2.3-.2 3.3-.6"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>

                <div class="text-center mt-3">
                    <a href="login.php?form=register">Create an account</a>
                </div>
        <?php endif; ?>
        </section>
    </div>
</main>

<script>
    document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var passwordField = document.getElementById(toggle.dataset.passwordToggle);
            var isVisible = passwordField.type === 'text';

            passwordField.type = isVisible ? 'password' : 'text';
            toggle.classList.toggle('is-visible', !isVisible);
            toggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            toggle.setAttribute('title', isVisible ? 'Show password' : 'Hide password');
        });
    });
</script>
</body>
</html>
