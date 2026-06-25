<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_auth_attempts', function (Blueprint $table) {
            // Kiosk + member context for the check-in flow.
            $table->string('kiosk_id')->nullable()->after('requested_by');
            $table->string('member_id')->nullable()->after('kiosk_id');

            // Which mechanism is responsible for the gate on this attempt, and
            // (for app_turnstile) which managed Device to drive.
            $table->string('gate_mode')->nullable()->after('employee_no');
            $table->unsignedBigInteger('app_gate_device_id')->nullable()->after('gate_mode');

            // Idempotency: a gate opens at most once per session.
            $table->boolean('gate_opened')->default(false)->after('status');
            $table->timestamp('gate_opened_at')->nullable()->after('gate_opened');

            // One audit row per face_auth session — also enforces idempotency
            // at the DB level so a duplicate start can't create a second row.
            $table->unique('session_id', 'face_auth_attempts_session_unique');
        });
    }

    public function down(): void
    {
        Schema::table('face_auth_attempts', function (Blueprint $table) {
            $table->dropUnique('face_auth_attempts_session_unique');
            $table->dropColumn([
                'kiosk_id', 'member_id', 'gate_mode',
                'app_gate_device_id', 'gate_opened', 'gate_opened_at',
            ]);
        });
    }
};
