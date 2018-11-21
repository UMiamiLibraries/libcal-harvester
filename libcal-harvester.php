<?php
/**
 * Created by PhpStorm.
 * User: acarrasco
 * Date: 11/21/18
 * Time: 10:21 AM
 */

include_once getcwd() . '/config.php';
include_once getcwd() . '/LibCalHarvester.php';

$harvester = new LibCalHarvester();

$harvester->harvest();

