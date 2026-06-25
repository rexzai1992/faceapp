<?php

use App\Http\Controllers\Api\AppDashboardController;
use App\Http\Controllers\Api\DeviceCallbackController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\ExternalGateController;
use App\Http\Controllers\Api\FaceAuthController;
use App\Http\Controllers\Api\KioskCheckinController;
use Illuminate\Support\Facades\Route;

Route::get('/app/dashboard', [AppDashboardController::class, 'show']);
Route::get('/device/status', [DeviceController::class, 'status']);
Route::post('/device/callbacks/heartbeat', [DeviceCallbackController::class, 'heartbeat'])
    ->name('api.devices.callbacks.heartbeat');
Route::post('/device/callbacks/records', [DeviceCallbackController::class, 'record'])
    ->name('api.devices.callbacks.record');
Route::post('/device/callbacks/person-registrations', [DeviceCallbackController::class, 'personRegistration'])
    ->name('api.devices.callbacks.person-registration');

Route::post('/enrollments', [EnrollmentController::class, 'store']);
Route::get('/enrollments/{enrollment:public_id}', [EnrollmentController::class, 'show']);

// External integration surface — bearer-token authed in the controller.
// Currently used by qparking-local to raise the turnstile after a paid exit.
Route::get('/external/health', [ExternalGateController::class, 'health']);
Route::post('/external/open-gate', [ExternalGateController::class, 'open']);

// Server-side proxy in front of the external face_auth service. The browser
// hits these; FaceAuthClient (holding FACE_AUTH_API_KEY) calls /api/v1 upstream.
Route::prefix('face-auth')->group(function () {
    Route::get('/devices', [FaceAuthController::class, 'devices']);
    Route::get('/persons', [FaceAuthController::class, 'persons']);
    Route::post('/start', [FaceAuthController::class, 'start']);
    Route::get('/session/{session}', [FaceAuthController::class, 'show']);
    Route::post('/session/{session}/cancel', [FaceAuthController::class, 'cancel']);
});

// Kiosk check-in: face_auth verifies the face, then the gate opens via the
// mode in FACE_AUTH_GATE_MODE (app_turnstile by default). Gate opens once.
Route::prefix('kiosk')->group(function () {
    Route::post('/face-checkin/start', [KioskCheckinController::class, 'start']);
    Route::post('/face-checkin/{session}/complete', [KioskCheckinController::class, 'complete']);
});
