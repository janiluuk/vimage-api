<?php

namespace App\JsonApi\V1\VideoJobs;

use App\Models\Videojob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsToMany;
use LaravelJsonApi\Eloquent\Fields\Relations\MorphToMany;
use LaravelJsonApi\Eloquent\Filters\Where;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Pagination\PagePagination;
use LaravelJsonApi\Eloquent\Schema;

use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Sorting\SortCountable;

/**
 * Summary of VideoJobSchema
 */
class VideoJobSchema extends Schema
{

    /**
     * The model the schema corresponds to.
     *
     * @var string
     */
    public static string $model = Videojob::class;

    /**
     * Get the resource fields.
     *
     * @return array
     */
    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('filename'),
            Str::make('original_filename'),
            BelongsTo::make('modelfile')->type('model-files')->readOnly(),
            BelongsTo::make('user')->type('users')->readOnly(),
            MorphToMany::make('media', [
                BelongsToMany::make('finished'),
                BelongsToMany::make('previewImages'),
            ]),
             Number::make('user_id'),
            Str::make('status')->sortable()->readOnly(),
            Str::make('url'),
            Str::make('media'),
            Str::make('original_filename'),
            Str::make('prompt'),
            Str::make('negative_prompt'),
            Str::make('controlnet'),
            Str::make('denoising'),
            Str::make('generation_parameters'),
            Str::make('preview_url'),
            Str::make('preview_img'),
            Str::make('preview_animation'),
            Str::make('mimetype'),
            Str::make('audio_codec'),
            Str::make('outfile'),
            Str::make('codec'),
            Number::make('fps'),
            Number::make('job_time'),
            Number::make('estimated_time_left'),
            Number::make('length'),
            Number::make('progress'),
            Number::make('model_id'),
            Number::make('bitrate'),
            Number::make('size'),
            Number::make('width'),
            Number::make('height'),
            Number::make('frame_count'),
            DateTime::make('created_at')->sortable()->readOnly(),
            DateTime::make('updated_at')->sortable()->readOnly(),
        ];
    }

    /**
     * Get the resource filters.
     *
     * @return array
     */
    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
            Where::make('status')

        ];
    }

    /**
     * Get the resource paginator.
     *
     * @return Paginator|null
     */
    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }
    
    /**
     * Build an "index" query for the given resource.
     *
     * @param  \Illuminate\Http\Request|null         $request
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function indexQuery(?Request $request, Builder $query): Builder
    {
        $user = $request->user();   
        if (!$user) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public function meta()
    {
        return ['foo' => 'bar'];
    }
    /**
     * Get sortable
     *
     * @return iterable
     */
    public function sortables(): iterable
    {
        return [
            SortCountable::make($this, 'updated_at'),
            SortCountable::make($this, 'created_at'),
        ];
    }
    /**
     * Determine if the resource is authorizable.
     *
     * @return bool
     */
    public function authorizable(): bool
    {
        return false;
    }

}
