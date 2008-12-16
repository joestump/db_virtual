<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * DB_Virtual_Cache_Basic
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

require_once 'DB/Virtual/Cache/Interface.php';
require_once 'System/Folders.php';
require_once 'Cache/Lite.php';

/**
 * DB_Virtual_Cache_Basic
 * 
 * Meant to be a simple example of how you can create and use a cache handler
 * with DB_Virtual. As it caches EVERYTHING to the system's temp directory I
 * wouldn't recommend using it in production.
 * 
 * @category   Database
 * @package    DB_Virtual
 * @author     Joe Stump <joe@joestump.net> 
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @see        DB_Virtual
 */
class DB_Virtual_Cache_Basic implements DB_Virtual_Cache_Interface
{
    /**
     * $cache
     *
     * @access      protected
     * @var         object      $cache Instance of Cache_Lite
     * @link        http://pear.php.net/package/Cache_Lite
     * @static
     */
    static protected $cache = null;

    /**
     * $cacheOptions
     *
     * @access      protected
     * @var         array       $cacheOptions Options for Cache_Lite
     * @static
     */
    static protected $cacheOptions = array('lifeTime' => 3600,
                                           'fileLocking' => false,
                                           'readControl' => false,
                                           'automaticSerialization' => true,
                                           'writeControl' => false,
                                           'hashedDirectoryLevel' => 2);

    /**
     * __construct
     *
     * @access  public
     * @return  void
     */
    public function __construct()
    {
        $sf = new System_Folders();
        self::$cacheOptions['cacheDir'] = $sf->getTemp();
    }

    /**
     * get
     *
     * @access  public
     * @param   string  $function   Name of function called (ie. getOne())
     * @param   string  $args       Args that $function was called with
     * @return  mixed
     */
    public function get($function, $args) 
    {
        $cache = & self::getCache();
        if (PEAR::isError($cache)) {
            return $cache;
        }

        $cacheID = self::getCacheId($function, $args);
        if ($data = $cache->get($cacheID)) {
            return $data;
        } 

        return false;
    }

    /**
     * save
     *
     * @access  public
     * @param   string  $function   Name of function called
     * @param   string  $args       Args that $function was called with
     * @param   mixed   $result     Result set to cache
     * @return  mixed
     */
    public function save($function, $args, $result)
    {
        $cache = & self::getCache();
        if (PEAR::isError($cache)) {
            return $cache;
        }

        $cacheID = self::getCacheId($function, $args);
        if (!$cache->save($result, $cacheID)) {
            return PEAR::raiseError('Could not save cache: '.$cacheID);
        } 

        return true;
    }

    /**
     * getCacheId
     *
     * @access  protected
     * @return  string
     * @static
     */
    static protected function getCacheId()
    {
        $args = func_get_args();
        return md5(serialize($args));
    }

    /**
     * getCache
     * 
     * @access  protected
     * @retuen  object A reference to Cache_Lite
     * @static
     */
    static protected function &getCache()
    {
        if (is_null(self::$cache)) {
            self::$cache = new Cache_Lite(self::$cacheOptions);
        }   
        
        return self::$cache;
    }   
}

?>
