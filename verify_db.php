<?php
require __DIR__ . '/db.php';

$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'activities' => (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn(),
    'announcements' => (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn(),
];

file_put_contents(__DIR__ . '/db_verify.json', json_encode($counts, JSON_PRETTY_PRINT));
echo json_encode($counts, JSON_PRETTY_PRINT), PHP_EOL;
