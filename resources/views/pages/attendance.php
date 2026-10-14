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
require_once dirname(__DIR__, 3) . '/attendance_store.php';

$memberQuery = $pdo->prepare('SELECT id, name, role, position, username FROM users WHERE id = ? LIMIT 1');
$memberQuery->execute([$member['id'] ?? 0]);
$memberRecord = $memberQuery->fetch() ?: [];
$memberName = $memberRecord['name'] ?? $member['name'] ?? 'Member';
$memberId = (int)($memberRecord['id'] ?? $member['id'] ?? 0);
$memberUsername = $memberRecord['username'] ?? $member['username'] ?? '';
$memberRole = $memberRecord['role'] ?? $member['role'] ?? 'member';
$currentPage = 'attendance';
$memberPosition = strtolower(trim((string)($memberRecord['position'] ?? $member['position'] ?? '')));
$activityId = isset($_GET['activity_id']) ? (int) $_GET['activity_id'] : 0;
$activity = null;
if ($activityId > 0) {
    $activityQuery = $pdo->prepare('SELECT id, title, activity_date, venue, status FROM activities WHERE id = ? LIMIT 1');
    $activityQuery->execute([$activityId]);
    $activity = $activityQuery->fetch() ?: null;
}
$targetMemberId = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;
$targetMember = null;
if ($targetMemberId > 0) {
    $targetMemberQuery = $pdo->prepare('SELECT id, name, username FROM users WHERE id = ? LIMIT 1');
    $targetMemberQuery->execute([$targetMemberId]);
    $targetMember = $targetMemberQuery->fetch() ?: null;
}

$agendaId = isset($_GET['agenda_id']) ? (int)$_GET['agenda_id'] : 0;
$agenda = null;
if ($agendaId > 0) {
    $agendas = json_decode(file_get_contents(dirname(__DIR__, 3) . '/meeting-agendas.json') ?: '[]', true);
    if (is_array($agendas)) {
        foreach ($agendas as $item) {
            if (isset($item['id']) && (int)$item['id'] === $agendaId) {
                $agenda = $item;
                break;
            }
        }
    }
}

$attendance = loadAttendanceFromStorage();

$presentCutoff = '08:00';
$lateCutoff = '08:15';

$filteredAttendance = [];
foreach ($attendance as $record) {
    $matchesAgenda = $agendaId === 0 || (isset($record['agenda_id']) && $record['agenda_id'] === $agendaId);
    $matchesActivity = $activityId === 0 || (isset($record['activity_id']) && (int) $record['activity_id'] === $activityId);

    $recordUserId = isset($record['user_id']) ? (int)$record['user_id'] : 0;
    $recordUsername = isset($record['username']) ? trim((string)$record['username']) : '';
    $matchesUser = false;

    if ($recordUserId > 0) {
        $matchesUser = $recordUserId === $memberId;
    } elseif ($recordUsername !== '') {
        $matchesUser = strcasecmp($recordUsername, $memberUsername) === 0;
    }

    if ($matchesAgenda && $matchesActivity && $matchesUser) {
        $filteredAttendance[] = $record;
    }
}

$message = '';
$messageType = 'success';

function normalizeParticipationRating($value, string $status = ''): int {
    $rating = (int) $value;
    if ($rating < 1 || $rating > 5) {
        $rating = match (strtolower($status)) {
            'late' => 3,
            'absent' => 1,
            'excused' => 2,
            default => 5,
        };
    }

    return max(1, min(5, $rating));
}

function formatParticipationRatingLabel(int $rating): string {
    return match ($rating) {
        5 => 'Excellent',
        4 => 'Very Good',
        3 => 'Good',
        2 => 'Fair',
        default => 'Needs Improvement',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'scan_qr') {
        if (!canScanAttendance($memberRole, $memberPosition)) {
            $message = 'Only the president and secretary can scan attendance.';
            $messageType = 'danger';
        } else {
            $qrData = trim($_POST['qr_data'] ?? '');
            $presentCutoff = trim($_POST['present_cutoff'] ?? '08:00');
            $lateCutoff = trim($_POST['late_cutoff'] ?? '08:15');

            if ($qrData === '') {
                $message = 'No QR data was received.';
                $messageType = 'danger';
            } else {
                $payload = json_decode($qrData, true);
                if (!is_array($payload)) {
                    $message = 'The scanned QR code is invalid.';
                    $messageType = 'danger';
                } else {
                    $isMemberProfile = ($payload['type'] ?? '') === 'member_profile';
                    $event = trim((string)($payload['event'] ?? $activity['title'] ?? $agenda['meeting'] ?? 'Attendance'));
                    $date = trim((string)($payload['date'] ?? $activity['activity_date'] ?? date('Y-m-d')));
                    $agendaRefId = isset($payload['agenda_id']) ? (int)$payload['agenda_id'] : $agendaId;
                    $activityRefId = isset($payload['activity_id']) ? (int) $payload['activity_id'] : $activityId;

                    $recordUserId = $memberId;
                    $recordUsername = $memberUsername;
                    $recordName = $memberName;
                    if ($isMemberProfile && !empty($payload['user_id'])) {
                        $recordUserId = (int)$payload['user_id'];
                        $recordUsername = trim((string)($payload['username'] ?? ''));
                        $recordName = trim((string)($payload['name'] ?? ''));
                    }

                    if ($activity && $activity['status'] === 'completed') {
                        $message = 'Attendance is closed because this activity is completed.';
                        $messageType = 'danger';
                    } elseif ($targetMemberId > 0 && $recordUserId !== $targetMemberId) {
                        $message = 'Attendance was not recorded. Please scan the QR code for ' . ($targetMember['name'] ?? 'the selected member') . '.';
                        $messageType = 'danger';
                    } elseif ($activityRefId > 0 && !isRegisteredForActivity($pdo, $activityRefId, $recordUserId)) {
                        $message = 'Attendance was not recorded. This member must register for the activity first.';
                        $messageType = 'danger';
                    } else {
                        $currentTime = date('H:i');
                        $status = resolveAttendanceStatus($presentCutoff, $lateCutoff, $currentTime);
                        $rating = normalizeParticipationRating($_POST['rating'] ?? null, $status);
                        $timeIn = $currentTime;

                        $newRecord = [
                            'activity_id' => $activityRefId,
                            'agenda_id' => $agendaRefId,
                            'user_id' => $recordUserId,
                            'username' => $recordUsername,
                            'event' => $event,
                            'date' => $date,
                            'status' => $status,
                            'rating' => $rating,
                            'time_in' => $timeIn,
                            'remarks' => $isMemberProfile ? 'Scanned member profile QR' : 'Scanned QR code',
                            'recorded_by' => $recordName !== '' ? $recordName : $memberName,
                            'created_at' => date('c'),
                        ];

                        addAttendanceRecords([$newRecord]);
                        $filteredAttendance[] = $newRecord;
                        $message = $isMemberProfile
                            ? 'Attendance recorded for ' . ($recordName !== '' ? $recordName : 'the selected member') . '.'
                            : ($status === 'late'
                                ? 'Attendance recorded as late.'
                                : 'Attendance recorded as present.');
                        $messageType = 'success';
                    }
                }
            }
        }
    } elseif (!canScanAttendance($memberRole, $memberPosition)) {
        $message = 'You do not have permission to save attendance.';
        $messageType = 'danger';
    } else {
        $event = trim($_POST['event'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $rating = normalizeParticipationRating($_POST['rating'] ?? null, $status);
        $timeIn = trim($_POST['time_in'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $referenceAgendaId = isset($_POST['agenda_id']) ? (int)$_POST['agenda_id'] : 0;
        $referenceActivityId = isset($_POST['activity_id']) ? (int) $_POST['activity_id'] : $activityId;

        if ($event !== '' && $date !== '' && $status !== '' && ($referenceActivityId === 0 || isRegisteredForActivity($pdo, $referenceActivityId, $memberId))) {
            $newRecord = [
                'activity_id' => $referenceActivityId,
                'agenda_id' => $referenceAgendaId,
                'user_id' => $memberId,
                'username' => $memberUsername,
                'event' => $event,
                'date' => $date,
                'status' => $status,
                'rating' => $rating,
                'time_in' => $timeIn,
                'remarks' => $remarks,
                'recorded_by' => $memberName,
                'created_at' => date('c'),
            ];

            addAttendanceRecords([$newRecord]);
            $filteredAttendance[] = $newRecord;
            $message = 'Attendance record saved.';
            $messageType = 'success';
        } elseif ($referenceActivityId > 0) {
            $message = 'Attendance was not recorded. This member must register for the activity first.';
            $messageType = 'danger';
        } else {
            $message = 'Please complete the event, date, and status fields.';
            $messageType = 'danger';
        }
    }
}

$attendance = $filteredAttendance;

if (isset($_GET['export']) && $_GET['export'] === 'attendance') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Event Name', 'Date', 'Status', 'Rating', 'Time In', 'Remarks']);
    foreach ($attendance as $record) {
        fputcsv($output, [
            (string) ($record['event'] ?? ''),
            (string) ($record['date'] ?? ''),
            (string) ($record['status'] ?? ''),
            (string) ($record['rating'] ?? ''),
            (string) ($record['time_in'] ?? ''),
            (string) ($record['remarks'] ?? ''),
        ]);
    }
    fclose($output);
    exit;
}

$agendaMeeting = is_array($agenda) && isset($agenda['meeting']) ? (string) $agenda['meeting'] : 'Attendance';
$agendaDate = is_array($agenda) && isset($agenda['date']) ? date('Y-m-d', strtotime((string) $agenda['date'])) : date('Y-m-d');
$agendaTime = is_array($agenda) && isset($agenda['time']) ? (string) $agenda['time'] : '';

$qrPayload = [
    'activity_id' => $activityId,
    'agenda_id' => $agendaId,
    'event' => $activity['title'] ?? $agendaMeeting,
    'date' => $activity['activity_date'] ?? $agendaDate,
    'time' => $agendaTime,
];
$qrText = json_encode($qrPayload);

function canScanAttendance(string $memberRole, string $memberPosition): bool {
    $normalizedRole = strtolower($memberRole);
    $normalizedPosition = strtolower(trim($memberPosition));

    return in_array($normalizedRole, ['admin', 'officer'], true)
        || in_array($normalizedPosition, ['president', 'secretary'], true);
}

function getStatusBadgeClass(string $status): string {
    $lookup = [
        'present' => 'success',
        'late' => 'warning',
        'absent' => 'danger',
        'excused' => 'secondary',
    ];

    return $lookup[strtolower($status)] ?? 'info';
}

function resolveAttendanceStatus(string $presentCutoff, string $lateCutoff, ?string $timeIn = null): string {
    $currentTime = $timeIn ?? date('H:i');
    $currentMinutes = (int) substr($currentTime, 0, 2) * 60 + (int) substr($currentTime, 3, 2);
    $presentMinutes = (int) substr($presentCutoff, 0, 2) * 60 + (int) substr($presentCutoff, 3, 2);
    $lateMinutes = (int) substr($lateCutoff, 0, 2) * 60 + (int) substr($lateCutoff, 3, 2);

    if ($currentMinutes <= $presentMinutes) {
        return 'present';
    }

    if ($currentMinutes <= $lateMinutes) {
        return 'late';
    }

    return 'late';
}

function isRegisteredForActivity(PDO $pdo, int $activityId, int $userId): bool {
    if ($activityId <= 0 || $userId <= 0) {
        return false;
    }

    $registrationQuery = $pdo->prepare(
        'SELECT id FROM activity_registrations WHERE activity_id = ? AND user_id = ? LIMIT 1'
    );
    $registrationQuery->execute([$activityId, $userId]);
    return (bool) $registrationQuery->fetchColumn();
}

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - Youth Organization</title>
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
        .attendance-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <div class="app-shell">

        <!-- Main Content -->
        <div class="main">
            <div class="topbar">
                <h1 style="margin: 0; font-size: 24px;">My Attendance</h1>
            </div>

            <div class="content">
                <div class="section-toolbar">
                    <div class="page-intro mb-0 flex-grow-1">
                        <div class="text-uppercase small fw-bold text-success mb-2">Track your participation</div>
                        <h2 class="mb-2">Keep your attendance records polished and up to date</h2>
                        <p class="mb-0 text-muted">Use the quick check-in tools below to mark attendance with ease and review your history whenever needed.</p>
                    </div>
                    <div class="toolbar-actions">
                        <?php $attendanceExportUrl = 'attendance.php?export=attendance' . ($activityId > 0 ? '&activity_id=' . $activityId : '') . ($agendaId > 0 ? '&agenda_id=' . $agendaId : '') . ($targetMemberId > 0 ? '&member_id=' . $targetMemberId : ''); ?>
                        <a href="<?php echo legacyEscape($attendanceExportUrl); ?>" class="btn btn-primary">Export Excel</a>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Export PDF</button>
                    </div>
                </div>
                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?php echo legacyEscape($messageType); ?>">
                        <?php echo legacyEscape($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($activity): ?>
                    <div class="card mb-4 border-success">
                        <div class="card-body">
                            <div class="text-success text-uppercase small fw-bold mb-1">Activity attendance</div>
                            <h5 class="card-title mb-2"><?php echo legacyEscape($activity['title']); ?></h5>
                            <p class="mb-1"><strong>Date:</strong> <?php echo legacyEscape(date('F j, Y', strtotime($activity['activity_date']))); ?></p>
                            <p class="mb-0"><strong>Venue:</strong> <?php echo legacyEscape($activity['venue'] ?: 'Venue not set'); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($targetMember): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between gap-3">
                        <span><strong>Taking attendance for:</strong> <?php echo legacyEscape($targetMember['name']); ?> (<?php echo legacyEscape($targetMember['username']); ?>)</span>
                        <a href="attendance.php?activity_id=<?php echo legacyEscape((string) $activityId); ?>" class="btn btn-sm btn-outline-primary">Change Member</a>
                    </div>
                <?php endif; ?>

                <?php if ($agendaId > 0 && $agenda): ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-2">Attendance for Agenda</h5>
                            <p class="mb-1"><strong>Meeting:</strong> <?php echo legacyEscape($agenda['meeting']); ?></p>
                            <p class="mb-1"><strong>Date:</strong> <?php echo legacyEscape($agenda['date']); ?></p>
                            <p class="mb-1"><strong>Time:</strong> <?php echo legacyEscape($agenda['time']); ?></p>
                            <p class="mb-0"><strong>Venue:</strong> <?php echo legacyEscape($agenda['venue']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php $canScan = canScanAttendance($memberRole, $memberPosition); ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">QR Attendance Check-In</h5>
                        <p class="text-muted mb-3">Members can view their own attendance records and QR code here. Only authorized officers can scan attendance.</p>
                        <div class="row g-4">
                            <div class="col-lg-5">
                                <div class="border rounded p-3 text-center">
                                    <div id="qrCode" class="d-flex justify-content-center"></div>
                                    <p class="mt-3 mb-0 small text-muted">Scan this code to mark attendance</p>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <?php if ($canScan): ?>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="present_cutoff">Present cutoff</label>
                                            <input id="present_cutoff" name="present_cutoff" type="time" class="form-control" value="08:00">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="late_cutoff">Late cutoff</label>
                                            <input id="late_cutoff" name="late_cutoff" type="time" class="form-control" value="08:15">
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label" for="participation_rating">Participation rating</label>
                                            <select id="participation_rating" name="rating" class="form-select">
                                                <option value="5">5 - Excellent</option>
                                                <option value="4">4 - Very Good</option>
                                                <option value="3" selected>3 - Good</option>
                                                <option value="2">2 - Fair</option>
                                                <option value="1">1 - Needs Improvement</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-3 border rounded p-3">
                                        <h6 class="mb-2">Scan with camera</h6>
                                        <div id="reader" style="width: 100%; min-height: 280px;"></div>
                                        <button type="button" id="startScan" class="btn btn-success mt-3">Start Camera Scan</button>
                                    </div>
                                <?php else: ?>
                                    <div class="border rounded p-3 bg-light">
                                        <h6 class="mb-2">Attendance access</h6>
                                        <p class="mb-0 text-muted">Only the president and secretary can scan attendance. You can still view your attendance records and QR code here.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6 class="text-muted">Total Records</h6>
                                <h2 class="mb-0"><?php echo legacyEscape((string)count($attendance)); ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive attendance-table">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Event Name</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Rating</th>
                                <th>Time In</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance as $record): ?>
                            <?php $ratingValue = max(1, min(5, (int) ($record['rating'] ?? 0))); ?>
                            <tr>
                                <td><?php echo legacyEscape($record['event']); ?></td>
                                <td><?php echo legacyEscape($record['date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo legacyEscape(getStatusBadgeClass($record['status'] ?? '')); ?>">
                                        <?php echo legacyEscape($record['status'] ?? 'Unknown'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo str_repeat('★', $ratingValue) . str_repeat('☆', 5 - $ratingValue); ?>
                                        <?php echo ' ' . legacyEscape(formatParticipationRatingLabel($ratingValue)); ?>
                                    </span>
                                </td>
                                <td><?php echo legacyEscape($record['time_in'] ?? '-'); ?></td>
                                <td><?php echo legacyEscape($record['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.3/html5-qrcode.min.js"></script>
    <script>
        const qrPayload = <?php echo json_encode($qrText, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
        if (document.getElementById('qrCode')) {
            new QRCode(document.getElementById('qrCode'), {
                text: qrPayload,
                width: 220,
                height: 220,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        const startScanButton = document.getElementById('startScan');
        const readerElement = document.getElementById('reader');
        if (startScanButton && readerElement) {
            const html5QrCode = new Html5Qrcode('reader');
            const scanQr = async () => {
                try {
                    const devices = await Html5Qrcode.getCameras();
                    if (!devices || devices.length === 0) {
                        alert('No camera found.');
                        return;
                    }

                    const cameraId = devices[0].id;
                    await html5QrCode.start(cameraId, { fps: 10, qrbox: { width: 250, height: 250 } }, async (decodedText) => {
                        const presentCutoff = document.getElementById('present_cutoff')?.value || '08:00';
                        const lateCutoff = document.getElementById('late_cutoff')?.value || '08:15';
                        const rating = document.getElementById('participation_rating')?.value || '3';
                        const formData = new FormData();
                        formData.append('action', 'scan_qr');
                        formData.append('qr_data', decodedText);
                        formData.append('present_cutoff', presentCutoff);
                        formData.append('late_cutoff', lateCutoff);
                        formData.append('rating', rating);

                        await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });

                        window.location.reload();
                    });
                } catch (error) {
                    console.error(error);
                    alert('Unable to start camera scan.');
                }
            };

            startScanButton.addEventListener('click', scanQr);
        }
    </script>
</body>
</html>
