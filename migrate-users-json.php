<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$usersFile = __DIR__ . '/users.json';

if (!file_exists($usersFile)) {
    echo "No users.json file found.\n";
    exit;
}

$users = json_decode(file_get_contents($usersFile), true);

if (!is_array($users)) {
    echo "users.json is not valid JSON.\n";
    exit(1);
}

$inserted = 0;
$skipped = 0;

$checkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$createUser = $pdo->prepare(
    'INSERT INTO users (name, username, role, password, created_at) VALUES (?, ?, ?, ?, ?)'
);

foreach ($users as $user) {
    if (!isset($user['name'], $user['username'], $user['password'])) {
        $skipped++;
        continue;
    }

    $checkUser->execute([$user['username']]);

    if ($checkUser->fetch()) {
        $skipped++;
        continue;
    }

    $createUser->execute([
        $user['name'],
        $user['username'],
        $user['role'] ?? 'member',
        $user['password'],
        isset($user['created_at']) ? date('Y-m-d H:i:s', strtotime($user['created_at'])) : date('Y-m-d H:i:s'),
    ]);

    $inserted++;
}

echo "Migration complete. Inserted: {$inserted}. Skipped: {$skipped}.\n";

