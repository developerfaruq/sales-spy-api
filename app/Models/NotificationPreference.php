<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'email_on_export_complete',
        'email_on_billing',
        'email_on_new_features',
        'email_on_security_alerts',
        'inapp_on_export_complete',
        'inapp_on_low_credits',
        'inapp_on_scan_complete',
    ];

    protected function casts(): array
    {
        return [
            'email_on_export_complete' => 'boolean',
            'email_on_billing'         => 'boolean',
            'email_on_new_features'    => 'boolean',
            'email_on_security_alerts' => 'boolean',
            'inapp_on_export_complete' => 'boolean',
            'inapp_on_low_credits'     => 'boolean',
            'inapp_on_scan_complete'   => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
