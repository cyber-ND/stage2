<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = [
        'name', 'capital', 'region', 'population',
        'currency_code', 'exchange_rate', 'estimated_gdp', 'flag_url', 'last_refreshed_at'
    ];

    protected $casts = [
        'last_refreshed_at' => 'datetime',
        'exchange_rate' => 'decimal:6',
        'estimated_gdp' => 'decimal:2',
    ];
}
