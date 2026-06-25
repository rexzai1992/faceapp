<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_auth_attempts', function (Blueprint $table) {
            // True only when the gate opened without a matched identity because
            // FACE_AUTH_REQUIRE_MATCHED_IDENTITY=false (local testing). Always
            // false in production where the flag stays true.
            $table->boolean('identity_fallback_used')->default(false)->after('gate_opened');
        });
    }

    public function down(): void
    {
        Schema::table('face_auth_attempts', function (Blueprint $table) {
            $table->dropColumn('identity_fallback_used');
        });
    }
};
