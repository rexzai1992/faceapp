<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaceAuthAttempt extends Model
{
    protected $fillable = [
        'requested_by',
        'kiosk_id',
        'member_id',
        'caller_ip',
        'face_auth_device_id',
        'person_id',
        'employee_no',
        'gate_mode',
        'app_gate_device_id',
        'session_id',
        'status',
        'gate_opened',
        'identity_fallback_used',
        'gate_opened_at',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'gate_opened' => 'boolean',
        'identity_fallback_used' => 'boolean',
        'gate_opened_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
