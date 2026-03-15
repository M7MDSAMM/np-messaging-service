<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Delivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'notification_uuid',
        'user_uuid',
        'channel',
        'provider',
        'recipient',
        'subject',
        'content',
        'payload',
        'status',
        'attempts_count',
        'max_attempts',
        'last_error',
        'sent_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $delivery) {
            if (! $delivery->uuid) {
                $delivery->uuid = (string) Str::uuid();
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class, 'delivery_uuid', 'uuid');
    }
}
