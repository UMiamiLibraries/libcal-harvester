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
			$data[] = $timeslot->booking_label;
			$data[] = date('m/d/Y H:i', strtotime($timeslot->booking_start));
			$data[] = date('m/d/Y', strtotime($timeslot->booking_start));
			$data[] = date('h:i A', strtotime($timeslot->booking_start));  //start time
			$data[] = $timeslot->booking_created;
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