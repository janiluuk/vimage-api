<?php

namespace App\JsonApi\Sorting;

use LaravelJsonApi\Eloquent\Contracts\SortField;

class UserSort implements SortField
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
     * @return UserSort
     */
    public static function make(string $name): self
    {
        return new static($name);
    }

    /**
     * UserSort constructor.
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
        $column = $this->sortField();

        if ($column === 'roles.name') {
            $query->leftJoin( 'model_has_roles', 'model_id', '=', 'users.id' )
                  ->leftjoin( 'roles', 'model_has_roles.role_id', '=', 'roles.id' )
                  ->groupBy('users.id')
                  ->select('users.*', DB::raw('group_concat(roles.name) as croles'));

            $query->orderBy('croles', $direction)->orderBy('users.id', $direction);
            return $query;
        }
        $query->orderBy($column, $direction);
        return $query;
    }

}
