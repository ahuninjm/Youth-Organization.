<?php
// Simple storage helpers for announcements.
// Stored in announcements.json (same directory).

declare(strict_types=1);

function loadAnnouncementsFromStorage(): array
{
    $storagePath = __DIR__ . '/announcements.json';

    if (!file_exists($storagePath)) {
        return [];
    }

    $contents = file_get_contents($storagePath);
    if ($contents === false) {
        return [];
    }

    $contents = trim($contents);
    if ($contents === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function saveAnnouncementsToStorage(array $announcements): void
{
    $storagePath = __DIR__ . '/announcements.json';

    // Lock during write to reduce chance of corruption.
    $fp = fopen($storagePath, 'c+');
    if ($fp === false) {
        // Fallback: best-effort write
        file_put_contents($storagePath, json_encode($announcements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return;
    }

    try {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($announcements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

