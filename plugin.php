<?php
/**
 * osTicket Statuspage – plugin metadata.
 *
 * Native Atlassian Statuspage home page for the osTicket client portal,
 * plus an optional slim banner on other pages when systems are degraded.
 *
 * Safe for production: does not patch core files. Uses output buffering
 * to inject into client HTML when the plugin is installed and enabled.
 *
 * MIT License – see LICENSE
 */
return array(
    'id'          => 'opensource:osticket-statuspage',
    'version'     => '1.2.3',
    'name'        => 'Statuspage Home',
    'author'      => 'osTicket Statuspage contributors',
    'description' => 'Native Statuspage home page for the osTicket client portal (no iframe), plus an optional degraded banner. Does not modify core files.',
    'url'         => 'https://github.com/HairyDuck/osticket-statuspage',
    'plugin'      => 'osticket-statuspage-banner.php:StatuspageBannerPlugin',
);
