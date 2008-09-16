<?php
/**
*
* @package dbal
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

include_once(PHPBB_ROOT_PATH . 'includes/db/dbal.' . PHP_EXT);

/**
* PostgreSQL Database Abstraction Layer
* Minimum Requirement is Version 7.3+
* @package dbal
*/
class dbal_postgres extends dbal
{
	var $last_query_text = '';

	var $dbms_type = 'postgres';

	/**
	* Connect to server
	*/
	function sql_connect($sqlserver, $sqluser, $sqlpassword, $database, $port = false, $persistency = false, $new_link = false)
	{
		$connect_string = '';

		if ($sqluser)
		{
			$connect_string .= "user=$sqluser ";
		}

		if ($sqlpassword)
		{
			$connect_string .= "password=$sqlpassword ";
		}

		if ($sqlserver)
		{
			if (strpos($sqlserver, ':') !== false)
			{
				list($sqlserver, $port) = explode(':', $sqlserver);
			}

			if ($sqlserver !== 'localhost')
			{
				$connect_string .= "host=$sqlserver ";
			}
		
			if ($port)
			{
				$connect_string .= "port=$port ";
			}
		}

		$schema = '';

		if ($database)
		{
			$this->dbname = $database;
			if (strpos($database, '.') !== false)
			{
				list($database, $schema) = explode('.', $database);
			}
			$connect_string .= "dbname=$database";
		}

		$this->persistency = $persistency;

		$this->db_connect_id = ($this->persistency) ? @pg_pconnect($connect_string, $new_link) : @pg_connect($connect_string, $new_link);

		if ($this->db_connect_id)
		{
			if (version_compare($this->sql_server_info(true), '8.2', '>='))
			{
				$this->multi_table_deletion = true;
				$this->multi_insert = true;
			}

			if ($schema !== '')
			{
				@pg_query($this->db_connect_id, 'SET search_path TO ' . $schema);
			}

			return $this->db_connect_id;
		}

		return $this->sql_error('');
	}

	/**
	* Version information about used database
	* @param bool $raw if true, only return the fetched sql_server_version
	* @return string sql server version
	*/
	function sql_server_info($raw = false)
	{
		global $cache;

		if (empty($cache) || ($this->sql_server_version = $cache->get('pgsql_version')) === false)
		{
			$query_id = @pg_query($this->db_connect_id, 'SELECT VERSION() AS version');
			$row = @pg_fetch_assoc($query_id, null);
			@pg_free_result($query_id);

			$this->sql_server_version = (!empty($row['version'])) ? trim(substr($row['version'], 10)) : 0;

			if (!empty($cache))
			{
				$cache->put('pgsql_version', $this->sql_server_version);
			}
		}

		return ($raw) ? $this->sql_server_version : 'PostgreSQL ' . $this->sql_server_version;
	}

	/**
	* SQL Transaction
	* @access private
	*/
	function _sql_transaction($status = 'begin')
	{
		switch ($status)
		{
			case 'begin':
				return @pg_query($this->db_connect_id, 'BEGIN');
			break;

			case 'commit':
				return @pg_query($this->db_connect_id, 'COMMIT');
			break;

			case 'rollback':
				return @pg_query($this->db_connect_id, 'ROLLBACK');
			break;
		}

		return true;
	}

	/**
	* Base query method
	*
	* @param	string	$query		Contains the SQL query which shall be executed
	* @param	int		$cache_ttl	Either 0 to avoid caching or the time in seconds which the result shall be kept in cache
	* @return	mixed				When casted to bool the returned value returns true on success and false on failure
	*
	* @access	public
	*/
	function sql_query($query = '', $cache_ttl = 0)
	{
		if ($query != '')
		{
			global $cache;

			// EXPLAIN only in extra debug mode
			if (defined('DEBUG_EXTRA'))
			{
				$this->sql_report('start', $query);
			}

			$this->last_query_text = $query;
			$this->query_result = ($cache_ttl && method_exists($cache, 'sql_load')) ? $cache->sql_load($query) : false;
			$this->sql_add_num_queries($this->query_result);

			if ($this->query_result === false)
			{
				if (($this->query_result = pg_query($this->db_connect_id, $query)) === false)
				{
					$this->sql_error($query);
				}

				if (defined('DEBUG_EXTRA'))
				{
					$this->sql_report('stop', $query);
				}

				if ($cache_ttl && method_exists($cache, 'sql_save'))
				{
					$this->open_queries[(int) $this->query_result] = $this->query_result;
					$cache->sql_save($query, $this->query_result, $cache_ttl);
				}
				else if (strpos($query, 'SELECT') === 0 && $this->query_result)
				{
					$this->open_queries[(int) $this->query_result] = $this->query_result;
				}
			}
			else if (defined('DEBUG_EXTRA'))
			{
				$this->sql_report('fromcache', $query);
			}
		}
		else
		{
			return false;
		}

		return $this->query_result;
	}

	/**
	* Build db-specific query data
	* @access private
	*/
	function _sql_custom_build($stage, $data)
	{
		return $data;
	}

	/**
	* Build LIMIT query
	*/
	function _sql_query_limit($query, $total, $offset = 0, $cache_ttl = 0)
	{
		$this->query_result = false;

		// if $total is set to 0 we do not want to limit the number of rows
		if ($total == 0)
		{
			$total = -1;
		}

		$query .= "\n LIMIT $total OFFSET $offset";

		return $this->sql_query($query, $cache_ttl);
	}

	/**
	* Return number of affected rows
	*/
	function sql_affectedrows()
	{
		return ($this->query_result) ? @pg_affected_rows($this->query_result) : false;
	}

	/**
	* Fetch current row
	*/
	function sql_fetchrow($query_id = false)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		if (isset($cache->sql_rowset[$query_id]))
		{
			return $cache->sql_fetchrow($query_id);
		}

		return ($query_id !== false) ? @pg_fetch_assoc($query_id, null) : false;
	}

	/**
	* Get last inserted id after insert statement
	*/
	function sql_nextid()
	{
		$query_id = $this->query_result;

		if ($query_id !== false && $this->last_query_text != '')
		{
			if (preg_match("/^INSERT[\t\n ]+INTO[\t\n ]+([a-z0-9\_\-]+)/is", $this->last_query_text, $tablename))
			{
				$query = "SELECT currval('" . $tablename[1] . "_seq') AS last_value";
				$temp_q_id = @pg_query($this->db_connect_id, $query);

				if (!$temp_q_id)
				{
					return false;
				}

				$temp_result = @pg_fetch_assoc($temp_q_id, NULL);
				@pg_free_result($query_id);

				return ($temp_result) ? $temp_result['last_value'] : false;
			}
		}

		return false;
	}

	/**
	* Free sql result
	*/
	function sql_freeresult($query_id = false)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		if (isset($cache->sql_rowset[$query_id]))
		{
			return $cache->sql_freeresult($query_id);
		}

		if (isset($this->open_queries[(int) $query_id]))
		{
			unset($this->open_queries[(int) $query_id]);
			return @pg_free_result($query_id);
		}

		return false;
	}

	/**
	* Escape string used in sql query
	* Note: Do not use for bytea values if we may use them at a later stage
	*/
	function sql_escape($msg)
	{
		return @pg_escape_string($msg);
	}

	/**
	* Expose a DBMS specific function
	*/
	function sql_function($type, $col)
	{
		switch ($type)
		{
			case 'length_varchar':
			case 'length_text':
				return 'LENGTH(' . $col . ')';
			break;
		}
	}

	function sql_handle_data($type, $table, $data, $where = '')
	{
		// for now, stmtname is an empty string, it might change to something more unique in the future
		if ($type === 'INSERT')
		{
			$stmt = pg_prepare($this->dbms_type, '', "INSERT INTO $table (". implode(', ', array_keys($data)) . ") VALUES ($" . implode(', $', range(1, sizeof($data))) . ')');
		}
		else
		{
			$query = "UPDATE $table SET ";

			$set = array();
			foreach (array_keys($data) as $key_id => $key)
			{
				$set[] = $key . ' = $' . $key_id;
			}
			$query .= implode(', ', $set);

			if ($where !== '')
			{
				$query .= $where;
			}
			
			$stmt = pg_prepare($this->db_connect_id, '', $query);
		}

		// add the stmtname to the top
		array_unshift($data, '');

		// add the connection resource
		array_unshift($data, $this->db_connect_id);

		call_user_func_array('pg_execute', $data);
	}

	/**
	* Build LIKE expression
	* @access private
	*/
	function _sql_like_expression($expression)
	{
		return $expression;
	}

	/**
	* return sql error array
	* @access private
	*/
	function _sql_error()
	{
		return array(
			'message'	=> (!$this->db_connect_id) ? @pg_last_error() : @pg_last_error($this->db_connect_id),
			'code'		=> ''
		);
	}

	/**
	* Close sql connection
	* @access private
	*/
	function _sql_close()
	{
		return @pg_close($this->db_connect_id);
	}

	/**
	* Build db-specific report
	* @access private
	*/
	function _sql_report($mode, $query = '')
	{
		switch ($mode)
		{
			case 'start':

				$explain_query = $query;
				if (preg_match('/UPDATE ([a-z0-9_]+).*?WHERE(.*)/s', $query, $m))
				{
					$explain_query = 'SELECT * FROM ' . $m[1] . ' WHERE ' . $m[2];
				}
				else if (preg_match('/DELETE FROM ([a-z0-9_]+).*?WHERE(.*)/s', $query, $m))
				{
					$explain_query = 'SELECT * FROM ' . $m[1] . ' WHERE ' . $m[2];
				}

				if (preg_match('/^SELECT/', $explain_query))
				{
					$html_table = false;

					if ($result = @pg_query($this->db_connect_id, "EXPLAIN $explain_query"))
					{
						while ($row = @pg_fetch_assoc($result, NULL))
						{
							$html_table = $this->sql_report('add_select_row', $query, $html_table, $row);
						}
					}
					@pg_free_result($result);

					if ($html_table)
					{
						$this->html_hold .= '</table>';
					}
				}

			break;

			case 'fromcache':
				$endtime = explode(' ', microtime());
				$endtime = $endtime[0] + $endtime[1];

				$result = @pg_query($this->db_connect_id, $query);
				while ($void = @pg_fetch_assoc($result, NULL))
				{
					// Take the time spent on parsing rows into account
				}
				@pg_free_result($result);

				$splittime = explode(' ', microtime());
				$splittime = $splittime[0] + $splittime[1];

				$this->sql_report('record_fromcache', $query, $endtime, $splittime);

			break;
		}
	}
}

?>