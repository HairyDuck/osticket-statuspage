<?php
/**
 * Plugin configuration for Statuspage Banner.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once __DIR__ . '/include/StatuspageClient.php';

class StatuspageBannerConfig extends PluginConfig
{
    public function getOptions()
    {
        return array(
            'enabled' => new BooleanField(array(
                'id'      => 'enabled',
                'label'   => 'Enable plugin',
                'default' => true,
                'hint'    => 'When disabled, nothing is injected into the portal.',
            )),
            'statuspage_url' => new TextboxField(array(
                'id'       => 'statuspage_url',
                'label'    => 'Statuspage base URL',
                'required' => false,
                'default'  => '',
                'hint'     => 'HTTPS only, e.g. https://example.statuspage.io (trailing slash optional).',
                'configuration' => array('length' => 255, 'size' => 60, 'html' => false),
            )),
            'home_panel' => new BooleanField(array(
                'id'      => 'home_panel',
                'label'   => 'Native status panel on client home',
                'default' => true,
                'hint'    => 'Renders Statuspage data on the landing page (no iframe). Always visible, including when healthy.',
            )),
            'link_label' => new TextboxField(array(
                'id'      => 'link_label',
                'label'   => 'Status page link label',
                'default' => 'View full status page',
                'hint'    => 'Text link to the full Statuspage.',
                'configuration' => array('length' => 80, 'size' => 40, 'html' => false),
            )),
            'show_subscribe' => new BooleanField(array(
                'id'      => 'show_subscribe',
                'label'   => 'Show subscribe button on home panel',
                'default' => true,
                'hint'    => 'Opens the Statuspage (Subscribe to Updates is on that page).',
            )),
            'subscribe_label' => new TextboxField(array(
                'id'      => 'subscribe_label',
                'label'   => 'Subscribe button label',
                'default' => 'Subscribe to updates',
                'hint'    => 'Label for the subscribe button on the home panel.',
                'configuration' => array('length' => 80, 'size' => 40, 'html' => false),
            )),
            'banner_on' => new ChoiceField(array(
                'id'      => 'banner_on',
                'label'   => 'Degraded banner on',
                'default' => 'other_client',
                'choices' => array(
                    'other_client' => 'Other client pages only (recommended with home panel)',
                    'all_client'   => 'All client portal pages',
                    'off'          => 'Off (home panel only)',
                ),
                'hint'    => 'Slim top banner when status is degraded. Hidden when everything is fine.',
            )),
            // Keep legacy key readable if an older instance still has show_on.
            'cache_ttl' => new TextboxField(array(
                'id'      => 'cache_ttl',
                'label'   => 'Cache TTL (seconds)',
                'default' => '0',
                'hint'    => '0 = no cache (recommended). Fetch Statuspage on every page view. Max 3600.',
                'configuration' => array('validator' => 'number', 'size' => 6),
            )),
            'show_on_scp' => new BooleanField(array(
                'id'      => 'show_on_scp',
                'label'   => 'Also show degraded banner for agents (SCP)',
                'default' => false,
                'hint'    => 'Off by default. Does not add the home panel to SCP.',
            )),
        );
    }

    public function pre_save(&$config, &$errors)
    {
        global $msg;

        $enabled = !empty($config['enabled']);
        $url = isset($config['statuspage_url']) ? trim((string) $config['statuspage_url']) : '';

        if ($enabled && $url === '') {
            $errors['err'] = 'Statuspage base URL is required when the plugin is enabled.';
            return false;
        }

        if ($url !== '') {
            $normalised = StatuspageClient::normaliseBaseUrl($url);
            if ($normalised === null) {
                $errors['err'] = 'Statuspage base URL must be a valid https:// address (no path required).';
                return false;
            }
            $config['statuspage_url'] = $normalised;
        }

        if (isset($config['link_label'])) {
            $label = trim((string) $config['link_label']);
            $config['link_label'] = $label !== '' ? $label : 'View full status page';
        }

        if (isset($config['subscribe_label'])) {
            $sub = trim((string) $config['subscribe_label']);
            $config['subscribe_label'] = $sub !== '' ? $sub : 'Subscribe to updates';
        }

        $ttl = isset($config['cache_ttl']) ? (int) $config['cache_ttl'] : 0;
        if ($ttl < 0) {
            $ttl = 0;
        } elseif ($ttl > 3600) {
            $ttl = 3600;
        }
        $config['cache_ttl'] = (string) $ttl;

        if (!isset($config['banner_on']) || !in_array($config['banner_on'], array('other_client', 'all_client', 'off'), true)) {
            // Migrate legacy show_on if present.
            if (isset($config['show_on']) && $config['show_on'] === 'landing_only') {
                $config['banner_on'] = 'off';
            } elseif (isset($config['show_on']) && $config['show_on'] === 'all_client') {
                $config['banner_on'] = 'all_client';
            } else {
                $config['banner_on'] = 'other_client';
            }
        }

        if (!$errors) {
            $msg = 'Configuration updated successfully';
        }

        return true;
    }
}
