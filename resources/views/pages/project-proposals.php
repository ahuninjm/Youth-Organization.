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
$memberId = (int) ($member['id'] ?? 0);
$memberRole = $member['role'] ?? 'member';
if (!in_array($memberRole, ['officer', 'admin'], true)) {
    $_SESSION['flash_message'] = 'Access denied. Project proposals are available only to officers and administrators.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: homepage.php');
    exit;
}
$memberPosition = strtolower(trim((string) ($member['position'] ?? '')));
$memberQuery = $pdo->prepare('SELECT position FROM users WHERE id = ? LIMIT 1');
$memberQuery->execute([$memberId]);
$memberPosition = strtolower(trim((string) ($memberQuery->fetchColumn() ?: $memberPosition)));
$canReview = in_array($memberRole, ['officer', 'admin'], true);
$isTreasurer = $memberRole === 'admin' || $memberPosition === 'treasurer';
$isPurokPresident = $memberRole === 'admin' || in_array($memberPosition, ['president', 'purok president'], true);
$currentPage = 'project-proposals';
$message = '';
$messageType = 'success';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $proposalId = (int) ($_POST['proposal_id'] ?? 0);

    if ($action === 'submit_proposal') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $budget = trim((string) ($_POST['budget'] ?? ''));
        $budgetAmount = $budget !== '' && is_numeric($budget) && (float) $budget > 0 ? (float) $budget : null;

        if ($title === '' || $description === '') {
            $message = 'Please provide a project title and description.';
            $messageType = 'danger';
        } else {
            $submitProposal = $pdo->prepare(
                'INSERT INTO project_proposals (title, description, budget, budget_amount, submitted_by) VALUES (?, ?, ?, ?, ?)'
            );
            $submitProposal->execute([$title, $description, $budget !== '' ? $budget : null, $budgetAmount, $memberId]);
            $message = 'Project proposal submitted for officer review.';
        }
    } elseif ($canReview && $proposalId > 0 && $action === 'start_review') {
        $proposalQuery = $pdo->prepare('SELECT budget, budget_amount FROM project_proposals WHERE id = ? LIMIT 1');
        $proposalQuery->execute([$proposalId]);
        $proposal = $proposalQuery->fetch();
        $reviewStatus = (float) ($proposal['budget_amount'] ?? 0) <= 0 ? 'waiting_budget' : 'under_review';
        $updateProposal = $pdo->prepare(
            "UPDATE project_proposals SET status = '{$reviewStatus}', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'submitted'"
        );
        $updateProposal->execute([$memberId, $proposalId]);
        $message = $reviewStatus === 'waiting_budget'
            ? 'Proposal is waiting because it has no budget.'
            : 'Proposal moved to Officer Review.';
    } elseif ($canReview && $proposalId > 0 && $action === 'approve_budget' && $isTreasurer) {
        $proposalQuery = $pdo->prepare('SELECT budget, budget_amount, status FROM project_proposals WHERE id = ? LIMIT 1');
        $proposalQuery->execute([$proposalId]);
        $proposal = $proposalQuery->fetch();
        if (!$proposal || (float) ($proposal['budget_amount'] ?? 0) <= 0) {
            $message = 'This proposal is waiting because it has no budget.';
            $messageType = 'warning';
        } elseif (!in_array($proposal['status'], ['under_review', 'waiting_budget'], true)) {
            $message = 'This proposal is not ready for budget approval.';
            $messageType = 'info';
        } else {
            $updateProposal = $pdo->prepare(
                "UPDATE project_proposals SET status = 'awaiting_president', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $updateProposal->execute([$memberId, $proposalId]);
            $message = 'Budget approved. The proposal is waiting for Purok President final approval.';
        }
    } elseif ($canReview && $proposalId > 0 && $action === 'set_budget' && $isTreasurer) {
        $budget = trim((string) ($_POST['budget'] ?? ''));
        if ($budget === '') {
            $message = 'Enter a budget before sending this proposal back to review.';
            $messageType = 'danger';
        } else {
            if (!is_numeric($budget) || (float) $budget <= 0) {
                $message = 'Enter a valid positive budget amount.';
                $messageType = 'danger';
            } else {
                $updateProposal = $pdo->prepare(
                "UPDATE project_proposals SET budget = ?, budget_amount = ?, status = 'under_review' WHERE id = ? AND status = 'waiting_budget'"
            );
                $updateProposal->execute([$budget, (float) $budget, $proposalId]);
                $message = 'Budget added. The proposal is ready for Treasurer approval.';
            }
        }
    } elseif ($canReview && $proposalId > 0 && $action === 'reject_proposal' && ($isTreasurer || $isPurokPresident)) {
        $updateProposal = $pdo->prepare(
            "UPDATE project_proposals SET status = 'rejected', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('under_review', 'waiting_budget', 'awaiting_president')"
        );
        $updateProposal->execute([$memberId, $proposalId]);
        $message = 'Proposal rejected.';
    } elseif ($canReview && $proposalId > 0 && $action === 'approve_proposal' && $isPurokPresident) {
        $updateProposal = $pdo->prepare(
            "UPDATE project_proposals SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'awaiting_president'"
        );
        $updateProposal->execute([$memberId, $proposalId]);
        $message = 'Proposal finally approved by the Purok President. It is ready for implementation.';
    } elseif ($canReview && $proposalId > 0 && $action === 'start_implementation') {
        $updateProposal = $pdo->prepare(
            "UPDATE project_proposals SET status = 'implementation' WHERE id = ? AND status = 'approved'"
        );
        $updateProposal->execute([$proposalId]);
        $message = 'Project moved to Implementation.';
    } elseif ($canReview && $proposalId > 0 && $action === 'complete_project') {
        $report = trim((string) ($_POST['completion_report'] ?? ''));
        if ($report === '') {
            $message = 'Please enter a completion report before closing the project.';
            $messageType = 'danger';
        } else {
            $updateProposal = $pdo->prepare(
                "UPDATE project_proposals SET status = 'completed', completion_report = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'implementation'"
            );
            $updateProposal->execute([$report, $proposalId]);
            $message = 'Project completed and report saved.';
        }
    }
}

$proposalQuery = $pdo->query(
    'SELECT p.*, submitter.name AS submitter_name, reviewer.name AS reviewer_name
     FROM project_proposals p
     INNER JOIN users submitter ON submitter.id = p.submitted_by
     LEFT JOIN users reviewer ON reviewer.id = p.reviewed_by
     ORDER BY p.submitted_at DESC, p.id DESC'
);
$proposals = $proposalQuery->fetchAll();

function legacyEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function proposalStatusLabel(string $status): string {
    return match ($status) {
        'under_review' => 'Officer Review',
        'waiting_budget' => 'Waiting for Budget Approval',
        'awaiting_president' => 'Waiting for Purok President',
        'implementation' => 'Implementation',
        default => ucfirst($status),
    };
}

function proposalStatusClass(string $status): string {
    return match ($status) {
        'submitted' => 'info',
        'under_review' => 'warning',
        'waiting_budget' => 'warning',
        'awaiting_president' => 'primary',
        'approved', 'completed' => 'success',
        'implementation' => 'primary',
        'rejected' => 'danger',
        default => 'secondary',
    };
}

function proposalNextStep(string $status, bool $isTreasurer, bool $isPurokPresident): string {
    return match ($status) {
        'submitted' => 'Next: Any officer must review this proposal.',
        'under_review' => $isTreasurer ? 'Next: Treasurer must approve the budget.' : 'Next: Waiting for Treasurer budget approval.',
        'waiting_budget' => $isTreasurer ? 'Next: Treasurer must add a budget.' : 'Next: Waiting for a budget from the Treasurer.',
        'awaiting_president' => $isPurokPresident ? 'Next: Purok President final approval.' : 'Next: Waiting for Purok President final approval.',
        'approved' => 'Next: Begin project implementation.',
        'implementation' => 'Next: Submit the project completion report.',
        'completed' => 'Final: Completion report recorded.',
        'rejected' => 'Final: Proposal rejected.',
        default => '',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Proposals - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .proposal-flow {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.45rem;
            margin-bottom: 1.5rem;
        }
        .proposal-step {
            min-width: 0;
            padding: 0.8rem 0.45rem;
            border: 1px solid #dfe8e3;
            border-radius: 8px;
            background: #f9fbfa;
            text-align: center;
        }
        .proposal-step-number {
            display: grid;
            width: 1.8rem;
            height: 1.8rem;
            margin: 0 auto 0.4rem;
            place-items: center;
            border-radius: 50%;
            color: #fff;
            background: #226147;
            font-size: 0.78rem;
            font-weight: 800;
        }
        .proposal-step-label {
            display: block;
            color: #12352a;
            font-size: 0.7rem;
            font-weight: 800;
            line-height: 1.25;
        }
        .proposal-card {
            height: 100%;
            border: 1px solid #dfe8e3;
            border-radius: 10px;
            box-shadow: 0 8px 22px rgba(18, 53, 42, 0.07);
        }
        .proposal-card .card-header {
            background: #f8fbf9;
            border-bottom: 1px solid #dfe8e3;
        }
        .proposal-title {
            color: #12352a;
            font-weight: 800;
        }
        .proposal-description {
            white-space: pre-line;
        }
        .completion-report {
            border-left: 3px solid #2f8a63;
            padding-left: 0.75rem;
            color: #66746e;
            font-size: 0.85rem;
        }
        @media (max-width: 767.98px) {
            .proposal-flow {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <main class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <p class="text-success text-uppercase small fw-bold mb-1">Community projects</p>
                <h1 class="h3 mb-1">Project Proposals</h1>
                <p class="text-muted mb-0">Submit ideas, review decisions, and document completed projects.</p>
            </div>
            <button class="btn btn-success" type="button" id="toggleProposalForm" aria-expanded="false" aria-controls="proposalFormPanel">+ Create proposal</button>
        </div>

        <div class="proposal-flow" aria-label="Project proposal process">
            <?php foreach (['Create Project Proposal', 'Submit Proposal', 'Officer Review', 'Treasurer Budget Approval', 'Purok President Final Approval', 'Implementation', 'Project Completion Report'] as $index => $step): ?>
                <div class="proposal-step"><span class="proposal-step-number"><?php echo $index + 1; ?></span><span class="proposal-step-label"><?php echo legacyEscape($step); ?></span></div>
            <?php endforeach; ?>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo legacyEscape($messageType); ?>"><?php echo legacyEscape($message); ?></div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-lg-4 workflow-form-modal" id="proposalFormPanel" hidden>
                <div class="card proposal-card">
                    <div class="card-header"><h5 class="mb-0">Create Project Proposal</h5></div>
                    <div class="card-body">
                        <form method="post"><?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="submit_proposal">
                            <div class="mb-3">
                                <label class="form-label" for="proposal_title">Project title</label>
                                <input type="text" id="proposal_title" name="title" class="form-control" placeholder="e.g. Community Garden" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="proposal_description">Project details</label>
                                <textarea id="proposal_description" name="description" class="form-control" rows="6" placeholder="Explain the need, activities, and expected community benefit." required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="proposal_budget">Estimated budget</label>
                                                    <input type="number" id="proposal_budget" name="budget" class="form-control" min="0.01" step="0.01" placeholder="Optional">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Submit Proposal</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="row g-3">
                    <?php if (!$proposals): ?>
                        <div class="col-12"><div class="card proposal-card"><div class="card-body text-muted">No project proposals have been submitted yet.</div></div></div>
                    <?php endif; ?>
                    <?php foreach ($proposals as $proposal): ?>
                        <div class="col-12">
                            <article class="card proposal-card">
                                <div class="card-body">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div>
                                            <h5 class="proposal-title mb-1"><?php echo legacyEscape($proposal['title']); ?></h5>
                                            <div class="small text-muted">Submitted by <?php echo legacyEscape($proposal['submitter_name']); ?> on <?php echo legacyEscape(date('F j, Y', strtotime($proposal['submitted_at']))); ?></div>
                                        </div>
                                        <span class="badge bg-<?php echo legacyEscape(proposalStatusClass($proposal['status'])); ?>"><?php echo legacyEscape(proposalStatusLabel($proposal['status'])); ?></span>
                                    </div>
                                    <div class="small text-muted mt-2"><?php echo legacyEscape(proposalNextStep($proposal['status'], $isTreasurer, $isPurokPresident)); ?></div>
                                    <p class="proposal-description mt-3 mb-2"><?php echo legacyEscape($proposal['description']); ?></p>
                                    <?php if ($proposal['budget']): ?><p class="small mb-2"><strong>Estimated budget:</strong> <?php echo legacyEscape($proposal['budget']); ?></p><?php endif; ?>
                                    <?php if ($proposal['reviewer_name']): ?><p class="small text-muted mb-2">Reviewed by <?php echo legacyEscape($proposal['reviewer_name']); ?></p><?php endif; ?>
                                    <?php if ($proposal['completion_report']): ?><div class="completion-report mb-3"><strong>Completion report</strong><br><?php echo legacyEscape($proposal['completion_report']); ?></div><?php endif; ?>

                                    <?php if ($canReview && $proposal['status'] === 'submitted'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="start_review"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-outline-primary">Start Review</button></form>
                                            <span class="small text-muted align-self-center">Officer review required first.</span>
                                        </div>
                                    <?php elseif ($canReview && $proposal['status'] === 'waiting_budget'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="small text-warning align-self-center">Waiting for a budget before Treasurer approval.</span>
                                            <?php if ($isTreasurer): ?>
                                                <form method="post" class="d-flex gap-2"><?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="set_budget">
                                                    <input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>">
                                                    <label class="visually-hidden" for="budget_<?php echo legacyEscape((string) $proposal['id']); ?>">Add project budget</label>
                                                    <input type="text" id="budget_<?php echo legacyEscape((string) $proposal['id']); ?>" name="budget" class="form-control form-control-sm" placeholder="Enter budget" required>
                                                    <button class="btn btn-sm btn-primary">Add Budget</button>
                                                </form>
                                                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_proposal"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($canReview && $proposal['status'] === 'under_review'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if ($isTreasurer && trim((string) ($proposal['budget'] ?? '')) !== ''): ?>
                                                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_budget"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-success">Approve Budget</button></form>
                                            <?php else: ?>
                                                <span class="small text-warning align-self-center"><?php echo trim((string) ($proposal['budget'] ?? '')) === '' ? 'Waiting for a budget before Treasurer approval.' : 'Waiting for Treasurer review.'; ?></span>
                                            <?php endif; ?>
                                            <?php if ($isTreasurer): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_proposal"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-outline-danger">Reject</button></form><?php endif; ?>
                                        </div>
                                    <?php elseif ($canReview && $proposal['status'] === 'awaiting_president'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if ($isPurokPresident): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_proposal"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-success">Final Approve</button></form><?php endif; ?>
                                            <?php if ($isPurokPresident): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_proposal"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-outline-danger">Reject</button></form><?php endif; ?>
                                            <?php if (!$isPurokPresident): ?><span class="small text-muted align-self-center">Waiting for Purok President final approval.</span><?php endif; ?>
                                        </div>
                                    <?php elseif ($canReview && $proposal['status'] === 'approved'): ?>
                                        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="start_implementation"><input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>"><button class="btn btn-sm btn-primary">Start Implementation</button></form>
                                    <?php elseif ($canReview && $proposal['status'] === 'implementation'): ?>
                                        <form method="post" class="mt-2"><?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="complete_project">
                                            <input type="hidden" name="proposal_id" value="<?php echo legacyEscape((string) $proposal['id']); ?>">
                                            <label class="form-label small" for="completion_report_<?php echo legacyEscape((string) $proposal['id']); ?>">Project Completion Report</label>
                                            <textarea id="completion_report_<?php echo legacyEscape((string) $proposal['id']); ?>" name="completion_report" class="form-control mb-2" rows="3" placeholder="Record what was completed and the result." required></textarea>
                                            <button class="btn btn-sm btn-success">Mark Project Completed</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
</body>
<script>
    const proposalFormPanel = document.getElementById('proposalFormPanel');
    const toggleProposalForm = document.getElementById('toggleProposalForm');
    if (proposalFormPanel && toggleProposalForm) {
        toggleProposalForm.addEventListener('click', function () {
            proposalFormPanel.hidden = !proposalFormPanel.hidden;
            document.body.style.overflow = proposalFormPanel.hidden ? '' : 'hidden';
            toggleProposalForm.setAttribute('aria-expanded', proposalFormPanel.hidden ? 'false' : 'true');
            toggleProposalForm.textContent = proposalFormPanel.hidden ? '+ Create proposal' : 'Hide form';
        });
    }
</script>
</html>
