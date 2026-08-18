<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    /** @use HasFactory<\Database\Factories\BrandFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'order',
        'in_use',
    ];

        protected function casts(): array
    {
        return [
            'in_use'    => 'boolean',
            'order'     => 'integer',
        ];
    }
}
