<?php

use App\Http\Controllers\ItemBarcodeLabelController;
use App\Http\Controllers\SaleReceiptController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified', 'super-admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
    Volt::route('/', 'pages.super-admin.dashboard')->name('dashboard');
    Volt::route('tenants', 'pages.super-admin.tenants')->name('tenants.index');
    Volt::route('subscription-plans', 'pages.super-admin.subscription-plans')->name('subscription-plans.index');
});

Route::middleware(['auth', 'verified', 'tenant.active'])->group(function () {
    Volt::route('dashboard', 'pages.dashboard.index')->name('dashboard');
    Volt::route('reports/sales', 'pages.reports.sales')->name('reports.sales');
    Volt::route('reports/stock', 'pages.reports.stock')->name('reports.stock');
    Volt::route('reports/shifts', 'pages.reports.shifts')->name('reports.shifts');
    Volt::route('reports/scheduled', 'pages.reports.scheduled')->name('reports.scheduled');
    Volt::route('tax-rates', 'pages.tax-rates.index')->name('tax-rates.index');
    Volt::route('branches', 'pages.branches.index')->name('branches.index');
    Volt::route('users', 'pages.users.index')->name('users.index');
    Volt::route('categories', 'pages.categories.index')->name('categories.index');
    Volt::route('units-of-measure', 'pages.units-of-measure.index')->name('units-of-measure.index');
    Volt::route('products', 'pages.products.index')->name('products.index');
    Volt::route('products/{product}/items', 'pages.products.items')->name('products.items');
    Route::get('items/{uuid}/label', ItemBarcodeLabelController::class)->name('items.label');
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
    Route::get('sales/{uuid}/receipt', SaleReceiptController::class)->name('sales.receipt');
    Volt::route('stock-levels', 'pages.stock-levels.index')->name('stock-levels.index');
    Volt::route('customers', 'pages.customers.index')->name('customers.index');
    Volt::route('audit-log', 'pages.audit-log.index')->name('audit-log.index');
    Volt::route('notifications', 'pages.notifications.index')->name('notifications.index');
});

require __DIR__.'/auth.php';
