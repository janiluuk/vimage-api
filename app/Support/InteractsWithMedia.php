<?php

namespace App\Support;

use Spatie\MediaLibrary\InteractsWithMedia as BaseInteractsWithMedia;

trait InteractsWithMedia
{
    use BaseInteractsWithMedia;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function morphOneMedia()
    {
        return $this->morphOne(config('media-library.media_model'), 'model')->orderBy('order_column');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function morphManyMedia()
    {
        return $this->morphMany(config('media-library.media_model'), 'model')->orderBy('order_column');
    }
}