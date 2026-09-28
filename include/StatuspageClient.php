<?php
/**
 * HTTPS client and status decision logic for Atlassian Statuspage summary.json.
 *
 * Standalone enough to unit-test offline with fixtures (no osTicket required).
 */

class StatuspageClient
{
    const HTTP_TIMEOUT = 2.5;

    /** @var bool */
    private static $loggedFetchError = false;

    /**
     * Normalise a Statuspage base URL: https only, no trailing slash, no path.
     *
     * @param string $url
     * @return string|null Normalised base URL, or null if invalid
     */
    public static function normaliseBaseUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (!preg_match('#^https://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (!preg_match('#^[a-z0-9.-]+$#', $host)) {
            return null;
        }

        $port = '';
        if (!empty($parts['port']) && (int) $parts['port'] !== 443) {
            $port = ':' . (int) $parts['port'];
        }

        return 'https://' . $host . $port;
    }

    /**
     * Build the summary API URL from a normalised base.
     *
     * @param string $baseUrl
     * @return string
     */
    public static function summaryUrl($baseUrl)
    {
        return rtrim($baseUrl, '/') . '/api/v2/summary.json';
    }

    /**
     * Fetch (or read cached) summary JSON and decide whether to show a banner.
     *
     * @param string $baseUrl Normalised https base
     * @param int    $ttlSeconds
     * @return array|null Banner payload or null when nothing should be shown
     *                    Keys: severity, title, description, page_url, dismiss_key
     */
    public static function getBannerPayload($baseUrl, $ttlSeconds = 0)
    {
        $baseUrl = self::normaliseBaseUrl($baseUrl);
        if ($baseUrl === null) {
            return null;
        }

        $summary = self::fetchSummary($baseUrl, $ttlSeconds);
        if ($summary === null) {
            return null;
        }

        return self::decideFromSummary($summary, $baseUrl);
    }

    /**
     * Fetch summary and build a homepage status panel payload (always when fetch OK).
     *
     * @param string $baseUrl
     * @param int    $ttlSeconds
     * @return array|null
     */
    public static function getHomePayload($baseUrl, $ttlSeconds = 0)
    {
        $baseUrl = self::normaliseBaseUrl($baseUrl);
        if ($baseUrl === null) {
            return null;
        }

        $summary = self::fetchSummary($baseUrl, $ttlSeconds);
        if ($summary === null) {
            return null;
        }

        return self::buildHomePayload($summary, $baseUrl);
    }

    /**
     * Always-on homepage payload from a decoded summary (smoke-testable).
     *
     * @param array  $summary
     * @param string $fallbackPageUrl
     * @return array|null
     */
    public static function buildHomePayload(array $summary, $fallbackPageUrl = '')
    {
        if (!isset($summary['status']) || !is_array($summary['status'])) {
            return null;
        }

        $pageUrl = $fallbackPageUrl;
        if (!empty($summary['page']['url']) && is_string($summary['page']['url'])) {
            $pageUrl = $summary['page']['url'];
        }
        $pageUrl = rtrim((string) $pageUrl, '/');

        $pageName = '';
        if (!empty($summary['page']['name']) && is_string($summary['page']['name'])) {
            $pageName = trim($summary['page']['name']);
        }

        $indicator = isset($summary['status']['indicator'])
            ? strtolower(trim((string) $summary['status']['indicator']))
            : 'none';
        $statusDescription = isset($summary['status']['description'])
            ? trim((string) $summary['status']['description'])
            : '';

        $incidentsRaw = isset($summary['incidents']) && is_array($summary['incidents'])
            ? $summary['incidents']
            : array();
        $maintenancesRaw = isset($summary['scheduled_maintenances']) && is_array($summary['scheduled_maintenances'])
            ? $summary['scheduled_maintenances']
            : array();

        $incidents = array();
        foreach ($incidentsRaw as $incident) {
            if (!is_array($incident)) {
                continue;
            }
            $status = isset($incident['status']) ? strtolower((string) $incident['status']) : '';
            if (in_array($status, array('resolved', 'postmortem'), true)) {
                continue;
            }
            $incidents[] = array(
                'id'     => isset($incident['id']) ? (string) $incident['id'] : '',
                'name'   => isset($incident['name']) ? trim((string) $incident['name']) : '',
                'status' => $status,
                'impact' => isset($incident['impact']) ? strtolower((string) $incident['impact']) : '',
                'update' => self::latestIncidentUpdateBody($incident),
            );
        }

        $maintenances = array();
        $upcoming = array();
        $now = time();
        foreach ($maintenancesRaw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $status = isset($m['status']) ? strtolower((string) $m['status']) : '';
            $row = array(
                'id'         => isset($m['id']) ? (string) $m['id'] : '',
                'name'       => isset($m['name']) ? trim((string) $m['name']) : '',
                'status'     => $status,
                'scheduled'  => isset($m['scheduled_for']) ? (string) $m['scheduled_for'] : '',
            );
            if (in_array($status, array('in_progress', 'verifying'), true)) {
                $maintenances[] = $row;
                continue;
            }
            if ($status === 'scheduled' && $row['name'] !== '') {
                $when = self::parseTime($row['scheduled']);
                // Quiet notice for maintenance starting within 72 hours.
                if ($when !== null && $when >= $now && $when <= ($now + 72 * 3600)) {
                    $upcoming[] = $row;
                }
            }
        }

        $severity = 'none';
        if (!empty($incidents)) {
            $impact = isset($incidents[0]['impact']) ? $incidents[0]['impact'] : '';
            $severity = self::mapImpactToSeverity($impact, $indicator);
        } elseif (!empty($maintenances) || $indicator === 'maintenance') {
            $severity = 'maintenance';
        } elseif (in_array($indicator, array('minor', 'major', 'critical'), true)) {
            $severity = $indicator;
        }

        $headline = $statusDescription !== '' ? $statusDescription : 'All systems operational';
        if (!empty($incidents) && $incidents[0]['name'] !== '') {
            $headline = $incidents[0]['name'];
        } elseif (!empty($maintenances) && $maintenances[0]['name'] !== '') {
            $headline = $maintenances[0]['name'];
        }

        // Avoid duplicating the state label / Statuspage description when healthy.
        $stateLabel = 'All systems operational';
        if ($severity === 'none'
            && strcasecmp($headline, $statusDescription) === 0
        ) {
            $headline = '';
        } elseif ($severity === 'none'
            && strcasecmp($headline, $stateLabel) === 0
        ) {
            $headline = '';
        }

        $components = array();
        if (isset($summary['components']) && is_array($summary['components'])) {
            foreach ($summary['components'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                if (!empty($c['group'])) {
                    continue;
                }
                $showcase = !empty($c['showcase']);
                $components[] = array(
                    'name'     => isset($c['name']) ? trim((string) $c['name']) : '',
                    'status'   => isset($c['status']) ? strtolower((string) $c['status']) : 'operational',
                    'showcase' => $showcase,
                );
            }
            $showcased = array_values(array_filter($components, function ($row) {
                return !empty($row['showcase']) && $row['name'] !== '';
            }));
            if (!empty($showcased)) {
                $components = $showcased;
            } else {
                $components = array_values(array_filter($components, function ($row) {
                    return $row['name'] !== '';
                }));
            }
            // Degraded / maintenance first.
            usort($components, function ($a, $b) {
                $ao = ($a['status'] === 'operational') ? 1 : 0;
                $bo = ($b['status'] === 'operational') ? 1 : 0;
                if ($ao === $bo) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return $ao - $bo;
            });
        }

        return array(
            'severity'     => $severity,
            'indicator'    => $indicator,
            'headline'     => $headline,
            'description'  => $statusDescription,
            'page_url'     => $pageUrl,
            'page_name'    => $pageName,
            'updated_at'   => isset($summary['page']['updated_at']) ? (string) $summary['page']['updated_at'] : '',
            'checked_at'   => time(),
            'components'   => $components,
            'incidents'    => $incidents,
            'maintenances' => $maintenances,
            'upcoming'     => $upcoming,
        );
    }

    /**
     * @param array $incident
     * @return string
     */
    private static function latestIncidentUpdateBody(array $incident)
    {
        if (empty($incident['incident_updates']) || !is_array($incident['incident_updates'])) {
            return '';
        }
        $updates = $incident['incident_updates'];
        // Prefer newest by created_at when present.
        usort($updates, function ($a, $b) {
            $at = isset($a['created_at']) ? strtotime((string) $a['created_at']) : 0;
            $bt = isset($b['created_at']) ? strtotime((string) $b['created_at']) : 0;
            return $bt - $at;
        });
        $first = reset($updates);
        if (!is_array($first) || empty($first['body'])) {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $first['body'])));
    }

    /**
     * @param string $value
     * @return int|null Unix timestamp
     */
    private static function parseTime($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return ($ts === false) ? null : $ts;
    }

    /**
     * Decide from a decoded summary array (used by smoke tests with fixtures).
     *
     * @param array  $summary
     * @param string $fallbackPageUrl
     * @return array|null
     */
    public static function decideFromSummary(array $summary, $fallbackPageUrl = '')
    {
        $pageUrl = $fallbackPageUrl;
        if (!empty($summary['page']['url']) && is_string($summary['page']['url'])) {
            $pageUrl = $summary['page']['url'];
        }
        $pageUrl = rtrim((string) $pageUrl, '/');

        $indicator = isset($summary['status']['indicator'])
            ? strtolower(trim((string) $summary['status']['indicator']))
            : 'none';
        $statusDescription = isset($summary['status']['description'])
            ? trim((string) $summary['status']['description'])
            : '';

        $incident = self::firstUnresolvedIncident(
            isset($summary['incidents']) && is_array($summary['incidents'])
                ? $summary['incidents']
                : array()
        );
        $maintenance = self::firstActiveMaintenance(
            isset($summary['scheduled_maintenances']) && is_array($summary['scheduled_maintenances'])
                ? $summary['scheduled_maintenances']
                : array()
        );

        $shouldShow = ($incident !== null)
            || ($maintenance !== null)
            || in_array($indicator, array('minor', 'major', 'critical', 'maintenance'), true);

        if (!$shouldShow) {
            return null;
        }

        $severity = 'minor';
        $title = $statusDescription !== '' ? $statusDescription : 'Service status update';
        $dismissKey = 'status:' . $indicator;
        if (!empty($summary['page']['updated_at'])) {
            $dismissKey .= ':' . $summary['page']['updated_at'];
        }

        if ($incident !== null) {
            $impact = isset($incident['impact']) ? strtolower((string) $incident['impact']) : '';
            $severity = self::mapImpactToSeverity($impact, $indicator);
            $title = isset($incident['name']) && trim((string) $incident['name']) !== ''
                ? trim((string) $incident['name'])
                : $title;
            $dismissKey = 'incident:' . (isset($incident['id']) ? $incident['id'] : 'unknown');
            if (!empty($incident['updated_at'])) {
                $dismissKey .= ':' . $incident['updated_at'];
            }
        } elseif ($maintenance !== null || $indicator === 'maintenance') {
            $severity = 'maintenance';
            if ($maintenance !== null
                && isset($maintenance['name'])
                && trim((string) $maintenance['name']) !== ''
            ) {
                $title = trim((string) $maintenance['name']);
                $dismissKey = 'maintenance:' . (isset($maintenance['id']) ? $maintenance['id'] : 'unknown');
                if (!empty($maintenance['updated_at'])) {
                    $dismissKey .= ':' . $maintenance['updated_at'];
                }
            } else {
                $title = $statusDescription !== '' ? $statusDescription : 'Scheduled maintenance in progress';
            }
        } else {
            $severity = self::mapImpactToSeverity($indicator, $indicator);
        }

        return array(
            'severity'    => $severity,
            'title'       => $title,
            'description' => $statusDescription,
            'page_url'    => $pageUrl,
            'dismiss_key' => $dismissKey,
        );
    }

    /**
     * @param array $incidents
     * @return array|null
     */
    private static function firstUnresolvedIncident(array $incidents)
    {
        foreach ($incidents as $incident) {
            if (!is_array($incident)) {
                continue;
            }
            $status = isset($incident['status']) ? strtolower((string) $incident['status']) : '';
            // Resolved / postmortem are finished; anything else is active for banner purposes.
            if (in_array($status, array('resolved', 'postmortem'), true)) {
                continue;
            }
            return $incident;
        }
        return null;
    }

    /**
     * Only in_progress / verifying maintenances (not upcoming scheduled).
     *
     * @param array $maintenances
     * @return array|null
     */
    private static function firstActiveMaintenance(array $maintenances)
    {
        foreach ($maintenances as $m) {
            if (!is_array($m)) {
                continue;
            }
            $status = isset($m['status']) ? strtolower((string) $m['status']) : '';
            if (in_array($status, array('in_progress', 'verifying'), true)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * @param string $impact
     * @param string $fallbackIndicator
     * @return string minor|major|critical|maintenance
     */
    private static function mapImpactToSeverity($impact, $fallbackIndicator)
    {
        $impact = strtolower(trim((string) $impact));
        if (in_array($impact, array('critical', 'major', 'minor'), true)) {
            return $impact;
        }
        $fallback = strtolower(trim((string) $fallbackIndicator));
        if (in_array($fallback, array('critical', 'major', 'minor'), true)) {
            return $fallback;
        }
        return 'minor';
    }

    /**
     * @param string $baseUrl
     * @param int    $ttlSeconds 0 = always fetch fresh
     * @return array|null Decoded summary or null on failure
     */
    public static function fetchSummary($baseUrl, $ttlSeconds = 0)
    {
        $ttlSeconds = (int) $ttlSeconds;
        if ($ttlSeconds < 0) {
            $ttlSeconds = 0;
        } elseif ($ttlSeconds > 3600) {
            $ttlSeconds = 3600;
        }

        if ($ttlSeconds > 0) {
            $cached = self::readCache($baseUrl, $ttlSeconds);
            if ($cached !== null) {
                return $cached;
            }
        }

        $url = self::summaryUrl($baseUrl);
        $body = self::httpGet($url);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['status'])) {
            self::logFetchErrorOnce('Invalid Statuspage summary JSON from ' . $url);
            return null;
        }

        if ($ttlSeconds > 0) {
            self::writeCache($baseUrl, $decoded);
        }

        return $decoded;
    }

    /**
     * @param string $url
     * @return string|null Response body or null
     */
    private static function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                self::logFetchErrorOnce('curl_init failed for Statuspage fetch');
                return null;
            }
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT      => 'osticket-statuspage-banner/1.0',
                CURLOPT_HTTPHEADER     => array('Accept: application/json'),
            ));
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) {
                self::logFetchErrorOnce(
                    'Statuspage fetch failed (http=' . $status . ', errno=' . $errno . ')'
                );
                return null;
            }
            return (string) $body;
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'method'  => 'GET',
                'timeout' => self::HTTP_TIMEOUT,
                'header'  => "Accept: application/json\r\nUser-Agent: osticket-statuspage-banner/1.0\r\n",
            ),
            'ssl' => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ),
        ));
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            self::logFetchErrorOnce('Statuspage fetch failed via file_get_contents');
            return null;
        }
        return (string) $body;
    }

    /**
     * @param string $baseUrl
     * @return string
     */
    private static function cachePath($baseUrl)
    {
        $dir = sys_get_temp_dir();
        $hash = hash('sha256', $baseUrl);
        return $dir . DIRECTORY_SEPARATOR . 'ost_sp_banner_' . $hash . '.json';
    }

    /**
     * @param string $baseUrl
     * @param int    $ttlSeconds
     * @return array|null
     */
    private static function readCache($baseUrl, $ttlSeconds)
    {
        $path = self::cachePath($baseUrl);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $wrap = json_decode($raw, true);
        if (!is_array($wrap) || !isset($wrap['fetched_at'], $wrap['summary']) || !is_array($wrap['summary'])) {
            return null;
        }
        if ((time() - (int) $wrap['fetched_at']) > $ttlSeconds) {
            return null;
        }
        return $wrap['summary'];
    }

    /**
     * @param string $baseUrl
     * @param array  $summary
     */
    private static function writeCache($baseUrl, array $summary)
    {
        $path = self::cachePath($baseUrl);
        $payload = json_encode(array(
            'fetched_at' => time(),
            'summary'    => $summary,
        ));
        if ($payload === false) {
            return;
        }
        @file_put_contents($path, $payload, LOCK_EX);
    }

    /**
     * @param string $message
     */
    private static function logFetchErrorOnce($message)
    {
        if (self::$loggedFetchError) {
            return;
        }
        self::$loggedFetchError = true;
        error_log('[osticket-statuspage-banner] ' . $message);
    }
}
