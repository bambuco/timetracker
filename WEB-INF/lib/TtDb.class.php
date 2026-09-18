<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

/**
 * Thin mysqli adapter that preserves the MDB2 API surface used by Time Tracker.
 * Replaces the abandoned PEAR MDB2 package for PHP 8.2+ compatibility.
 */

require_once 'PEAR.php';

if (!defined('MDB2_FETCHMODE_DEFAULT')) {
  define('MDB2_FETCHMODE_DEFAULT', 0);
}
if (!defined('MDB2_FETCHMODE_ORDERED')) {
  define('MDB2_FETCHMODE_ORDERED', 1);
}
if (!defined('MDB2_FETCHMODE_ASSOC')) {
  define('MDB2_FETCHMODE_ASSOC', 2);
}
if (!defined('MDB2_OK')) {
  define('MDB2_OK', 1);
}

if (!class_exists('MDB2_Error', false)) {
  /**
   * Compatibility error class so existing is_a(..., 'MDB2_Error') / PEAR_Error checks keep working.
   */
  class MDB2_Error extends PEAR_Error {
    function __construct($message = 'MDB2 Error', $code = null) {
      parent::__construct($message, $code);
    }
  }
}

class TtDb {
  /** @var mysqli */
  public $connection;

  /** @var int */
  public $fetchmode = MDB2_FETCHMODE_ASSOC;

  /** @var array */
  public $options = array(
    'debug' => false,
  );

  /** @var string */
  public $last_query = '';

  /** @var array */
  protected $dsn = array();

  /**
   * Connect using an MDB2-style DSN (mysqli://user:pass@host:port/db?charset=utf8mb4).
   *
   * @param string $dsn
   * @return TtDb|MDB2_Error
   */
  static function connect($dsn) {
    $parsed = self::parseDSN($dsn);
    if ($parsed['phptype'] !== 'mysqli') {
      return new MDB2_Error('MDB2 Error: only mysqli DSN is supported (got '.$parsed['phptype'].')');
    }
    if (!extension_loaded('mysqli')) {
      return new MDB2_Error('MDB2 Error: mysqli extension is not loaded');
    }

    $host = $parsed['hostspec'] ? $parsed['hostspec'] : 'localhost';
    $user = $parsed['username'] !== false ? $parsed['username'] : '';
    $pass = $parsed['password'] !== false ? $parsed['password'] : '';
    $db = $parsed['database'] !== false ? $parsed['database'] : '';
    $port = $parsed['port'] ? (int)$parsed['port'] : 0;
    $socket = $parsed['socket'] ? $parsed['socket'] : '';

    mysqli_report(MYSQLI_REPORT_OFF);

    if ($parsed['protocol'] === 'unix' && $socket) {
      $mysqli = @new mysqli($host, $user, $pass, $db, $port, $socket);
    } elseif ($port) {
      $mysqli = @new mysqli($host, $user, $pass, $db, $port);
    } else {
      $mysqli = @new mysqli($host, $user, $pass, $db);
    }

    if ($mysqli->connect_errno) {
      return new MDB2_Error('MDB2 Error: '.$mysqli->connect_error);
    }

    $charset = isset($parsed['charset']) ? $parsed['charset'] : 'utf8mb4';
    if ($charset && !$mysqli->set_charset($charset)) {
      $err = new MDB2_Error('MDB2 Error: Could not set client character set: '.$mysqli->error);
      $mysqli->close();
      return $err;
    }

    $conn = new TtDb();
    $conn->connection = $mysqli;
    $conn->dsn = $parsed;
    return $conn;
  }

  /**
   * Parse an MDB2-style DSN string.
   *
   * @param string $dsn
   * @return array
   */
  static function parseDSN($dsn) {
    $parsed = array(
      'phptype'  => false,
      'dbsyntax' => false,
      'username' => false,
      'password' => false,
      'protocol' => false,
      'hostspec' => false,
      'port'     => false,
      'socket'   => false,
      'database' => false,
    );

    if (is_array($dsn)) {
      return array_merge($parsed, $dsn);
    }

    if (($pos = strpos($dsn, '://')) !== false) {
      $str = substr($dsn, 0, $pos);
      $dsn = substr($dsn, $pos + 3);
    } else {
      $str = $dsn;
      $dsn = null;
    }

    if (preg_match('|^(.+?)\((.*?)\)$|', $str, $arr)) {
      $parsed['phptype'] = $arr[1];
      $parsed['dbsyntax'] = $arr[2] !== '' ? $arr[2] : $arr[1];
    } else {
      $parsed['phptype'] = $str;
      $parsed['dbsyntax'] = $str;
    }

    if ($dsn === null || $dsn === '') {
      return $parsed;
    }

    if (($at = strrpos($dsn, '@')) !== false) {
      $str = substr($dsn, 0, $at);
      $dsn = substr($dsn, $at + 1);
      if (($pos = strpos($str, ':')) !== false) {
        $parsed['username'] = rawurldecode(substr($str, 0, $pos));
        $parsed['password'] = rawurldecode(substr($str, $pos + 1));
      } else {
        $parsed['username'] = rawurldecode($str);
      }
    }

    $proto = null;
    $proto_opts = null;
    if (preg_match('|^([^(]+)\((.*?)\)/?(.*?)$|', $dsn, $match)) {
      $proto = $match[1];
      $proto_opts = $match[2] !== '' ? $match[2] : false;
      $dsn = $match[3];
    } else {
      if (strpos($dsn, '+') !== false) {
        list($proto, $dsn) = explode('+', $dsn, 2);
      }
      if (strpos($dsn, '/') !== false) {
        list($proto_opts, $dsn) = explode('/', $dsn, 2);
      } else {
        $proto_opts = $dsn;
        $dsn = null;
      }
    }

    $parsed['protocol'] = !empty($proto) ? $proto : 'tcp';
    $proto_opts = rawurldecode($proto_opts);
    if (strpos($proto_opts, ':') !== false) {
      list($proto_opts, $parsed['port']) = explode(':', $proto_opts);
    }
    if ($parsed['protocol'] == 'tcp') {
      $parsed['hostspec'] = $proto_opts;
    } elseif ($parsed['protocol'] == 'unix') {
      $parsed['socket'] = $proto_opts;
    }

    if ($dsn) {
      if (($pos = strpos($dsn, '?')) === false) {
        $parsed['database'] = rawurldecode($dsn);
      } else {
        $parsed['database'] = rawurldecode(substr($dsn, 0, $pos));
        $opts = explode('&', substr($dsn, $pos + 1));
        foreach ($opts as $opt) {
          if (strpos($opt, '=') === false) {
            continue;
          }
          list($key, $value) = explode('=', $opt, 2);
          if (!array_key_exists($key, $parsed) || false === $parsed[$key]) {
            $parsed[$key] = rawurldecode($value);
          }
        }
      }
    }

    return $parsed;
  }

  /**
   * @param string $query
   * @return TtDbResult|MDB2_Error
   */
  function query($query) {
    $this->last_query = $query;
    $result = @$this->connection->query($query);
    if ($result === false) {
      return new MDB2_Error('MDB2 Error: '.$this->connection->error);
    }
    return new TtDbResult($result, $this);
  }

  /**
   * Execute a manipulation query and return affected rows.
   *
   * @param string $query
   * @return int|MDB2_Error
   */
  function exec($query) {
    // Match MDB2 PORTABILITY_DELETE_COUNT for bare DELETE FROM table.
    if (preg_match('/^\s*DELETE\s+FROM\s+(\S+)\s*$/i', $query)) {
      $query = preg_replace('/^\s*DELETE\s+FROM\s+(\S+)\s*$/i',
        'DELETE FROM $1 WHERE 1=1', $query);
    }

    $this->last_query = $query;
    $result = @$this->connection->query($query);
    if ($result === false) {
      return new MDB2_Error('MDB2 Error: '.$this->connection->error);
    }
    if ($result instanceof mysqli_result) {
      $result->free();
    }
    return $this->connection->affected_rows;
  }

  /**
   * Quote a value for safe inclusion in SQL (MDB2-compatible behaviour).
   *
   * Empty strings and null become NULL (MDB2_PORTABILITY_EMPTY_TO_NULL).
   *
   * @param mixed $value
   * @param string|null $type
   * @param bool $quote
   * @return string|MDB2_Error
   */
  function quote($value, $type = null, $quote = true) {
    if ($value === null || $value === '') {
      return $quote ? 'NULL' : null;
    }

    if ($type === null) {
      switch (gettype($value)) {
        case 'integer':
          $type = 'integer';
          break;
        case 'double':
          $type = 'decimal';
          break;
        case 'boolean':
          $type = 'boolean';
          break;
        default:
          if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $type = 'timestamp';
          } elseif (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
            $type = 'time';
          } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $type = 'date';
          } else {
            $type = 'text';
          }
          break;
      }
    }

    switch ($type) {
      case 'integer':
        return (string)(int)$value;
      case 'boolean':
        return $value ? '1' : '0';
      case 'decimal':
      case 'float':
        return (string)(float)$value;
      case 'date':
      case 'time':
      case 'timestamp':
      case 'text':
      default:
        if (!$quote) {
          return $value;
        }
        return "'".$this->connection->real_escape_string((string)$value)."'";
    }
  }

  /**
   * Return last auto-increment ID (via LAST_INSERT_ID() to handle BIGINT safely).
   *
   * @param string|null $table unused, kept for MDB2 signature compatibility
   * @param string|null $field unused, kept for MDB2 signature compatibility
   * @return string|MDB2_Error
   */
  function lastInsertID($table = null, $field = null) {
    $res = $this->query('SELECT LAST_INSERT_ID()');
    if (is_a($res, 'MDB2_Error')) {
      return $res;
    }
    $row = $res->fetchRow(MDB2_FETCHMODE_ORDERED);
    if ($row === null) {
      return '0';
    }
    return $row[0];
  }

  /**
   * @param int $fetchmode
   * @return int
   */
  function setFetchMode($fetchmode) {
    $this->fetchmode = $fetchmode;
    return MDB2_OK;
  }

  /**
   * @param string $option
   * @param mixed $value
   * @return int
   */
  function setOption($option, $value) {
    $this->options[$option] = $value;
    return MDB2_OK;
  }

  /**
   * @param bool $force
   * @return bool|int
   */
  function disconnect($force = true) {
    if ($this->connection instanceof mysqli) {
      @$this->connection->close();
      $this->connection = null;
    }
    return true;
  }
}

class TtDbResult {
  /** @var mysqli_result */
  protected $result;

  /** @var TtDb */
  protected $db;

  function __construct($result, $db) {
    $this->result = $result;
    $this->db = $db;
  }

  /**
   * Fetch next row. Returns null when there are no more rows (MDB2 behaviour).
   *
   * @param int $fetchmode
   * @return array|null|MDB2_Error
   */
  function fetchRow($fetchmode = MDB2_FETCHMODE_DEFAULT) {
    if (!($this->result instanceof mysqli_result)) {
      return new MDB2_Error('MDB2 Error: resultset has already been freed');
    }

    if ($fetchmode == MDB2_FETCHMODE_DEFAULT) {
      $fetchmode = $this->db->fetchmode;
    }

    if ($fetchmode == MDB2_FETCHMODE_ORDERED) {
      $row = @$this->result->fetch_row();
    } else {
      $row = @$this->result->fetch_assoc();
    }

    if (!$row) {
      return null;
    }

    // Match MDB2 PORTABILITY_EMPTY_TO_NULL + RTRIM on result values.
    foreach ($row as $key => $value) {
      if ($value === '') {
        $row[$key] = null;
      } elseif (is_string($value)) {
        $row[$key] = rtrim($value);
      }
    }

    return $row;
  }

  /**
   * @return int|MDB2_Error
   */
  function numRows() {
    if (!($this->result instanceof mysqli_result)) {
      return new MDB2_Error('MDB2 Error: resultset has already been freed');
    }
    return $this->result->num_rows;
  }

  function free() {
    if ($this->result instanceof mysqli_result) {
      $this->result->free();
      $this->result = null;
    }
    return true;
  }
}
