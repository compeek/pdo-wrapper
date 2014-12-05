<?php

/**
 * @package Compeek\PDOWrapper
 */

namespace Compeek\PDOWrapper;

/**
 * PDO wrapper
 *
 * The PDO wrapper class works as a drop-in replacement for the standard PDO class with a bit of additional
 * functionality. All standard PDO methods are exposed by the wrapper, so it can be used in exactly the same way as a
 * standard PDO object.
 *
 * Behind the scenes, an actual PDO object is hidden within the wrapper so that all references to it can be controlled.
 * Likewise the actual PDO statements are hidden within the PDO statement wrappers.
 *
 * When connecting to a database, a new PDO object is created within the wrapper. Any PDO statements created as a result
 * are initially tied to that PDO object. When disconnecting from the database, the PDO object and any related PDO
 * statements are destroyed so that they are garbage collected, causing the PDO driver to drop the connection. When
 * reconnecting to the database, a new PDO object is created, and any related PDO statements are lazily recreated from
 * the new PDO object upon next use. This all happens seamlessly behind the scenes.
 *
 * Lazy connect means that the connection is not made until first needed.
 *
 * Auto reconnect means that a new connection will be made as needed if previously disconnected from the database. It is
 * simply a convenience so that connect() does not need to be called manually later on after disconnecting. Auto
 * reconnect does not mean that a dead connection will be detected and refreshed, which unfortunately is not feasible
 * with prepared statements and transactions and locks and so forth.
 *
 * @package Compeek\PDOWrapper
 */
class PDO extends \PDO {
    const PDO_STATEMENT_WRAPPER_CLASS = '\\Compeek\\PDOWrapper\\PDOStatement';

    protected $args; // PDO constructor args
    protected $autoReconnect;
    protected $firstConnected;
    protected $lastKnownIsAlive;
    protected $lastKnownIsAliveOn;
    protected $pdoStatementWrapperClass;

    protected $pdo;
    protected $pdoAttributes;

    protected $pdoStatements;

    /**
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $options
     * @param bool $lazyConnect whether to delay connection until first needed
     * @param bool $autoReconnect whether to automatically reconnect as needed if previously disconnected
     */
    public function __construct($dsn, $username = null, $password = null, array $options = null, $lazyConnect = false, $autoReconnect = false) {
        $this->args = array_slice(func_get_args(), 0, 4);
        $this->autoReconnect = $autoReconnect;
        $this->firstConnected = false;
        $this->lastKnownIsAlive = null;
        $this->lastKnownIsAliveOn = null;

        $this->pdo = null;
        $this->pdoAttributes = array();

        $this->pdoStatements = array();

        if (!$lazyConnect) {
            $this->connect();
        }
    }

    public static function getAvailableDrivers() {
        return parent::getAvailableDrivers();
    }

    /**
     * Determines whether the client is connected to the database (not whether the connection is alive)
     *
     * This has nothing to do with whether the connection is still alive, but simply whether a connection was made that
     * has not been manually disconnected. To check whether the connection is still alive, use isAlive().
     *
     * @see isAlive()
     * @return bool
     */
    public function isConnected() {
        return $this->pdo !== null;
    }

    /**
     * Connects to the database
     *
     * To connect to the database, a new PDO object is created and hidden within the wrapper. Any related PDO statements
     * are lazily recreated by the PDO statement wrapper.
     */
    public function connect() {
        if ($this->isConnected()) {
            return;
        }

        $this->firstConnected = true;

        $connectedOn = microtime(true);

        switch (count($this->args)) {
        case 1:
            $this->pdo = new \PDO($this->args[0]);

            break;

        case 2:
            $this->pdo = new \PDO($this->args[0], $this->args[1]);

            break;

        case 3:
            $this->pdo = new \PDO($this->args[0], $this->args[1], $this->args[2]);

            break;

        case 4:
            $this->pdo = new \PDO($this->args[0], $this->args[1], $this->args[2], $this->args[3]);

            break;
        }

        $this->lastKnownIsAlive = true;
        $this->lastKnownIsAliveOn = $connectedOn;

        foreach ($this->pdoAttributes as $attribute => $value) {
            $this->pdo->setAttribute($attribute, $value);
        }
    }

    /**
     * Disconnects from the database
     *
     * To disconnect from the database, the PDO object and any related PDO statement objects are destroyed so that they
     * are garbage collected, causing the PDO driver to drop the connection.
     */
    public function disconnect() {
        if (!$this->isConnected()) {
            return;
        }

        $this->lastKnownIsAlive = null;
        $this->lastKnownIsAliveOn = null;

        foreach ($this->pdoStatements as &$pdoStatement) {
            $pdoStatement = null;
        }

        $this->pdoStatements = array();

        $this->pdo = null;
    }

    /**
     * Disconnects from and reconnects to the database
     */
    public function reconnect() {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Tests whether connection is alive using specified SQL statement
     *
     * A failure does not necessarily mean that the connection is dead. The statement could simply be invalid for the
     * current database, in which case another query should be tried until one is known to succeed at least once.
     *
     * @param string $statement SQL no-op statement
     * @return bool
     */
    protected function executeIsAliveStatement($statement) {
        try {
            $result = $this->pdo->query($statement);

            if ($result) {
                $result->closeCursor();
            }
        } catch (\PDOException $e) {
            $result = false;
        }

        return (bool) $result;
    }

    /**
     * Determines whether the connection is alive
     *
     * A no-op query is executed to test whether the connection is alive (if the query succeeds). Since there is no
     * universal no-op SQL query for all databases, multiple queries are tried until one is known to succeed at least
     * once, after which only that one will be used.
     *
     * Since the connection can usually be assumed to be alive if it was very recently known to be alive, and to avoid
     * spamming the database with useless queries when testing the connection multiple times in a short time span, a
     * cache duration can be specified, which will prevent actually testing the connection if it was tested within the
     * specified last number of seconds, and will instead assume the last known status. If a cache duration is not
     * specified, then the connection will be tested every time.
     *
     * @param int $cacheDuration seconds for which to cache alive status
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function isAlive($cacheDuration = null) {
        // http://stackoverflow.com/a/3670000/361030

        static $statements = array( // must try each statement until one known to be valid for DB
            "DO 1;", // MySQL >= 3.23.47
            "SELECT 1;", // MySQL, Microsoft SQL Server, PostgreSQL, SQLite, H2
            "SELECT 1 FROM DUAL;", // Oracle
            "SELECT 1 FROM INFORMATION_SCHEMA.SYSTEM_USERS;", // HSQLDB
            "SELECT 1 FROM SYSIBM.SYSDUMMY1;", // DB2, Apache Derby
            "SELECT COUNT(*) FROM SYSTABLES;", // Informix
        );

        static $knownValidStatementIndex = null;

        $this->requireConnection();

        if ($cacheDuration === null || $this->lastKnownIsAliveOn <= microtime(true) - $cacheDuration) {
            if ($knownValidStatementIndex !== null) {
                $this->lastKnownIsAliveOn = microtime(true);
                $this->lastKnownIsAlive = $this->executeIsAliveStatement($statements[$knownValidStatementIndex]);
            } else {
                foreach ($statements as $i => $statement) {
                    $executedOn = microtime(true);
                    $result = $this->executeIsAliveStatement($statement);

                    if ($result) {
                        $knownValidStatementIndex = $i;

                        break;
                    }
                }

                $this->lastKnownIsAlive = $result;
                $this->lastKnownIsAliveOn = $executedOn;
            }
        }

        return $this->lastKnownIsAlive;
    }

    /**
     * Requires a connection to the database, automatically connecting if allowed if the client is not connected
     *
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    protected function requireConnection() {
        if (!$this->isConnected()) {
            if (!$this->firstConnected || $this->autoReconnect) {
                $this->connect();
            } else {
                throw new \Compeek\PDOWrapper\NotConnectedException('Disconnected');
            }
        }
    }

    /**
     * Recreates a PDO statement and injects it into the given PDO statement wrapper
     *
     * When disconnecting from the database, any related PDO statements are destroyed and need to be recreated if they
     * are to be used again. Each PDO statement wrapper initiates the recreation upon next use by calling this method,
     * which creates a new PDO statement and injects it into the PDO statement wrapper.
     *
     * This method should only be called by the PDO statement wrapper, never elsewhere.
     *
     * @param \Compeek\PDOWrapper\PDOStatement $pdoStatementWrapper PDO statement wrapper requiring new PDO statement
     * @param bool $prepared whether statement is prepared
     * @param array $args PDO->prepare() or PDO->query() args
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function reconstructPdoStatement(\Compeek\PDOWrapper\PDOStatement $pdoStatementWrapper, $prepared, $args) {
        $this->requireConnection();

        if ($prepared) {
            $result = call_user_func_array(array($this->pdo, 'prepare'), $args);
        } else {
            $result = $this->pdo->prepare($args[0]);

            if ($result && count($args) > 1) {
                call_user_func_array(array($result, 'setFetchMode'), array_slice($args, 1));
            }
        }

        if ($result) {
            $this->pdoStatements[] = &$result;

            $pdoStatementWrapper->setPdoStatement($result);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Forgets the given PDO statement
     *
     * When a PDO statement is created, a reference to it is remembered in this class so it can be destroyed and garbage
     * collected when disconnecting from the database. If a PDO statement wrapper is destroyed, the reference to its PDO
     * statement can be forgotten so that it is garbage collected immediately.
     *
     * This method should only be called by the PDO statement wrapper, never elsewhere.
     *
     * @param \PDOStatement $pdoStatement
     */
    public function forgetPdoStatement(\PDOStatement $pdoStatement) {
        $index = array_search($pdoStatement, $this->pdoStatements);

        if ($index !== false) {
            array_splice($this->pdoStatements, $index, 1);
        }
    }

    public function errorCode() {
        $this->requireConnection();

        return $this->pdo->errorCode();
    }

    public function errorInfo() {
        $this->requireConnection();

        return $this->pdo->errorInfo();
    }

    public function getAttribute($attribute) {
        $this->requireConnection();

        return $this->pdo->getAttribute($attribute);
    }

    public function setAttribute($attribute, $value) {
        $this->requireConnection();

        $result = $this->pdo->setAttribute($attribute, $value);

        if ($result) {
            $this->pdoAttributes[$attribute] = $value;
        }

        return $result;
    }

    public function inTransaction() {
        $this->requireConnection();

        return $this->pdo->inTransaction();
    }

    public function beginTransaction() {
        $this->requireConnection();

        return $this->pdo->beginTransaction();
    }

    public function commit() {
        $this->requireConnection();

        return $this->pdo->commit();
    }

    public function rollBack() {
        $this->requireConnection();

        return $this->pdo->rollBack();
    }

    public function quote($string, $parameter_type = \PDO::PARAM_STR) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdo, 'quote'), func_get_args());
    }

    public function prepare($statement, array $driver_options = array()) {
        $this->requireConnection();

        $args = func_get_args();

        $result = call_user_func_array(array($this->pdo, 'prepare'), $args);

        if ($result) {
            $this->pdoStatements[] = &$result;

            $className = static::PDO_STATEMENT_WRAPPER_CLASS;

            return new $className($this, $this->lastKnownIsAlive, $this->lastKnownIsAliveOn, true, $args, $result);
        } else {
            return false;
        }
    }

    public function query($statement) {
        $this->requireConnection();

        $args = func_get_args();

        $executedOn = microtime(true);

        $result = call_user_func_array(array($this->pdo, 'query'), $args);

        if ($result) {
            $this->lastKnownIsAlive = true;
            $this->lastKnownIsAliveOn = $executedOn;

            $this->pdoStatements[] = &$result;

            $className = static::PDO_STATEMENT_WRAPPER_CLASS;

            return new $className($this, $this->lastKnownIsAlive, $this->lastKnownIsAliveOn, false, $args, $result);
        } else {
            return false;
        }
    }

    public function exec($statement) {
        $this->requireConnection();

        $executedOn = microtime(true);

        $result = $this->pdo->exec($statement);

        if ($result) {
            $this->lastKnownIsAlive = true;
            $this->lastKnownIsAliveOn = $executedOn;
        }

        return $result;
    }

    public function lastInsertId($name = NULL) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdo, 'lastInsertId'), func_get_args());
    }
}
