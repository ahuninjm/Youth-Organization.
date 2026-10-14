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
    $_SESSION['flash_message'] = 'Access denied. Documents are available only to officers and administrators.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: homepage.php');
    exit;
}
$currentPage = 'documents';
$dir = public_path('uploads/documents');

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$message = '';
$allowedCategories = ['general', 'forms', 'reports', 'meeting-files', 'photos', 'other'];
$allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
$maxFileSize = 5 * 1024 * 1024;
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = (string) ($_GET['category'] ?? 'all');
$dateFilter = (string) ($_GET['date'] ?? '');

if ($categoryFilter !== 'all' && !in_array($categoryFilter, $allowedCategories, true)) {
    $categoryFilter = 'all';
}

function legacyEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function documentCategoryLabel(string $category): string
{
    $value = strtolower(trim($category));
    if ($value === '') {
        return 'General';
    }
    return ucwords(str_replace(['-', '_'], ' ', $value));
}

function documentBadgeClass(string $category): string
{
    return match (strtolower(trim($category))) {
        'general' => 'badge-general',
        'forms' => 'badge-forms',
        'reports' => 'badge-reports',
        'meeting-files' => 'badge-meeting-files',
        'photos' => 'badge-photos',
        default => 'badge-other',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['document'])) {
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? 'general'));
    $file = $_FILES['document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeCategory = in_array($category, $allowedCategories, true) ? $category : 'general';

    if ($title === '' || $file['error'] !== UPLOAD_ERR_OK || !in_array($ext, $allowedExtensions, true) || $file['size'] > $maxFileSize) {
        $message = 'Provide a title, choose a valid file type, and keep uploads under 5 MB.';
    } else {
        $categoryDir = $dir . '/' . $safeCategory;
        if (!is_dir($categoryDir)) {
            mkdir($categoryDir, 0755, true);
        }

        $storedName = uniqid('doc_', true) . '.' . $ext;
        $destination = $categoryDir . '/' . $storedName;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $insert = $pdo->prepare('INSERT INTO documents (title, description, file_path, uploaded_by, category, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $title,
                $description !== '' ? $description : null,
                'uploads/documents/' . $safeCategory . '/' . $storedName,
                $member['id'],
                $safeCategory,
                $file['size'],
                mime_content_type($destination) ?: 'application/octet-stream',
            ]);
            logAudit($pdo, 'document', (int) $pdo->lastInsertId(), 'create', (int) ($member['id'] ?? 0), (string) ($member['name'] ?? 'User'), 'Uploaded document: ' . $title);
            $message = 'Document uploaded successfully.';
        }
    }
}

$query = 'SELECT d.*, u.name FROM documents d JOIN users u ON u.id = d.uploaded_by WHERE 1=1';
$params = [];

if ($searchTerm !== '') {
    $query .= ' AND (LOWER(d.title) LIKE LOWER(?) OR LOWER(COALESCE(d.description, "")) LIKE LOWER(?) OR LOWER(u.name) LIKE LOWER(?))';
    $like = '%' . $searchTerm . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($categoryFilter !== 'all') {
    $query .= ' AND d.category = ?';
    $params[] = $categoryFilter;
}

if ($dateFilter !== '') {
    $query .= ' AND DATE(d.created_at) = ?';
    $params[] = $dateFilter;
}

$query .= ' ORDER BY d.created_at DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documents - Youth Organization</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f3f6fa; color: #1d2530; }
        .document-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin-bottom: 18px; }
        .document-toolbar .form-control, .document-toolbar .form-select { max-width: 220px; }
        .badge-category { display: inline-block; padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .badge-general { background: #e8f1ff; color: #1d4ed8; }
        .badge-forms { background: #eefaf2; color: #136d4e; }
        .badge-reports { background: #fff3d9; color: #9a6700; }
        .badge-meeting-files { background: #f1e7ff; color: #5a3aa8; }
        .badge-photos { background: #fce7f3; color: #9d174d; }
        .badge-other { background: #e5e7eb; color: #374151; }
        .document-member-view .document-library-column {
            width: 100%;
        }
        .document-member-view .document-library-column .card {
            min-height: 0;
        }
    </style>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="<?php echo in_array($role, ['officer', 'admin'], true) ? 'document-manager-view' : 'document-member-view'; ?>">
    <?php require_once __DIR__ . '/topnav.php'; ?>
    <main class="container-fluid px-4 py-4">
        <p class="text-success text-uppercase small fw-bold mb-1">Organization files</p>
        <h1 class="h3">Documents</h1>
        <p class="text-muted">Upload and access proposals, reports, certificates, and other files.</p>
        <?php if (in_array($role, ['officer', 'admin'], true)): ?><div class="text-end mb-3"><button class="btn btn-success" type="button" id="toggleDocumentForm" aria-expanded="false" aria-controls="documentFormPanel">+ Upload document</button></div><?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo legacyEscape($message); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4 workflow-form-modal" id="documentFormPanel" hidden>
                <?php if (in_array($role, ['officer', 'admin'], true)): ?>
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Upload document</h5></div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data"><?php echo csrf_field(); ?>
                                <label class="form-label">Title</label>
                                <input name="title" class="form-control mb-3" required>

                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control mb-3"></textarea>

                                <label class="form-label">Category</label>
                                <select name="category" class="form-select mb-3">
                                    <?php foreach ($allowedCategories as $category): ?>
                                        <option value="<?php echo legacyEscape($category); ?>"><?php echo legacyEscape(documentCategoryLabel($category)); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="form-label">File</label>
                                <label class="file-picker mb-3" for="documentUpload">
                                    <span class="file-picker-button">Choose File</span>
                                    <span class="file-picker-name" id="documentUploadName">No file chosen</span>
                                </label>
                                <input name="document" id="documentUpload" type="file" class="visually-hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                                <div class="small text-muted mb-3">Accepted formats: PDF, Word, Excel, JPG, PNG. Max file size: 5 MB.</div>
                                <button class="btn btn-primary w-100">Upload Document</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="<?php echo in_array($role, ['officer', 'admin'], true) ? 'col-lg-8' : 'col-12'; ?> document-library-column">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">Document library</h5></div>
                    <div class="card-body p-0">
                        <div class="p-3">
                            <form method="get" class="document-toolbar">
                                <input type="text" name="q" class="form-control" value="<?php echo legacyEscape($searchTerm); ?>" placeholder="Search documents">
                                <select name="category" class="form-select">
                                    <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>All categories</option>
                                    <?php foreach ($allowedCategories as $category): ?>
                                        <option value="<?php echo legacyEscape($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo legacyEscape(documentCategoryLabel($category)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="date" name="date" class="form-control" value="<?php echo legacyEscape($dateFilter); ?>">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <?php if ($searchTerm !== '' || $categoryFilter !== 'all' || $dateFilter !== ''): ?>
                                    <a href="documents.php" class="btn btn-outline-secondary">Clear</a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>Document</th>
                                        <th>Category</th>
                                        <th>Uploaded by</th>
                                        <th>Date</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <?php $badgeClass = documentBadgeClass((string) ($item['category'] ?? 'general')); ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo legacyEscape($item['title']); ?></strong>
                                                <div class="small text-muted"><?php echo legacyEscape($item['description'] ?? ''); ?></div>
                                            </td>
                                            <td><span class="badge-category <?php echo legacyEscape($badgeClass); ?>"><?php echo legacyEscape(documentCategoryLabel((string) ($item['category'] ?? 'general'))); ?></span></td>
                                            <td><?php echo legacyEscape($item['name']); ?></td>
                                            <td><?php echo legacyEscape(date('F j, Y', strtotime($item['created_at']))); ?></td>
                                            <td><a class="btn btn-sm btn-outline-primary" href="<?php echo legacyEscape($item['file_path']); ?>" target="_blank" rel="noreferrer">Open</a></td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (!$items): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">No documents found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script>
        const documentFormPanel = document.getElementById('documentFormPanel');
        const toggleDocumentForm = document.getElementById('toggleDocumentForm');
        if (documentFormPanel && toggleDocumentForm) {
            toggleDocumentForm.addEventListener('click', function () {
                documentFormPanel.hidden = !documentFormPanel.hidden;
                document.body.style.overflow = documentFormPanel.hidden ? '' : 'hidden';
                toggleDocumentForm.setAttribute('aria-expanded', documentFormPanel.hidden ? 'false' : 'true');
                toggleDocumentForm.textContent = documentFormPanel.hidden ? '+ Upload document' : 'Hide form';
            });
        }
        const documentUpload = document.getElementById('documentUpload');
        const documentUploadName = document.getElementById('documentUploadName');
        if (documentUpload && documentUploadName) {
            documentUpload.addEventListener('change', function () {
                documentUploadName.textContent = this.files.length ? this.files[0].name : 'No file chosen';
            });
        }
    </script>
</body>
</html>
