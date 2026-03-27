<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivity extends Model
{
    public $timestamps = false; // We only have created_at, not updated_at

    protected $fillable = [
        'user_id',
        'type',
        'description',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
