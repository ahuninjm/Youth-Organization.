<?php

declare(strict_types=1);

$dbHost = '127.0.0.1';
$dbName = 'youth_organization_db';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$pdo = null;
$driver = 'mysql';

try {
    $pdo = new PDO("mysql:host={$dbHost};charset={$dbCharset}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    $sqlitePath = __DIR__ . '/database.sqlite';

    try {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $driver = 'sqlite';
    } catch (PDOException $sqliteException) {
        if (!headers_sent()) {
            http_response_code(500);
        }

        exit('Database connection failed. Please start MySQL in XAMPP or make sure the SQLite database file can be created.');
    }
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition, string $driver): void {
    if ($driver === 'sqlite') {
        $columns = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $existingColumn) {
            if (($existingColumn['name'] ?? '') === $column) {
                return;
            }
        }

        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}");
        return;
    }

    $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $existingColumn) {
        if (($existingColumn['Field'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function logAudit(PDO $pdo, string $entityType, ?int $entityId, string $action, ?int $userId, string $actorName, string $details = ''): void {
    $actorName = trim($actorName) !== '' ? substr($actorName, 0, 120) : 'System';
    $details = trim($details);

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (entity_type, entity_id, action, user_id, actor_name, details) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$entityType, $entityId, $action, $userId, $actorName, $details !== '' ? $details : null]);
}

function initializeDatabase(PDO $pdo, string $driver): void {
    if ($driver === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                username TEXT NOT NULL UNIQUE,
                role TEXT NOT NULL DEFAULT 'member',
                position TEXT NULL,
                password TEXT NOT NULL,
                email TEXT NULL,
                phone TEXT NULL,
                status TEXT NOT NULL DEFAULT 'inactive',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NULL,
                activity_date TEXT NOT NULL,
                venue TEXT NULL,
                    budget_amount REAL NULL,
                    status TEXT NOT NULL DEFAULT 'open',
                created_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS meeting_agendas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                meeting_title TEXT NOT NULL,
                agenda_item TEXT NOT NULL,
                meeting_date TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 1,
                created_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS announcements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                alert_type TEXT NOT NULL DEFAULT 'info',
                published_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by INTEGER NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                event_name TEXT NOT NULL,
                event_date TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'present',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gallery (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                caption TEXT NULL,
                image_path TEXT NULL,
                uploaded_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NULL,
                action TEXT NOT NULL,
                user_id INTEGER NULL,
                actor_name TEXT NOT NULL,
                details TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                username VARCHAR(30) NOT NULL UNIQUE,
                role ENUM('member', 'officer', 'admin') NOT NULL DEFAULT 'member',
                position VARCHAR(80) NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(150) NULL,
                phone VARCHAR(30) NULL,
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activities (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                activity_date DATE NOT NULL,
                venue VARCHAR(150) NULL,
                status ENUM('open', 'registration', 'planning', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS meeting_agendas (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                meeting_title VARCHAR(150) NOT NULL,
                agenda_item VARCHAR(255) NOT NULL,
                meeting_date DATE NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_meeting_agendas_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS announcements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                body TEXT NOT NULL,
                alert_type ENUM('success', 'warning', 'info', 'danger') NOT NULL DEFAULT 'info',
                published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by INT UNSIGNED NULL,
                CONSTRAINT fk_announcements_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS attendance (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                event_name VARCHAR(150) NOT NULL,
                event_date DATE NOT NULL,
                status ENUM('present', 'absent', 'excused') NOT NULL DEFAULT 'present',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gallery (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                caption VARCHAR(255) NULL,
                image_path VARCHAR(255) NULL,
                uploaded_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_gallery_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(80) NOT NULL,
                entity_id INT UNSIGNED NULL,
                action VARCHAR(40) NOT NULL,
                user_id INT UNSIGNED NULL,
                actor_name VARCHAR(120) NOT NULL,
                details TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
    }

    if ($driver === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activity_registrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                registered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (activity_id, user_id),
                FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS project_proposals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                budget TEXT NULL,
                status TEXT NOT NULL DEFAULT 'submitted',
                completion_report TEXT NULL,
                submitted_by INTEGER NOT NULL,
                reviewed_by INTEGER NULL,
                submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TEXT NULL,
                completed_at TEXT NULL,
                FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, subject TEXT NOT NULL, message TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'new', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS funds (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, amount REAL NOT NULL, fund_type TEXT NOT NULL, description TEXT NULL, recorded_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS documents (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NULL, file_path TEXT NOT NULL, uploaded_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_rsvps (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER NOT NULL, event_type TEXT NOT NULL, user_id INTEGER NOT NULL, rsvp_status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (event_id, event_type, user_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_responses (id INTEGER PRIMARY KEY AUTOINCREMENT, feedback_id INTEGER NOT NULL, responded_by INTEGER NOT NULL, response_message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE, FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE CASCADE)");
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activity_registrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                activity_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_activity_user (activity_id, user_id),
                CONSTRAINT fk_activity_registrations_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
                CONSTRAINT fk_activity_registrations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS project_proposals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                description TEXT NOT NULL,
                budget VARCHAR(80) NULL,
                budget_amount DECIMAL(12,2) NULL,
                status ENUM('submitted', 'under_review', 'approved', 'rejected', 'implementation', 'completed') NOT NULL DEFAULT 'submitted',
                completion_report TEXT NULL,
                submitted_by INT UNSIGNED NOT NULL,
                reviewed_by INT UNSIGNED NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                CONSTRAINT fk_project_proposals_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_proposals_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, subject VARCHAR(150) NOT NULL, message TEXT NOT NULL, status ENUM('new', 'reviewed', 'resolved') NOT NULL DEFAULT 'new', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS funds (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, amount DECIMAL(12,2) NOT NULL, fund_type ENUM('allocation', 'expense') NOT NULL, fund_status ENUM('committed', 'spent') NOT NULL DEFAULT 'spent', description TEXT NULL, activity_id INT UNSIGNED NULL, proposal_id INT UNSIGNED NULL, recorded_by INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_funds_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE SET NULL, CONSTRAINT fk_funds_proposal FOREIGN KEY (proposal_id) REFERENCES project_proposals(id) ON DELETE SET NULL, CONSTRAINT fk_funds_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS documents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, description TEXT NULL, file_path VARCHAR(255) NOT NULL, uploaded_by INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_rsvps (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, event_type ENUM('activity', 'meeting') NOT NULL, user_id INT UNSIGNED NOT NULL, rsvp_status ENUM('pending', 'confirmed', 'declined') NOT NULL DEFAULT 'pending', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_rsvp (event_id, event_type, user_id), CONSTRAINT fk_rsvp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_responses (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, feedback_id INT UNSIGNED NOT NULL, responded_by INT UNSIGNED NOT NULL, response_message TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_response_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE, CONSTRAINT fk_response_user FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE CASCADE)");
    }

    $pdo->exec("UPDATE activities SET status = 'open' WHERE status = 'registration'");
    $pdo->exec("UPDATE activities SET status = 'completed' WHERE status = 'cancelled'");

    if ($driver === 'mysql') {
        $pdo->exec("ALTER TABLE project_proposals MODIFY status ENUM('submitted', 'under_review', 'waiting_budget', 'awaiting_president', 'approved', 'rejected', 'implementation', 'completed') NOT NULL DEFAULT 'submitted'");
    }

    ensureColumn($pdo, 'users', 'position', 'VARCHAR(80) NULL', $driver);
    ensureColumn($pdo, 'users', 'birthdate', 'DATE NULL', $driver);
    ensureColumn($pdo, 'users', 'address', 'VARCHAR(255) NULL', $driver);
    ensureColumn($pdo, 'users', 'skills', 'TEXT NULL', $driver);
    ensureColumn($pdo, 'users', 'committee_assignment', 'VARCHAR(100) NULL', $driver);
    ensureColumn($pdo, 'activities', 'budget_amount', $driver === 'sqlite' ? 'REAL NULL' : 'DECIMAL(12,2) NULL', $driver);
    ensureColumn($pdo, 'project_proposals', 'budget_amount', $driver === 'sqlite' ? 'REAL NULL' : 'DECIMAL(12,2) NULL', $driver);
    ensureColumn($pdo, 'funds', 'fund_status', $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'spent'" : "ENUM('committed', 'spent') NOT NULL DEFAULT 'spent'", $driver);
    ensureColumn($pdo, 'funds', 'activity_id', $driver === 'sqlite' ? 'INTEGER NULL' : 'INT UNSIGNED NULL', $driver);
    ensureColumn($pdo, 'funds', 'proposal_id', $driver === 'sqlite' ? 'INTEGER NULL' : 'INT UNSIGNED NULL', $driver);
    ensureColumn($pdo, 'feedback', 'related_type', 'VARCHAR(30) NOT NULL DEFAULT "general"', $driver);
    ensureColumn($pdo, 'feedback', 'related_id', 'INT NULL', $driver);
    ensureColumn($pdo, 'feedback', 'related_label', 'VARCHAR(200) NULL', $driver);
    ensureColumn($pdo, 'attendance', 'participation_rating', 'INT UNSIGNED NOT NULL DEFAULT 5', $driver);
    ensureColumn($pdo, 'documents', 'category', 'VARCHAR(40) NOT NULL DEFAULT "general"', $driver);
    ensureColumn($pdo, 'documents', 'file_size', 'INT UNSIGNED NOT NULL DEFAULT 0', $driver);
    ensureColumn($pdo, 'documents', 'mime_type', 'VARCHAR(80) NULL', $driver);

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $defaultPassword = password_hash('password', PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (name, username, role, position, password, email, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'Administrator',
            'admin',
            'admin',
            'Administrator',
            $defaultPassword,
            'admin@example.com',
            '0000000000',
            'active',
        ]);
    }

    $agendaCount = (int) $pdo->query('SELECT COUNT(*) FROM meeting_agendas')->fetchColumn();
    if ($agendaCount === 0) {
        $pdo->exec(
            "INSERT INTO meeting_agendas (meeting_title, agenda_item, meeting_date, sort_order) VALUES
            ('July Monthly Meeting', 'Review completed activities and attendance reports', '2026-07-10', 1),
            ('July Monthly Meeting', 'Finalize clean-up drive assignments', '2026-07-10', 2),
            ('July Monthly Meeting', 'Discuss seminar registration and speaker needs', '2026-07-10', 3),
            ('July Monthly Meeting', 'Budget planning for the sports festival', '2026-07-10', 4),
            ('July Monthly Meeting', 'Open forum for member concerns', '2026-07-10', 5)"
        );
    }

    $announcementCount = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
    if ($announcementCount === 0) {
        $pdo->exec(
            "INSERT INTO announcements (title, body, alert_type) VALUES
            ('Leadership seminar registration is open', 'Submit your name to an officer before July 20, 2026.', 'success'),
            ('Monthly meeting attendance is required', 'Members are requested to attend the July 10, 2026 meeting.', 'warning'),
            ('Gallery submissions accepted', 'Send activity photos to the documentation committee for posting.', 'info')"
        );
    }
}

initializeDatabase($pdo, $driver);
