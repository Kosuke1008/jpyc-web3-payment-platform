<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'staffs';

    protected $fillable = [
        'store_id',
        'staff_id',
        'name',
        'pin',
        'role',
    ];

    protected $hidden = [
        'pin',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
