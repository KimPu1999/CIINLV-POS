<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceContactInquiry extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
    ];
}
