<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * A DB load balancing class
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Database
 * @package    DB_Virtual
 * @author     Joe Stump <joe@joestump.net> 
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 */

require_once 'DB.php';

define('DB_VIRTUAL_READ',    1);
define('DB_VIRTUAL_WRITE',   2);
define('DB_VIRTUAL_CACHE',   4);
define('DB_VIRTUAL_MASTER',  8);

/**
 * DB_Virtual
 * 
 * DB_Virtual is a package that uses the decorator pattern to implement most
 * of the PEAR DB API in a weight round robin environment. This means you can
 * have one master and N nodes and spread non-manipulation queries across 
 * those nodes. 
 *
 * @category   Database
 * @package    DB_Virtual
 * @author     Joe Stump <joe@joestump.net> 
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @see        DB, DB_common
 */
class DB_Virtual 
{
    /**
     * $master
     * 
     * @access  private
     * @var     string      $master The DSN of the master DB
     */
    private $master = '';

    /**
     * $nodes
     *
     * @access  private
     * @var     array       $slaves An array of DB nodes available to run 
     *                      queries.
     */
    private $nodes = array();

    /**
     * $weights
     *
     * @access  private
     * @var     array       $weights Array of weights keyed by node
     */
    private $weights = array();

    /**
     * $normalized
     *
     * The weights can be given as *any* number and could very well add up
     * to over 100. We use the function normalizeWeight to run through
     * weights and normalize them on a 100 point scale.
     *
     * @access  private
     * @var     array       $normalized Array of normalized weights 
     * @see     DB_Virtual::normalizeWeight()
     * @see     DB_Virtual::$weights
     */
    private $normalized = array();

    /**
     * $masterOnly
     *
     * @access  private
     * @var     boolean     $masterOnly If true send all queries to the master
     */
    private $masterOnly = false;

    /**
     * $lastNode
     *
     * @access  private
     * @var     string      $lastNode The node used for the last query
     */
    private $lastNode = '';

    /**
     * $queryFunctions
     *
     * @access  private
     * @var     array       $queryFunctions 
     * @static
     */
    static private $queryFunctions = array('getOne',
                                           'getCol',
                                           'getAll',
                                           'getRow',
                                           'limitQuery');

    /**
     * $modeKeys
     *
     * @access  private
     * @var     array       $modeKeys 
     * @static
     */
    static private $modeKeys = array('getOne' => 2,
                                     'getCol' => 3,
                                     'getAll' => 3,
                                     'getRow' => 3,
                                     'getAssoc' => 5);

    /**
     * $cacheHandler
     *
     * @access  private
     * @var     object      $cacheHandler   
     */
    private $cacheHandler = null;

    /**
     * attachNode
     *
     * This takes a $dsn, connects to it and registers the weight of the node.
     * If any kind of error occurs it returns a PEAR_Error.
     *
     * @access public
     * @param string $dsn
     * @param int $weight
     * @return mixed PEAR_Error on failure, DB_OK on success
     * @see DB::connect()
     * @see DB_Virtual::attachMaster()
     */
    public function attachNode($dsn, $weight)
    {
        if (!is_array($dsn)) {
            $dsn = DB::parseDSN($dsn);
        }

        if ($this->master == '') {
            return PEAR::raiseError('You must attach a master first');
        }

        if (!is_numeric($weight)) {
            return PEAR::raiseError('Weight must be numeric');
        }

        if ($weight <= 0) {
            return PEAR::raiseError('Weight must be greater than zero');
        }

        if (!isset($this->nodes[$dsn['hostspec']])) {
            $this->nodes[$dsn['hostspec']] = & DB::connect($dsn);
            if (PEAR::isError($this->nodes[$dsn['hostspec']])) {
                $error = $this->nodes[$dsn['hostspec']];
                unset($this->nodes[$dsn['hostspec']]);
                return $error;
            }
            $this->weights[$dsn['hostspec']] = $weight;
            $this->normalizeWeight();
        }

        return DB_OK;
    }

    /**
     * attachMaster
     *
     * @access public
     * @param string $dsn PEAR DB DSN
     * @param int $weight
     * @see DB_Virtual::attachNode()
     */
    public function attachMaster($dsn, $weight)
    {
        if (!is_array($dsn)) {
            $dsn = DB::parseDSN($dsn);
        }

        $this->master = $dsn['hostspec'];
        return $this->attachNode($dsn, $weight);
    }

    /**
     * query
     *
     * The query function runs 100% of all manipulation queries against the
     * master node. Additionally, DB_Virtual keeps track of transactions so if
     * you are in a transaction 100% of all transactions go to the master, 
     * including selects. Otherwise, it grabs a node and runs the query 
     * against that node.
     *
     * @access  public
     * @param   string  $query
     * @param   array   $params
     * @param   int     $mode
     * @see     DB::isManip(), DB_common::query()
     * @see     DB_Virtual::getNode()
     */
    public function query($query, $params = array(), $mode = DB_VIRTUAL_MASTER)
    {
        if (DB::isManip($query) || $this->masterOnly == true ||
            $mode & DB_VIRTUAL_MASTER || $mode & DB_VIRTUAL_WRITE) {
            $node = $this->master;
        } else {
            $node = $this->getNode();
        }

        if ($mode & DB_VIRTUAL_CACHE) {
            trigger_error('You cannot use DB_VIRTUAL_CACHE in DB::query()', E_USER_NOTICE);
        }

        if ($mode & DB_VIRTUAL_MASTER) {
            return $this->queryMaster($query, $params);
        }

        $this->lastNode = $node;
        $result = $this->nodes[$node]->query($query, $params);
        if ($node != $this->master && 
            !PEAR::isError($result) && $result->numRows() == 0) {
            $result = $this->nodes[$this->master]->query($query, $params);
        }

        return $result;
    }

    /**
     * queryMaster
     *
     * Sometimes you want to send SELECT queries to your master. A good 
     * example is when you insert X records and then, immediately afterwards,
     * need to query that data. Sometimes, due to master<->slave latency, the
     * data hasn't arrived on the slave yet and, as a result, does not exist
     * yet.
     *
     * @access public
     * @param string $query
     * @param array $params
     * @see DB_common::query()
     * @see DB_Virtual::$master, DB_Virtual::$lastNode
     */
    public function queryMaster($query, $params = array()) 
    {
        $this->lastNode = $this->master;
        return $this->nodes[$this->master]->query($query, $params);
    }

    /**
     * autoCommit
     *
     * @access public
     * @param boolean $onoff 
     * @see DB_common::autoCommit()
     * @return mixed
     */
    public function autoCommit($onoff = false)
    {
        $result = $this->nodes[$this->master]->autoCommit($onoff);
        if (!PEAR::isError($result)) {
            $this->masterOnly = ($onoff == false);
        }

        return $result;
    }

    /**
     * __call
     *
     * Call takes the function and, depending on the function, either runs it
     * against a random node (ie. non-manip functions), the master (for 
     * transactions) or *all* of the nodes (ie. setting the fetch mode).
     *
     * @access public
     * @param string $function
     * @param array $args
     * @return mixed
     */
    public function __call($function, $args) 
    {
        $node = null;
        $mode = DB_VIRTUAL_MASTER;
        switch($function)
        {
        // Run on a random node
        case 'getOne':
        case 'getCol':
        case 'getAll':
        case 'getRow':
        case 'limitQuery':
        case 'quoteSmart':
        case 'getTables':
        case 'getAssoc':
            if (isset(self::$modeKeys[$function]) && 
                isset($args[self::$modeKeys[$function]])) {
                $mode = $args[self::$modeKeys[$function]];
                if (is_null($args[(self::$modeKeys[$function] - 1)])) {
                    $args[(self::$modeKeys[$function] - 1)] = DB_FETCHMODE_DEFAULT;
                }
            }

            if ($mode & DB_VIRTUAL_MASTER) {
                $node = $this->master;
            } else {
                $node = $this->getNode();
            }
            break;
        // Run these functions on only the master
        case 'prepare':
        case 'provides':
        case 'tableInfo':
        case 'getOption':
        case 'getListOf':
        case 'commit':
        case 'rollback':
            $node = $this->master;
            break;
        // Run these functions on all of the nodes
        case 'disconnect':
        case 'setOption':
        case 'setFetchMode':
            foreach ($this->nodes as $dsn => $db) {
                if (PEAR::isError($this->nodes[$dsn])) {
                    trigger_error($this->nodes[$dsn]->getMessage().' ('.$dsn.') '.$this->nodes[$dsn]->getUserInfo(), E_USER_WARNING);
                } else {
                    $result = call_user_func_array(array($this->nodes[$dsn],$function),$args);
                    if (PEAR::isError($result)) {
                        return $result;
                    }
                }
            }

            return DB_OK;
        // Run these on the lastNode
        case 'affectedRows':
            $result = call_user_func_array(array($this->nodes[$this->lastNode], $function), $args);
            if (PEAR::isError($result)) {
                return $result;
            }

            return DB_OK;
        }

        if (is_null($node)) {
            $err = 'The PEAR DB function '.$function.' is not supported by DB_Virtual at this time';
            trigger_error($err, E_USER_WARNING);
            return PEAR::raiseError($err);
        }

        if (in_array($function,DB_Virtual::$queryFunctions)) {
            $this->lastNode = $node;
        }

        if (!is_null($this->cacheHandler) && $mode & DB_VIRTUAL_CACHE && 
            isset(self::$modeKeys[$function])) {
            $data = $this->cacheHandler->get($function, $args);
            if (PEAR::isError($data)) {
                return $data;
            }

            if ($data !== false) {
                return $data;
            }
        }

        if (PEAR::isError($this->nodes[$node])) {
            trigger_error($this->nodes[$node]->getMessage(), E_USER_NOTICE);
            return $this->nodes[$node];
        }

        $result = call_user_func_array(array($this->nodes[$node],$function),$args);
        if (PEAR::isError($result)) {
            return $result;
        }

        if (!is_null($this->cacheHandler) && $mode & DB_VIRTUAL_CACHE && 
            isset(self::$modeKeys[$function])) {
            $this->cacheHandler->save($function, $args, $result);
        }

        return $result;
    }

    /**
     * getNode
     *
     * @access private
     * @return string 
     */
    private function getNode()
    {
        $rand = rand(0,100);
        foreach ($this->normalized as $weight => $nodes) {
            if ($rand <= $weight) {
                if (count($nodes) == 1) {
                    return $nodes[0];
                } else {
                    $n = array_rand($nodes);
                    return $nodes[$n];
                }
            }
        }
    
        // Return the last $dsn from the loop as this means our rand() was
        // higher than it's normalized weight.
        if (count($nodes) == 1) {
            return $nodes[0];
        } else {
            $n = array_rand($nodes);
            return $nodes[$n];
        }
    }

    /**
     * normalizeWeight
     *
     * @access private
     * @return void
     */
    private function normalizeWeight()
    {
        // Reset the normalized array befor re-normalizing weights
        $this->normalized = array();

        $total = 0;
        foreach ($this->weights as $dsn => $weight) {
            $total += $weight;
        }
        
        foreach ($this->weights as $dsn => $weight) {
            // $this->normalized[$dsn] = round((($weight / $total) * 100)); 
            $newWeight = round((($weight / $total) * 100)); 
            if (!isset($this->normalized[$newWeight])) {
                $this->normalized[$newWeight] = array();
            } 

            $this->normalized[$newWeight][] = $dsn;
        }

        ksort($this->normalized);
    }

    /**
     * __get
     *
     * @access public
     * @param string $var
     * @return mixed
     */
    public function __get($var)
    {
        switch ($var) {
        case 'last_query':
            return $this->nodes[$this->lastNode]->last_query;
        case 'lastNode':
            return $this->lastNode;
        default:
            return $this->nodes[$this->master]->$var;
        }
    }

    /**
     * __set
     *
     * @access public
     * @param string $var
     * @param mixed $val
     * @return void
     */
    public function __set($var,$val)
    {
        foreach ($this->nodes as $dsn => $db) {
            $this->nodes[$dsn]->$var = $val;
        }
    }

    /**
     * accept
     *
     * @access  public
     * @param   object      $object Object being accepted
     * @return  boolean     True if it was accepted, false if not
     */
    public function accept($object)
    {
        if ($object instanceof DB_Virtual_Cache_Interface) {
            $this->cacheHandler = $object;
            return true;
        }

        return false;
    }
}

?>
