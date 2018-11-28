<?php
/**
 * Created by PhpStorm.
 * User: acarrasco
 * Date: 11/21/18
 * Time: 10:25 AM
 */

require __DIR__ . '/vendor/autoload.php';

class LibCalHarvester {

	private $access_token;

	public function __construct() {

		$this->access_token = $this->getAccessToken();
	}

	private function getAccessToken() {
		global $libcal_client_id;
		global $libcal_client_secret;
		global $libcal_token_request_endpoint;
		$curl = curl_init();

		curl_setopt_array( $curl, array(
			CURLOPT_URL            => $libcal_token_request_endpoint,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => "POST",
			CURLOPT_POSTFIELDS     => array(
				'client_id'     => $libcal_client_id,
				'client_secret' => $libcal_client_secret,
				'grant_type'    => 'client_credentials'
			)
		) );

		$response = curl_exec( $curl );
		$err      = curl_error( $curl );

		curl_close( $curl );

		if ( $err ) {
			return "";
		} else {
			$decoded_response = json_decode( $response );

			return $decoded_response->access_token;
		}
	}

	public function harvest() {
		if ( $this->validAccessToken() ) {
			$room_groups_ids = $this->getRoomGroupsIds();

			foreach ( $room_groups_ids as $group_id ) {
				$timeslots = $this->getTimeSlots( $group_id );
				$this->updateGoogleSheet( $timeslots );
			}
		}
	}

	public function ingest() {
		if ( $this->validAccessToken() ) {
			$h = fopen("lc_rooms_20181126051044.csv", "r");

			$data = fgetcsv($h, 1000, ",");

			$new_data = [];

			while (($data = fgetcsv($h, 1000, ",")) !== FALSE)
			{
				if ($this->checkValidRow($data)){
					$row = new stdClass();
					$row->booking_label = $data[4];

					$libCaldateStr = $data[5];
					$libCaldateStr = explode(",", $libCaldateStr);
					$tempdateStr = $libCaldateStr[1] . ", " . $libCaldateStr[2];
					$libCaldateStr = trim($tempdateStr);

					$timeStr = $data[6];

					$timeStr = explode(':', $timeStr);

					$hours = (int) $timeStr[0];
					$minutes = (int) $timeStr[1];

					$dateTime = new DateTime();
					$dateTime = $dateTime->createFromFormat('M d, Y', $libCaldateStr);

					$dateTime = $dateTime->setTime($hours, $minutes);

					$timeStamp = $dateTime->getTimestamp();

					$row->booking_start = date('m/d/Y H:i', $timeStamp);
					$row->booking_end = date('m/d/Y H:i', $timeStamp+$data[7]*60);

					$dateStr = $data[8];
					$dateStr = explode(' ', $dateStr);
					$dateStr = $dateStr[1] . " " . $dateStr[2] . " " . $dateStr[3];
					$dateTime = \DateTime::createFromFormat('M d, Y', $dateStr);
					$row->booking_created = $dateTime->format('Y-m-d H:i:s');;
					$row->room_name = $data[11];

					$new_data[] = $row;

					if (count($new_data) > 450){
						$this->updateGoogleSheet($new_data);
						sleep(100);
						$new_data = [];
					}

				}else{
					$data = fgetcsv($h, 1000, ",");
				}
			}

			$this->updateGoogleSheet($new_data);
		}
	}

	private function checkValidRow ($data){
		if (count($data) != 1){
			if(!array_filter($data)) {
				return false;
			}else{
				if ($data[0]=="First Name" && $data[2] == "Email"){
					return false;
				}
			}
		}

		return true;
	}

	private function updateGoogleSheet( $timeslots ) {


		global $google_spreadsheet_id;
		global $google_spreadsheet_range;
		global $gogole_json_auth_file_path;
		$client = new \Google_Client();
		$client->setApplicationName( 'My PHP App' );
		$client->setScopes( [ \Google_Service_Sheets::SPREADSHEETS ] );
		$client->setAccessType( 'offline' );

		$client->setAuthConfig( $gogole_json_auth_file_path);
		$service = new Google_Service_Sheets( $client );
		$spreadsheetId = $google_spreadsheet_id;
		$range = $google_spreadsheet_range;

		$values = [];

		foreach ($timeslots as $timeslot){
			$data = [];
			$data[] = $timeslot->booking_label; //booking nickname
			$data[] = date('m/d/Y H:i', strtotime($timeslot->booking_start)); //date and time
			$data[] = date('m/d/Y', strtotime($timeslot->booking_start)); //date
			$data[] = date('h:i A', strtotime($timeslot->booking_start));  //start time
			$data[] = (strtotime($timeslot->booking_end)-strtotime($timeslot->booking_start))/60;  //duration (minutes)
			$data[] = 1;  //countId (minutes)
			$data[] = $timeslot->booking_created; //booking created
			$data[] = $timeslot->room_name; //room
			$values[] = $data;
		}

		$body = new Google_Service_Sheets_ValueRange([
			'values' => $values
		]);
		$params = ["valueInputOption" => "USER_ENTERED"];

		try{
			$service->spreadsheets_values->append($spreadsheetId, $range,
				$body, $params);
		}catch (Exception $e){
			//Todo handle error

			var_dump($e);
			die();
		}



	}

	private function validAccessToken() {
		if ( empty( $this->access_token ) ) {
			return false;
		}

		return true;
	}

	private function getTimeSlots( $group_id ) {
		global $libcal_api_url;
		$query = http_build_query( [
			'group_id' => $group_id,
			'date'     => date( "Y-m-d" ),
		] );
		$url   = $libcal_api_url . "/room_bookings_nickname?" . $query;

		$http_header  = array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->access_token
		);
		$request_type = "GET";

		$response  = $this->doCurl( $url, "", $request_type, $http_header );
		$response  = json_decode( $response );
		$timeslots = $response->bookings->timeslots;

		return $timeslots;
	}

	private function getRoomGroupsIds() {
		global $libcal_api_url;
		$url          = $libcal_api_url . "/room_groups";
		$http_header  = array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->access_token
		);
		$request_type = "GET";

		$response = $this->doCurl( $url, "", $request_type, $http_header );
		$response = json_decode( $response );
		$groups   = $response->groups;

		$result = [];
		foreach ( $groups as $group ) {
			$group_id = $group->group_id;
			$result[] = $group_id;
		}

		return $result;

	}

	private function doCurl( $url, $post_fields = "", $request_type, $http_header = "" ) {
		$curl = curl_init();

		curl_setopt_array( $curl, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => $request_type,
			CURLOPT_HTTPHEADER     => $http_header,
			CURLOPT_POSTFIELDS     => $post_fields
		) );

		$response = curl_exec( $curl );

		$err = curl_error( $curl );

		curl_close( $curl );

		if ( $err ) {
			return "";
		} else {
			return $response;
		}
	}

}