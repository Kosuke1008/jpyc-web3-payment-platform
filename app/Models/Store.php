<?php

namespace App\Models;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'store_code',
        'store_pin',
        'name'
    ];

    protected $hidden = [
        'store_pin'
    ];

    public function staffs()
    {
        return $this->hasMany(Staff::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    
}
