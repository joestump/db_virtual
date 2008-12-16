<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * DB_Virtual_Cache_Interface
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

/**
 * DB_Virtual_Cache_Interface
 * 
 * DB_Virtual allows you to create cache handlers for caching the result of a 
 * query. Any cache handler you create must implement this interface in order
 * to work properly with DB_Virtual. 
 * 
 * @category   Database
 * @package    DB_Virtual
 * @author     Joe Stump <joe@joestump.net> 
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @see        DB_Virtual
 */
interface DB_Virtual_Cache_Interface 
{
    /**
     * get
     *
     * This function must return false if there was no cached data to return.
     * If it does not then very strange things will happen if you're trying to
     * use caching.
     *
     * @access  public
     * @param   string  $function   Name of function called (ie. getAll())
     * @param   array   $args       Arguments passed to $function
     * @return  mixed
     */
    public function get($function, $args); 

    /**
     * save
     *
     * @access  public
     * @param   string  $function   Name of function called (ie. getAll())
     * @param   array   $args       Arguments passed to $function
     * @param   mixed   $result     The result data to cache
     * @return  mixed
     */
    public function save($function, $args, $result);
}

?>
