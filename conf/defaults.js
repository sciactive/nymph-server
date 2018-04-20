export default {
  driver: 'MySQL',
  pubsub: typeof global['\\Nymph\\PubSub\\HookMethods'] === 'function',
  cache: false,
  cache_threshold: 4,
  cache_limit: 50,
  empty_list_error: false,
  MySQL: {
    host: 'localhost',
    port: 3306,
    user: 'nymph',
    password: 'password',
    database: 'nymph',
    prefix: 'nymph_',
    engine: 'InnoDB',
    transactions: true,
    foreign_keys: true,
    row_locking: true,
    table_locking: false
  },
  PostgreSQL: {
    connection_type: 'host',
    host: 'localhost',
    port: 5432,
    user: 'nymph',
    password: 'password',
    database: 'nymph',
    prefix: 'nymph_',
    use_plperl: false,
    allow_persistent: true
  },
  SQLite3: {
    filename: ':memory:',
    prefix: 'nymph_',
    busy_timeout: 10000,
    open_flags: SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
    encryption_key: undefined
  }
};
