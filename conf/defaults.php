<?php
/**
 * Nymph's configuration defaults.
 *
 * @package Nymph
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */

return [
  /*
   * Nymph Database Driver
   * The database driver for Nymph to use.
   */
  'driver' => 'MySQL',
  /*
   * PubSub Enabled
   * Whether Nymph should use the PubSub functionality. This requires the
   * Nymph-PubSub package.
   */
  'pubsub' => class_exists('\\Nymph\\PubSub\\HookMethods'),
  /*
   * Cache Entities
   * Cache recently retrieved entities to speed up database queries. Uses more
   * memory.
   */
  'cache' => false,
  /*
   * Cache Threshold
   * Cache entities after they're accessed this many times.
   */
  'cache_threshold' => 4,
  /*
   * Cache Limit
   * The number of recently retrieved entities to cache. If you're running out
   * of memory, try lowering this value. 0 means unlimited.
   */
  'cache_limit' => 50,
  /*
   * Empty List Returns an Error
   * When querying for multiple entities with NymphREST, if the list is empty,
   * return a 404 error.
   */
  'empty_list_error' => false,
  /*
   * MySQL specific settings
   */
  'MySQL' => [
    /*
     * Host
     * The host on which to connect to MySQL. Can include a port, like
     * hostname:port.
     */
    'host' => 'localhost',
    /*
     * Port
     * The port on which to connect to MySQL.
     */
    'port' => 3306,
    /*
     * User
     * The MySQL user.
     */
    'user' => 'nymph',
    /*
     * Password
     * The MySQL password.
     */
    'password' => 'password',
    /*
     * Database
     * The MySQL database.
     */
    'database' => 'nymph',
    /*
     * Table Prefix
     * The MySQL table name prefix.
     */
    'prefix' => 'nymph_',
    /*
     * Table Engine
     * The MySQL table engine. You should use MYISAM if you are using
     * MySQL < 5.6.
     *
     * Options are: Any MySQL storage engine supported on your server.
     */
    'engine' => 'InnoDB',
    /*
     * Enable Transactions
     * Whether to use transactions. If your table engine doesn't support
     * it (like MYISAM), you should turn this off.
     */
    'transactions' => true,
    /*
     * Enable Foreign Keys
     * Whether to use foreign keys. If your table engine doesn't support
     * it (like MYISAM), you should turn this off.
     */
    'foreign_keys' => true,
    /*
     * Enable Row Locking
     * Whether to use row locking. If your table engine doesn't support
     * it (like MYISAM), you should turn this off.
     */
    'row_locking' => true,
    /*
     * Enable Table Locking
     * Whether to use table locking. If you use row locking, this should be off.
     * If you can't use row locking (like with MYISAM), you can use table
     * locking to ensure data consistency.
     */
    'table_locking' => false,
  ],
  /*
   * PostgreSQL specific settings
   */
  'PostgreSQL' => [
    /*
     * Connection Type
     * The type of connection to establish with PostreSQL. Choosing socket will
     * attempt to use the default socket path. You can also choose host and
     * provide the socket path as the host. If you get errors that it can't
     * connect, check that your pg_hba.conf file allows the specified user to
     * access the database through a socket.
     *
     * Options are: "host", "socket"
     */
    'connection_type' => 'host',
    /*
     * Host
     * The host on which to connect to PostgreSQL.
     */
    'host' => 'localhost',
    /*
     * Port
     * The port on which to connect to PostgreSQL.
     */
    'port' => 5432,
    /*
     * User
     * The PostgreSQL user.
     */
    'user' => 'nymph',
    /*
     * Password
     * The PostgreSQL password.
     */
    'password' => 'password',
    /*
     * Database
     * The PostgreSQL database.
     */
    'database' => 'nymph',
    /*
     * Table Prefix
     * The PostgreSQL table name prefix.
     */
    'prefix' => 'nymph_',
    /*
     * Use PL/Perl Functions
     * This speeds up PCRE regular expression matching ("match" clauses) a lot,
     * but requires the Perl Procedural Language to be installed on your
     * Postgres server.
     */
    'use_plperl' => false,
    /*
     * Allow Persistent Connections
     * Allow connections to persist, if that is how PHP is configured.
     */
    'allow_persistent' => true,
  ],
  /*
   * SQLite3 specific settings
   */
  'SQLite3' => [
    /*
     * Filename
     * The filename of the SQLite3 DB. Use ':memory:' for an in-memory DB.
     */
    'filename' => ':memory:',
    /*
     * Table Prefix
     * The SQLite3 table name prefix.
     */
    'prefix' => 'nymph_',
    /*
     * Busy Timeout
     * The timeout to use for waiting for the DB to become available.
     * See SQLite3::busyTimeout
     */
    'busy_timeout' => 10000,
    /*
     * Open Flags
     * The flags used to open the SQLite3 db. (Can be used to programmatically
     * open for readonly, which is needed for PubSub.)
     */
    'open_flags' => SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
    /*
     * Encryption Key
     * The encryption key to use to open the database.
     */
    'encryption_key' => null,
  ],
];
