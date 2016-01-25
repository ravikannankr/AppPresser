<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Allows plugins to use their own update API.
 *
 * @author Matt Thiessen
 * @version 2.0.0
 */
class AppPresser_License_Check {

	// A single instance of this class.
	public static $instance = null;

	const ADMIN_LIC_NAG = 'appp_license_nag';
	const DEBUG = false;

	public static $check_frequency = 86400;  // 86400 = one day to check license expiration
	public static $admin_lic_nag_length = 1814400; // 1814400 = 21 days to silence the admin nag
	public static $admin_dismiss = '';
	public static $expired_licenses = array();

	public function __construct() {
		if(self::DEBUG) {
			self::$check_frequency = 10;
			self::$admin_lic_nag_length = 10;
			if(isset($_GET['appp_debug'])) {
				delete_transient( $transient );
			}
		}
		$this->hooks();
		$this->license_check();
	}

	public static function run() {
		if ( self::$instance === null )
			self::$instance = new self();

		return self::$instance;
	}

	public function hooks() {
		add_action( 'wp_ajax_app_license_dismiss', array( $this, 'license_dismiss' ) );
		add_action( 'wp_ajax_nopriv_app_license_dismiss', array( $this, 'license_dismiss' ) );
	}

	public function license_dismiss() {
		global $current_user;

		$user_id = $current_user->ID;
		if ( ! get_transient(self::ADMIN_LIC_NAG . $user_id) ) {
			set_transient( self::ADMIN_LIC_NAG . $user_id, true, self::$admin_lic_nag_length );
		}

		echo $user_id;
		die();
	}

	/**
	 * Only check the transient cache status. If the transient has expired
	 * then request the license status and then resave the transient
	 */
	public function license_check() {

		global $current_user;

		$user_id = $current_user->ID;

		if( ! is_admin() || ! user_can( $current_user, 'manage_options' ) ) {
			return;
		}

		if ( get_transient(self::ADMIN_LIC_NAG . $user_id) ) {
			// wait to nag the admin
			return;
		}

		if( get_transient( 'appp_license_' . $user_id ) ) {
			// too soon to check licenses
			return;
		}

		$this->get_expired_appp_licenses();

		if( empty( self::$expired_licenses ) ) {
			// still valid
			set_transient( 'appp_license_' . $user_id, 'valid', self::$check_frequency );
		} else {
			// not valid, notify
			add_action( 'admin_notices', array( $this, 'show_notices' ) );
		}
	}

	/**
	 * Gets the invalid liceses from EDD and checks the 'expires' value to now
	 * @since 2.0.0
	 * @return array expired_licenses
	 */
	public static function get_expired_appp_licenses() {

		self::$expired_licenses = array();

		$license_keys = AppPresser_Admin_Settings::license_keys();

		foreach ( $license_keys as $key_setting_name => $dir_file ) {

			// false or license key
			$_key = appp_get_setting( $key_setting_name );

			if( $_key ) {

				$theme_name = ( defined('AppPresser_Theme_Setup::THEME_SLUG') ) ? AppPresser_Theme_Setup::THEME_SLUG : false;

				// apptheme or plugin
				$is_plugin = ( $theme_name === false || $dir_file != $theme_name );

				$status = self::get_license_status( $_key, $dir_file, $is_plugin );

				// valid or ( invalid, compare expired date )
				if( self::DEBUG && ( $status->license == 'valid' && isset($status->expires) && strtotime($status->expires) > strtotime('now') ) ||
					( $status->license == 'invalid' && isset($status->expires) && strtotime($status->expires) > strtotime('now') ) ) {
					
					if($is_plugin) {
						// Expired plugin
						$plugin_name = dirname($dir_file);
						self::$expired_licenses[$plugin_name] = array('expired'=>$status->expires);
					} else if( defined('AppPresser_Theme_Setup::THEME_NAME') ) {
						// Expired theme
						$theme_name = AppPresser_Theme_Setup::THEME_NAME;
						self::$expired_licenses[$theme_name] = array('expired'=>$status->expires);
					}
				}
			}
		}

		return self::$expired_licenses;
	}

	/**
	 * Retrieves a license key's status from the store
	 * @since  1.0.0
	 * @param  string  $license      License Key
	 * @param  string  $plugintheme  Plugin dir/file
	 * @param  boolean $plugin       Whether this is a plugin or theme
	 * @return mixed                 License status or false if failure
	 */
	public static function get_license_status( $license, $plugintheme, $plugin = true ) {

		$plugin = false === strpos( $plugintheme, '/' ) ? false : $plugin;

		if ( ! ( $updater = AppPresser_Updater::get_updater( $plugintheme, $plugin ) ) )
			return false;

		$license = trim( $license );
		if ( empty( $license ) )
			return false;

		// Call the custom API.
		$response = wp_remote_post( esc_url_raw( add_query_arg( array(
			'edd_action'=> 'activate_license',
			'license' 	=> $license,
			// 'the_title' filter needed to match EDD's check
			'item_name' => urlencode( apply_filters( 'the_title', $updater->public['item_name'], 0 ) ),
		), $updater->public['api_url'] ) ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		// Send back license status
		return isset( $license_data ) ? $license_data : false;
	}

	public function show_notices() {
		
		if( ! get_user_meta( get_current_user_id(), self::ADMIN_LIC_NAG, true ) ) {

			$expired_licenses = array_keys( self::$expired_licenses );
			$msg_expired_liceses = implode(', ', $expired_licenses);
						
			$error  = '<p>' . sprintf( __( 'The license for <b>%s</b> has expired.', 'The licenses for <b>%s</b> has expired.', count($expired_licenses), 'apppresser' ), '<a href="/wp-admin/admin.php?page=apppresser_settings">'.$msg_expired_liceses.'</a>' ) . '</p>';
			$error .= (self::DEBUG) ? '<p>Debugging</p>':'';

			echo '<div class="error notice is-dismissible license-expired">
					'.$error.'
			      </div>
			    <script type="text/javascript">

			    function appp_license_dismiss() {

			    	console.log("appp_license_dismiss");

			    	jQuery.ajax({
						type: "POST",
						url:  "'.admin_url('admin-ajax.php').'",
						data: {
							action: "app_license_dismiss"
						},
						success: function(response) {
							console.log(response);
						}
					});
			    }

			    jQuery(document).ready(function() {
			    	// double layer doc ready to add these events after wp-admin doc ready stuff
					jQuery(document).ready(function() {				    	
						jQuery(".license-expired .notice-dismiss").on("click", appp_license_dismiss);
					});

				});
			    	
			    </script>';
		}
	}

	/**
	 * Handle the dismissable admin notice
	 *
	 * @since 2.0.0
	 */
	function dismiss_notices() {
		if( ! isset( $_GET['appp_dismiss_notice_nonce'] ) || ! wp_verify_nonce( $_GET['appp_dismiss_notice_nonce'], 'appp_dismiss_notice') ) {
			wp_die( __( 'Security check failed', 'apppresser' ), __( 'Error', 'apppresser' ), array( 'response' => 403 ) );
		}

		if( isset( $_GET['appp_notice'] ) ) {
			update_user_meta( get_current_user_id(), '_appp_' . $_GET['appp_notice'] . '_dismissed', 1 );
			wp_redirect( remove_query_arg( array( 'appp_action', 'appp_notice' ) ) );
			exit;
		}
	}
}