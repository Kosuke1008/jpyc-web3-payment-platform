<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Model
{
    protected $table = 'staffs';

    use HasApiTokens;

    protected $fillable = [
        'store_id',
        'staff_id',
        'name',
        'pin',
        'role'
    ];

    protected $hidden = [
        'pin'
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
