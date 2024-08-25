# wp-tnfs-server-monitor
TNFS Server Monitor for Wordpress

# General

This plugin checks the TCP and UDP status of a TNFS server by it's hostname. The status is checked on the Wordpress CRON schedule every hour and details are updated in the database.

# Admin Panel

The plugin creates a menu option in the admin section to change settings. On the settings page you can add a new server, manually run the CRON job and view the list of servers. The server list has a button to `Delete` a server. You can re-order the list of servers by dragging them in the list and clicking the `Save Order` button.

# Front End Shortcode

The list of servers can be displayed anywhere in Wordpress with the `[tnfs_monitor]` shortcode.