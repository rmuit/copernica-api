<?php

/**
 * A key-value store manager using SQLite as backend.
 *
 * Application code can use separate key-value stores with different
 * collection names. This manager class
 * - is a factory class to create those key value store instances
 * - takes care of creating the back end storage that those instances use
 * - could take care of maintenance tasks, like removing 'outdated' entries.
 * @todo ^
 * @todo maybe implement or sugggest an optional internal timestamp column
 *   'queried' that would be updated with every get() - this would allow us to
 *   keep the 'outdated period' low and still not remove old entries that are
 *   constantly being requeried. Not sure if it's worth it.
 *
 * This class has no official interface yet; that only comes into play when we
 * add one a KeyValueStoreInterface. Maybe by that time we'll know whether this
 * is even general enough to need an interface. (For reference:
 * \Drupal\Core\KeyValueStore\KeyValueFactoryInterface only includes get().)
 */
// We're being bad. I know. But extra/ is not autoloaded.
// phpcs:ignore PSR1.Classes.ClassDeclaration
class SqliteKeyValueStoreManager
{
    /**
     * The database connection.
     *
     * @var PDO
     */
    protected $pdoConnection;

    /**
     * Constructor.
     *
     * No backward compatibility is guaranteed for the constructor. (Code that
     * uses this class must check it on upgrades; that's what you get for using
     * namespace-less code in a directory called extra/.)
     *
     * @param PDO $pdo_connection
     *   PDO connection.
     * @param bool $create_backend
     *   (Optional) Pass False to not create the table, meaning you're certain
     *   it exists already.
     *
     * @todo make a way to vary 'collation' determining how keys are matched.
     *   (case sensitive or insensitive.)
     */
    public function __construct(PDO $pdo_connection, $create_backend = true)
    {
        $this->pdoConnection = $pdo_connection;
        if ($create_backend) {
            // We blindly assume we have the right to use the key_value table
            // and that it doesn't exist yet.
            $this->pdoConnection->exec("CREATE TABLE key_value (
              key TEXT NOT NULL COLLATE NOCASE,
              collection TEXT NOT NULL,
              value TEXT NOT NULL,
              updated TEXT NOT NULL,
              UNIQUE (key, collection) ON CONFLICT ABORT)");
            $this->pdoConnection->exec('CREATE INDEX updated ON key_value (updated)');
        }
    }

    /**
     * Gets the database connection.
     *
     * @return PDO
     */
    public function getPdoConnection()
    {
        return $this->pdoConnection;
    }

    /**
     * Constructs a new key/value store for a given collection name.
     *
     * @param string $collection
     *   The name of the collection holding key and value pairs.
     *
     * @return PdoKeyValueStore
     *   A key/value store implementation for the given $collection.
     */
    public function get($collection)
    {
        return new PdoKeyValueStore($this->getPdoConnection(), 'key_value', 'key', $collection, 'collection', 'updated');
    }
}
