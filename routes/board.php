<?php

use App\Http\Controllers\Board\BoardMeController;
use Illuminate\Support\Facades\Route;

// Endpoints for the module-requests board Worker (onsplan.nl/requests). Bearer tokens only, so like
// routes/mcp.php this is loaded outside the `web` group (no session, no CSRF). See App\Models\OAuthClient.
Route::get('/api/board/me', BoardMeController::class)->middleware(['auth:api', 'throttle:60,1']);
