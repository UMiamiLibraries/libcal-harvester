<?php
/**
Instructions:
1. Rename this file to config.php
2. Enter your libcal api and Google settings
3. Run libcal-harvester.php

Happy harvesting!!!
 *
 */

$csv_files_directory_name = "";

$libcal_api_url = "";
$libcal_client_id = "";
$libcal_client_secret = "";
$libcal_token_request_endpoint = $libcal_api_url . "/oauth/token";

$google_drive_folder_id="";
$google_spreadsheet_id= "";
$gogole_json_auth_file_path = "";