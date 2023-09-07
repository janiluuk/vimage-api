<?php

namespace App\Support;

use Spatie\MediaLibrary\HasMedia\HasMediaTrait as BaseHasMediaTrait;

trait HasMediaTrait
{
    use BaseHasMediaTrait;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function morphOneMedia()
    {
        return $this->morphOne(config('medialibrary.media_model'), 'model')->orderBy('order_column');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function morphManyMedia()
    {
        return $this->morphMany(config('medialibrary.media_model'), 'model')->orderBy('order_column');
    }
}