<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
//use Laravel\Sanctum\HasApiTokens;

class Wallet extends Model
{
    //use HasApiTokens;

    protected $fillable = [
        'store_id',
        'address',
        'network'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
