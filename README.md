PDO Wrapper
===========

PDO Wrapper is a simple, drop-in PDO wrapper featuring lazy connections, manual disconnections, and re-connections.

By default, it works exactly as standard PDO and can be used in exactly the same way. It adds a few additional features
without affecting the default functionality.

The PDO and PDOStatement classes extend the standard PDO and PDOStatement classes, so the wrapper objects can be used
anywhere standard PDO and PDO statement objects were previously used, and all standard methods remain the same.

## Constructor Parameters

There are two additional, boolean constructor parameters for the PDO wrapper object: $lazyConnect and $autoReconnect

```php
$db = new \Compeek\PDOWrapper\PDO($dsn, $username, $password, $options, $lazyConnect, $autReconnect);
```

### Lazy Connect

Lazy connect means that the connection is not made until first needed.

By default, lazy connect is disabled (false), but passing true to the constructor will enable it.

### Auto Reconnect

Auto reconnect means that a new connection will be made as needed if previously disconnected from the database.

It is simply a convenience so that connect() does not need to be called manually later on after disconnecting.

Auto reconnect does not mean that a dead connection will be detected and refreshed, which unfortunately is not feasible
with prepared statements and transactions and locks and so forth.

By default, auto reconnect is disabled (false), but passing true to the constructor will enable it.

## Methods

There are some additional methods for the PDO wrapper object.

### connect()

```php
$db->connect();
```

If not currently connected to the database, a connection will be made.

### disconnect()

```php
$db->disconnect();
```

If currently connected to the database, the connection will be dropped.

### reconnect()

```php
$db->reconnect();
```

The current connection to the database will be dropped, and a new connection will be made.

It is simply a shortcut for:

```php
$db->disconnect();
$db->connect();
```

### isConnected()

```php
$connected = $db->isConnected();
```

This method returns whether currently connected to the database.

It has nothing to do with whether the connection is still alive, but simply whether a connection was made that has not
been manually disconnected. To test whether the connection is still alive, see isAlive();

### isAlive()

```php
$alive = $db->isAlive();
```

This method tests whether the connection is still alive.

It does so by executing a no-op SQL query and checking whether it succeeds.

While good programming practice is not to hold a connection open for longer than it is needed at one time, this method
can be used to ensure the connection is still alive before executing more SQL statements if there is a possibility that
the connection has timed out.

## Errors

Any standard PDO errors or exceptions are simply passed through.

The one special case is if a method requiring a connection is called, but there is not currently a connection to the
database and auto reconnect is disabled. In that case, a \Compeek\PDOWrapper\NotConnectedException will be thrown (even
if the PDO error mode is not set to exceptions).

## Requirements

PDO Wrapper runs on PHP 5.3.0+ and requires the PDO extension.
