<?php

namespace paKanhu\MyORM;

use PDO;
use Exception;

abstract class Model
{
    /**
     * The database connection for the model.
     * @var null|Database
     */
    protected static $dbc = null;

    /**
     * The primary key for the model.
     * @var string
     */
    protected static $primaryColumn = 'id';

    /**
     * The table associated with the model.
     * @var string
     */
    protected static $tableName;

    /**
     * The fractional timestamps in the model.
     * @var array
     */
    protected static $fractionalTimestamps = [];

    /**
     * The protected properties of the model which cannot be accessed directly outside
     * @var array
     */
    protected static $guarded = [];

    /**
     * Get the database connection required to access database
     * @return Database
     */
    protected static function getDatabaseConnection()
    {
        if (static::$dbc === null) {
            static::$dbc = Database::getConnection();
        }

        return static::$dbc;
    }

    /**
     * Converts a string to snake case
     * @param  string $str
     * @return string
     */
    protected static function toSnakeCase($str)
    {
        $str[0] = strtolower($str[0]);
        return preg_replace_callback('/([A-Z])/', function ($matches) {
            return '_' . strtolower($matches[1]);
        }, $str);
    }

    /**
     * Converts a string to camel case
     * @param  string  $str
     * @param  boolean $capitaliseFirstChar
     * @return string
     */
    protected static function toCamelCase($str, $capitaliseFirstChar = false)
    {
        if ($capitaliseFirstChar) {
            $str[0] = strtoupper($str[0]);
        } else {
            $str[0] = strtolower($str[0]);
        }

        if (strpos($str, '_') === false) {
            return $str;
        }

        $str = strtolower($str);

        return preg_replace_callback('/_([a-z])/', function ($matches) {
            return strtoupper($matches[1]);
        }, $str);
    }

    /**
     * Dynamically set attributes on the model.
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function __set($name, $value)
    {
        $name = static::toCamelCase($name);
        $this->$name = $value;
    }

    /**
     * Dynamically retrieve attributes on the model.
     * @param  string    $name
     * @throws Exception
     * @return mixed
     */
    public function __get($name)
    {
        $methodName = 'get' . static::toCamelCase($name, true);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        if (!in_array($name, static::$guarded, true) && property_exists($this, $name)) {
            return $this->$name;
        }

        throw new Exception("'{$name}' is not defined.", 1);
    }

    /**
     * Handles dynamically called non static methods
     * @param  string $name
     * @param  array  $arguments
     * @param  array  $options
     * @return mixed
     */
    protected function callNonStatic($name, $arguments, $options)
    {
        $matchType = '';

        if (preg_match('/^get([A-Z].+)$/', $name, $options)) {
            $matchType = 'get';
            $filters = [
                'select'     => [static::toCamelCase($options[1])],
                'onlyValues' => true,
                'isUnique'   => true,
                'where'      => [[
                    'column' => static::$primaryColumn,
                    'value'  => $this->{static::$primaryColumn},
                ]],
            ];
        }

        switch ($matchType) {
            case 'get':
                return static::getAll($filters);

            default:
                return false;
        }
    }

    /**
     * Handles dynamically called static methods
     * @param  string $name
     * @param  array  $arguments
     * @param  array  $options
     * @return mixed
     */
    protected static function callStatic($name, $arguments, $options)
    {
        $matchType = '';

        if (preg_match('/^getBy([A-Z].+)$/', $name, $options)) {
            $matchType = 'getBy';
            $filters = (!empty($arguments[1]) && is_array($arguments[1])) ? $arguments[1] : [];
            $filters['where'][] = ['column' => static::toCamelCase($options[1]), 'value' => $arguments[0]];

            if ($name === 'getBy' . static::toCamelCase(static::$primaryColumn, true)) {
                $filters['isUnique'] = true;
            }
        }

        switch ($matchType) {
            case 'getBy':
                return static::getAll($filters);

            default:
                return false;
        }
    }

    /**
     * Validated MySQL database and column names
     * @param  string  $name
     * @return boolean
     */
    protected static function isValidMySQLName($name)
    {
        if (preg_match('/^(?![0-9$]*$)[a-zA-Z0-9_$]+$/', static::$tableName)) {
            return true;
        }

        error_log('Invalid database/table name: ' . static::$tableName);
        error_log(static::generateCallTrace());
        return false;
    }

    /**
     * Provides current class FQCN.
     * @return string
     */
    protected static function getStaticClass()
    {
        return static::class;
    }

    /**
     * Provides a nice and comprehensible call trace
     * @author <jurchiks101 at gmail dot com>
     *
     * @link  http://php.net/manual/en/function.debug-backtrace.php#112238
     *
     * @return string Call trace
     */
    protected static function generateCallTrace()
    {
        $e = new Exception();
        $trace = explode("\n", $e->getTraceAsString());
        $trace = array_reverse($trace);
        array_shift($trace);
        array_pop($trace);
        $length = count($trace);
        $result = [];

        for ($i = 0; $i < $length; $i++) {
            $result[] = ($i + 1) . ')' .
            substr($trace[$i], strpos($trace[$i], ' '));
        }

        return "\t" . implode("\n\t", $result);
    }

    /**
     * Handles dynamically called methods
     * @param  string    $name
     * @param  array     $arguments
     * @throws Exception
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (preg_match('/^getBy([A-Z].+)$/', $name, $matches)
            && isset($arguments[0])
            && property_exists(static::getStaticClass(), static::toCamelCase($matches[1]))
        ) {
            $data = static::callStatic($name, $arguments, $matches);

            return $data;
        }

        if (preg_match('/^get([A-Z].+)$/', $name, $matches)
            && property_exists(static::getStaticClass(), static::toCamelCase($matches[1]))
        ) {
            $data = $this->callNonStatic($name, $arguments, $matches);
            $this->{static::toCamelCase($matches[1])} = $data;

            return $this->{static::toCamelCase($matches[1])};
        }

        throw new Exception("Method \"{$name}\" is not defined.", 1);
    }

    /**
     * Handles dynamically handled static methods
     * @param  string    $name
     * @param  array     $arguments
     * @throws Exception
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        if (preg_match('/^getBy([A-Z].+)$/', $name, $matches)
            && isset($arguments[0])
            && property_exists(static::getStaticClass(), static::toCamelCase($matches[1]))
        ) {
            $data = static::callStatic($name, $arguments, $matches);

            return $data;
        }

        throw new Exception("Static Method \"{$name}\" is not defined.", 1);
    }

    /**
     * Creates where condition SQL query string from where condition filter
     * @param  array  $where
     * @param  string $tableClause
     * @param  array  $values
     * @return array
     */
    protected static function getWhereConditionQuery($where, $tableClause, $values = [])
    {
        $allowedConditions = [
            '=',
            '!=',
            '<',
            '>',
            '<=',
            '>=',
            '<>',
            '<=>',
            'LIKE',
            'NOT LIKE',
            'BETWEEN',
            'NOT BETWEEN',
            'IN',
            'NOT IN',
        ];

        $columnName = $where['column'] . count($values);
        $conditionType = '';
        $query = '';
        $condition = '=';

        if (isset($where['condition']) && in_array(strtoupper($where['condition']), $allowedConditions)) {
            $condition = $where['condition'];
        }

        switch ($condition) {
            case 'NOT BETWEEN':
                $conditionType = $conditionType ? $conditionType : 'notbtw';
            // Fall through
            // no break
            case 'BETWEEN':
                $conditionType = $conditionType ? $conditionType : 'btw';
                if (!is_array($where['value'])
                    || !isset($where['value'][0])
                    || !isset($where['value'][1])
                ) {
                    error_log('BETWEEN condition expects an array with two values.');
                    error_log(static::generateCallTrace());
                    return false;
                }

                $query = "({$tableClause}.`" . static::toSnakeCase($where['column']) .
                    "` {$condition} :{$columnName}{$conditionType}0 AND" .
                    " :{$columnName}{$conditionType}1)";

                $values[":{$columnName}{$conditionType}0"] = $where['value'][0];
                $values[":{$columnName}{$conditionType}1"] = $where['value'][1];
                break;

            case 'NOT IN':
                $conditionType = $conditionType ? $conditionType : 'notin';
            // Fall through
            // no break
            case 'IN':
                $conditionType = $conditionType ? $conditionType : 'in';
                if (!is_array($where['value']) || !count($where['value'])) {
                    error_log('IN condition expects a non-empty array.');
                    error_log(static::generateCallTrace());
                    return false;
                }

                $inQueryParts = [];
                $where['value'] = array_values($where['value']);

                foreach ($where['value'] as $key => $whereValue) {
                    $inQueryParts[] = ":{$columnName}{$conditionType}{$key}";

                    $values[":{$columnName}{$conditionType}{$key}"] = $whereValue;
                }

                $inQuery = '(' . implode(', ', $inQueryParts) . ')';
                $query = "({$tableClause}.`" . static::toSnakeCase($where['column'])
                    . "` {$condition} {$inQuery})";
                break;

            default:
                $query = "({$tableClause}.`" .
                static::toSnakeCase($where['column']) . "` {$condition} :{$columnName})";

                $values[":{$columnName}"] = $where['value'];
                break;
        }

        return ['query' => $query, 'values' => $values];
    }

    /**
     * Creates parametrised prepared statement where condition query and values
     * @param  array         $where
     * @param  string        $tableClause
     * @param  array         $values
     * @return boolean|array
     */
    protected static function getWhereQuery($where, $tableClause, $values = [])
    {
        $allowedOperators = ['AND', 'OR'];

        if (isset($where['operands']) && isset($where['operator'])) {
            if (!in_array(strtoupper($where['operator']), $allowedOperators)) {
                error_log("INVALID OPERATOR - {$where['operator']} in " . static::getStaticClass());
                error_log(static::generateCallTrace());
                return false;
            }

            $queryParts = [];

            foreach ($where['operands'] as $operand) {
                $data = static::getWhereQuery($operand, $tableClause, $values);
                $queryParts[] = $data['query'];
                $values = $data['values'];
            }

            $query = '(' . implode(strtoupper($where['operator']), $queryParts) . ')';

            return ['query' => $query, 'values' => $values];
        }

        if (isset($where['column'])
            && isset($where['value'])
            && property_exists(static::getStaticClass(), $where['column'])
        ) {
            return static::getWhereConditionQuery($where, $tableClause, $values);
        }

        error_log('INVALID WHERE in ' . static::getStaticClass());
        error_log(static::generateCallTrace());
        return false;
    }

    /**
     * Retrieves model/s from database
     * @param  array                         $filters
     * @return boolean|static|array|static[]
     */
    public static function getAll($filters = [])
    {
        $values = [];
        $databaseClause = '';
        $tableClause = '`' . static::$tableName . '`';
        $selectClause = '*';
        $joinClause = '';
        $whereClause = '';
        $orderByClause = '';
        $limitClause = '';
        $onlyValueKey = '';
        $isUnique = false;
        $onlyCount = false;
        $isAllSelect = true;
        $onlyValues = false;
        $isDistinct = false;

        if (!static::isValidMySQLName(static::$tableName)) {
            return false;
        }

        if (empty($filters['fractionalTimestamps'])) {
            $filters['fractionalTimestamps'] = static::$fractionalTimestamps;
        }

        if (!empty($filters['isUnique'])) {
            $isUnique = true;
        }

        if (!empty($filters['database'])) {
            if (!static::isValidMySQLName($filters['database'])) {
                return false;
            }

            $databaseClause = "`{$filters['database']}`";
            $tableClause = "{$databaseClause}.{$tableClause}";
        }

        if (!empty($filters['select']) && is_array($filters['select'])) {
            $selectClauses = [];
            foreach ($filters['select'] as $selectColumn) {
                if (!property_exists(static::getStaticClass(), $selectColumn)) {
                    error_log("SELECT CLAUSE - {$selectColumn} does not exist in " . static::getStaticClass());
                    error_log(static::generateCallTrace());
                    return false;
                }

                $onlyValueKey = $selectColumn;
                $selectClauses[] = "{$tableClause}.`" . static::toSnakeCase($selectColumn) . '`';
            }

            if (!empty($filters['with']) && is_array($filters['with'])) {
                $withSelectColumns = array_column($filters['with'], 'column');

                foreach ($withSelectColumns as $withSelectColumn) {
                    if (!property_exists(static::getStaticClass(), $withSelectColumn)) {
                        error_log("SELECT CLAUSE - {$withSelectColumn} does not exist in " . static::getStaticClass());
                        error_log(static::generateCallTrace());
                        return false;
                    }

                    $onlyValueKey = $withSelectColumn;
                    $selectClauses[] = "{$tableClause}.`" . static::toSnakeCase($withSelectColumn) . '`';
                }
            }

            if (empty($selectClauses)) {
                return false;
            }

            $selectClauses = array_values(array_unique($selectClauses));
            $selectClause = implode(', ', $selectClauses);
            $isAllSelect = false;

            if (count($selectClauses) === 1) {
                if (!empty($filters['onlyValues'])) {
                    $onlyValues = true;
                }

                if (!empty($filters['isDistinct'])) {
                    $selectClause = "DISTINCT({$selectClause})";
                    $isDistinct = true;
                }
            }
        } else {
            $fractionalTimestampClause = '';
            if (!empty($filters['fractionalTimestamps'])) {
                $fractionalTimestamps = $filters['fractionalTimestamps'];
                $fractionalTimestampClauseParts = [];
                foreach ($fractionalTimestamps as $fractionalTimestamp) {
                    if (!empty($fractionalTimestamp) &&
                        property_exists(static::getStaticClass(), $fractionalTimestamp)) {
                        $fractionalTimestampClauseParts[] = "CONCAT({$tableClause}.`"
                        . static::toSnakeCase($fractionalTimestamp) . '`) AS `'
                        . static::toSnakeCase("{$fractionalTimestamp}Fractional") . '`';
                    }
                }

                $fractionalTimestampClause = implode($fractionalTimestampClauseParts, ', ');

                $fractionalTimestampClause = $fractionalTimestampClause ? ", {$fractionalTimestampClause}" : '';
            }

            $selectClause = trim("{$tableClause}.{$selectClause}{$fractionalTimestampClause}");
        }

        if (!empty($filters['onlyCount'])) {
            $selectClause = "COUNT({$tableClause}.`" . static::toSnakeCase(static::$primaryColumn)
                . '`) AS `totalRowCount`';
            $onlyCount = true;
            $isAllSelect = false;
        }

        if ((!empty($filters['where']) && is_array($filters['where']))
            || (!empty($filters['whereOr']) && is_array($filters['whereOr']))
        ) {
            if (isset($filters['where']['operands']) && isset($filters['where']['operator'])) {
                $whereClauseData = static::getWhereQuery($filters['where'], $tableClause, $values);
                if (!$whereClauseData) {
                    return false;
                }

                $values = $whereClauseData['values'];
                $whereClause = " WHERE {$whereClauseData['query']}";
            } else {
                $whereQueryParts = [];
                $allWhereData = !empty($filters['where']) ? $filters['where'] : $filters['whereOr'];

                foreach ($allWhereData as $whereData) {
                    if (!isset($whereData['column'])
                        || !isset($whereData['value'])
                        || !property_exists(static::getStaticClass(), $whereData['column'])
                    ) {
                        error_log('INVALID WHERE in getAll of ' . static::getStaticClass());
                        error_log(static::generateCallTrace());
                        return false;
                    }

                    $whereQueryPartsData = static::getWhereConditionQuery($whereData, $tableClause, $values);
                    if (!$whereQueryPartsData) {
                        return false;
                    }

                    $values = $whereQueryPartsData['values'];
                    $whereQueryParts[] = $whereQueryPartsData['query'];
                }

                if ($whereQueryParts) {
                    if (!empty($filters['where'])) {
                        $whereClause = ' WHERE ' . implode(' AND ', $whereQueryParts);
                    } else {
                        $whereClause = ' WHERE ' . implode(' OR ', $whereQueryParts);
                    }
                }
            }
        }

        if (!empty($filters['orderBy'])) {
            if (!empty($filters['orderBy']['column'])
                && property_exists(static::getStaticClass(), $filters['orderBy']['column'])
            ) {
                $orderByClause = " ORDER BY {$tableClause}.`"
                . static::toSnakeCase($filters['orderBy']['column']) . '`';

                if (!empty($filters['orderBy']['mode'])
                    && in_array(strtoupper($filters['orderBy']['mode']), ['ASC', 'DESC'])
                ) {
                    $orderByClause .= " {$filters['orderBy']['mode']}";
                }
            } elseif (!empty($filters['orderBy'][0]['column'])
                && property_exists(static::getStaticClass(), $filters['orderBy'][0]['column'])
            ) {
                $orderByClauseParts = [];

                foreach ($filters['orderBy'] as $orderBy) {
                    if (!empty($orderBy['column']) && property_exists(static::getStaticClass(), $orderBy['column'])) {
                        $orderByClausePart = " {$tableClause}.`" . static::toSnakeCase($orderBy['column']) . '`';

                        if (!empty($orderBy['mode']) &&
                            in_array(strtoupper($orderBy['mode']), ['ASC', 'DESC'])) {
                            $orderByClausePart .= " {$orderBy['mode']}";
                        }
                        $orderByClauseParts[] = $orderByClausePart;
                    }
                }

                $orderByClause = 'ORDER BY ' . implode(', ', $orderByClauseParts);
            }
        }

        if (!empty($filters['random']) && empty($filters['orderBy'])) {
            $orderByClause = ' ORDER BY RAND()';
        }

        if (!empty($filters['limit'])) {
            $values[':rowCount'] = (!empty($filters['limit']['rowCount']) && is_int($filters['limit']['rowCount'])) ?
            $filters['limit']['rowCount'] : '18446744073709551615';
            $values[':offset'] = (!empty($filters['limit']['offset']) && is_int($filters['limit']['offset'])) ?
            $filters['limit']['offset'] : '0';

            $limitClause = ' LIMIT :rowCount OFFSET :offset';
        } elseif ($isUnique) {
            $limitClause = ' LIMIT 1';
        }

        $query = "SELECT {$selectClause} FROM {$tableClause}{$whereClause}{$orderByClause}{$limitClause}";

        if ($onlyCount || !$isAllSelect) {
            $modelData = static::getDatabaseConnection()->execute($query, $values);
        } else {
            $modelData = static::getDatabaseConnection()->execute(
                $query,
                $values,
                '',
                PDO::FETCH_CLASS,
                static::getStaticClass()
            );
        }

        if (empty($modelData['status'])) {
            return false;
        }

        if ($onlyCount) {
            return !empty($modelData['result'][0]['totalRowCount']) ? $modelData['result'][0]['totalRowCount'] : 0;
        }

        if (!$isAllSelect) {
            foreach ($modelData['result'] as $key => $result) {
                $newResult = [];
                foreach ($result as $resultKey => $value) {
                    $newResult[static::toCamelCase($resultKey)] = $value;
                }
                $modelData['result'][$key] = $newResult;
            }
        }

        if (!$onlyCount && !empty($filters['with']) && is_array($filters['with'])) {
            foreach ($filters['with'] as $withDataKey => $withData) {
                if (isset($withData['column'])
                    && isset($withData['class'])
                    && isset($withData['property'])
                    && property_exists(static::getStaticClass(), $withData['column'])
                ) {
                    $withIds = [];
                    foreach ($modelData['result'] as $result) {
                        if ($isAllSelect) {
                            $withIds[] = $result->{$withData['column']};
                        } else {
                            $withIds[] = $result[$withData['column']];
                        }
                    }
                    $withData['foreignColumn'] = isset($withData['foreignColumn']) ?
                    $withData['foreignColumn'] : static::$primaryColumn;
                    $filters['with'][$withDataKey]['foreignColumn'] = $withData['foreignColumn'];

                    $withData['isUnique'] = isset($withData['isUnique']) ?
                    $withData['isUnique'] : true;
                    $filters['with'][$withDataKey]['isUnique'] = $withData['isUnique'];

                    $withModelDataFilters = (!empty($withData['filters']) && is_array($withData['filters'])) ?
                    $withData['filters'] : [];

                    $withModelDataFilters['where'][] = [
                        'column'    => $withData['foreignColumn'],
                        'condition' => 'IN',
                        'value'     => $withIds,
                    ];

                    if (!empty($withModelDataFilters['select'])) {
                        array_push($withModelDataFilters['select'], $withData['foreignColumn']);
                        $withModelDataFilters['select'] = array_values(
                            array_unique($withModelDataFilters['select'])
                        );
                    }

                    if (!empty($withData['database'])) {
                        $withModelDataFilters['database'] = $withData['database'];
                    }

                    if ($withIds && $withModelData = $withData['class']::getAll($withModelDataFilters)) {
                        $filters['with'][$withDataKey]['data'] = $withModelData;
                    } else {
                        $filters['with'][$withDataKey]['data'] = null;
                    }
                } elseif (isset($withData['columns']) && isset($withData['class']) && isset($withData['property'])) {
                    $withIds = [];

                    foreach ($withData['columns'] as $withDataColumnKey => $withDataColumn) {
                        if (!isset($withDataColumn['column'])) {
                            error_log("ERROR: With Data Columns 'column' does not exist in "
                                . static::getStaticClass());
                            error_log(static::generateCallTrace());
                            return false;
                        }

                        if (!property_exists(static::getStaticClass(), $withDataColumn['column'])) {
                            error_log("ERROR: With Data Columns - {$withDataColumn['column']} does not exist in "
                                . static::getStaticClass());
                            error_log(static::generateCallTrace());
                            return false;
                        }

                        $withDataColumn['foreignColumn'] = isset($withDataColumn['foreignColumn']) ?
                        $withDataColumn['foreignColumn'] : static::$primaryColumn;
                        $filters['with'][$withDataKey]['columns'][$withDataColumnKey]['foreignColumn'] =
                            $withDataColumn['foreignColumn'];

                        foreach ($modelData['result'] as $result) {
                            if ($isAllSelect) {
                                $withIds[$withDataColumn['foreignColumn']][] = $result->{$withDataColumn['column']};
                            } else {
                                $withIds[$withDataColumn['foreignColumn']][] = $result[$withDataColumn['column']];
                            }
                        }
                    }

                    $withData['isUnique'] = isset($withData['isUnique']) ?
                    $withData['isUnique'] : true;
                    $filters['with'][$withDataKey]['isUnique'] = $withData['isUnique'];

                    $withModelDataFilters = (!empty($withData['filters']) && is_array($withData['filters'])) ?
                    $withData['filters'] : [];
                    foreach ($withIds as $foreignColumn => $foreignColumnValues) {
                        $withModelDataFilters['where'][] = [
                            'column'    => $foreignColumn,
                            'condition' => 'IN',
                            'value'     => $foreignColumnValues,
                        ];
                    }

                    if (!empty($withModelDataFilters['select'])) {
                        foreach ($filters['with'][$withDataKey]['columns'] as $tempColumn) {
                            array_push($withModelDataFilters['select'], $tempColumn['foreignColumn']);
                        }

                        $withModelDataFilters['select'] = array_values(
                            array_unique($withModelDataFilters['select'])
                        );
                    }

                    if (!empty($withData['database'])) {
                        $withModelDataFilters['database'] = $withData['database'];
                    }

                    if ($withIds && $withModelData = $withData['class']::getAll($withModelDataFilters)) {
                        $filters['with'][$withDataKey]['data'] = $withModelData;
                    } else {
                        $filters['with'][$withDataKey]['data'] = null;
                    }
                } else {
                    unset($filters['with'][$withDataKey]);
                }
            }

            foreach ($filters['with'] as $withDataKey => $withData) {
                if ($withData['data'] === null) {
                    continue;
                }

                foreach ($modelData['result'] as &$result) {
                    if (!$isAllSelect) {
                        $result = (object) $result;
                    }

                    if (property_exists($result, $withData['property'])) {
                        error_log('"With" filter result data trying to overwrite ' . static::getStaticClass() .
                            '\'s property "' . $withData['property'] . '"');
                        error_log(static::generateCallTrace());
                        return false;
                    }
                    if (!$filters['with'][$withDataKey]['isUnique']) {
                        $result->{$withData['property']} = [];
                    }

                    foreach ($withData['data'] as $classData) {
                        $doesExist = false;
                        $isClassDataArray = is_array($classData);
                        $classData = (object) $classData;

                        if (isset($withData['column'])
                            && ($result->{$withData['column']} == $classData->{$withData['foreignColumn']})
                        ) {
                            $doesExist = true;
                        } else {
                            foreach ($withData['columns'] as $columns) {
                                if ($result->{$columns['column']} == $classData->{$columns['foreignColumn']}) {
                                    $doesExist = true;
                                } else {
                                    $doesExist = false;
                                    break;
                                }
                            }
                        }

                        if (!$doesExist) {
                            continue;
                        }

                        if ($isClassDataArray) {
                            $classData = (array) $classData;
                        }

                        if ($filters['with'][$withDataKey]['isUnique']) {
                            $result->{$withData['property']} = $classData;
                        } else {
                            $result->{$withData['property']}[] = $classData;
                        }
                    }

                    if (!$isAllSelect) {
                        $result = (array) $result;
                    }
                }
                unset($result);
            }
        }

        if ($onlyValues) {
            $modelData['result'] = array_column($modelData['result'], $onlyValueKey);
        }

        if ($isUnique) {
            return isset($modelData['result'][0]) ? $modelData['result'][0] : false;
        }

        return $modelData['result'];
    }

    /**
     * Retrieves row count from database for specified filters
     * @param  array       $filters
     * @return boolean|int
     */
    public static function getAllCount($filters = [])
    {
        return static::getAll(array_merge($filters, ['onlyCount' => true]));
    }

    /**
     * Updates current model data
     * @param  array          $data
     * @return boolean|static
     */
    public function update($data)
    {
        if (empty($data)) {
            return false;
        }

        $query = 'UPDATE `' . static::$tableName . '` SET ';
        $queryParts = [];
        $values = [':' . static::$primaryColumn => $this->{static::$primaryColumn}];

        foreach ($data as $property => $value) {
            $queryParts[] = '`' . static::toSnakeCase($property) . '` = :' . $property;
            $values[":{$property}"] = $value;
        }

        $query .= implode(', ', $queryParts) . ' WHERE `' . static::toSnakeCase(static::$primaryColumn) . '` = :'
        . static::$primaryColumn;

        $modelData = static::getDatabaseConnection()->execute(
            $query,
            $values,
            '',
            PDO::FETCH_CLASS,
            static::getStaticClass()
        );

        if ($modelData['status']) {
            foreach ($data as $property => $value) {
                $this->$property = $value;
            }

            return $this;
        }

        return false;
    }

    /**
     * Adds new record/s or updates duplicate record in database for the model
     * @param  array                   $data
     * @param  null|array              $updateData
     * @param  null|string             $uniqueProperty
     * @param  boolean                 $isBulk
     * @param  boolean                 $returnData
     * @return boolean|static|static[]
     */
    public static function add(
        $data,
        $updateData = null,
        $uniqueProperty = null,
        $isBulk = false,
        $returnData = true
    ) {
        if (empty($data) || ($isBulk && empty($data[0]))) {
            return false;
        }

        $columnNameQueryParts = [];
        $columnValueQueryParts = [];
        $updateQueryParts = [];
        $values = [];

        if (!$isBulk && ($updateData !== null) && ($uniqueProperty === null) && isset($data[static::$primaryColumn])) {
            $uniqueProperty = static::$primaryColumn;
        }

        if ($isBulk) {
            foreach ($data[0] as $property => $value) {
                $columnNameQueryParts[] = '`' . static::toSnakeCase($property) . '`';
                $columnValueQueryParts[] = ":{$property}";
            }

            foreach ($data as $singleDataKey => $singleData) {
                foreach ($singleData as $property => $value) {
                    $values[$singleDataKey][":{$property}"] = $value;
                }
            }
        } else {
            foreach ($data as $property => $value) {
                $columnNameQueryParts[] = '`' . static::toSnakeCase($property) . '`';
                $columnValueQueryParts[] = ":{$property}";
                $values[":{$property}"] = $value;
            }
        }

        $query = 'INSERT INTO `' . static::$tableName . '` (' . implode(', ', $columnNameQueryParts) . ') VALUES ('
        . implode(', ', $columnValueQueryParts) . ')';

        if (!$isBulk && ($updateData !== null) && ($uniqueProperty !== null) && is_array($updateData)) {
            foreach ($updateData as $property => $value) {
                $updateQueryParts[] = '`' . static::toSnakeCase($property) . '` = :new' . $property;
                $values[":new{$property}"] = $value;
            }

            $query .= '  ON DUPLICATE KEY UPDATE ' . implode(', ', $updateQueryParts);
        }

        if ($isBulk) {
            $modelData = static::getDatabaseConnection()->bulkExecute(
                $query,
                $values,
                '',
                PDO::FETCH_CLASS,
                static::getStaticClass()
            );
        } else {
            $modelData = static::getDatabaseConnection()->execute(
                $query,
                $values,
                '',
                PDO::FETCH_CLASS,
                static::getStaticClass()
            );
        }

        if (!$modelData['status']) {
            return false;
        }

        if (!$isBulk && ($updateData !== null) && ($uniqueProperty !== null) && is_array($updateData)) {
            return $returnData ? static::getAll($data[$uniqueProperty], ['isUnique' => true]) : true;
        }

        $methodName = 'getBy' . static::toCamelCase(static::$primaryColumn, true);
        if (!$isBulk) {
            return $returnData ? static::$methodName($modelData['lastInsertId']) : true;
        }

        if (!$returnData) {
            return true;
        }

        $models = [];
        foreach ($modelData['lastInsertIds'] as $lastInsertId) {
            $models[] = static::$methodName($lastInsertId);
        }

        return $models;
    }

    /**
     * Deletes current model data from database
     * @return boolean|static
     */
    public function delete()
    {
        $query = 'DELETE FROM `' . static::$tableName . '` WHERE `'
        . static::toSnakeCase(static::$primaryColumn) . '` = :' . static::$primaryColumn;
        $values = [':' . static::$primaryColumn => $this->{static::$primaryColumn}];

        $modelData = static::getDatabaseConnection()->execute(
            $query,
            $values,
            '',
            PDO::FETCH_CLASS,
            static::getStaticClass()
        );

        return $modelData['status'] ? $this : false;
    }
}
