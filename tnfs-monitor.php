<?php
/**
 * Plugin Name: TNFS Monitor
 * Description: Monitor TNFS servers and display their status on the front end.
 * Version: 1.0
 * Author: Joe Honold <mozzwald@gmail.com>
 */

// Ensure WordPress has loaded
if (!defined('ABSPATH')) exit;

// Register the plugin's custom database table
function tnfs_monitor_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tnfs_servers';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        server_url VARCHAR(255) NOT NULL,
        last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        tcp_status VARCHAR(10),
        udp_status VARCHAR(10),
        order_index INT DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'tnfs_monitor_install');

function tnfs_monitor_enqueue_styles() {
    wp_enqueue_style(
        'tnfs-monitor-styles',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        array(),
        '1.0.0'
    );
}
add_action('wp_enqueue_scripts', 'tnfs_monitor_enqueue_styles');

// Add admin menu
function tnfs_monitor_menu() {
    add_menu_page('TNFS Monitor', 'TNFS Monitor', 'manage_options', 'tnfs-monitor', 'tnfs_monitor_admin_page');
}
add_action('admin_menu', 'tnfs_monitor_menu');

// Admin page
function tnfs_monitor_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tnfs_servers';
    $plugin_url = plugin_dir_url(__FILE__) . 'assets/img/';

    // Handle server deletion (when not re-ordering the list)
    if (isset($_POST['delete_server']) && check_admin_referer('tnfs_monitor_delete_server', 'tnfs_monitor_delete_nonce') && !isset($_POST['tnfs_monitor_order_nonce'])) {
        $server_id = intval($_POST['delete_server']);
        $wpdb->delete($table_name, array('id' => $server_id), array('%d'));
        echo '<div class="updated"><p>Server deleted successfully.</p></div>';
    }

    // Handle Adding Server
    if (isset($_POST['server_url']) && check_admin_referer('tnfs_monitor_add_server', 'tnfs_monitor_nonce')) {
        $server_url = sanitize_text_field($_POST['server_url']);

        // Check the server status now
        $tcp_status = tnfs_check_tcp_status($server_url);
        $udp_status = tnfs_check_udp_status($server_url);

        // Insert the new server into the database
        $wpdb->insert(
            $table_name,
            array('server_url' => $server_url, 'last_check' => current_time('mysql'), 'tcp_status' => $tcp_status ? 'up' : 'down', 'udp_status' => $udp_status ? 'up' : 'down', 'order_index' => '1000'),
            array('%s', '%s', '%s', '%s', '%s')
        );

        echo '<div class="updated"><p>Server added successfully.</p></div>';
    }

    // Manually trigger the cron job function
    if (isset($_POST['run_cron_job']) && check_admin_referer('tnfs_monitor_run_cron', 'tnfs_monitor_run_nonce')) {
        tnfs_monitor_cron_function();
        echo '<div class="updated"><p>Cron job executed manually.</p></div>';
    }

    // Handle Server list re-ordering
    if (isset($_POST['order']) && check_admin_referer('tnfs_monitor_update_order', 'tnfs_monitor_order_nonce')) {
        // Update the order in the database
        $order = explode(',', sanitize_text_field($_POST['order']));
        foreach ($order as $index => $server_id) {
            $wpdb->update(
                $table_name,
                array('order_index' => $index),
                array('id' => $server_id),
                array('%d'),
                array('%d')
            );
        }
        echo '<div class="updated"><p>Server order updated successfully.</p></div>';
    }

    $servers = $wpdb->get_results("SELECT * FROM $table_name ORDER BY order_index ASC");

    ?>
    <div class="wrap">
        <h1>TNFS Monitor</h1>

        <!-- List of currently added servers, re-orderable -->
        <form method="post" action="">
            <?php
            wp_nonce_field('tnfs_monitor_update_order', 'tnfs_monitor_order_nonce');
            ?>
            <h2>Current Server List</h2>
            <p>Drag and drop servers to reorder placement in the list. Use the DELETE button to remove a server</p>
            <table id="tnfs-servers-table" class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th>Server URL</th>
                        <th>Last Check</th>
                        <th>TCP Status</th>
                        <th>UDP Status</th>
                        <th>Downtime</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servers as $server) : ?>
                    <?php
                        // Calculate the downtime duration
                        $downtime_message = '';
                        if (!is_null($server->down_since)) {
                            $down_since = strtotime($server->down_since);
                            $now = current_time('timestamp');
                            $diff_in_seconds = $now - $down_since;

                            if ($diff_in_seconds < 86400) {
                                // Show in hours for the first 24 hours
                                $hours_down = floor($diff_in_seconds / 3600);
                                if ($hours_down == 0)
                                    $hours_down = 1;
                                $downtime_message = $hours_down . ' hour';
                                if ($hours_down > 1)
                                    $downtime_message = $downtime_message."s";
                            } else {
                                // Show in days after the first 24 hours
                                $days_down = floor($diff_in_seconds / 86400);
                                $downtime_message = $days_down . ' day';
                                if ($days_down > 1)
                                    $downtime_message = $downtime_message."s";
                            }
                        }
                    ?>
                        <tr data-id="<?php echo $server->id; ?>">
                            <td><?php echo esc_html($server->server_url); ?></td>
                            <td><?php echo esc_html($server->last_check); ?></td>
                            <td><img src="<?php echo esc_url($plugin_url . ($server->tcp_status === 'up' ? 'up.svg' : 'down.svg')); ?>" alt="<?php echo esc_attr($server->tcp_status); ?>" width="20" height="20"></td>
                            <td><img src="<?php echo esc_url($plugin_url . ($server->udp_status === 'up' ? 'up.svg' : 'down.svg')); ?>" alt="<?php echo esc_attr($server->udp_status); ?>" width="20" height="20"></td>
                            <td><?php echo $downtime_message; ?></td>
                            <td>
                                <!-- Delete button -->
                                <form method="post" action="" style="display:inline;">
                                    <?php wp_nonce_field('tnfs_monitor_delete_server', 'tnfs_monitor_delete_nonce'); ?>
                                    <input type="hidden" name="delete_server" value="<?php echo $server->id; ?>">
                                    <input type="submit" value="Delete" class="button button-danger" onclick="return confirm('Are you sure you want to delete this server?');">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="hidden" name="order" id="order-input">
            <?php submit_button('Save Order'); ?>
        </form>
    </div>

    <!-- Form for adding a new server -->
    <form method="post" action="">
        <?php
        wp_nonce_field('tnfs_monitor_add_server', 'tnfs_monitor_nonce');
        ?>
        <h2>Add New Server</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="server_url">Server URL</label></th>
                <td><input name="server_url" type="text" id="server_url" value="" class="regular-text" required /></td>
            </tr>
        </table>
        <?php submit_button('Add Server'); ?>
    </form>

    <!-- Button to run the cron job manually -->
    <form method="post" action="">
        <?php
        wp_nonce_field('tnfs_monitor_run_cron', 'tnfs_monitor_run_nonce');
        ?>
        <h2>Run Cron Job Manually</h2>
        <?php submit_button('Run Now', 'secondary', 'run_cron_job'); ?>
    </form>


    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#tnfs-servers-table tbody').sortable({
                placeholder: "ui-state-highlight",
                update: function(event, ui) {
                    var order = [];
                    $('#tnfs-servers-table tbody tr').each(function() {
                        order.push($(this).data('id'));
                    });
                    $('#order-input').val(order.join(','));
                }
            }).disableSelection();
        });
    </script>
    <?php
}

// Add WordPress cron job
function tnfs_monitor_cron_activation() {
    if (!wp_next_scheduled('tnfs_monitor_cron_event')) {
        wp_schedule_event(time(), 'hourly', 'tnfs_monitor_cron_event');
    }
}
add_action('wp', 'tnfs_monitor_cron_activation');

function tnfs_monitor_cron_deactivation() {
    wp_clear_scheduled_hook('tnfs_monitor_cron_event');
}
register_deactivation_hook(__FILE__, 'tnfs_monitor_cron_deactivation');

function tnfs_monitor_cron_function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tnfs_servers';

    $servers = $wpdb->get_results("SELECT * FROM $table_name");

    foreach ($servers as $server) {
        $tcp_status = tnfs_check_tcp_status($server->server_url);
        $udp_status = tnfs_check_udp_status($server->server_url);

        // Check if both TCP and UDP are down
        if (!$tcp_status && !$udp_status) {
            // If both are down and it's the first time, store the timestamp
            if (is_null($server->down_since)) {
                $down_since = current_time('mysql');
                $wpdb->update(
                    $table_name,
                    array('down_since' => $down_since),
                    array('id' => $server->id),
                    array('%s'),
                    array('%d')
                );
            }
        } else {
            // If either TCP or UDP is up, reset the down_since timestamp
            if (!is_null($server->down_since)) {
                $wpdb->update(
                    $table_name,
                    array('down_since' => null),
                    array('id' => $server->id),
                    array('%s'),
                    array('%d')
                );
            }
        }

        // Update the server statuses and last check time
        $wpdb->update(
            $table_name,
            array(
                'last_check' => current_time('mysql'),
                'tcp_status' => $tcp_status ? 'up' : 'down',
                'udp_status' => $udp_status ? 'up' : 'down'
            ),
            array('id' => $server->id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
}

add_action('tnfs_monitor_cron_event', 'tnfs_monitor_cron_function');

// Parse TNFS response
function parse_tnfs_response($response) {
    // Ensure response length is sufficient
    if (strlen($response) < 8) {
        return false; // Invalid response length
    }

    // Extract session ID (2 bytes)
    $session_id = unpack('n', substr($response, 0, 2));
    $session_id = $session_id[1]; // Convert to integer

    // Extract sequence id (1 byte)
    $sequence_id = ord($response[2]);

    // Extract command (1 byte)
    $command = ord($response[3]);

    // Extract TNFS Version (2 bytes)
    $tnfs_version_minor = ord($response[4]);
    $tnfs_version_major = ord($response[5]);
    $server_version = ($tnfs_version_major << 8) | $tnfs_version_minor;

    // Extract minimum retry time (2 bytes)
    $min_retry_time = unpack('n', substr($response, 6, 2));
    $min_retry_time = $min_retry_time[1]; // Convert to integer

    return array(
        'session_id' => $session_id,
        'sequence_id' => $sequence_id,
        'command' => $command,
        'server_version' => $server_version,
        'min_retry_time' => $min_retry_time
    );
}

// TCP status check
function tnfs_check_tcp_status($host) {
    $port = 16384;
    $timeout = 5; // seconds
    $read_timeout = 2; // seconds for reading response

    // Build the TNFS packet
    $packet = pack('n', 0x0000);         // 2-byte empty Session ID
    $packet .= chr(0x00);                // Sequence ID 0
    $packet .= chr(0x00);                // Command byte MOUNT
    $packet .= chr(0x00);                // TNFS Version Minor
    $packet .= chr(0x02);                // TNFS Version Major
    $packet .= "/" . "\0";               // Mount path and null terminator
    $packet .= "\0";                     // Null terminator for empty user
    $packet .= "\0";                     // Null terminator for empty password

    // Create TCP socket
    $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);

    if ($connection) {
        // Set a timeout for reading from the socket
        stream_set_timeout($connection, $read_timeout);

        // Send the TNFS command
        $writecheck = fwrite($connection, $packet);

        // Check if the write was successful
        if ($writecheck !== false) {
            $response = '';
            $response_length = 0;
            $max_response_length = 1024; // Maximum expected response length

            // Read data from the socket
            while (!feof($connection) && $response_length < $max_response_length) {
                $chunk = fread($connection, $max_response_length - $response_length);
                if ($chunk === false || $chunk === '') {
                    $info = stream_get_meta_data($connection);
                    if ($info['timed_out']) {
                        break; // Timeout occurred
                    }
                    break; // End of data or error
                }
                $response .= $chunk;
                $response_length += strlen($chunk);
            }

            // Close the connection
            fclose($connection);

            // Check if we received a valid response
            if ($response_length > 0) {
                // Parse the TNFS response
                $result = parse_tnfs_response($response);

                // Check if parsing was successful
                if ($result && $result['command'] == 0x00) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            // Writing to the socket failed
            fclose($connection);
            return false;
        }
    } else {
        // Unable to create the socket
        return false;
    }
}

// UDP Status Check
function tnfs_check_udp_status($host) {
    $port = 16384;
    $timeout = 5; // seconds

    // Build the TNFS packet
    $packet = pack('n', 0x0000);         // 2-byte empty Session ID
    $packet .= chr(0x00);                // Sequence ID 0
    $packet .= chr(0x00);                // Command byte MOUNT
    $packet .= chr(0x00);                // TNFS Version Minor
    $packet .= chr(0x02);                // TNFS Version Major
    $packet .= "/" . "\0";               // Mount path and null terminator
    $packet .= "\0";                     // Null terminator for empty user
    $packet .= "\0";                     // Null terminator for empty password

    // Create UDP socket
    $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($socket === false) {
        return false;
    }

    // Set timeout for receiving
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));

    // Send a TNFS command
    socket_sendto($socket, $packet, strlen($packet), 0, $host, $port);

    // Receive response
    $buffer = '';
    $from = '';
    $response_length = socket_recvfrom($socket, $buffer, 1024, 0, $from, $port);

    // Parse the response
    $result = parse_tnfs_response($buffer); // Reuse the TCP parser

	if ($result != false)
	{
	    // Send unmount to close the session
	    $packet = pack('n', $result['session_id']); // 2-byte Session ID
	    $packet .= chr(0x00);                // Sequence ID 0
	    $packet .= chr(0x01);                // Command byte UNMOUNT
	    socket_sendto($socket, $packet, strlen($packet), 0, $host, $port);

	    // Receive response
	    $buffer = '';
	    $from = '';
	    $response_length = socket_recvfrom($socket, $buffer, 1024, 0, $from, $port);

	    // Parse the response
	    $result = parse_tnfs_response($buffer); // Reuse the TCP parser
	}

    // Close the socket
    socket_close($socket);

    // Check for a valid TNFS response
    if ($response_length > 0 && $result['command'] == 0x00) {
        return true;
    } else {
        return false;
    }
}

// Shortcode for front-end display
function tnfs_monitor_shortcode() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tnfs_servers';
    $servers = $wpdb->get_results("SELECT * FROM $table_name ORDER BY order_index ASC");

    // Get the plugin URL to correctly reference the image assets
    $plugin_url = plugin_dir_url(__FILE__) . 'assets/img/';

    ob_start();
    if ($servers) {
        echo '<table style="width: 100%; text-align: center;">';
        echo '<thead><tr><th>Server URL</th><th>TCP<br>Status</th><th>UDP<br>Status</th><th>Downtime</th></tr></thead>';
        echo '<tbody>';
        foreach ($servers as $server) {
            $tcp_status_image = ($server->tcp_status === 'up') ? $plugin_url . 'up.svg' : $plugin_url . 'down.svg';
            $udp_status_image = ($server->udp_status === 'up') ? $plugin_url . 'up.svg' : $plugin_url . 'down.svg';
            $last_check = esc_html($server->last_check); // Save this to display in last row

            // Calculate the downtime duration
            $downtime_message = '';
            if (!is_null($server->down_since)) {
                $down_since = strtotime($server->down_since);
                $now = current_time('timestamp');
                $diff_in_seconds = $now - $down_since;

                if ($diff_in_seconds < 86400) {
                    // Show in hours for the first 24 hours
                    $hours_down = floor($diff_in_seconds / 3600);
                    if ($hours_down == 0)
                        $hours_down = 1;
                    $downtime_message = $hours_down . ' hour';
                    if ($hours_down > 1)
                        $downtime_message = $downtime_message."s";
                } else {
                    // Show in days after the first 24 hours
                    $days_down = floor($diff_in_seconds / 86400);
                    $downtime_message = $days_down . ' day';
                    if ($days_down > 1)
                        $downtime_message = $downtime_message."s";
                }
            }

            echo '<tr>';
            echo '<td style="text-align: left;">' . esc_html($server->server_url) . '</td>';
            echo '<td style="text-align: center;"><span class="hovertext" data-hover="Checked at  ' . esc_html($server->last_check) . '"><img src="' . esc_url($tcp_status_image) . '" alt="' . esc_attr($server->tcp_status) . '" width="20" height="20"></span></td>';
            echo '<td style="text-align: center;"><span class="hovertext" data-hover="Checked at  ' . esc_html($server->last_check) . '"><img src="' . esc_url($udp_status_image) . '" alt="' . esc_attr($server->udp_status) . '" width="20" height="20"></span></td>';
            echo '<td>'.$downtime_message.'</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="4">Last checked TNFS status at '.$last_check.'</td></tr>';
        echo '</tbody></table>';
    } else {
        echo '<p>No servers found.</p>';
    }

    return ob_get_clean();
}
add_shortcode('tnfs_monitor', 'tnfs_monitor_shortcode');



?>
