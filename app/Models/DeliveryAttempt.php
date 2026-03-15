<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeliveryAttempt extends Model
{
    protected $fillable = [
        'uuid',
        'delivery_uuid',
        'attempt_number',
        'provider',
        'status',
        'error_message',
        'provider_message_id',
    ];

    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
