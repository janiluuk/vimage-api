<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductProperty extends Model
{
    protected $table = 'product_property';

    protected $fillable = ['property_id', 'product_id', 'value'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}