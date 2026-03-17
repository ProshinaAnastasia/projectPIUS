<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'telegram_id';
    public $incrementing = false;

    protected $fillable = [
        'telegram_id',
        'subscription_end_date',
        'todays_requests_count',
        'last_request_date',
    ];

    protected $casts = [
        'subscription_end_date' => 'date',
        'last_request_date' => 'date',
    ];
}
