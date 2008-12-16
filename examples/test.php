<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * test.php 
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

require_once 'DB/Virtual.php';

$db = new DB_Virtual();

// Attach a master (you MUST do this first)
$result = $db->attachMaster('mysql://root@192.168.10.25/enotes_com',50);
if (PEAR::isError($result)) {
    die($result->getMessage()."\n");
} 
        
// Attach a node (do this for however many nodes you have)
$result = $db->attachNode('mysql://root@192.168.10.10/enotes_com',50);
if (PEAR::isError($result)) {
    die($result->getMessage()."\n");
} 

// Depending on the query DB_Virtual will either propagate the call to all
// nodes or send it to the master.
$db->setFetchMode(DB_FETCHMODE_ASSOC);

// Use DB_Virtual just as you would PEAR DB
$result = $db->query("SELECT * FROM tbl");
if (!PEAR::isError($result)) {
    while ($row = $result->fetchRow()) {
        print_r($row);
    }
}

?>
