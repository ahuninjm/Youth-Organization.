<?php
require __DIR__ . '/db.php';
echo 'pdo_ready' . PHP_EOL;
if ($pdo) {
    echo 'driver=' . $driver . PHP_EOL;
}
