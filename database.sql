CREATE DATABASE IF NOT EXISTS youth_organization_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE youth_organization_db;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(30) NOT NULL UNIQUE,
    role ENUM('member', 'officer', 'admin') NOT NULL DEFAULT 'member',
    position VARCHAR(80) NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    birthdate DATE NULL,
    address VARCHAR(255) NULL,
    skills TEXT NULL,
    committee_assignment VARCHAR(100) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    activity_date DATE NOT NULL,
    venue VARCHAR(150) NULL,
    budget_amount DECIMAL(12,2) NULL,
    status ENUM('open', 'registration', 'planning', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activities_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS position VARCHAR(80) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS birthdate DATE NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS skills TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS committee_assignment VARCHAR(100) NULL;
ALTER TABLE activities ADD COLUMN IF NOT EXISTS budget_amount DECIMAL(12,2) NULL;

CREATE TABLE IF NOT EXISTS activity_registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_activity_user (activity_id, user_id),
    CONSTRAINT fk_activity_registrations_activity
        FOREIGN KEY (activity_id) REFERENCES activities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_activity_registrations_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_proposals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    budget VARCHAR(80) NULL,
    budget_amount DECIMAL(12,2) NULL,
    status ENUM('submitted', 'under_review', 'waiting_budget', 'awaiting_president', 'approved', 'rejected', 'implementation', 'completed') NOT NULL DEFAULT 'submitted',
    completion_report TEXT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    reviewed_by INT UNSIGNED NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    CONSTRAINT fk_project_proposals_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_proposals_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE project_proposals MODIFY status ENUM('submitted', 'under_review', 'waiting_budget', 'awaiting_president', 'approved', 'rejected', 'implementation', 'completed') NOT NULL DEFAULT 'submitted';
ALTER TABLE project_proposals ADD COLUMN IF NOT EXISTS budget_amount DECIMAL(12,2) NULL;

CREATE TABLE IF NOT EXISTS feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'reviewed', 'resolved') NOT NULL DEFAULT 'new',
    related_type VARCHAR(30) NOT NULL DEFAULT 'general',
    related_id INT NULL,
    related_label VARCHAR(200) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE feedback ADD COLUMN IF NOT EXISTS related_type VARCHAR(30) NOT NULL DEFAULT 'general';
ALTER TABLE feedback ADD COLUMN IF NOT EXISTS related_id INT NULL;
ALTER TABLE feedback ADD COLUMN IF NOT EXISTS related_label VARCHAR(200) NULL;

CREATE TABLE IF NOT EXISTS feedback_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT UNSIGNED NOT NULL,
    responded_by INT UNSIGNED NOT NULL,
    response_message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_response_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE,
    CONSTRAINT fk_response_user FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS funds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    fund_type ENUM('allocation', 'expense') NOT NULL,
    fund_status ENUM('committed', 'spent') NOT NULL DEFAULT 'spent',
    description TEXT NULL,
    activity_id INT UNSIGNED NULL,
    proposal_id INT UNSIGNED NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_funds_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_funds_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE SET NULL,
    CONSTRAINT fk_funds_proposal FOREIGN KEY (proposal_id) REFERENCES project_proposals(id) ON DELETE SET NULL
);

ALTER TABLE funds ADD COLUMN IF NOT EXISTS fund_status ENUM('committed', 'spent') NOT NULL DEFAULT 'spent';
ALTER TABLE funds ADD COLUMN IF NOT EXISTS activity_id INT UNSIGNED NULL;
ALTER TABLE funds ADD COLUMN IF NOT EXISTS proposal_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'general',
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE documents ADD COLUMN IF NOT EXISTS category VARCHAR(40) NOT NULL DEFAULT 'general';
ALTER TABLE documents ADD COLUMN IF NOT EXISTS file_size INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS mime_type VARCHAR(80) NULL;

CREATE TABLE IF NOT EXISTS meeting_agendas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_title VARCHAR(150) NOT NULL,
    agenda_item VARCHAR(255) NOT NULL,
    meeting_date DATE NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meeting_agendas_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    alert_type ENUM('success', 'warning', 'info', 'danger') NOT NULL DEFAULT 'info',
    published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    CONSTRAINT fk_announcements_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    status ENUM('present', 'absent', 'excused') NOT NULL DEFAULT 'present',
    participation_rating INT UNSIGNED NOT NULL DEFAULT 5,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
);

ALTER TABLE attendance ADD COLUMN IF NOT EXISTS participation_rating INT UNSIGNED NOT NULL DEFAULT 5;

CREATE TABLE IF NOT EXISTS event_rsvps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    event_type ENUM('activity', 'meeting') NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rsvp_status ENUM('pending', 'confirmed', 'declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rsvp (event_id, event_type, user_id),
    CONSTRAINT fk_rsvp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gallery (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    caption VARCHAR(255) NULL,
    image_path VARCHAR(255) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gallery_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(80) NOT NULL,
    entity_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
