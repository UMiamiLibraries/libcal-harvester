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
	private $google_client;
	private $google_spreadsheet_service;
	private $google_drive_service;
	private $google_spreadsheet_id;
	private $google_spreadsheet_range;
	private $google_drive_file_count = 0;
	private $share_users;

	public function __construct() {

		$this->access_token = $this->getAccessToken();

		global $google_spreadsheet_id;
		global $gogole_json_auth_file_path;
		$client = new \Google_Client();
		$client->setApplicationName( 'LibCalIngester' );
		$client->setScopes( [ \Google_Service_Sheets::SPREADSHEETS, \Google_Service_Drive::DRIVE ] );
		$client->setAccessType( 'offline' );

		$client->setAuthConfig( $gogole_json_auth_file_path );
		$this->google_spreadsheet_service = new Google_Service_Sheets( $client );
		$this->google_drive_service       = new Google_Service_Drive( $client );
		$this->google_client              = $client;
		$this->google_spreadsheet_id      = $google_spreadsheet_id;
		$this->google_spreadsheet_range   = 'Sheet1';

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

	public function remove_all_from_google_drive() {
		$file_ids = $this->getGoogleDriveFileId();

		$errors = [];
		foreach ( $file_ids as $file_id ) {
			try {
				$this->google_drive_service->files->delete( $file_id[0] );
			} catch ( Exception $e ) {
				$errors[] = [ $e, $file_id[1] ];
			}
		}

		foreach ( $errors as $error ) {
			if ( $error[0] instanceof Google_Service_Exception ) {
				echo "Can't delete file " . $error[1];
				echo $error[0]->getMessage();
			}
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

	private function getFiles() {
		global $csv_files_directory_name;
		$directory         = $csv_files_directory_name;
		$scanned_directory = array_diff( scandir( $directory ), array( '..', '.', '.git_keep' ) );

		return $scanned_directory;
	}

	public function ingest() {
		if ( $this->validAccessToken() ) {
			$files = $this->getFiles();
			$file_count = count($files);
			$file_iteration = 1;
			try {
				foreach ( $files as $file ) {
					$this->writeLogs("Processing file " . $file . " File " . $file_iteration++ . " of " . $file_count);
					global $csv_files_directory_name;
					$file_name = $csv_files_directory_name . "/" . $file;
					$h         = fopen( $file_name, "r" );
					$data      = fgetcsv( $h, 1000, "," );
					$new_data  = [];
					while ( ( $data = fgetcsv( $h, 1000, "," ) ) !== false ) {
						if ( $this->checkValidRow( $data ) ) {
							$row                = new stdClass();
							$row->booking_label = $data[4];

							$libCaldateStr = $data[5];
							$libCaldateStr = explode( ",", $libCaldateStr );
							$tempdateStr   = $libCaldateStr[1] . ", " . $libCaldateStr[2];
							$libCaldateStr = trim( $tempdateStr );

							$timeStr = $data[6];
							$timeStr = explode( ':', $timeStr );
							$hours   = (int) $timeStr[0];
							$minutes = (int) $timeStr[1];

							$dateTime = new DateTime();
							$dateTime = $dateTime->createFromFormat( 'M d, Y', $libCaldateStr );

							$dateTime = $dateTime->setTime( $hours, $minutes );

							$timeStamp = $dateTime->getTimestamp();

							$row->booking_start = date( 'm/d/Y H:i', $timeStamp );
							$row->booking_end   = date( 'm/d/Y H:i', $timeStamp + $data[7] * 60 );

							$dateStr              = $data[8];
							$dateStr              = explode( ' ', $dateStr );
							$dateStr              = $dateStr[1] . " " . $dateStr[2] . " " . $dateStr[3];
							$dateTime             = \DateTime::createFromFormat( 'M d, Y', $dateStr );
							$row->booking_created = $dateTime->format( 'Y-m-d H:i:s' );;
							$row->room_name = $data[11];

							$new_data[] = $row;

							if ( count( $new_data ) > 450 ) {
								$this->messageMe( 'Preparing to update Google Sheet' );
								$this->updateGoogleSheet( $new_data );
								$this->messageMe( 'Google Sheet Updated' );
								$this->writeLogs("450 rows processed in file " . $file . " Going to sleep!");
								sleep( 100 );
								$new_data = [];
							}

						} else {
							$data = fgetcsv( $h, 1000, "," );
						}
					}

					$this->messageMe( 'Preparing to update Google Sheet' );
					$this->updateGoogleSheet( $new_data );
					$this->messageMe( 'Google Sheet Updated' );
					$this->writeLogs("All rows processed for file " . $file);
				}
			}catch (Exception $e){
				$this->writeLogs("Error in " . $file);
				$this->writeLogs($e);
			}
		}
	}

	private function writeLogs ($message){
		file_put_contents("logs.txt", date("Y-m-d-H:i:s"). " " . $message . PHP_EOL, FILE_APPEND);
	}

	private function checkValidRow( $data ) {
		if ( count( $data ) != 1 ) {
			$filtered_data = array_filter( $data );
			if ( ! $filtered_data || ( $data[0] == "First Name" && $data[2] == "Email" ) ) {
				return false;
			}elseif (count($filtered_data) == 1){
				return false;
			}
		}

		if (count($data) == 1){
			return false;
		}

		return true;
	}

	private function getGoogleDriveFileId() {

		$file_ids  = [];
		$pageToken = null;
		do {
			$response = $this->google_drive_service->files->listFiles( array(
				'q'         => "mimeType='application/vnd.google-apps.spreadsheet'",
				'spaces'    => 'drive',
				'pageToken' => $pageToken,
				'fields'    => 'nextPageToken, files(id, name)',
			) );

			foreach ( $response->files as $file ) {
				$this->google_drive_file_count += 1;

				$file_ids[] = [ $file->id, $file->name ];
			}
			$pageToken = $response->pageToken;
		} while ( $pageToken != null );

		if ( ! empty( $file_ids ) ) {
			usort( $file_ids, function ( $a, $b ) {
				return strnatcmp( $a[1], $b[1] );
			} );

			return $file_ids;
		}

		return [];
	}

	private function getRowCount( $spreadsheet_id ) {
		$row_count = count( $this->google_spreadsheet_service->spreadsheets_values->get( $spreadsheet_id, 'Sheet1' ) );

		return $row_count;
	}

	private function createSpreadSheet() {
		global $google_drive_folder_id;

		$file_number = $this->google_drive_file_count + 1;
		$folderId    = $google_drive_folder_id;
		$requestBody = new Google_Service_Sheets_Spreadsheet();
		$properties  = new Google_Service_Sheets_SpreadsheetProperties();
		$properties->setTitle( 'libcalTimeSlots_' . $file_number );
		$requestBody->setProperties( $properties );
		$response = $this->google_spreadsheet_service->spreadsheets->create( $requestBody );
		$file_id  = $response->getSpreadsheetId();

		$emptyFileMetadata = new Google_Service_Drive_DriveFile();
		$file              = $this->google_drive_service->files->get( $file_id, array( 'fields' => 'parents' ) );
		$previousParents   = join( ',', $file->parents );
		$this->google_drive_service->files->update( $file_id, $emptyFileMetadata, array(
			'addParents'    => $folderId,
			'removeParents' => $previousParents,
			'fields'        => 'id, parents'
		) );

		$this->google_spreadsheet_id = $file_id;

		$values   = [];
		$data     = [];
		$data[]   = "Booking Nickname";
		$data[]   = "Date and time";
		$data[]   = "Date";
		$data[]   = "Start Time";
		$data[]   = "Duration (minutes)";
		$data[]   = "CountId";
		$data[]   = "Booking created";
		$data[]   = "Room";
		$values[] = $data;

		$this->appendValuesToSpreadsheet( $values );
	}

	private function appendValuesToSpreadsheet( $values ) {

		$body   = new Google_Service_Sheets_ValueRange( [
			'values' => $values
		] );
		$params = [ "valueInputOption" => "USER_ENTERED" ];

		try {
			$this->google_spreadsheet_service->spreadsheets_values->append( $this->google_spreadsheet_id, $this->google_spreadsheet_range,
				$body, $params );
		} catch ( Exception $e ) {
			//Todo handle error
			var_dump( $e->getMessage() );
			die();
		}
	}

	private function messageMe( $message ) {
		echo $message . PHP_EOL;
	}

	private function updateGoogleSheet( $timeslots ) {
		$values         = [];
		$new_data_count = count( $timeslots );

		$file_id = $this->getGoogleDriveFileId();

		if ( ! empty( $file_id ) ) {
			$file_id   = $file_id [ count( $file_id ) - 1 ][0];
			$row_count = $this->getRowCount( $file_id );

			if ( $row_count > 48500 || $row_count + $new_data_count > 48500 ) {
				$this->messageMe( 'Creating new spreadsheet' );
				$this->createSpreadSheet();
				$this->messageMe( 'New spreadsheet created' );
			} else {
				$this->google_spreadsheet_id = $file_id;
			}
		} else {
			$this->messageMe( 'Creating new spreadsheet' );
			$this->createSpreadSheet();
			$this->messageMe( 'New spreadsheet created' );
		}

		foreach ( $timeslots as $timeslot ) {
			$data     = [];
			$data[]   = $timeslot->booking_label; //booking nickname
			$data[]   = date( 'm/d/Y H:i', strtotime( $timeslot->booking_start ) ); //date and time
			$data[]   = date( 'm/d/Y', strtotime( $timeslot->booking_start ) ); //date
			$data[]   = date( 'h:i A', strtotime( $timeslot->booking_start ) );  //start time
			$data[]   = ( strtotime( $timeslot->booking_end ) - strtotime( $timeslot->booking_start ) ) / 60;  //duration (minutes)
			$data[]   = 1;  //countId (minutes)
			$data[]   = $timeslot->booking_created; //booking created
			$data[]   = $timeslot->room_name; //room
			$values[] = $data;
		}

		$this->appendValuesToSpreadsheet( $values );
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