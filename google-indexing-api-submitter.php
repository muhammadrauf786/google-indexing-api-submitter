<?php
/**
 * Plugin Name: Google Indexing API Submitter
 * Description: Integrates with the Google Indexing API using a background queue system (1 per minute) to respect quotas and prevent slowdowns.
 * Version: 1.3.0
 * Author: Expert Developer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Google_Indexing_API_Submitter {

    private $option_name = 'google_indexing_api_json_key';
    private $log_option_name = 'google_indexing_api_logs';
    private $daily_count_option = 'google_indexing_api_daily_count';
    private $last_date_option = 'google_indexing_api_last_date';
    private $daily_limit = 200;
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'google_indexing_queue';

        // Registration & Menus
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Post Hooks
        add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
        add_action( 'before_delete_post', array( $this, 'handle_post_deletion' ) );

        // Cron schedules and actions
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
        add_action( 'google_indexing_api_cron_job', array( $this, 'process_queue' ) );
    }

    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'google_indexing_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            url varchar(191) NOT NULL,
            action_type varchar(50) NOT NULL,
            added_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url (url)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        if ( ! wp_next_scheduled( 'google_indexing_api_cron_job' ) ) {
            wp_schedule_event( time(), 'every_minute', 'google_indexing_api_cron_job' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'google_indexing_api_cron_job' );
    }

    public function add_cron_interval( $schedules ) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => esc_html__( 'Every Minute' ),
        );
        return $schedules;
    }

    /**
     * The background cron processor that fires every minute
     */
    public function process_queue() {
        global $wpdb;
        
        $usage = $this->check_and_get_daily_usage();
        if ( $usage >= $this->daily_limit ) {
            return; // Quota reached, do nothing today
        }

        // Get oldest queue item
        $item = $wpdb->get_row( "SELECT * FROM {$this->table_name} ORDER BY added_at ASC LIMIT 1" );
        if ( ! $item ) {
            return; // Queue is empty
        }

        // Remove from queue first to prevent duplicate processing if script dies
        $wpdb->delete( $this->table_name, array( 'id' => $item->id ) );

        // Process it
        $this->notify_google( $item->url, $item->action_type );
    }

    /**
     * Add URL to the queue database table
     */
    private function add_to_queue( $url, $type ) {
        global $wpdb;
        // Insert or replace to avoid duplicates of the exact same URL
        $wpdb->replace( 
            $this->table_name, 
            array( 
                'url' => $url, 
                'action_type' => $type, 
                'added_at' => current_time( 'mysql' ) 
            ) 
        );
    }

    /**
     * Settings Pages
     */
    public function add_settings_page() {
        add_options_page(
            'Google Indexing Submitter',
            'Google Indexing Submitter',
            'manage_options',
            'google-indexing-api-submitter',
            array( $this, 'settings_page_html' )
        );
    }

    public function register_settings() {
        register_setting( 'google_indexing_api_group', $this->option_name, array(
            'sanitize_callback' => array( $this, 'sanitize_json_key' )
        ) );
    }

    public function sanitize_json_key( $input ) {
        if ( empty( $input ) ) {
            return '';
        }
        $decoded = json_decode( trim( $input ), true );
        if ( ! $decoded || ! isset( $decoded['private_key'] ) || ! isset( $decoded['client_email'] ) ) {
            add_settings_error( $this->option_name, 'invalid_json', 'Invalid Google Service Account JSON Key.' );
            return get_option( $this->option_name );
        }
        return trim( $input );
    }

    private function add_log( $url, $type, $status, $response ) {
        $logs = get_option( $this->log_option_name, array() );
        $new_entry = array(
            'time'     => current_time( 'mysql' ),
            'url'      => $url,
            'type'     => $type,
            'status'   => $status,
            'response' => $response
        );
        array_unshift( $logs, $new_entry );
        $logs = array_slice( $logs, 0, 50 );
        update_option( $this->log_option_name, $logs, false );
    }

    private function check_and_get_daily_usage() {
        $last_date = get_option( $this->last_date_option, '' );
        $today = current_time( 'Y-m-d' );
        if ( $last_date !== $today ) {
            update_option( $this->last_date_option, $today, false );
            update_option( $this->daily_count_option, 0, false );
            return 0;
        }
        return (int) get_option( $this->daily_count_option, 0 );
    }

    private function increment_daily_usage() {
        $count = $this->check_and_get_daily_usage();
        update_option( $this->daily_count_option, $count + 1, false );
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;

        // Clear logs
        if ( isset( $_GET['clear_logs'] ) && check_admin_referer( 'clear_logs_nonce' ) ) {
            delete_option( $this->log_option_name );
            echo '<div class="notice notice-success is-dismissible"><p>Logs cleared successfully.</p></div>';
        }

        // Bulk Queue All
        if ( isset( $_POST['bulk_queue_all'] ) && check_admin_referer( 'bulk_queue_nonce' ) ) {
            $posts = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')" );
            $count = 0;
            foreach ( $posts as $p ) {
                $url = get_permalink( $p->ID );
                if ( $url ) {
                    $this->add_to_queue( $url, 'URL_UPDATED' );
                    $count++;
                }
            }
            echo '<div class="notice notice-success is-dismissible"><p>Successfully added ' . $count . ' published posts/pages to the background queue.</p></div>';
        }

        // Clear Queue
        if ( isset( $_POST['clear_queue_all'] ) && check_admin_referer( 'clear_queue_nonce' ) ) {
            $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
            echo '<div class="notice notice-success is-dismissible"><p>Background queue cleared successfully.</p></div>';
        }

        $daily_usage = $this->check_and_get_daily_usage();
        $usage_percent = min( 100, round( ( $daily_usage / $this->daily_limit ) * 100 ) );
        $progress_color = $usage_percent > 80 ? 'red' : ( $usage_percent > 50 ? 'orange' : 'green' );
        $queue_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );

        ?>
        <div class="wrap">
            <h1>Google Indexing API Submitter (Queued)</h1>
            
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div style="flex: 1; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2>Daily Quota Usage</h2>
                    <p>You have used <strong><?php echo esc_html( $daily_usage ); ?></strong> out of <strong><?php echo esc_html( $this->daily_limit ); ?></strong> API requests today.</p>
                    <div style="width: 100%; background-color: #f1f1f1; border-radius: 4px; overflow: hidden; height: 20px;">
                        <div style="width: <?php echo esc_attr( $usage_percent ); ?>%; background-color: <?php echo esc_attr( $progress_color ); ?>; height: 100%; text-align: center; color: white; font-size: 12px; line-height: 20px;">
                            <?php echo esc_html( $usage_percent ); ?>%
                        </div>
                    </div>
                </div>

                <div style="flex: 1; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2>Background Queue Status</h2>
                    <p>Items waiting to be submitted: <strong style="font-size: 1.2em;"><?php echo esc_html( $queue_count ); ?></strong></p>
                    <p class="description">1 item is processed every minute automatically in the background.</p>
                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <form method="post" onsubmit="return confirm('Queue ALL published posts/pages? This may take several days to complete.');">
                            <?php wp_nonce_field( 'bulk_queue_nonce' ); ?>
                            <input type="hidden" name="bulk_queue_all" value="1">
                            <button type="submit" class="button button-primary">Queue All Published Posts</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Are you sure you want to delete all pending items in the queue?');">
                            <?php wp_nonce_field( 'clear_queue_nonce' ); ?>
                            <input type="hidden" name="clear_queue_all" value="1">
                            <button type="submit" class="button button-secondary" style="color: #b32d2e; border-color: #b32d2e;">Clear Queue</button>
                        </form>
                    </div>
                </div>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields( 'google_indexing_api_group' );
                $json_key = get_option( $this->option_name );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr( $this->option_name ); ?>">Service Account JSON Key</label></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $this->option_name ); ?>" id="<?php echo esc_attr( $this->option_name ); ?>" rows="10" cols="80" class="large-text code" placeholder='{"type": "service_account", ...}'><?php echo esc_textarea( $json_key ); ?></textarea>
                            <p class="description">Paste the entire content of your Google Cloud Service Account JSON key file here.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save JSON Key' ); ?>
            </form>

            <hr>

            <h2>Recent Submissions Log</h2>
            <?php $logs = get_option( $this->log_option_name, array() ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 15%">Date / Time</th>
                        <th style="width: 15%">Action Type</th>
                        <th style="width: 35%">URL</th>
                        <th style="width: 10%">Status</th>
                        <th style="width: 25%">API Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="5">No submissions logged yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log['time'] ); ?></td>
                                <td><?php echo esc_html( $log['type'] ); ?></td>
                                <td><a href="<?php echo esc_url( $log['url'] ); ?>" target="_blank"><?php echo esc_html( $log['url'] ); ?></a></td>
                                <td><?php echo esc_html( $log['status'] ); ?></td>
                                <td><?php echo esc_html( $log['response'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <br>
            <?php $clear_logs_url = wp_nonce_url( admin_url( 'options-general.php?page=google-indexing-api-submitter&clear_logs=1' ), 'clear_logs_nonce' ); ?>
            <a href="<?php echo esc_url( $clear_logs_url ); ?>" class="button" onclick="return confirm('Clear all logs?');">Clear Logs</a>
        </div>
        <?php
    }

    public function handle_post_status_transition( $new_status, $old_status, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! in_array( $post->post_type, array( 'post', 'page' ) ) ) return;
        
        $url = get_permalink( $post->ID );
        if ( ! $url ) return;

        if ( $new_status === 'publish' ) {
            $this->add_to_queue( $url, 'URL_UPDATED' );
        } elseif ( $old_status === 'publish' && $new_status === 'trash' ) {
            $this->add_to_queue( $url, 'URL_DELETED' );
        }
    }

    public function handle_post_deletion( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ) ) ) return;
        $url = get_permalink( $post_id );
        if ( $url ) {
            $this->add_to_queue( $url, 'URL_DELETED' );
        }
    }

    private function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private function get_access_token() {
        $json_key_string = get_option( $this->option_name );
        if ( empty( $json_key_string ) ) return false;

        $key_data = json_decode( $json_key_string, true );
        if ( ! $key_data || ! isset( $key_data['client_email'] ) || ! isset( $key_data['private_key'] ) ) return false;

        $header = json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) );
        $now = time();
        $payload = json_encode( array(
            'iss'   => $key_data['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ) );

        $base64_url_header = $this->base64url_encode( $header );
        $base64_url_payload = $this->base64url_encode( $payload );
        $signature_input = $base64_url_header . '.' . $base64_url_payload;

        $signature = '';
        if ( ! openssl_sign( $signature_input, $signature, $key_data['private_key'], 'SHA256' ) ) {
            return false;
        }

        $base64_url_signature = $this->base64url_encode( $signature );
        $jwt = $signature_input . '.' . $base64_url_signature;

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
        ) );

        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['access_token'] ) ? $body['access_token'] : false;
    }

    private function notify_google( $url, $type ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            $this->add_log( $url, $type, 'Error', 'Access token missing or invalid.' );
            return;
        }

        $endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
        $payload = json_encode( array( 'url' => $url, 'type' => $type ) );

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body'    => $payload,
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->add_log( $url, $type, 'HTTP Error', $response->get_error_message() );
            return;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        $this->add_log( $url, $type, $status_code, wp_trim_words( $body, 20 ) );
        $this->increment_daily_usage();
    }
}

register_activation_hook( __FILE__, array( 'Google_Indexing_API_Submitter', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Google_Indexing_API_Submitter', 'deactivate' ) );

new Google_Indexing_API_Submitter();
