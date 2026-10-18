<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

// The Filament panel owns "/". API routes live in routes/api.php.

// Invitation links are signed and expire; the form posts back to the same signed URL.
Route::middleware('signed')->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});
