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
   * Use PL/Perl Functions
   * (Postgres only) This speeds up PCRE regular expression matching ("match"
   * criteria type) a lot, but requires the Perl Procedural Language to be
   * installed on your Postgres server.
   */
  'use_plperl' => false,
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
     * The MySQL table engine. You can use InnoDB if you are using MySQL >= 5.6.
     *
     * Options are: "MYISAM", "InnoDB"
     */
    'engine' => 'MYISAM',
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
     * Allow Persistent Connections
     * Allow connections to persist, if that is how PHP is configured.
     */
    'allow_persistent' => true,
  ],
];
