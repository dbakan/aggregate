<?php

namespace Watson\Aggregate;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AggregateServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->registerEloquentBuilderMacros();
        // $this->registerBuilderMacros();
        $this->registerGrammarMacros();
        $this->registerRelationMacros();
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        //
    }

    /**
     * Register the additional aggregate macros with the eloquent builder.
     *
     * @return void
     */
    protected function registerEloquentBuilderMacros()
    {
        /**
         * Parse a list of relations into individuals.
         *
         * @param  array  $relations
         * @return array
         */
        /*
        EloquentBuilder::macro('parseWithAggregateRelations', function ($relations) {
        });
        */

        EloquentBuilder::macro('withAggregate', function ($relations) {
            if (empty($relations)) {
                return $this;
            }

            if (is_null($this->query->columns)) {
                $this->query->select([$this->query->from.'.*']);
            }

            $relations = is_array($relations) ? $relations : [$relations];

            foreach ($relations as $name => $constraints) {

                if (is_numeric($name)) {
                    throw new \Exception("no numeric keys here.");
                }

                $segments = explode(' ', $name);

                if (count($segments) == 3 && Str::lower($segments[1]) == 'as') {
                    list($name, $alias) = [$segments[0], $segments[2]];
                } else {
                    $alias = null;
                }

                if (Str::contains($name, '.')) {
                    list($relationName, $column) = explode('.', $name, 2);
                } else {
                    // TODO: throw exception to force even COUNT() to wrap this itself
                    $relationName = $name;
                    $column = '*';
                }

                $relation = $this->getRelationWithoutConstraints($relationName);

                if ( is_string($constraints) ) {
                    $aggregateAlias = $constraints;
                    $columns = new Expression("$constraints(".(
                        $column === '*'
                        ? $column
                        : $relation->getRelated()->qualifyColumn($column)
                    ).")");
                    $constraints = static function ($query) use($constraints, $column, $relation) {
                        // $query->select("$constraints(".$relation->getRelated()->qualifyColumn($column).")");
                    };
                } else {
                    // dd([$name, $constraints]);
                    // $columns = $constraints->columns;
                    $aggregateAlias = 'aggregate';
                    $columns = null;
                }


                $query = $relation->getRelationExistenceAggregatesQuery(
                    $relation->getRelated()->newQuery(),
                    $this,
                    $constraints,
                    $columns
                    // (
                    //     is_null($column) || $column === '*'
                    //     ? $column
                    //     : $relation->getRelated()->qualifyColumn($column)
                    // )
                );

                $query->callScope($constraints);

                $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

                // if (count($query->columns) > 1) {
                //     $query->columns = [$query->columns[0]];
                // }

                // $columnAlias = $alias ?? Str::snake($relationName.'_'.strtolower($aggregate)).($column !== '*' ? '_'.$column : '');
                $columnAlias = $alias ?? Str::snake(
                    collect([
                        $relationName,
                        // $query->columns[0],
                        (Str::endsWith($column, '*') ? null : $column),
                        strtolower($aggregateAlias),
                    ])
                    ->filter()
                    ->join('_')
                );

                $this->selectSub($query, $columnAlias);
            }

            return $this;
        });

        EloquentBuilder::macro('withCounty', function ($relations) {
            $relations = is_array($relations) ? $relations : [$relations];
            // Add optional '.*' for convienience (typo??)
            // TODO: add Test to prevent 'stores as total' becoming '(select count from ...) as total.*'
            // TODO: make sure it works with and without `those.ticks`
            $results = [];
            foreach ($this->parseWithAggregateRelations($relations) as $name => $constraints) {
                $segments = explode(' ', $name);

                if (count($segments) == 3 && Str::lower($segments[1]) == 'as') {
                    list($name, $alias) = [$segments[0], $segments[2]];
                }
                if (!Str::contains($name, '.')) {
                    $name = $name.'.*';
                }
                if ($alias) {
                    $name = $name.' as '.$alias;
                }

                $results[$name] = $constraints;
            }

            return $this->withAggregate($results, 'count');
        });

        EloquentBuilder::macro('wrapBasicAggregate', function ($relations, $functionName) {
            $relations = is_array($relations) ? $relations : [$relations];

            $results = [];

            foreach ($relations as $name => $constraints) {
                if (is_numeric($name)) {
                    $name = $constraints;
                    $constraints = $functionName;
                }

                $results[$name] = $constraints;
            }

            return $this->withAggregate($results);
        });

        EloquentBuilder::macro('withSum', function ($relations) {
            return $this->wrapBasicAggregate(func_get_args(), 'sum');
        });

        EloquentBuilder::macro('withAvg', function ($relations) {
            return $this->wrapBasicAggregate(func_get_args(), 'avg');
        });

        EloquentBuilder::macro('withMax', function ($relations) {
            return $this->wrapBasicAggregate(func_get_args(), 'max');
        });

        EloquentBuilder::macro('withMin', function ($relations) {
            return $this->wrapBasicAggregate(func_get_args(), 'min');
        });

        // EloquentBuilder::macro('withArray', function ($relations) {
        //     return $this->wrapBasicAggregate(
        //         func_get_args(),
        //         $this->getQuery()->grammar->getJsonArrayAggregateFunctionName(),
        //         'array'
        //     );
        // });
    }

    /**
     * Register the additional macros with the supported grammar classes.
     *
     * @return void
     */
    protected function registerGrammarMacros()
    {
        Grammar::macro('getJsonArrayAggregateFunctionName', function () {
            if ($this instanceof MySqlGrammar) {
                return 'json_arrayagg';
            }
            if ($this instanceof SQLiteGrammar) {
                return 'json_group_array';
            }

            throw new RuntimeException('This database engine does not support JSON array aggregate operations.');
        });

        // the different Grammar classes share the same $macros array.
        // we cannot register different macros per Grammar.
        // .e.g.
        // MySqlGrammar::macro('helloWorlMacro', function() { });
        // SqliteGrammar::hasMacro('helloWorlMacro') => true
        /*
        MySqlGrammar::macro('getJsonArrayAggregateFunctionName', function() {
            return 'json_arrayagg';
        });

        SQLiteGrammar::macro('getJsonArrayAggregateFunctionName', function() {
            return 'json_group_array';
        });
        */
    }

    /**
     * Register the additional macros with the relation class.
     *
     * @return void
     */
    protected function registerRelationMacros()
    {
        Relation::macro('getRelationExistenceAggregatesQuery', function (EloquentBuilder $query, EloquentBuilder $parentQuery, $aggregate, $column) {
            return $this->getRelationExistenceQuery(
                $query,
                $parentQuery,
                new Expression($column),
                // new Expression($column ? $aggregate."({$column})" : $aggregate)
            )->setBindings([], 'select');
        });
    }
}
