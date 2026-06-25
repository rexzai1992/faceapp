<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_auth_attempts', function (Blueprint $table) {
            $table->id();
            // Who/what asked for the verification (app user id if you add auth,
            // else the caller ip). Kept loose so it works before auth exists.
            $table->string('requested_by')->nullable();
            $table->string('caller_ip')->nullable();

            // What was requested.
            $table->string('face_auth_device_id')->nullable();
            $table->string('person_id')->nullable();
            $table->string('employee_no')->nullable();

            // face_auth session correlation + outcome.
            $table->string('session_id')->nullable()->index();
            $table->string('status')->default('started'); // started|pending|success|failed|cancelled|qr_required|timeout|error
            $table->text('error_message')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_auth_attempts');
    }
};
