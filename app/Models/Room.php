<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Room extends Model
{
    protected $fillable = [
        'id',
        'topic_id',
        'theme_name',
        'positive_user_id',
        'negative_user_id',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($room) {
            if (empty($room->id)) {
                $room->id = Str::uuid();
            }
        });
    }

    public function positiveUser()
    {
        return $this->belongsTo(User::class, 'positive_user_id');
    }

    public function negativeUser()
    {
        return $this->belongsTo(User::class, 'negative_user_id');
    }

    public function isFull()
    {
        return !is_null($this->positive_user_id) && !is_null($this->negative_user_id);
    }

    public function hasUser($userId)
    {
        return $this->positive_user_id === $userId || $this->negative_user_id === $userId;
    }

    public function getAvailableSide()
    {
        if (is_null($this->positive_user_id)) {
            return 'positive';
        }
        if (is_null($this->negative_user_id)) {
            return 'negative';
        }
        return null;
    }
}
