<?php
// Simple file-based attendance storage helper stored in attendance.json

declare(strict_types=1);

function loadAttendanceFromStorage(): array
{
    $path = __DIR__ . '/attendance.json';
    if (!file_exists($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function saveAttendanceToStorage(array $attendance): void
{
    $path = __DIR__ . '/attendance.json';
    file_put_contents($path, json_encode($attendance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function addAttendanceRecords(array $newRecords): void
{
    $attendance = loadAttendanceFromStorage();

    foreach ($newRecords as $record) {
        $attendance[] = $record;
    }

    saveAttendanceToStorage($attendance);
}
