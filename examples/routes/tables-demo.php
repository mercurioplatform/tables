<?php

use App\Tables\Demo\ArraySourceDemo;
use App\Tables\Demo\EloquentSourceDemo;
use App\Tables\Demo\FileSourceCsvDemo;
use App\Tables\Demo\FileSourceJsonlDemo;
use App\Tables\Demo\HttpSourceDemo;
use App\Tables\Demo\SqlSourceDemo;
use Illuminate\Support\Facades\Route;

/*
 * Tables demo routes — copy-paste snippet from mercurioplatform/tables examples.
 *
 * Подключите этот файл в routes/web.php строкой:
 *     require __DIR__.'/tables-demo.php';
 *
 * После этого demo-страницы будут доступны по адресам:
 *     /admin/tables-demo/eloquent
 *     /admin/tables-demo/array
 *     /admin/tables-demo/sql
 *     /admin/tables-demo/http
 *     /admin/tables-demo/file-csv
 *     /admin/tables-demo/file-jsonl
 *
 * --------------------------------------------------------------------------
 * Alternative: защитить demo за вашим auth guard'ом.
 * Замените `admin` на реальное имя guard'а в вашем проекте (например `web`).
 *
 * Route::prefix('admin/tables-demo')
 *     ->middleware(['web', 'auth:admin'])
 *     ->name('tables-demo.')
 *     ->group(function () {
 *         Route::tablesPage('eloquent', EloquentSourceDemo::class)->name('eloquent');
 *         Route::tablesPage('array', ArraySourceDemo::class)->name('array');
 *         Route::tablesPage('sql', SqlSourceDemo::class)->name('sql');
 *         Route::tablesPage('http', HttpSourceDemo::class)->name('http');
 *         Route::tablesPage('file-csv', FileSourceCsvDemo::class)->name('file-csv');
 *         Route::tablesPage('file-jsonl', FileSourceJsonlDemo::class)->name('file-jsonl');
 *     });
 * --------------------------------------------------------------------------
 */

Route::prefix('admin/tables-demo')
    ->middleware(['web'])
    ->name('tables-demo.')
    ->group(function () {
        Route::tablesPage('eloquent', EloquentSourceDemo::class)->name('eloquent');
        Route::tablesPage('array', ArraySourceDemo::class)->name('array');
        Route::tablesPage('sql', SqlSourceDemo::class)->name('sql');
        Route::tablesPage('http', HttpSourceDemo::class)->name('http');
        Route::tablesPage('file-csv', FileSourceCsvDemo::class)->name('file-csv');
        Route::tablesPage('file-jsonl', FileSourceJsonlDemo::class)->name('file-jsonl');
    });
