<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once dirname(__DIR__, 3) . '/db.php';

$member = $_SESSION['user'];
$role = $member['role'] ?? 'member';
if (!in_array($role, ['officer', 'admin'], true)) {
    $_SESSION['flash_message'] = 'Access denied. Only officers and administrators can view funds and budget records.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: dashboard.php');
    exit;
}

$currentPage = 'funds-budget';
$message = '';
$messageType = 'success';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'create_record'));

    if ($action === 'mark_spent') {
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $recordQuery = $pdo->prepare("SELECT activity_id FROM funds WHERE id = ? AND fund_type = 'expense' AND fund_status = 'committed' LIMIT 1");
        $recordQuery->execute([$recordId]);
        $record = $recordQuery->fetch();
        $update = $pdo->prepare("UPDATE funds SET fund_status = 'spent' WHERE id = ? AND fund_type = 'expense' AND fund_status = 'committed'");
        $update->execute([$recordId]);
        if ($update->rowCount() > 0 && !empty($record['activity_id'])) {
            $openActivity = $pdo->prepare("UPDATE activities SET status = 'open' WHERE id = ? AND status = 'planning'");
            $openActivity->execute([(int) $record['activity_id']]);
        }
        $message = $update->rowCount() > 0 ? 'Committed expense marked as spent.' : 'This expense was already spent or could not be found.';
        if ($update->rowCount() > 0 && !empty($record['activity_id'])) {
            $message .= ' The linked activity is now open for member registration.';
        }
        $messageType = $update->rowCount() > 0 ? 'success' : 'info';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $kind = trim((string) ($_POST['fund_type'] ?? ''));
        $fundStatus = trim((string) ($_POST['fund_status'] ?? 'spent'));
        $description = trim((string) ($_POST['description'] ?? ''));
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $proposalId = (int) ($_POST['proposal_id'] ?? 0);

        $validAmount = is_numeric($amount) && (float) $amount > 0;
        $validStatus = in_array($fundStatus, ['committed', 'spent'], true);
        if ($kind === 'allocation') {
            $fundStatus = 'spent';
            $validStatus = true;
        }

        if ($title === '' || !$validAmount || !in_array($kind, ['allocation', 'expense'], true) || !$validStatus) {
            $message = 'Enter a valid title, positive amount, type, and status.';
            $messageType = 'danger';
        } elseif ($activityId > 0 && $proposalId > 0) {
            $message = 'Link a record to either an activity or a project proposal, not both.';
            $messageType = 'danger';
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO funds (title, amount, fund_type, fund_status, description, activity_id, proposal_id, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $title,
                (float) $amount,
                $kind,
                $fundStatus,
                $description !== '' ? $description : null,
                $activityId > 0 ? $activityId : null,
                $proposalId > 0 ? $proposalId : null,
                (int) $member['id'],
            ]);
            if ($kind === 'expense' && $fundStatus === 'spent' && $activityId > 0) {
                $openActivity = $pdo->prepare("UPDATE activities SET status = 'open' WHERE id = ? AND status = 'planning'");
                $openActivity->execute([$activityId]);
                $message = 'Expense saved. The linked activity is now open for member registration.';
            } else {
                $message = $fundStatus === 'committed' ? 'Budget commitment saved.' : 'Financial record saved.';
            }
        }
    }
}

$activities = $pdo->query(
        "SELECT a.id, a.title, a.budget_amount
         FROM activities a
        WHERE a.status = 'open'
             AND NOT EXISTS (
                     SELECT 1 FROM funds f
                     WHERE f.activity_id = a.id
             )
         ORDER BY a.activity_date DESC, a.id DESC"
)->fetchAll();
$proposals = $pdo->query(
        "SELECT p.id, p.title, p.budget_amount, p.status
         FROM project_proposals p
         WHERE p.budget_amount IS NOT NULL
             AND p.budget_amount > 0
             AND p.status NOT IN ('completed', 'rejected')
             AND NOT EXISTS (
                     SELECT 1 FROM funds f
                     WHERE f.proposal_id = p.id
             )
         ORDER BY p.submitted_at DESC, p.id DESC"
)->fetchAll();
$items = $pdo->query(
    'SELECT f.*, u.name AS recorded_name, a.title AS activity_title, p.title AS proposal_title
     FROM funds f
     INNER JOIN users u ON u.id = f.recorded_by
     LEFT JOIN activities a ON a.id = f.activity_id
     LEFT JOIN project_proposals p ON p.id = f.proposal_id
     ORDER BY f.created_at DESC, f.id DESC'
)->fetchAll();

$allocation = 0.0;
$committed = 0.0;
$spent = 0.0;
foreach ($items as $item) {
    if ($item['fund_type'] === 'allocation') {
        $allocation += (float) $item['amount'];
    } elseif ($item['fund_status'] === 'committed') {
        $committed += (float) $item['amount'];
    } else {
        $spent += (float) $item['amount'];
    }
}
$available = $allocation - $committed - $spent;

function legacyEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Funds and Budget - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        .funds-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .funds-page-header .btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .financial-form-panel {
            margin-bottom: 1.5rem;
        }
        .financial-form-panel[hidden] {
            display: none;
        }
        .financial-form-panel .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        @media (max-width: 575.98px) {
            .funds-page-header {
                flex-direction: column;
            }
            .funds-page-header .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/topnav.php'; ?>
<main class="container-fluid px-4 py-4">
    <div class="funds-page-header">
        <div>
            <p class="text-success text-uppercase small fw-bold mb-1">Financial records</p>
            <h1 class="h3">Funds and Budget</h1>
            <p class="text-muted mb-0">Plan budgets first, commit approved spending, and record actual expenses after payment.</p>
        </div>
        <button class="btn btn-success" type="button" id="toggleFinancialForm" aria-expanded="false" aria-controls="financialFormPanel">+ Add financial record</button>
    </div>

    <?php if ($message !== ''): ?><div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Allocations</div><h3>PHP <?php echo number_format($allocation, 2); ?></h3></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Committed</div><h3>PHP <?php echo number_format($committed, 2); ?></h3></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Spent</div><h3>PHP <?php echo number_format($spent, 2); ?></h3></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Available</div><h3 class="<?php echo $available < 0 ? 'text-danger' : 'text-success'; ?>">PHP <?php echo number_format($available, 2); ?></h3></div></div></div>
    </div>

    <div class="financial-form-panel workflow-form-modal" id="financialFormPanel" hidden>
        <div class="card">
                <div class="card-header"><h5 class="mb-0">New financial record</h5><button class="btn btn-sm btn-outline-secondary" type="button" id="closeFinancialForm">Close</button></div>
                <div class="card-body">
                    <form method="post"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create_record">
                        <label class="form-label" for="title">Title</label>
                        <input id="title" name="title" class="form-control mb-3" required>
                        <label class="form-label" for="amount">Amount (PHP)</label>
                        <input id="amount" name="amount" type="number" min="0.01" step="0.01" class="form-control mb-3" required>
                        <label class="form-label" for="fund_type">Type</label>
                        <select id="fund_type" name="fund_type" class="form-select mb-3">
                            <option value="allocation">Allocation</option>
                            <option value="expense">Expense</option>
                        </select>
                        <label class="form-label" for="fund_status">Expense status</label>
                        <select id="fund_status" name="fund_status" class="form-select mb-3">
                            <option value="committed">Committed / approved</option>
                            <option value="spent">Spent / paid</option>
                        </select>
                        <label class="form-label" for="activity_id">Related activity</label>
                        <select id="activity_id" name="activity_id" class="form-select mb-3">
                            <option value="0">Not linked</option>
                            <?php foreach ($activities as $activity): ?><option value="<?php echo (int) $activity['id']; ?>"><?php echo legacyEscape($activity['title']); ?> (PHP <?php echo number_format((float) $activity['budget_amount'], 2); ?>)</option><?php endforeach; ?>
                        </select>
                        <label class="form-label" for="proposal_id">Related project proposal</label>
                        <select id="proposal_id" name="proposal_id" class="form-select mb-3">
                            <option value="0">Not linked</option>
                            <?php foreach ($proposals as $proposal): ?><option value="<?php echo (int) $proposal['id']; ?>"><?php echo legacyEscape($proposal['title']); ?> (PHP <?php echo number_format((float) $proposal['budget_amount'], 2); ?>)</option><?php endforeach; ?>
                        </select>
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" class="form-control mb-3"></textarea>
                        <button class="btn btn-success w-100" type="submit">Save financial record</button>
                    </form>
                </div>
            </div>
    </div>
    <div class="row g-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Financial history</h5></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Record</th><th>Link</th><th>Amount</th><th>Status</th><th>Recorded by</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?php echo legacyEscape($item['title']); ?></strong><br><small class="text-muted"><?php echo legacyEscape($item['description'] ?? ''); ?></small></td>
                                <td><?php echo legacyEscape($item['activity_title'] ?? $item['proposal_title'] ?? 'General funds'); ?></td>
                                <td>PHP <?php echo number_format((float) $item['amount'], 2); ?></td>
                                <td><span class="badge bg-<?php echo $item['fund_type'] === 'allocation' ? 'primary' : ($item['fund_status'] === 'committed' ? 'warning' : 'success'); ?>"><?php echo legacyEscape(ucfirst($item['fund_type'] === 'allocation' ? 'allocation' : $item['fund_status'])); ?></span></td>
                                <td><?php echo legacyEscape($item['recorded_name']); ?></td>
                                <td><?php if ($item['fund_type'] === 'expense' && $item['fund_status'] === 'committed'): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_spent"><input type="hidden" name="record_id" value="<?php echo (int) $item['id']; ?>"><button class="btn btn-sm btn-outline-success" type="submit">Mark spent</button></form><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="6" class="text-center text-muted py-4">No financial records yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
    const financialFormPanel = document.getElementById('financialFormPanel');
    const toggleFinancialForm = document.getElementById('toggleFinancialForm');
    const closeFinancialForm = document.getElementById('closeFinancialForm');
    function setFinancialFormVisibility(isVisible) {
        financialFormPanel.hidden = !isVisible;
        document.body.style.overflow = isVisible ? 'hidden' : '';
        toggleFinancialForm.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
        toggleFinancialForm.textContent = isVisible ? 'Hide form' : '+ Add financial record';
        if (isVisible) {
            financialFormPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    toggleFinancialForm.addEventListener('click', function () {
        setFinancialFormVisibility(financialFormPanel.hidden);
    });
    closeFinancialForm.addEventListener('click', function () {
        setFinancialFormVisibility(false);
    });
</script>
</body>
</html>
