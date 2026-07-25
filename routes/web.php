<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KnowledgeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/admin/login', [AuthController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('admin.login.store');

Route::prefix('admin')->middleware('admin.auth')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::post('/knowledge-bases', [KnowledgeController::class, 'storeBase'])->name('bases.store');
    Route::get('/knowledge-bases/{knowledgeBase}', [KnowledgeController::class, 'show'])->name('bases.show');
    Route::post('/knowledge-bases/{knowledgeBase}/documents', [KnowledgeController::class, 'ingest'])->name('documents.store');
    Route::delete('/documents/{document}', [KnowledgeController::class, 'destroyDocument'])->name('documents.destroy');
    Route::post('/knowledge-bases/{knowledgeBase}/query', [KnowledgeController::class, 'query'])->name('query');
});
