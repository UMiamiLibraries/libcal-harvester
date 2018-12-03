<?php
/**
 * Created by PhpStorm.
 * User: acarrasco
 * Date: 11/21/18
 * Time: 10:21 AM
 */

include_once getcwd() . '/config.php';
include_once getcwd() . '/LibCalHarvester.php';

//$harvester = new LibCalHarvester();
//$harvester->ingest();

foreach ($argv as $arg){
	$arg = escapeshellcmd($arg);

	switch ($arg) {
		case "ingest":
			$harvester = new LibCalHarvester();
			$harvester->ingest();
			break;
		case "harvest":
			$harvester = new LibCalHarvester();
			$harvester->harvest();
			break;
		case "remove_all_from_google_drive":
			$harvester = new LibCalHarvester();
			$harvester->remove_all_from_google_drive();
			break;
	}
}