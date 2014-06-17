<?php
/**
 * Statistics Class
 *
 * @package  	Delightful Downloads
 * @author   	Ashley Rich
 * @copyright   Copyright (c) 2014, Ashley Rich
 * @since    	1.4
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

class DEDO_Statistics {

	/**
	 *	Init Statistics
	 *
	 * @access public
	 * @since 1.4
	 * @return void
	 */
	public function __construct() {

		global $wpdb;

		// Add custom table to wpdb.
		$wpdb->ddownload_statistics = $wpdb->prefix . 'ddownload_statistics';
	}

	/**
	 * Count Downloads
	 *
	 * Count total downloads for all/single downloads/download. If a date range is set
	 * the statistics table is used. If not, the meta keys are used.
	 *
	 * Data is cached in transients.
	 *
	 * @access public
	 * @since 1.4
	 * @return string
	 */
	public function count_downloads( $args = array() ) {

		global $wpdb;

		// Parse arguments with defaults
		extract( wp_parse_args( $args, array(
			'days'			=> 0,
			'download_id'	=> 0,
			'cache'			=> true
		) ) );

		// First check for cached data
		$key = 'dedo_downloads_days' . $days . 'id' . $download_id;

		if ( true == $cache && false !== ( $cached_data = dedo_get_cache( $key ) ) ) {

			return $cached_data;
		}

		// Days set, convert to start date and pass to count_logs
		if ( $days ) {

			$start_date = $this->convert_days_date( $days );
			$result = $this->count_logs( $download_id, $start_date, false, 'success' );
		}
		// No days set, sum up file count meta_value
		else {

			// Set query
			$sql = $wpdb->prepare( "
				SELECT SUM(meta_value)
				FROM $wpdb->postmeta
				WHERE meta_key = %s
			",
			'_dedo_file_count' );

			// Append download id
			if ( $download_id ) {

				$sql .= $wpdb->prepare( " AND post_id = %d", $download_id );
			}

			$result = $wpdb->get_var( $sql );
		}

		// Save to cache
		dedo_set_cache( $key, $result );

		return ( $result === NULL ) ? 0 : $result;
	}

	/**
	 * Count Logs
	 *
	 * Count logs from statistics table.
	 *
	 * @access public
	 * @since 1.4
	 * @return string
	 */
	public function count_logs( $download_id = false, $start_date = false, $end_date = false, $status = false ) {

		global $wpdb;

		// Set main SQL query
		$sql = "
			SELECT COUNT(ID)
			FROM $wpdb->ddownload_statistics
		";

		// Append where clause for status
		if ( $status ) {

			$sql .= $wpdb->prepare( " WHERE status = %s", $status );
		}
		else {

			$sql .= $wpdb->prepare( " WHERE 1 = %d", 1 );
		}

		// Append download id
		if ( $download_id ) {

			$sql .= $wpdb->prepare( " AND post_id = %d", $download_id );
		}

		// Append start date
		if ( $start_date ) {

			$sql .= $wpdb->prepare( " AND date >= %s", $start_date );
		}

		// Append end date
		if ( $end_date ) {

			$sql .= $wpdb->prepare( " AND date <= %s", $end_date );
		}

		$result = $wpdb->get_var( $sql );

		return ( $result === NULL ) ? 0 : $result;
	}

	/**
	 * Get Popular Downloads
	 *
	 * Get popular downloads and order by download count.
	 * If days supplied use statistics table, else use download meta.
	 *
	 * Selecting by days is slow. Use responsibly!
	 * 
	 * @access public
	 * @since 1.4
	 * @return array
	 */
	function get_popular_downloads( $days = 0, $limit = 5, $cache = true ) {

		global $wpdb;

		// First check for cached data
		$key = 'dedo_popular_days' . $days . 'limit' . $limit;

		if ( true == $cache && false !== ( $cached_data = dedo_get_cache( $key ) ) ) {

			return $cached_data;
		}

		// Days set, convet to start date and use statistics table
		if ( $days ) {

			$start_date = $this->convert_days_date( $days );

			$sql = $wpdb->prepare( "
				SELECT $wpdb->ddownload_statistics.post_id AS ID, COUNT( $wpdb->ddownload_statistics.ID ) AS downloads
				FROM $wpdb->ddownload_statistics
				WHERE $wpdb->ddownload_statistics.status = %s
					AND $wpdb->ddownload_statistics.date >= %s
				GROUP BY $wpdb->ddownload_statistics.post_id
				ORDER BY downloads DESC
				LIMIT %d
			",
			'success',
			$start_date,
			$limit );

			$result = $wpdb->get_results( $sql, ARRAY_A );

			// Get title for each download
			foreach ( $result as $key2 => $value ) {

				$result[$key2]['title'] = get_the_title( $result[$key2]['ID'] );
			}
		}
		// User meta_value file_count
		else {

			$sql = $wpdb->prepare( "
				SELECT $wpdb->posts.ID AS ID, $wpdb->posts.post_title AS title, $wpdb->postmeta.meta_value AS downloads
				FROM $wpdb->posts
				LEFT JOIN $wpdb->postmeta
					ON $wpdb->posts.ID = $wpdb->postmeta.post_id
				WHERE $wpdb->posts.post_type = %s
					AND $wpdb->posts.post_status = %s
					AND meta_key = %s
				ORDER BY CAST( $wpdb->postmeta.meta_value AS unsigned ) DESC
				LIMIT %d
			",
			'dedo_download',
			'publish',
			'_dedo_file_count',
			$limit );

			$result = $wpdb->get_results( $sql, ARRAY_A );
		}

		// Save to cache
		dedo_set_cache( $key, $result );

		return $result;
	}

	/**
	 * Delete Logs
	 *
	 * Delete logs, oldest first.
	 *
	 * @access public
	 * @since 1.4
	 * @return string
	 */
	public function delete_logs( $start_date = false, $end_date = false, $limit = false, $status = false ) {

		global $wpdb;

		$sql = "
			DELETE FROM $wpdb->ddownload_statistics
			WHERE 1 = 1
		";

		// Append start date
		if ( $start_date ) {

			$sql .= $wpdb->prepare( " AND date > %s", $start_date );
		}

		// Append start date
		if ( $end_date ) {

			$sql .= $wpdb->prepare( " AND date < %s", $end_date );
		}

		// Append status
		if ( $status ) {

			$sql .= $wpdb->prepare( " AND status = %s", $status );
		}

		// Append orderby
		$sql .= " ORDER BY date ASC";

		// Append limit
		if ( $limit ) {

			$sql .= $wpdb->prepare( " LIMIT %d", $limit );
		}

		return $wpdb->query( $sql );
	}

	/**
	 * Convert Days Date
	 *
	 * Converts number of days into current date minus days.
	 *
	 * @access public
	 * @since 1.4
	 * @return string
	 */
	public function convert_days_date( $days ) {

		$now = current_time( 'timestamp' );
		$timestamp = strtotime( '-' . $days . ' days', $now );

		return date( 'Y-m-d H:i:s', $timestamp );
	}	

	/**
	 * Setup Statistics Table
	 *
	 * @access public
	 * @since 1.4
	 * @return void
	 */
	public function setup_table() {
		
		global $wpdb;

		$sql = "
			CREATE TABLE $wpdb->ddownload_statistics (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(10) NOT NULL DEFAULT 'success',
				date datetime NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT '0',
				user_ip varbinary(16) NOT NULL,
				user_agent varchar(255) NOT NULL,
			PRIMARY KEY  (ID)
			) DEFAULT CHARSET=$wpdb->charset;
		";

		// Include our database function and run
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( $sql );
	}

	/**
	 * Empty Statistics Table
	 *
	 * @access public
	 * @since 1.4
	 * @return int/boolean (rows affected or false on error)
	 */
	public function empty_table() {
		
		global $wpdb;

		// Only admins allowed to empty table
		if ( !current_user_can( 'administrator' ) ) {
			return;
		}

		$sql = "TRUNCATE TABLE $wpdb->ddownload_statistics";

		return $wpdb->query( $sql );
	}

	/**
	 * Delete Statistics Table
	 *
	 * @access public
	 * @since 1.4
	 * @return int/boolean (rows affected or false on error)
	 */
	public function delete_table() {
		
		global $wpdb;

		// Only admins allowed to remove table
		if ( !current_user_can( 'administrator' ) ) {
			return;
		}

		$sql = "DROP TABLE IF EXISTS $wpdb->ddownload_statistics";

		return $wpdb->query( $sql );
	}	

}

// Initiate the logging system
$GLOBALS['dedo_statistics'] = new DEDO_Statistics();