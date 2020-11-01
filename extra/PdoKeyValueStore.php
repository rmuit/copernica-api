<?php

/**
 * A key-value store using a PDO connection as backend.
 *
 * This class has no official interface yet; that will/should be added once
 * non-test code in a non-Drupal project is using it. (This evolved out of me
 * copying most of Drupal\Core\KeyValueStore\KeyValueStoreInterface, but that
 * interface isn't easy to peel out of Drupal so we'll copy it at some point.)
 *
 * Keys are matched case insensitively. To change this, see constructor @todo.
 *
 * Somehow 'value' is the only database column that is still hardcoded. (I
 * don't remember how it came to be that it is, while $this->keyColumn is
 * abstracted.) The collection column is abstracted incompletely; it's optional
 * for some methods and required for others.
 *
 * This class has no unit tests yet; some methods may never have been tested.
 */
// We're being bad. I know. But extra/ is not autoloaded.
// phpcs:ignore PSR1.Classes.ClassDeclaration
class PdoKeyValueStore
{
    /**
     * The database connection.
     *
     * @var PDO
     */
    protected $pdoConnection;

    /**
     * The name of the SQL table to use.
     *
     * @var string
     */
    protected $table;

    /**
     * The name of the column containing the key.
     *
     * @var string
     */
    protected $keyColumn;

    /**
     * The name of the column containing the collection.
     *
     * @var string
     */
    protected $collectionColumn;

    /**
     * The name of the collection to read/write data from/to.
     *
     * @var string
     */
    protected $collection;

    /**
     * The name of the column containing the 'updated' value.
     *
     * @var string
     */
    protected $updatedColumn;

    /**
     * Constructor.
     *
     * @param PDO $pdo_connection
     *   The PDO connection containing the table used to fetch/store data.
     * @param string $table
     *   Table name.
     * @param string $key_column
     *   Column containing the keys.
     * @param string $collection_name
     *   (Optional) Name of the 'collection' (or if you will, 'namespace') of
     *   values to select from. Values not matching this collection are never
     *   retrieved. This is a way for this class to store multiple collections
     *   of key-value items using the same back end.
     * @param string $collection_column
     *   (Optional) Column containing the collection values. Unused if
     *   $collection_name is empty.
     * @param string $updated_column
     *   (Optional) Column containing a timestamp value that will be updated
     *   to 'now', every time the value is written. Empty string must be passed
     *   in order to turn this feature off.
     *
     * @todo make a parameter for 'collation' determining how keys are matched.
     *   (case sensitive or insensitive.) Actually, maybe this parameter plus
     *   all above parameters should move to a factory class instead. Or
     *   rather... a 'manager' class which is not only a factory for
     *   KeyValueStore classes, but can also perform "removing outdated cached
     *   values" and possible the other @todo mentioned in the class comment?
     */
    public function __construct(PDO $pdo_connection, $table, $key_column = 'name', $collection_name = '', $collection_column = 'collection', $updated_column = 'updated')
    {
        // Sanitize values used in SQL silently.
        $this->pdoConnection = $pdo_connection;
        $this->table = preg_replace('/[^a-z\d_]+/', '', $table);
        $this->keyColumn = preg_replace('/[^a-z\d_]+/', '', $key_column);
        $this->collectionColumn = preg_replace('/[^a-z\d_]+/', '', $collection_column);
        $this->collection = $collection_name;
        $this->updatedColumn = preg_replace('/[^a-z\d_]+/', '', $updated_column);
    }

    // General:

    public function getCollectionName()
    {
        return $this->collection;
    }

    public function get($key, $default = null)
    {
        $values = $this->getMultiple([$key]);
        return isset($values[$key]) ? $values[$key] : $default;
    }

    public function setMultiple(array $data)
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function delete($key)
    {
        $this->deleteMultiple([$key]);
    }

    // Backend specific:

    public function has($key)
    {
        $query = "SELECT 1 FROM {$this->table} WHERE {$this->keyColumn} = :key";
        $placeholders = ['key' => $key];
        if ($this->collection) {
            $query .= " AND {$this->collectionColumn} = :coll";
            $placeholders[':coll'] = $this->collection;
        }
        return (bool) $this->dbfetchField($query, $placeholders);
    }

    public function getMultiple(array $keys)
    {
        $placeholders = array_combine(
            array_map(function ($i) {
                return ':in' . $i;
            }, array_keys($keys)),
            $keys
        );
        $in_placeholders = implode(',', array_keys($placeholders));
        $query = "SELECT {$this->keyColumn}, value FROM {$this->table} WHERE {$this->keyColumn} IN ($in_placeholders)";
        if ($this->collection) {
            $query .= " AND {$this->collectionColumn} = :coll";
            $placeholders[':coll'] = $this->collection;
        }

        $result = $this->dbFetchAll($query, $placeholders);
        $values = [];
        foreach ($result as $row) {
            $values[$row[$this->keyColumn]] = unserialize($row['value']);
        }
        return $values;
    }

    public function getAllBatched($limit = 1024, $offset = 0)
    {
        $query = "SELECT {$this->keyColumn}, value FROM {$this->table}";
        $placeholders = ['limit' => $limit, 'offset' => $offset];
        if ($this->collection) {
            $query .= " WHERE {$this->collectionColumn} = :coll";
            $placeholders['coll'] = $this->collection;
        }
        $query .= " ORDER BY {$this->keyColumn} LIMIT :limit OFFSET :offset";

        $result = $this->dbFetchAll($query, $placeholders);
        $values = [];
        foreach ($result as $row) {
            $values[$row[$this->keyColumn]] = unserialize($row['value']);
        }
        return $values;
    }

    public function set($key, $value)
    {
        $this->dbExecuteQuery(
            "INSERT INTO {$this->table} ({$this->collectionColumn}, {$this->keyColumn}, value, {$this->updatedColumn}) VALUES (:coll, :key, :val, :upd)
              ON CONFLICT ({$this->collectionColumn}, {$this->keyColumn}) DO UPDATE SET value=excluded.value, {$this->updatedColumn}=excluded.{$this->updatedColumn}",
            ['coll' => $this->collection, 'key' => $key, 'val' => serialize($value), 'upd' => time()]
        );
    }

    public function deleteMultiple(array $keys)
    {
        // Delete in chunks when a large array is passed.
        while ($keys) {
            $chunk = array_splice($keys, 0, 1024);
            $placeholders = array_combine(
                array_map(function ($i) {
                    return ':in' . $i;
                }, array_keys($chunk)),
                $chunk
            );
            $in_placeholders = implode(',', array_keys($placeholders));
            $query = "DELETE FROM {$this->table} WHERE {$this->keyColumn} IN ($in_placeholders)";
            if ($this->collection) {
                $query .= " AND {$this->collectionColumn} = :coll";
                $placeholders[':coll'] = $this->collection;
            }
            $this->dbExecuteQuery($query, $placeholders);
        }
    }

    public function deleteAll()
    {
        $query = "DELETE FROM {$this->table}";
        $placeholders = [];
        if ($this->collection) {
            $query .= " WHERE {$this->collectionColumn} = :coll";
            $placeholders[':coll'] = $this->collection;
        }
        $this->dbExecuteQuery($query, $placeholders);
    }

    // Helper methods for PDO. Maybe should be a trait because also in TestApi.

    /**
     * Executes a PDO query/statement.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   Query parameters as could be used in PDOStatement::execute.
     *
     * @return PDOStatement
     *   Executed PDO statement.
     */
    protected function dbExecutePdoStatement($query, $parameters)
    {
        $statement = $this->pdoConnection->prepare($query);
        if (!$statement) {
            // This is likely an error in some internal SQL query so we likely
            // want to know the arguments too.
            throw new LogicException("PDO statement could not be prepared: $query " . json_encode($parameters));
        }
        $ret = $statement->execute($parameters);
        if (!$ret) {
            $info = $statement->errorInfo();
            throw new RuntimeException("Database statement execution failed: Driver code $info[1], SQL code $info[0]: $info[2]");
        }

        return $statement;
    }

    /**
     * Executes a non-select query.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   Query parameters as could be used in PDOStatement::execute.
     * @param int $special_handling
     *   Affects the behavior and/or type of value returned from this function.
     *   (Admittedly this is a strange way to do things; quick and dirty and
     *   does the job.)
     *   0 = return the number of affected rows
     *   1 = return the last inserted ID; assumes insert statement which
     *       inserts exactly one row; otherwise logs an error.
     *   other values: undefined.
     *
     * @return int
     *   The number of rows affected by the executed SQL statement, or (if
     *   $special_handling == 1) the last inserted ID.
     */
    protected function dbExecuteQuery($query, $parameters = [], $special_handling = 0)
    {
        $statement = $this->dbExecutePdoStatement($query, $parameters);
        $affected_rows = $statement->rowCount();
        if ($special_handling === 1) {
            if ($affected_rows !== 1) {
                throw new RuntimeException('Unexpected affected-rows count in insert statement: {affected_rows}.', ['affected_rows' => $affected_rows]);
            }
            return $this->pdoConnection->lastInsertId();
        }
        return $affected_rows;
    }

    /**
     * Fetches single field value from database.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   (Optional) query parameters as could be used in PDOStatement::execute.
     *
     * @return mixed
     *   The value, or false for not found. (This implies that 'not found'
     *   only works for fields that we know cannot contain return boolean
     *   false, but that's most of them.) Note integer field values are likely
     *   returned as numeric strings (by SQLite); don't trust the type.
     */
    public function dbFetchField($query, $parameters = [])
    {
        $statement = $this->dbExecutePdoStatement($query, $parameters);
        $ret = $statement->fetchAll(PDO::FETCH_ASSOC);
        $record = current($ret);
        if ($record) {
            // Misuse the record/row as the value. Get the first field of what
            // we assume to be a record with a single field.
            $record = reset($record);
        }

        return $record;
    }

    /**
     * Fetches database rows for query.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   (Optional) query parameters as could be used in PDOStatement::execute.
     * @param string $key
     *   (Optional) Name of the field on which to index the array. It's the
     *   caller's responsibility to be sure the field values are unique and
     *   always populated; if not, there is no guarantee on the returned
     *   result. If an empty string is passed, then the array is numerically
     *   indexed; the difference with not passing the argument is that the
     *   return value is guaranteed to be an array (so it's countable, etc).
     *
     * @return array|Traversable
     *   An array of database rows (as arrays), or an equivalent traversable.
     */
    public function dbFetchAll($query, $parameters = [], $key = null)
    {
        $statement = $this->dbExecutePdoStatement($query, $parameters);
        $ret = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (isset($key)) {
            $result = [];
            $i = 0;
            foreach ($ret as $record) {
                $result[$key ? $record[$key] : $i++] = $record;
            }
            $ret = $result;
        }

        return $ret;
    }
}
