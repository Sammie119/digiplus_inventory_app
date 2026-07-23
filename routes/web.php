<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'pages.dashboard.index')->name('dashboard');
    Volt::route('reports/sales', 'pages.reports.sales')->name('reports.sales');
    Volt::route('reports/stock', 'pages.reports.stock')->name('reports.stock');
    Volt::route('reports/shifts', 'pages.reports.shifts')->name('reports.shifts');
    Volt::route('branches', 'pages.branches.index')->name('branches.index');
    Volt::route('users', 'pages.users.index')->name('users.index');
    Volt::route('categories', 'pages.categories.index')->name('categories.index');
    Volt::route('units-of-measure', 'pages.units-of-measure.index')->name('units-of-measure.index');
    Volt::route('products', 'pages.products.index')->name('products.index');
    Volt::route('products/{product}/items', 'pages.products.items')->name('products.items');
    Volt::route('suppliers', 'pages.suppliers.index')->name('suppliers.index');
    Volt::route('purchases', 'pages.purchases.index')->name('purchases.index');
    Volt::route('purchases/create', 'pages.purchases.show')->name('purchases.create');
    Volt::route('purchases/{purchase}', 'pages.purchases.show')->name('purchases.show');
    Volt::route('stock-adjustments', 'pages.stock-adjustments.index')->name('stock-adjustments.index');
    Volt::route('stock-transfers', 'pages.stock-transfers.index')->name('stock-transfers.index');
    Volt::route('stock-transfers/create', 'pages.stock-transfers.show')->name('stock-transfers.create');
    Volt::route('stock-transfers/{stockTransfer}', 'pages.stock-transfers.show')->name('stock-transfers.show');
    Volt::route('pos', 'pages.pos.index')->name('pos.index');
    Volt::route('sales', 'pages.sales.index')->name('sales.index');
    Volt::route('stock-levels', 'pages.stock-levels.index')->name('stock-levels.index');
    Volt::route('customers', 'pages.customers.index')->name('customers.index');
});

require __DIR__.'/auth.php';
