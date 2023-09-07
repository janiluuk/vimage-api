<?php

namespace App\JsonApi\Sorting;

use Illuminate\Support\Facades\DB;
use LaravelJsonApi\Eloquent\Contracts\SortField;
class ItemSort implements SortField
{

    /**
     * @var string
     */
    private string $name;

    /**
     * Create a new sort field.
     *
     * @param string $name
     * @param string|null $column
     * @return ItemSort
     */
    public static function make(string $name): self
    {
        return new static($name);
    }

    /**
     * ItemSort constructor.
     *
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get the name of the sort field.
     *
     * @return string
     */
    public function sortField(): string
    {
        return $this->name;
    }

    /**
     * Apply the sort order to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $direction
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function sort($query, string $direction = 'asc')
    {

        if ($this->sortField() === 'category.name') {
            $query->leftJoin('categories', 'categories.id', '=', 'items.category_id')
                  ->select('items.*');

            $query->orderBy('categories.name', $direction)->orderBy('items.id', $direction);
            return $query;
        }

        if ($this->sortField() === 'tags.name') {
            $query->leftJoin('item_tag', 'item_id', '=', 'items.id')
                  ->leftJoin('tags', 'item_tag.tag_id', '=', 'tags.id')
                  ->groupBy('items.id')
                  ->select('items.*', DB::raw('group_concat(tags.name) as ctags'));

            $query->orderBy('ctags', $direction)->orderBy('items.id', $direction);
            return $query;
        }

        $query->orderBy($this->sortField(), $direction);
    }


}
