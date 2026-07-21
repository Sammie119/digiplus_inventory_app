<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('branches', 'pages.branches.index')->name('branches.index');
    Volt::route('users', 'pages.users.index')->name('users.index');
    Volt::route('categories', 'pages.categories.index')->name('categories.index');
    Volt::route('units-of-measure', 'pages.units-of-measure.index')->name('units-of-measure.index');
    Volt::route('products', 'pages.products.index')->name('products.index');
    Volt::route('products/{product}/items', 'pages.products.items')->name('products.items');
});

require __DIR__.'/auth.php';
