<?php
/**
 * Plugin Name:       Business Report
 * Plugin URI:        https://example.com/
 * Description:       A comprehensive reporting tool for WooCommerce.
 * Version:           1.4.3
 * Author:            Your Name
 * Author URI:        https://yourwebsite.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       business-report
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Bumping the version number to ensure updates are registered.
define( 'BR_PLUGIN_VERSION', '1.4.3' );

/**
 * The core plugin class.
 */
final class Business_Report {

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Business_Report ) ) {
			self::$instance = new Business_Report();
			self::$instance->setup_constants();
			self::$instance->hooks();
			self::$instance->includes();
		}
		return self::$instance;
	}

	private function setup_constants() {
		define( 'BR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		define( 'BR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	}

	private function includes() {
		require_once BR_PLUGIN_DIR . 'includes/cogs-management.php';
		require_once BR_PLUGIN_DIR . 'includes/meta-ads.php';
		require_once BR_PLUGIN_DIR . 'includes/expense-management.php';
	}

	private function hooks() {
		register_activation_hook( __FILE__, [ $this, 'run_db_install' ] );
		add_action( 'plugins_loaded', [ $this, 'check_for_updates' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles_and_scripts' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'remove_admin_notices' ] );
		add_action( 'br_daily_add_monthly_expenses_event', [ $this, 'execute_monthly_expense_cron' ] );
	}

    public function remove_admin_notices() {
        if ( ! isset( $_GET['page'] ) || ( strpos( $_GET['page'], 'br-' ) === false && strpos( $_GET['page'], 'business-report' ) === false ) ) {
            return;
        }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
    }

	public function admin_menu() {
		add_menu_page( __( 'Business Report', 'business-report' ), __( 'Business Report', 'business-report' ), 'manage_woocommerce', 'business-report', 'br_dashboard_page_html', 'dashicons-chart-bar', 56 );
		add_submenu_page( 'business-report', __( 'Expense', 'business-report' ), __( 'Expense', 'business-report' ), 'manage_woocommerce', 'br-expense', 'br_expense_page_html' );
	}

	public function enqueue_styles_and_scripts( $hook ) {
		if ( strpos( $hook, 'business-report' ) === false && strpos($hook, 'br-') === false ) { return; }
		
		// This function forces the browser to load the newest CSS file by changing the version number.
		$css_version = filemtime( BR_PLUGIN_DIR . 'assets/css/admin-styles.css' );
        wp_enqueue_style( 'br-admin-styles', BR_PLUGIN_URL . 'assets/css/admin-styles.css', [], $css_version );
	}

    public function check_for_updates() {
        if ( get_option( 'br_plugin_version' ) != BR_PLUGIN_VERSION ) {
            $this->run_db_install();
            $this->schedule_cron_jobs();
            update_option( 'br_plugin_version', BR_PLUGIN_VERSION );
        }
    }

    public function schedule_cron_jobs() {
        if ( ! wp_next_scheduled( 'br_daily_add_monthly_expenses_event' ) ) {
            wp_schedule_event( time(), 'daily', 'br_daily_add_monthly_expenses_event' );
        }
    }

	public function execute_monthly_expense_cron() {
        br_add_monthly_expenses_to_list();
    }


	public function run_db_install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// COGS Table
		$cogs_table_name = $wpdb->prefix . 'br_product_cogs';
		$sql_cogs = "CREATE TABLE $cogs_table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, post_id bigint(20) NOT NULL, cost decimal(10,2) NOT NULL DEFAULT '0.00', last_updated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY  (id), UNIQUE KEY post_id (post_id) ) $charset_collate;";
		dbDelta( $sql_cogs );

		// Meta Ads Accounts Table
		$accounts_table = $wpdb->prefix . 'br_meta_ad_accounts';
		$sql_accounts = "CREATE TABLE $accounts_table ( id BIGINT(20) NOT NULL AUTO_INCREMENT, account_name VARCHAR(255) NOT NULL, app_id VARCHAR(255), app_secret TEXT, access_token TEXT NOT NULL, ad_account_id VARCHAR(255) NOT NULL, usd_to_bdt_rate DECIMAL(10, 4) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id) ) $charset_collate;";
		dbDelta($sql_accounts);
		
		// Meta Ads Summary Table
        $summary_table = $wpdb->prefix . 'br_meta_ad_summary';
        $sql_summary = "CREATE TABLE $summary_table ( id BIGINT(20) NOT NULL AUTO_INCREMENT, account_fk_id BIGINT(20) NOT NULL, report_date DATE NOT NULL, spend_usd DECIMAL(12, 2) DEFAULT 0.00, purchases INT(11) DEFAULT 0, purchase_value DECIMAL(12, 2) DEFAULT 0.00, adds_to_cart INT(11) DEFAULT 0, initiate_checkouts INT(11) DEFAULT 0, impressions INT(11) DEFAULT 0, clicks INT(11) DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY account_date (account_fk_id, report_date) ) $charset_collate;";
		dbDelta($sql_summary);

		// Meta Ads Campaign Table
        $campaign_table = $wpdb->prefix . 'br_meta_campaign_data';
        $sql_campaign = "CREATE TABLE $campaign_table ( id BIGINT(20) NOT NULL AUTO_INCREMENT, campaign_id VARCHAR(255) NOT NULL, campaign_name TEXT NOT NULL, account_fk_id BIGINT(20) NOT NULL, report_date DATE NOT NULL, objective VARCHAR(255), spend_usd DECIMAL(12, 2) DEFAULT 0.00, impressions INT(11) DEFAULT 0, reach INT(11) DEFAULT 0, frequency DECIMAL(10, 4) DEFAULT 0.0000, clicks INT(11) DEFAULT 0, ctr DECIMAL(10, 4) DEFAULT 0.0000, cpc DECIMAL(10, 4) DEFAULT 0.0000, cpm DECIMAL(10, 4) DEFAULT 0.0000, roas DECIMAL(10, 4) DEFAULT 0.0000, purchases INT(11) DEFAULT 0, adds_to_cart INT(11) DEFAULT 0, initiate_checkouts INT(11) DEFAULT 0, purchase_value DECIMAL(12, 2) DEFAULT 0.00, PRIMARY KEY (id), UNIQUE KEY campaign_date (campaign_id, report_date) ) $charset_collate;";
		dbDelta($sql_campaign);
		
		// Expense Categories Table
		$expense_cat_table = $wpdb->prefix . 'br_expense_categories';
		$sql_expense_cat = "CREATE TABLE $expense_cat_table ( id BIGINT(20) NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, PRIMARY KEY (id), UNIQUE KEY name (name) ) $charset_collate;";
		dbDelta($sql_expense_cat);
		
		// Check if 'Uncategorized' exists, if not, insert it.
		$uncategorized_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $expense_cat_table WHERE name = %s", 'Uncategorized' ) );
		if ( $uncategorized_exists == 0 ) {
			$wpdb->insert( $expense_cat_table, [ 'name' => 'Uncategorized' ], [ '%s' ] );
		}
		
		// Expenses Table
		$expenses_table = $wpdb->prefix . 'br_expenses';
		$sql_expenses = "CREATE TABLE $expenses_table ( id BIGINT(20) NOT NULL AUTO_INCREMENT, reason TEXT, category_id BIGINT(20) NOT NULL, amount DECIMAL(12, 2) NOT NULL, expense_date DATE NOT NULL, PRIMARY KEY (id) ) $charset_collate;";
		dbDelta($sql_expenses);
		
		// Monthly Expenses Table
		$monthly_expenses_table = $wpdb->prefix . 'br_monthly_expenses';
		$sql_monthly_expenses = "CREATE TABLE $monthly_expenses_table ( id BIGINT(20) NOT NULL AUTO_INCREMENT, reason TEXT, category_id BIGINT(20) NOT NULL, amount DECIMAL(12, 2) NOT NULL, listed_date INT(2) NOT NULL, last_added_month INT(2), last_added_year INT(4), PRIMARY KEY (id) ) $charset_collate;";
		dbDelta($sql_monthly_expenses);
	}
}

function br_dashboard_page_html() {
	?>
	<div class="wrap br-wrap">
		<h1><?php esc_html_e( 'Business Report Dashboard', 'business-report' ); ?></h1>
		<p><?php esc_html_e( 'The main dashboard will be built here.', 'business-report' ); ?></p>
	</div>
	<?php
}

function business_report_init() {
	return Business_Report::instance();
}
business_report_init();

