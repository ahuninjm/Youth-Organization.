<?php

use Illuminate\Support\Facades\Route;

$legacyPages = [
    'activities' => 'activities',
    'activity-insights' => 'activity-insights',
    'activity-report' => 'activity-report',
    'announcements' => 'announcements',
    'attendance' => 'attendance',
    'change-password' => 'change-password',
    'dashboard' => 'dashboard',
    'documents' => 'documents',
    'edit-profile' => 'edit-profile',
    'events-calendar' => 'events-calendar',
    'events-detail' => 'events-detail',
    'feedback' => 'feedback',
    'funds-budget' => 'funds-budget',
    'gallery' => 'gallery',
    'homepage' => 'homepage',
    'login' => 'login',
    'meeting-agendas' => 'meeting-agendas',
    'members' => 'members',
    'officers' => 'officers',
    'profile' => 'profile',
    'project-proposals' => 'project-proposals',
    'reports' => 'reports',
];

Route::match(['GET', 'POST'], '/', fn () => view('pages.index'))->name('home');
Route::match(['GET', 'POST'], '/index', fn () => view('pages.index'))->name('pages.index');
Route::match(['GET', 'POST'], '/index.php', fn () => view('pages.index'))->name('legacy.index');

foreach ($legacyPages as $path => $view) {
    Route::match(['GET', 'POST'], "/{$path}", fn () => view("pages.{$view}"))->name("pages.{$path}");
    Route::match(['GET', 'POST'], "/{$path}.php", fn () => view("pages.{$view}"))->name("legacy.{$path}");
}
