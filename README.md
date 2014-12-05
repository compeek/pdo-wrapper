PDO Wrapper
===========

PDO Wrapper is a simple, drop-in PDO wrapper featuring lazy connect, manual disconnect, reconnect, and is alive testing.

It is not a PDO abstraction, but a simple extension of PDO that adds a few useful features without affecting the
standard functionality:

- Lazy connect: the connection is not made until first needed
- Manual disconnect: disconnect from the database at any time instead of just when the script ends
- Reconnect: connect again later on after disconnecting, maintaining any previously created PDO statements
- Is alive: test whether the connection is still alive 

## Installation

Use Composer to install the library. See https://getcomposer.org if you are not familiar with it.

Add both the repository and the dependency to your composer.json:

```json
{
    "require": {
        "compeek/pdo-wrapper": "dev-master",
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/compeek/pdo-wrapper"
        }
    ]
}
```

If your minimum-stability is "stable", you may need to override it for this package by appending "@dev" to the version:

```json
    "require": {
        "compeek/pdo-wrapper": "dev-master@dev",
    }
```

## Usage

The PDO and PDOStatement wrapper classes both wrap and extend the standard PDO and PDOStatement classes, so they can be
used anywhere standard PDO and PDO statement objects were previously used, and all standard methods remain the same.

Creating a PDO wrapper object is exactly the same as creating a standard PDO object: 

```php
$db = new \Compeek\PDOWrapper\PDO($dsn, $username, $password, $options);
```

However, there are two optional additional parameters: $lazyConnect and $autoReconnect (both boolean):

```php
$db = new \Compeek\PDOWrapper\PDO($dsn, $username, $password, $options, $lazyConnect, $autoReconnect);
```

These are explained below.

There are some additional methods for the PDO wrapper as well. These are also explained below.

As with standard PDO, the PDO statement wrappers are never created directly, but as a result of calling prepare() or 
query() on a PDO wrapper.

There are no additional methods for the PDO statement wrappers other than a few used internally.

See the code documentation for more in-depth details about implementation and usage.

### Constructor Parameters

#### $lazyConnect

Lazy connect means that the connection is not made until first needed.

By default, lazy connect is disabled (false), but passing true to the constructor will enable it.

#### $autoReconnect

Auto reconnect means that a new connection will be made as needed if the client was previously disconnected from the
database.

It is simply a convenience so that connect() does not need to be called manually later on after disconnecting. As soon
as a method requiring a connection is called, a new connection will be made automatically.

Auto reconnect does not mean that a dead connection will be detected and refreshed, which unfortunately is not feasible
considering prepared statements, transactions, locks, etc., which are all generally stateful.

By default, auto reconnect is disabled (false), but passing true to the constructor will enable it.

### Methods

#### connect()

```php
$db->connect();
```

If not currently connected to the database, a connection will be made.

#### disconnect()

```php
$db->disconnect();
```

If currently connected to the database, the connection will be dropped.

#### reconnect()

```php
$db->reconnect();
```

The current connection to the database will be dropped, and a new connection will be made.

It is simply a shortcut for:

```php
$db->disconnect();
$db->connect();
```

#### isConnected()

```php
$connected = $db->isConnected();
```

This method returns whether the client is currently connected to the database.

It has nothing to do with whether the connection is still alive, but simply whether a connection was made that has not
been manually disconnected. To test whether the connection is still alive, see isAlive();

#### isAlive()

```php
$alive = $db->isAlive();
```

This method returns whether the connection is still alive.

It does so by executing a no-op SQL query and checking whether it succeeds.

To avoid spamming the database when calling this method multiple times in a short time span, you can use the optional
$cacheDuration parameter (integer):
 
```php
$cacheDuration = 3;
$alive = $db->isAlive($cacheDuration);
```
 
The cache duration is the minimum number of seconds between actual connection tests. If the connection was tested within
the last specified number of seconds, calling this method again will simply return the cached alive status, and no
additional query will be executed. By default, the cache is disabled, and calling the method will always test the
connection.

While good programming practice is not to hold a connection open for longer than it is needed at one time, this method
can be used to ensure the connection is still alive before executing more SQL statements if there is a possibility that
the connection has timed out.

## Errors

Any standard PDO errors or exceptions are simply passed through.

The one special case is if a method requiring a connection is called, but the client was previously disconnected and
auto reconnect is disabled, then a \Compeek\PDOWrapper\NotConnectedException will be thrown (even if the PDO error mode
is not set to exceptions).

## Requirements

PDO Wrapper runs on PHP 5.3.0+ and requires the PDO extension.
