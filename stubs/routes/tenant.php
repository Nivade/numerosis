<?php

declare(strict_types=1);

/*
 * Routes for a tenant's own domain, path or subdomain. Numerosis::routes()
 * loads this file inside the `tenant` middleware group, ahead of the
 * package's own tenant routes.
 *
 * use Illuminate\Support\Facades\Route;
 *
 * Route::get('/reports', ReportsController::class)->name('reports.index');
 */
