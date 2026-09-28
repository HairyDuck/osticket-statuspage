<?php
/**
 * Statuspage Banner – main plugin class.
 *
 * - Native status panel on the client landing page (no iframe)
 * - Optional slim degraded banner on other client pages
 * Does not patch core files.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/StatuspageClient.php';
require_once __DIR__ . '/include/BannerRenderer.php';
require_once __DIR__ . '/include/HomePanelRenderer.php';

class StatuspageBannerPlugin extends Plugin
{
    public $config_class = 'StatuspageBannerConfig';

    /** @var StatuspageBannerPlugin|null */
    private static $instance = null;

    /**
     * Side-loaded instance config captured during bootstrap.
     * Named separately from Plugin::$config; PluginManager nulls that after boot.
     *
     * @var StatuspageBannerConfig|null
     */
    private static $cachedInstanceConfig = null;

    /** @var bool */
    private static $bufferStarted = false;

    public function bootstrap()
    {
        self::$instance = $this;
        $sideLoaded = $this->getConfig();
        if ($sideLoaded) {
            self::$cachedInstanceConfig = $sideLoaded;
        }

        if (php_sapi_name() === 'cli') {
            return;
        }

        $conf = self::conf();
        if (!$conf || !$conf->get('enabled')) {
            return;
        }

        $url = trim((string) $conf->get('statuspage_url'));
        if ($url === '' || StatuspageClient::normaliseBaseUrl($url) === null) {
            return;
        }

        if (!self::shouldBufferThisRequest($conf)) {
            return;
        }

        if (self::$bufferStarted) {
            return;
        }
        self::$bufferStarted = true;
        ob_start();
        register_shutdown_function(array('StatuspageBannerPlugin', 'flushBanner'));
    }

    /**
     * @return StatuspageBannerConfig|null
     */
    public static function conf()
    {
        if (self::$cachedInstanceConfig) {
            return self::$cachedInstanceConfig;
        }

        if (!self::$instance) {
            return null;
        }

        if (method_exists(self::$instance, 'getActiveInstances')) {
            foreach (self::$instance->getActiveInstances() as $pluginInstance) {
                $conf = $pluginInstance->getConfig();
                if ($conf) {
                    self::$cachedInstanceConfig = $conf;
                    return self::$cachedInstanceConfig;
                }
            }
        }

        return null;
    }

    /**
     * Shutdown handler: rewrite HTML buffer with home panel and/or banner.
     */
    public static function flushBanner()
    {
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            return;
        }

        try {
            if (!self::isHtmlDocument($html)) {
                echo $html;
                return;
            }

            $conf = self::conf();
            if (!$conf || !$conf->get('enabled')) {
                echo $html;
                return;
            }

            $isScp = defined('OSTSCPINC')
                || (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/scp/') !== false);
            $showOnScp = (bool) $conf->get('show_on_scp');

            if ($isScp && !$showOnScp) {
                echo $html;
                return;
            }

            if (!$isScp && defined('OSTCLIENTINC') && !OSTCLIENTINC) {
                echo $html;
                return;
            }

            $baseUrl = StatuspageClient::normaliseBaseUrl((string) $conf->get('statuspage_url'));
            if ($baseUrl === null) {
                echo $html;
                return;
            }

            $ttl = (int) $conf->get('cache_ttl');
            if ($ttl < 0) {
                $ttl = 0;
            }

            $linkLabel = (string) $conf->get('link_label');
            $isLanding = !$isScp && self::isClientLandingPage();
            $homePanelEnabled = !$isScp && $conf->get('home_panel');

            // Landing: always strip Statuspage embeds; inject native panel when fetch works.
            if ($isLanding && $homePanelEnabled) {
                $homePayload = StatuspageClient::getHomePayload($baseUrl, $ttl);
                $subscribeLabel = (string) $conf->get('subscribe_label');
                if ($subscribeLabel === '') {
                    $subscribeLabel = 'Subscribe to updates';
                }
                $showSubscribe = (bool) $conf->get('show_subscribe');
                // Default on when config key not yet saved on older instances.
                if ($conf->get('show_subscribe') === null || $conf->get('show_subscribe') === '') {
                    $showSubscribe = true;
                }
                $panel = $homePayload
                    ? HomePanelRenderer::render($homePayload, $linkLabel, $subscribeLabel, $showSubscribe)
                    : '';
                $html = HomePanelRenderer::injectIntoLandingHtml($html, $panel);
            } elseif ($isLanding) {
                // Even if panel disabled, remove cramped Statuspage iframe if present.
                $html = HomePanelRenderer::stripStatuspageEmbeds($html);
            }

            // Degraded slim banner (not on landing when home panel already covers it).
            $bannerMode = self::bannerMode($conf);
            $wantBanner = false;
            if ($bannerMode === 'all_client') {
                $wantBanner = true;
            } elseif ($bannerMode === 'other_client') {
                $wantBanner = !$isLanding;
            }
            if ($isScp && $showOnScp) {
                $wantBanner = true;
            }

            if ($wantBanner) {
                $payload = StatuspageClient::getBannerPayload($baseUrl, $ttl);
                if ($payload !== null) {
                    $banner = BannerRenderer::render($payload, $linkLabel);
                    $html = BannerRenderer::injectIntoHtml($html, $banner);
                }
            }

            echo $html;
        } catch (Exception $e) {
            error_log('[osticket-statuspage-banner] ' . $e->getMessage());
            echo $html;
        } catch (Throwable $e) {
            error_log('[osticket-statuspage-banner] ' . $e->getMessage());
            echo $html;
        }
    }

    /**
     * @param StatuspageBannerConfig $conf
     * @return string other_client|all_client|off
     */
    private static function bannerMode($conf)
    {
        $mode = (string) $conf->get('banner_on');
        if (in_array($mode, array('other_client', 'all_client', 'off'), true)) {
            return $mode;
        }
        // Legacy show_on
        $legacy = (string) $conf->get('show_on');
        if ($legacy === 'landing_only') {
            return 'off';
        }
        if ($legacy === 'all_client') {
            return 'all_client';
        }
        return 'other_client';
    }

    /**
     * Early path filter used at bootstrap (constants may not be defined yet).
     *
     * @param StatuspageBannerConfig $conf
     * @return bool
     */
    private static function shouldBufferThisRequest($conf)
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? str_replace('\\', '/', (string) $_SERVER['REQUEST_URI']) : '';

        if (preg_match('#/(ajax|logo|file|offline|captcha)\.php#i', $script)
            || preg_match('#/(ajax|logo|file|offline|captcha)\.php#i', $uri)
        ) {
            return false;
        }

        if (strpos($script, '/api/') !== false || strpos($uri, '/api/') !== false) {
            return false;
        }

        $showOnScp = (bool) $conf->get('show_on_scp');
        $isScp = (strpos($script, '/scp/') !== false || strpos($uri, '/scp/') !== false);

        if ($isScp && !$showOnScp) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private static function isClientLandingPage()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($uri, PHP_URL_PATH);
        $pathBase = is_string($path) ? basename($path) : '';

        $candidates = array($script, $pathBase);
        foreach ($candidates as $name) {
            if ($name === '' || $name === '/' || strcasecmp($name, 'index.php') === 0) {
                return true;
            }
        }

        if (is_string($path)
            && preg_match('#/(index\.php)?$#i', $path)
            && !preg_match('#/(tickets|open|view|login|logout|profile|account|pwreset)#i', $path)
        ) {
            $parts = array_values(array_filter(explode('/', $path), 'strlen'));
            if (count($parts) <= 1) {
                return true;
            }
            $last = end($parts);
            if (strcasecmp($last, 'index.php') === 0) {
                return true;
            }
        }

        // Landing pages usually include #landing_page; detected later is harder in bootstrap.
        return false;
    }

    /**
     * @param string $html
     * @return bool
     */
    private static function isHtmlDocument($html)
    {
        $sample = substr($html, 0, 2048);
        if (stripos($sample, '<html') === false && stripos($html, 'id="container"') === false) {
            return false;
        }
        $trim = ltrim($html);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            return false;
        }
        return true;
    }
}
