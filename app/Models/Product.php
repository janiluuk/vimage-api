<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'vendor_code',
        'title',
        'slug',
        'category_id',
        'description',
        'short_description',
        'warning_description',
        'old_price',
        'price',
        'quantity',
        'status',
        'options',
    ];

    protected $appends = ['preview'];

    protected $with = ['category'];

    protected $casts = [
        'price' => 'float',
        'quantity' => 'integer',
        'status' => 'text',
        'options' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class)->withPivot('value');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function scopeSearched(Builder $query): void
    {
        $query->when(request('search'), function (Builder $q) {
            $q->whereFullText(['title', 'description'], request('search'));
        });
    }

    public static function getFilterableProperties(Collection $goods): Collection
    {
        $singleProperties = collect();

        $goods->each(function (Product $product) use ($singleProperties) {
            $product->properties
                ->filter(fn (Property $property) => $property->filterable)
                ->each(fn (Property $property) => $singleProperties->push([
                    'product_id' => $property->pivot->product_id,
                    'property_id' => $property->pivot->property_id,
                    'name' => $property->name,
                    'slug' => $property->slug,
                    'value' => $property->pivot->value,
                ]));
        });

        $groupedValues = $singleProperties->mapToGroups(fn (array $item) => [$item['property_id'] => $item['value']]);

        $properties = collect();

        $singleProperties->each(function (array $item) use ($groupedValues, $properties) {
            $properties->getOrPut($item['property_id'], [
                'property_id' => $item['property_id'],
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'slug' => $item['slug'],
                'values' => $groupedValues->get($item['property_id'])->toArray(),
            ]);
        });

        return $properties;
    }

    protected function preview(): Attribute
    {
        return Attribute::get(fn () => $this->hasMedia('products') ? $this->getFirstMediaUrl('products') : url('static/not-found.svg'));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}