<?php

/**
 * @package Compeek\PDOWrapper
 */

namespace Compeek\PDOWrapper;

/**
 * PDOStatement wrapper
 *
 * The PDO statement wrapper class works as a drop-in replacement for the standard PDO statement class with a bit of
 * additional functionality. All standard PDO statement methods are exposed by the wrapper, so it can be used in exactly
 * the same way as a standard PDO statement object.
 *
 * Behind the scenes, an actual PDO statement object is hidden within the wrapper so that all references to it can be
 * controlled.
 *
 * When disconnecting from the database, the PDO object and all related PDO statements are destroyed by the PDO wrapper
 * so that they are garbage collected, causing the PDO driver to drop the connection. When reconnecting to the database,
 * the PDO wrapper creates a new PDO object, and each PDO statement is recreated from the new PDO object upon next use.
 * To give the illusion that the PDO statements are the same ones as before, all attributes, options, and bindings are
 * restored when recreating them.
 *
 * @package Compeek\PDOWrapper
 */
class PDOStatement extends \PDOStatement {
    protected $pdoWrapper;
    protected $pdoWrapperLastKnownIsAlive;
    protected $pdoWrapperLastKnownIsAliveOn;

    protected $prepared;
    protected $args; // PDO->prepare() or PDO->query() args

    protected $pdoStatement;
    protected $pdoStatementAttributes;
    protected $pdoStatementBindColumns;
    protected $pdoStatementPostExecuteBindColumnNames; // columns that cannot be bound before statement execution when reconstructing statement
    protected $pdoStatementBindParams;
    protected $pdoStatementBindValues;
    protected $pdoStatementFetchModeArgs;

    /**
     * @param PDO $pdoWrapper PDO wrapper creating PDO statement
     * @param bool $pdoWrapperLastKnownIsAlive last connection alive status
     * @param int $pdoWrapperLastKnownIsAliveOn last time connection alive status known
     * @param bool $prepared whether statement is prepared
     * @param array $args PDO->prepare() or PDO->query() args
     * @param \PDOStatement $pdoStatement PDO statement
     */
    public function __construct(\Compeek\PDOWrapper\PDO $pdoWrapper, &$pdoWrapperLastKnownIsAlive, &$pdoWrapperLastKnownIsAliveOn, $prepared, array $args, \PDOStatement &$pdoStatement) {
        $this->pdoWrapper = $pdoWrapper;
        $this->pdoWrapperLastKnownIsAlive = &$pdoWrapperLastKnownIsAlive;
        $this->pdoWrapperLastKnownIsAliveOn = &$pdoWrapperLastKnownIsAliveOn;

        $this->prepared = $prepared;
        $this->args = $args;

        $this->pdoStatement = &$pdoStatement;
        $this->pdoStatementAttributes = array();
        $this->pdoStatementBindColumns = array();
        $this->pdoStatementPostExecuteBindColumnNames = array();
        $this->pdoStatementBindParams = array();
        $this->pdoStatementBindValues = array();
        $this->pdoStatementFetchMode = null;
    }

    /**
     * Informs PDO wrapper that PDO statement wrapper is being destroyed and destroys PDO statement
     */
    public function __destruct() {
        if ($this->pdoStatement !== null) {
            $this->pdoWrapper->handlePdoStatementWrapperDestruction($this->pdoStatement);

            $this->pdoStatement = null;
        }
    }

    /**
     * Sets PDO statement
     *
     * This method should only be called by the PDO wrapper, never elsewhere.
     *
     * @param \PDOStatement $pdoStatement
     */
    public function setPdoStatement(\PDOStatement &$pdoStatement) {
        $this->pdoStatement = &$pdoStatement;
    }

    /**
     * Recreates PDO statement
     *
     * To recreate a PDO statement, all attributes, options, and bindings are restored from the previous one, giving the
     * illusion that the PDO statement is the same one as before. However, since sometimes columns can only be bound
     * after a result set is retrieved, any errors binding columns here will be ignored, and the column bindings will be
     * tried again after the statement is next executed.
     *
     * @return bool
     */
    protected function reconstructPdoStatement() {
        if ($this->pdoWrapper->reconstructPdoStatement($this, $this->prepared, $this->args)) {
            foreach ($this->pdoStatementAttributes as $attribute => $value) {
                $this->pdoStatement->setAttribute($attribute, $value);
            }

            $this->pdoStatementPostExecuteBindColumnNames = array();

            foreach ($this->pdoStatementBindColumns as $column => $args) {
                $args[1] = &$args[1]; // ensure reference is passed to function since PHP seems to convert reference to value if no other variables reference data (e.g. if column bound to local variable in function that has ended)

                try {
                    $success = call_user_func_array(array($this->pdoStatement, 'bindColumn'), $args);
                } catch (\PDOException $e) {
                    $success = false;
                }

                if (!$success) { // column cannot be bound before statement execution
                    $this->pdoStatementPostExecuteBindColumnNames[$column] = $column;
                }
            }

            foreach ($this->pdoStatementBindParams as $args) {
                $args[1] = &$args[1]; // ensure reference is passed to function since PHP seems to convert reference to value if no other variables reference data (e.g. if param bound to local variable in function that has ended)

                call_user_func_array(array($this->pdoStatement, 'bindParam'), $args);
            }

            foreach ($this->pdoStatementBindValues as $args) {
                call_user_func_array(array($this->pdoStatement, 'bindValue'), $args);
            }

            if ($this->pdoStatementFetchModeArgs !== null) {
                call_user_func_array(array($this->pdoStatement, 'setFetchMode'), $this->pdoStatementFetchModeArgs);
            }

            return true;
        } else { // applies only if PDO::ATTR_ERRMODE attribute != PDO::ERRMODE_EXCEPTION (will not reach here on error otherwise)
            return false;
        }
    }

    /**
     * Requires connection to database, automatically connecting if disconnected if possible
     *
     * If the PDO statement does not exist, that means it was destroyed when last disconnected from the database and
     * must be recreated, which in turn will require a connection. If the PDO statement does exist, there is already
     * still a connection.
     *
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    protected function requireConnection() {
        if ($this->pdoStatement === null) {
            $this->reconstructPdoStatement();
        }
    }

    public function debugDumpParams() {
        $this->requireConnection();

        return $this->pdoStatement->debugDumpParams();
    }

    public function errorCode() {
        $this->requireConnection();

        return $this->pdoStatement->errorCode();
    }

    public function errorInfo() {
        $this->requireConnection();

        return $this->pdoStatement->errorInfo();
    }

    public function getAttribute($attribute) {
        $this->requireConnection();

        return $this->pdoStatement->getAttribute($attribute);
    }

    public function setAttribute($attribute, $value) {
        $this->requireConnection();

        $result = $this->pdoStatement->setAttribute($attribute, $value);

        if ($result) {
            $this->pdoStatementAttributes[$attribute] = $value;
        }

        return $result;
    }

    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null) {
        $this->requireConnection();

        $args = func_get_args();
        $args[1] = &$param;

        $result = call_user_func_array(array($this->pdoStatement, 'bindColumn'), $args);

        if ($result) {
            unset($this->pdoStatementPostExecuteBindColumnNames[$column]);
            $this->pdoStatementBindColumns[$column] = $args;
        }

        return $result;
    }

    public function bindParam($parameter, &$variable, $data_type = \PDO::PARAM_STR, $length = null, $driver_options = null) {
        $this->requireConnection();

        $args = func_get_args();
        $args[1] = &$variable;

        $result = call_user_func_array(array($this->pdoStatement, 'bindParam'), $args);

        if ($result) {
            unset($this->pdoStatementBindValues[$parameter]);
            $this->pdoStatementBindParams[$parameter] = $args;
        }

        return $result;
    }

    public function bindValue($parameter, $value, $data_type = \PDO::PARAM_STR) {
        $this->requireConnection();

        $args = func_get_args();

        $result = call_user_func_array(array($this->pdoStatement, 'bindValue'), $args);

        if ($result) {
            unset($this->pdoStatementBindParams[$parameter]);
            $this->pdoStatementBindValues[$parameter] = $args;
        }

        return $result;
    }

    public function execute(array $input_parameters = null) {
        $this->requireConnection();

        $executedOn = microtime(true);

        $result = call_user_func_array(array($this->pdoStatement, 'execute'), func_get_args());

        if ($result) {
            $this->pdoWrapperLastKnownIsAlive = true;
            $this->pdoWrapperLastKnownIsAliveOn = $executedOn;

            foreach ($this->pdoStatementPostExecuteBindColumnNames as $column) {
                $this->pdoStatementBindColumns[$column][1] = &$this->pdoStatementBindColumns[$column][1]; // ensure reference is passed to function since PHP seems to convert reference to value if no other variables reference data (e.g. if column bound to local variable in function that has ended)

                call_user_func_array(array($this->pdoStatement, 'bindColumn'), $this->pdoStatementBindColumns[$column]);
            }

            $this->pdoStatementPostExecuteBindColumnNames = array();
        }

        return $result;
    }

    public function nextRowset() {
        $this->requireConnection();

        return $this->pdoStatement->nextRowset();
    }

    public function getColumnMeta($column) {
        $this->requireConnection();

        return $this->pdoStatement->getColumnMeta($column);
    }

    public function columnCount() {
        $this->requireConnection();

        return $this->pdoStatement->columnCount();
    }

    public function rowCount() {
        $this->requireConnection();

        return $this->pdoStatement->rowCount();
    }

    public function setFetchMode($mode) {
        $this->requireConnection();

        $args = func_get_args();

        $result = call_user_func_array(array($this->pdoStatement, 'setFetchMode'), $args);

        if ($result) {
            $this->pdoStatementFetchModeArgs = $args;
        }

        return $result;
    }

    public function fetch($fetch_style = null, $cursor_orientation = \PDO::FETCH_ORI_NEXT, $cursor_offset = 0) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetch'), func_get_args());
    }

    public function fetchAll($fetch_style = null, $fetch_argument = null, array $ctor_args = array()) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetchAll'), func_get_args());
    }

    public function fetchColumn($column_number = 0) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetchColumn'), func_get_args());
    }

    public function fetchObject($class_name = "stdClass", array $ctor_args = null) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetchObject'), func_get_args());
    }

    public function closeCursor() {
        $this->requireConnection();

        return $this->pdoStatement->closeCursor();
    }
}
