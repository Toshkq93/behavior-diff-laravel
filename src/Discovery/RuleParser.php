<?php

namespace Toshkq93\BehaviorDiffLaravel\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Toshkq93\BehaviorDiff\Discovery\Param;

/**
 * Turns Laravel validation rules into parameters with a type and known values.
 *
 *   'title' => 'required|string|max:255'  -> string, required, max length 255
 *   'user_id' => 'exists:users,id'         -> int, values: two ids from the users table
 *   'status' => Rule::in(['a', 'b'])       -> values: a, b
 */
final class RuleParser
{
    /** @var array<string, list<mixed>> */
    private $existsCache = [];

    /**
     * @param array<string, mixed> $rules field => "a|b" | ['a', 'b', Rule]
     * @return list<Param>
     */
    public function parse(array $rules, string $in): array
    {
        $params = [];

        foreach ($rules as $field => $fieldRules) {
            $field = (string) $field;

            // Nested fields (items.*.id) are covered by their parent array.
            if ($field === '' || strpos($field, '.') !== false || strpos($field, '*') !== false) {
                continue;
            }

            $params[] = $this->param($field, $this->normalize($fieldRules), $in);
        }

        return $params;
    }

    /**
     * @param list<string> $rules
     */
    private function param(string $field, array $rules, string $in): Param
    {
        $type = null;
        $required = false;
        $values = [];
        $max = null;

        foreach ($rules as $rule) {
            $parts = explode(':', $rule, 2);
            $name = strtolower(trim($parts[0]));
            $argument = isset($parts[1]) ? $parts[1] : '';

            switch ($name) {
                case 'required':
                case 'present':
                case 'accepted':
                    $required = true;
                    break;
                case 'integer':
                case 'int':
                case 'digits':
                case 'digits_between':
                    $type = Param::TYPE_INT;
                    break;
                case 'numeric':
                case 'decimal':
                    $type = $type !== null ? $type : Param::TYPE_FLOAT;
                    break;
                case 'boolean':
                case 'bool':
                    $type = Param::TYPE_BOOL;
                    break;
                case 'email':
                    $type = Param::TYPE_EMAIL;
                    break;
                case 'date':
                case 'date_format':
                case 'after':
                case 'before':
                case 'after_or_equal':
                case 'before_or_equal':
                    $type = Param::TYPE_DATE;
                    break;
                case 'array':
                case 'list':
                    $type = Param::TYPE_ARRAY;
                    break;
                case 'string':
                    $type = $type !== null ? $type : Param::TYPE_STRING;
                    break;
                case 'max':
                    $max = is_numeric($argument) ? (int) $argument : null;
                    break;
                case 'in':
                    $values = self::csv($argument);
                    break;
                case 'exists':
                    $values = $values !== [] ? $values : $this->existing($argument, $field);
                    $type = $type !== null ? $type : (self::allNumeric($values) ? Param::TYPE_INT : null);
                    break;
                case 'enum':
                    $values = $argument !== '' ? explode(',', $argument) : $values;
                    break;
            }
        }

        if ($type === null) {
            $type = Param::guessType($field);
        }

        // The max length of a string is a size limit only for strings.
        return new Param($field, $in, $type, $required, array_values($values), $type === Param::TYPE_STRING ? $max : null);
    }

    /**
     * @param mixed $rules
     * @return list<string>
     */
    private function normalize($rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (! is_array($rules)) {
            $rules = [$rules];
        }

        $result = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $result = array_merge($result, explode('|', $rule));
            } elseif ($rule instanceof \Illuminate\Validation\Rules\Enum) {
                $result[] = 'enum:'.implode(',', self::enumValues($rule));
            } elseif (is_object($rule) && method_exists($rule, '__toString')) {
                $result[] = (string) $rule;
            }
        }

        return $result;
    }

    /**
     * Two existing values for exists:table,column; empty when the table cannot be read.
     *
     * @return list<mixed>
     */
    private function existing(string $argument, string $field): array
    {
        if (isset($this->existsCache[$argument.'|'.$field])) {
            return $this->existsCache[$argument.'|'.$field];
        }

        $parts = self::csv($argument);
        $table = isset($parts[0]) ? (string) $parts[0] : '';
        $column = isset($parts[1]) && $parts[1] !== 'NULL' ? (string) $parts[1] : $field;
        $connection = null;

        if (class_exists($table) && is_subclass_of($table, Model::class)) {
            /** @var Model $model */
            $model = new $table();
            $connection = $model->getConnectionName();
            $table = $model->getTable();
        } elseif (strpos($table, '.') !== false) {
            list($connection, $table) = explode('.', $table, 2);
        }

        try {
            $values = DB::connection($connection)->table($table)->orderBy($column)->limit(2)->pluck($column)->all();
        } catch (\Throwable $e) {
            $values = [];
        }

        // PDO before PHP 8.1 returns integers as strings; keep the cases file the same on every version.
        $values = array_map(function ($value) {
            return is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) && (string) (int) $value === $value
                ? (int) $value
                : $value;
        }, $values);

        return $this->existsCache[$argument.'|'.$field] = array_values($values);
    }

    /** @return list<string> */
    private static function csv(string $argument): array
    {
        if ($argument === '') {
            return [];
        }

        return array_map(function ($value) {
            return trim($value, " \"'");
        }, str_getcsv($argument, ',', '"', '\\'));
    }

    /** @param list<mixed> $values */
    private static function allNumeric(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function enumValues($rule): array
    {
        try {
            $property = new \ReflectionProperty($rule, 'type');
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true); // a no-op and deprecated since PHP 8.1
            }
            $type = $property->getValue($rule);

            if (is_string($type) && function_exists('enum_exists') && enum_exists($type)) {
                return array_map(function ($case) {
                    return property_exists($case, 'value') ? (string) $case->value : $case->name;
                }, $type::cases());
            }
        } catch (\Throwable $e) {
            // Unknown structure in this Laravel version.
        }

        return [];
    }
}
