<?php

namespace Redmix0901\ElasticResource;

use Exception;
use ReflectionMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

trait BuildFromElasticsearch
{
    /**
     * Create a result collection of models from plain elasticsearch result.
     *
     * @param  array  $result
     * @return \Collection
     */
    public function newFromElastisearch(array $result)
    {
        $items = $result['hits']['hits'] ?? [];

        $instance = new static;

        return collect($items)->map(function ($item) use ($instance) {
            return $instance->newFromHitBuilder($item);
        });
    }

    /**
     * New From Hit Builder
     *
     * Variation on newFromBuilder. Instead, takes
     *
     * @param array $hit
     *
     * @return static
     */
    public function newFromHitBuilder($hit = array())
    {
        $key_name = $this->getKeyName();
        
        $attributes = $hit['_source'] ?? [];

        // if (isset($hit['_id'])) {
        //     $idAsInteger = intval($hit['_id']);
        //     $attributes[$key_name] = $idAsInteger ? $idAsInteger : $hit['_id'];
        // }

        // Add fields to attributes
        if (isset($hit['fields'])) {
            foreach ($hit['fields'] as $key => $value) {
                $attributes[$key] = $value;
            }
        }

        $instance = $this::newFromBuilderRecursive($this, $attributes);

        // In addition to setting the attributes
        // from the index, we will set the score as well.
        $instance->documentScore = $hit['_score'] = 0;

        // This is now a model created
        // from an Elasticsearch document.
        $instance->isDocument = true;

        // Set our document version if it's
        if (isset($hit['_version'])) {
            $instance->documentVersion = $hit['_version'] ?? 0;
        }

        return $instance;
    }

    /**
     * Create a new model instance that is existing recursive.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $attributes
     * @param  \Illuminate\Database\Eloquent\Relations\Relation  $parentRelation
     * @return static
     */
    public static function newFromBuilderRecursive(Model $model, array $attributes = [], Relation $parentRelation = null)
    {
        $instance = $model->newInstance([], $exists = true);

        $instance->setRawAttributes((array)$attributes, $sync = true);

        // Load relations recursive
        static::loadRelationsAttributesRecursive($instance);
        // Load pivot
        static::loadPivotAttribute($instance, $parentRelation);

        return $instance;
    }

    /**
     * Create a collection of models from plain arrays recursive.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @param  \Illuminate\Database\Eloquent\Relations\Relation $parentRelation
     * @param  array $items
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function hydrateRecursive(Model $model, array $items, Relation $parentRelation = null)
    {
        $instance = $model;

        $items = array_map(function ($item) use ($instance, $parentRelation) {
            // Convert all null relations into empty arrays
            $item = $item ?: [];
            
            return static::newFromBuilderRecursive($instance, $item, $parentRelation);
        }, $items);

        return $instance->newCollection($items);
    }

    /**
     * Get the relations attributes from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     */
    public static function loadRelationsAttributesRecursive(Model $model)
    {
        $attributes = $model->getAttributes();

        foreach ($attributes as $key => $value) {
            if (method_exists($model, $key)) {
                $reflection_method = new ReflectionMethod($model, $key);

                // Check if method class has or inherits Illuminate\Database\Eloquent\Model
                if (!static::isClassInClass("Illuminate\Database\Eloquent\Model", $reflection_method->class)) {
                    $relation = $model->$key();

                    if ($relation instanceof Relation) {
                        // Check if the relation field is single model or collections
                        if (is_null($value) === true || !static::isMultiLevelArray($value)) {
                            $value = [$value];
                        }

                        $models = static::hydrateRecursive($relation->getModel(), $value, $relation);

                        // Unset attribute before match relation
                        unset($model[$key]);
                        $relation->match([$model], $models, $key);
                    }
                }
            }
        }
    }

    /**
     * Get the pivot attribute from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Eloquent\Relations\Relation  $parentRelation
     */
    public static function loadPivotAttribute(Model $model, Relation $parentRelation = null)
    {
        $attributes = $model->getAttributes();

        foreach ($attributes as $key => $value) {
            if ($key === 'pivot') {
                unset($model[$key]);
                $pivot = $parentRelation->newExistingPivot($value);
                $model->setRelation($key, $pivot);
            }
        }
    }

    /**
     * Check if an array is multi-level array like [[id], [id], [id]].
     *
     * For detect if a relation field is single model or collections.
     *
     * @param  array  $array
     * @return boolean
     */
    private static function isMultiLevelArray(array $array)
    {
        foreach ($array as $key => $value) {
            if (!is_array($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check the hierarchy of the given class (including the given class itself)
     * to find out if the class is part of the other class.
     *
     * @param string $classNeedle
     * @param string $classHaystack
     * @return bool
     */
    private static function isClassInClass($classNeedle, $classHaystack)
    {
        // Check for the same
        if ($classNeedle == $classHaystack) {
            return true;
        }

        // Check for parent
        $classHaystackReflected = new \ReflectionClass($classHaystack);
        while ($parent = $classHaystackReflected->getParentClass()) {
            /**
             * @var \ReflectionClass $parent
             */
            if ($parent->getName() == $classNeedle) {
                return true;
            }
            $classHaystackReflected = $parent;
        }

        return false;
    }
}
