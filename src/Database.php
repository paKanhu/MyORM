<?php

namespace paKanhu\MyORM;

use PDO;
use PDOException;

class Database
{
    private $db;
    protected $transactionCount = 0;
    private static $dbc = null;

    public function __construct($host, $name, $username, $password, $timeZone = '+00:00')
    {
        try {
            $this->db = new PDO("mysql:host={$host};dbname={$name};charset=utf8", $username, $password);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->exec("SET time_zone='{$timeZone}'");
            static::$dbc = $this;
        } catch (PDOException $e) {
            $this->logException($e);
            exit();
        }
    }

    public function exec($query)
    {
        try {
            $result = $this->db->exec($query);

            if ($result !== false) {
                return ['status' => true, 'result' => $result];
            }

            return ['status' => false, 'error' => 'Failed to execute query.'];
        } catch (PDOException $e) {
            $this->logException($e);
            return ['status' => false, 'error' => print_r($e->errorInfo, true)];
        }
    }

    public function useDb($db)
    {
        return $this->exec("USE `{$db}`");
    }

    public function execute($query, $values = [], $queryType = '', $fetchMode = PDO::FETCH_ASSOC, $className = '')
    {
        $isError = true;

        $validQueryTypes = ['select', 'update', 'delete', 'insert'];

        // For INSERT query
        $lastInsertId = 0;

        //  For SELECT query
        $result = [];

        // For DELETE and UPDATE query
        $rowCount = 0;

        if (!trim($queryType) && preg_match('/(\w+)/', $query, $queryTypeMatches)) {
            $queryType = strtolower($queryTypeMatches[0]);
        }

        if (!in_array($queryType, $validQueryTypes)) {
            return ['status' => false, 'error' => 'Please provide a query type.'];
        }

        try {
            $stmt = $this->db->prepare($query);
            if ($stmt->execute($values)) {
                if ($queryType === 'insert') {
                    $lastInsertId = $this->db->lastInsertId();
                } elseif ($queryType === 'select') {
                    if ($className !== '') {
                        $stmt->setFetchMode($fetchMode, $className);
                    } else {
                        $stmt->setFetchMode($fetchMode);
                    }
                    $result = $stmt->fetchAll();
                } else {
                    $rowCount = $stmt->rowCount();
                }
                $isError = false;
            }
        } catch (PDOException $e) {
            $this->logException($e);
            return ['status' => false, 'error' => print_r($e->errorInfo, true)];
        }

        if ($isError) {
            return ['status' => false, 'error' => 'Failed while inserting into database.'];
        }

        if ($queryType === 'insert') {
            return ['status' => true, 'lastInsertId' => $lastInsertId];
        } elseif ($queryType === 'select') {
            return ['status' => true, 'result' => $result];
        } else {
            return ['status' => true, 'rowCount' => $rowCount];
        }
    }

    public function beginTransaction()
    {
        try {
            if (!$this->transactionCount++) {
                return $this->db->beginTransaction();
            }

            $this->db->exec("SAVEPOINT trans{$this->transactionCount}");
        } catch (PDOException $e) {
            $this->logException($e);
            return false;
        }
    }

    public function commit()
    {
        try {
            if (!--$this->transactionCount) {
                return $this->db->commit();
            }

            return $this->transactionCount >= 0;
        } catch (PDOException $e) {
            $this->logException($e);
            return false;
        }
    }

    public function rollback()
    {
        try {
            if (--$this->transactionCount) {
                $this->db->exec('ROLLBACK TO trans' . ($this->transactionCount + 1));
                return true;
            }

            return $this->db->rollback();
        } catch (PDOException $e) {
            $this->logException($e);
            return false;
        }
    }

    public function bulkExecute(
        $query,
        $allValues = [[]],
        $queryType = '',
        $fetchMode = PDO::FETCH_ASSOC,
        $className = ''
    ) {
        $isError = true;
        $is_query_error = false;

        $validQueryTypes = ['select', 'update', 'delete', 'insert'];

        // For INSERT query
        $lastInsertIds = [];

        //  For SELECT query
        $results = [];

        // For DELETE and UPDATE query
        $rowCounts = [];

        // Query Type
        if (!trim($queryType) && preg_match('/(\w+)/', $query, $queryTypeMatches)) {
            $queryType = strtolower($queryTypeMatches[0]);
        }

        if (!in_array($queryType, $validQueryTypes)) {
            return ['status' => false, 'error' => 'Please provide a query type.'];
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare($query);
            foreach ($allValues as $values) {
                if ($stmt->execute($values)) {
                    if ($queryType === 'insert') {
                        array_push($lastInsertIds, $this->db->lastInsertId());
                    } elseif ($queryType === 'select') {
                        if ($className !== '') {
                            $stmt->setFetchMode($fetchMode, $className);
                        } else {
                            $stmt->setFetchMode($fetchMode);
                        }
                        array_push($results, $stmt->fetchAll());
                    } else {
                        array_push($rowCounts, $stmt->rowCount());
                    }
                    $isError = false;
                } else {
                    $is_query_error = true;
                }
            }

            if ($isError) {
                $this->db->rollback();
            } else {
                if ($is_query_error) {
                    $isError = true;
                    $this->db->rollback();
                } else {
                    $this->db->commit();
                }
            }
        } catch (PDOException $e) {
            $this->db->rollback();
            $this->logException($e);
            return ['status' => false, 'error' => print_r($e->errorInfo, true)];
        }

        if ($isError) {
            return ['status' => false, 'error' => 'Failed.'];
        } else {
            if ($queryType === 'insert') {
                return ['status' => true, 'lastInsertIds' => $lastInsertIds];
            } elseif ($queryType === 'select') {
                return ['status' => true, 'results' => $results];
            } else {
                return ['status' => true, 'rowCounts' => $rowCounts];
            }
        }
    }

    public static function getConnection()
    {
        if (static::$dbc === null) {
            error_log('No database connection');
            exit();
        }

        return static::$dbc;
    }

    public function logException($e)
    {
        error_log($e->getMessage());
        error_log(print_r($e->errorInfo, true));
        error_log(var_export($e->getTraceAsString(), true));
    }
}
