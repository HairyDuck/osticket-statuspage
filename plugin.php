<?php
/**
 * osTicket Statuspage Banner – plugin metadata.
 *
 * Native Statuspage status panel on the client helpdesk home page, plus an
 * optional slim banner on other pages when systems are degraded.
 *
 * Safe for production: does not patch core files. Uses output buffering
 * to inject into client HTML when the plugin is installed and enabled.
 *
 * MIT License – see LICENSE
 */
return array(
    'id'          => 'opensource:osticket-statuspage-banner',
    'version'     => '1.2.2',
    'name'        => 'Statuspage Banner',
    'author'      => 'osTicket Statuspage Banner contributors',
    'description' => 'Native Statuspage status on the client helpdesk home page, plus an optional degraded banner. Does not modify core files.',
    'url'         => 'https://github.com/HairyDuck/osticket-statuspage-banner',
    'plugin'      => 'osticket-statuspage-banner.php:StatuspageBannerPlugin',
);
