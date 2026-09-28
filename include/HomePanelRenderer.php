<?php
/**
 * Native homepage Statuspage panel (no iframe).
 */

class HomePanelRenderer
{
    /**
     * @param array|null $payload From StatuspageClient::buildHomePayload
     * @param string     $linkLabel
     * @param string     $subscribeLabel
     * @param bool       $showSubscribe
     * @return string
     */
    public static function render(
        $payload,
        $linkLabel = 'View full status page',
        $subscribeLabel = 'Subscribe to updates',
        $showSubscribe = true
    ) {
        if (!is_array($payload) || empty($payload['page_url'])) {
            return '';
        }

        $severity = isset($payload['severity']) ? (string) $payload['severity'] : 'none';
        if (!in_array($severity, array('none', 'minor', 'major', 'critical', 'maintenance'), true)) {
            $severity = 'none';
        }

        $headlineRaw = isset($payload['headline']) ? trim((string) $payload['headline']) : '';
        $descriptionRaw = isset($payload['description']) ? trim((string) $payload['description']) : '';
        $stateLabelRaw = self::stateLabel($severity);

        // Drop duplicate lines when Statuspage description matches the state label or headline.
        $showHeadline = ($headlineRaw !== ''
            && strcasecmp($headlineRaw, $stateLabelRaw) !== 0
            && strcasecmp($headlineRaw, $descriptionRaw) !== 0);
        $showDescription = ($descriptionRaw !== ''
            && strcasecmp($descriptionRaw, $stateLabelRaw) !== 0
            && (!$showHeadline || strcasecmp($descriptionRaw, $headlineRaw) !== 0)
            && $severity !== 'none');

        $pageUrl = self::escapeAttr((string) $payload['page_url']);
        $pageName = self::escape(isset($payload['page_name']) ? (string) $payload['page_name'] : 'Service status');
        $linkLabel = trim((string) $linkLabel);
        if ($linkLabel === '') {
            $linkLabel = 'View full status page';
        }
        $subscribeLabel = trim((string) $subscribeLabel);
        if ($subscribeLabel === '') {
            $subscribeLabel = 'Subscribe to updates';
        }

        $updated = self::formatCheckedAt(
            isset($payload['checked_at']) ? $payload['checked_at'] : null
        );

        $incidentsHtml = self::incidentBlock(
            isset($payload['incidents']) && is_array($payload['incidents']) ? $payload['incidents'] : array()
        );
        $maintHtml = self::listBlock(
            'Active maintenance',
            isset($payload['maintenances']) && is_array($payload['maintenances']) ? $payload['maintenances'] : array(),
            'maintenance'
        );
        $upcomingHtml = self::listBlock(
            'Upcoming maintenance',
            isset($payload['upcoming']) && is_array($payload['upcoming']) ? $payload['upcoming'] : array(),
            'upcoming'
        );
        $componentsHtml = self::componentsBlock(
            isset($payload['components']) && is_array($payload['components']) ? $payload['components'] : array()
        );

        $actions = '<p class="ost-sp-home-actions">';
        if ($showSubscribe) {
            $actions .= '<a class="button blue ost-sp-home-btn" href="' . $pageUrl . '" target="_blank" rel="noopener noreferrer">'
                . self::escape($subscribeLabel) . '</a> ';
        }
        $actions .= '<a class="ost-sp-home-link" href="' . $pageUrl . '" target="_blank" rel="noopener noreferrer">'
            . self::escape($linkLabel) . '</a>';
        $actions .= '</p>';

        $css = self::css();

        return
            '<div id="ost-sp-home" class="ost-sp-home ost-sp-home-' . self::escapeAttr($severity) . '" role="region" aria-label="Service status">'
            . '<style type="text/css">' . $css . '</style>'
            . '<div class="ost-sp-home-hero">'
            . '<p class="ost-sp-home-kicker">' . ($pageName !== '' ? $pageName : 'Service status') . '</p>'
            . '<p class="ost-sp-home-state"><span class="ost-sp-home-dot" aria-hidden="true"></span>'
            . self::escape($stateLabelRaw) . '</p>'
            . ($showHeadline ? '<p class="ost-sp-home-headline">' . self::escape($headlineRaw) . '</p>' : '')
            . ($showDescription ? '<p class="ost-sp-home-desc">' . self::escape($descriptionRaw) . '</p>' : '')
            . ($updated !== '' ? '<p class="ost-sp-home-updated">' . self::escape($updated) . '</p>' : '')
            . $actions
            . '</div>'
            . $incidentsHtml
            . $maintHtml
            . $upcomingHtml
            . $componentsHtml
            . '</div>';
    }

    /**
     * Remove Statuspage embed script/iframe and inject the native panel into landing content.
     *
     * @param string $html
     * @param string $panel
     * @return string
     */
    public static function injectIntoLandingHtml($html, $panel)
    {
        if ($html === '') {
            return $html;
        }

        $html = self::stripStatuspageEmbeds($html);

        if ($panel === '') {
            return $html;
        }
        if (stripos($html, 'id="ost-sp-home"') !== false) {
            return $html;
        }

        if (preg_match('/(<div[^>]*class=["\'][^"\']*thread-body[^"\']*["\'][^>]*>)(.*?)(<\/div>)/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $open = $m[1][0];
            $inner = $m[2][0];
            $close = $m[3][0];
            $start = $m[1][1];
            $end = $m[3][1] + strlen($close);
            $kept = self::keepWelcomeMarkup($inner);
            $replacement = $open . $kept . $panel . $close;
            return substr($html, 0, $start) . $replacement . substr($html, $end);
        }

        if (preg_match('/(<div[^>]*\bid=["\']landing_page["\'][^>]*>)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[1][1] + strlen($m[1][0]);
            return substr($html, 0, $pos) . "\n" . $panel . "\n" . substr($html, $pos);
        }

        if (preg_match('/(<div[^>]*\bid=["\']content["\'][^>]*>)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[1][1] + strlen($m[1][0]);
            return substr($html, 0, $pos) . "\n" . $panel . "\n" . substr($html, $pos);
        }

        return $html;
    }

    /**
     * @param string $html
     * @return string
     */
    public static function stripStatuspageEmbeds($html)
    {
        $html = preg_replace(
            '#<script[^>]+statuspage\.io/embed/script\.js[^>]*>\s*</script>#i',
            '',
            $html
        );
        $html = preg_replace(
            '#<iframe[^>]+statuspage\.io[^>]*>\s*</iframe>#is',
            '',
            $html
        );
        $html = preg_replace(
            '#<iframe[^>]+name=["\']statuspage["\'][^>]*>\s*</iframe>#is',
            '',
            $html
        );
        return is_string($html) ? $html : '';
    }

    /**
     * @param string $inner
     * @return string
     */
    private static function keepWelcomeMarkup($inner)
    {
        $inner = trim((string) $inner);
        if ($inner === '') {
            return '';
        }
        $withoutEmbeds = self::stripStatuspageEmbeds($inner);
        $withoutEmbeds = trim(preg_replace('#<script\b[^>]*>.*?</script>#is', '', $withoutEmbeds));
        if ($withoutEmbeds === '') {
            return '';
        }
        if (preg_match_all('#<(h[1-3]|p)\b[^>]*>.*?</\1>#is', $withoutEmbeds, $matches)) {
            $bits = array();
            foreach ($matches[0] as $chunk) {
                $text = trim(html_entity_decode(strip_tags($chunk), ENT_QUOTES, 'UTF-8'));
                if ($text === '') {
                    continue;
                }
                $bits[] = $chunk;
                if (count($bits) >= 3) {
                    break;
                }
            }
            if (!empty($bits)) {
                return implode("\n", $bits) . "\n";
            }
        }
        return '';
    }

    /**
     * @param array $incidents
     * @return string
     */
    private static function incidentBlock(array $incidents)
    {
        if (empty($incidents)) {
            return '';
        }
        $out = '<div class="ost-sp-home-section"><h2 class="ost-sp-home-h">Current incidents</h2><ul class="ost-sp-home-list">';
        foreach ($incidents as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $meta = '';
            if (!empty($item['impact'])) {
                $meta = ' <span class="ost-sp-home-meta">(' . self::escape((string) $item['impact']) . ')</span>';
            }
            $out .= '<li><strong>' . self::escape((string) $item['name']) . '</strong>' . $meta;
            if (!empty($item['update'])) {
                $out .= '<div class="ost-sp-home-update">' . self::escape((string) $item['update']) . '</div>';
            }
            $out .= '</li>';
        }
        $out .= '</ul></div>';
        return $out;
    }

    /**
     * @param string $title
     * @param array  $items
     * @param string $kind
     * @return string
     */
    private static function listBlock($title, array $items, $kind)
    {
        if (empty($items)) {
            return '';
        }
        $out = '<div class="ost-sp-home-section"><h2 class="ost-sp-home-h">' . self::escape($title) . '</h2><ul class="ost-sp-home-list">';
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $meta = '';
            if ($kind === 'upcoming' && !empty($item['scheduled'])) {
                $meta = ' <span class="ost-sp-home-meta">(' . self::escape((string) $item['scheduled']) . ')</span>';
            } elseif (!empty($item['status'])) {
                $meta = ' <span class="ost-sp-home-meta">(' . self::escape(str_replace('_', ' ', (string) $item['status'])) . ')</span>';
            }
            $out .= '<li>' . self::escape((string) $item['name']) . $meta . '</li>';
        }
        $out .= '</ul></div>';
        return $out;
    }

    /**
     * List all components; degraded ones first.
     *
     * @param array $components
     * @return string
     */
    private static function componentsBlock(array $components)
    {
        if (empty($components)) {
            return '';
        }

        $out = '<div class="ost-sp-home-section"><h2 class="ost-sp-home-h">Components</h2>';
        $out .= self::componentList($components);
        $out .= '</div>';
        return $out;
    }

    /**
     * @param array $components
     * @return string
     */
    private static function componentList(array $components)
    {
        $out = '<ul class="ost-sp-home-components">';
        foreach ($components as $c) {
            $status = isset($c['status']) ? (string) $c['status'] : 'operational';
            $label = self::componentStatusLabel($status);
            $out .= '<li class="ost-sp-comp ost-sp-comp-' . self::escapeAttr($status) . '">'
                . '<span class="ost-sp-comp-name">' . self::escape((string) $c['name']) . '</span>'
                . '<span class="ost-sp-comp-status">' . self::escape($label) . '</span>'
                . '</li>';
        }
        $out .= '</ul>';
        return $out;
    }

    /**
     * Show when this request checked Statuspage (not Statuspage's own page.updated_at).
     *
     * @param mixed $checkedAt Unix timestamp or null
     * @return string
     */
    private static function formatCheckedAt($checkedAt)
    {
        if ($checkedAt === null || $checkedAt === '') {
            return 'Checked just now';
        }
        $ts = is_numeric($checkedAt) ? (int) $checkedAt : strtotime((string) $checkedAt);
        if ($ts === false || $ts <= 0) {
            return 'Checked just now';
        }
        $mins = (int) floor((time() - $ts) / 60);
        if ($mins < 1) {
            return 'Checked just now';
        }
        if ($mins < 60) {
            return 'Checked ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
        }
        $hours = (int) floor($mins / 60);
        return 'Checked ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    /**
     * @param string $severity
     * @return string
     */
    private static function stateLabel($severity)
    {
        switch ($severity) {
            case 'critical':
                return 'Major outage';
            case 'major':
                return 'Partial outage';
            case 'minor':
                return 'Degraded performance';
            case 'maintenance':
                return 'Maintenance in progress';
            case 'none':
            default:
                return 'All systems operational';
        }
    }

    /**
     * @param string $status
     * @return string
     */
    private static function componentStatusLabel($status)
    {
        $map = array(
            'operational'   => 'Operational',
            'degraded_performance' => 'Degraded performance',
            'partial_outage' => 'Partial outage',
            'major_outage'  => 'Major outage',
            'under_maintenance' => 'Under maintenance',
        );
        $status = strtolower($status);
        return isset($map[$status]) ? $map[$status] : ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Align with osTicket notice bars / portal buttons.
     *
     * @return string
     */
    private static function css()
    {
        return
            '#ost-sp-home{margin:0 0 1em;padding:0;font:inherit;color:#000;max-width:100%;box-sizing:border-box}'
            . '#ost-sp-home .ost-sp-home-hero{padding:8px 12px;border:1px solid #00aa00;'
            . 'background:#e0ffe0;min-height:16px;line-height:1.4}'
            . '#ost-sp-home.ost-sp-home-minor .ost-sp-home-hero{border-color:#f26522;background:#ffffdd}'
            . '#ost-sp-home.ost-sp-home-major .ost-sp-home-hero,#ost-sp-home.ost-sp-home-critical .ost-sp-home-hero{'
            . 'border-color:#aa0000;background:#fff0f0}'
            . '#ost-sp-home.ost-sp-home-maintenance .ost-sp-home-hero{border-color:#3a87ad;background:#d9edf7}'
            . '#ost-sp-home .ost-sp-home-kicker{margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:0.03em;opacity:0.75}'
            . '#ost-sp-home .ost-sp-home-state{margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}'
            . '#ost-sp-home .ost-sp-home-dot{width:9px;height:9px;border-radius:50%;background:#00aa00;display:inline-block;flex:0 0 auto}'
            . '#ost-sp-home.ost-sp-home-minor .ost-sp-home-dot{background:#f26522}'
            . '#ost-sp-home.ost-sp-home-major .ost-sp-home-dot,#ost-sp-home.ost-sp-home-critical .ost-sp-home-dot{background:#aa0000}'
            . '#ost-sp-home.ost-sp-home-maintenance .ost-sp-home-dot{background:#3a87ad}'
            . '#ost-sp-home .ost-sp-home-headline{margin:6px 0 0;font-size:13px;line-height:1.45;font-weight:700}'
            . '#ost-sp-home .ost-sp-home-desc,#ost-sp-home .ost-sp-home-update{margin:4px 0 0;font-size:13px;line-height:1.45}'
            . '#ost-sp-home .ost-sp-home-updated{margin:6px 0 0;font-size:11px;opacity:0.75}'
            . '#ost-sp-home .ost-sp-home-actions{margin:10px 0 0;display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px}'
            . '#ost-sp-home .ost-sp-home-btn{display:inline-block;text-decoration:none !important}'
            . '#ost-sp-home .ost-sp-home-link{font-weight:700;text-decoration:underline;color:inherit}'
            . '#ost-sp-home .ost-sp-home-section{margin:14px 0 0}'
            . '#ost-sp-home .ost-sp-home-h{margin:0 0 8px;font-size:13px;font-weight:700}'
            . '#ost-sp-home .ost-sp-home-list{margin:0;padding-left:1.2em}'
            . '#ost-sp-home .ost-sp-home-list li{margin:0 0 8px;line-height:1.4}'
            . '#ost-sp-home .ost-sp-home-meta{opacity:0.75;font-size:12px}'
            . '#ost-sp-home .ost-sp-home-components{list-style:none;margin:0;padding:0;border:1px solid #ddd;background:#fff}'
            . '#ost-sp-home .ost-sp-comp{display:flex;justify-content:space-between;gap:12px;padding:7px 10px;border-bottom:1px solid #eee;font-size:13px}'
            . '#ost-sp-home .ost-sp-comp:last-child{border-bottom:0}'
            . '#ost-sp-home .ost-sp-comp-name{flex:1 1 auto}'
            . '#ost-sp-home .ost-sp-comp-status{flex:0 0 auto;font-weight:700;color:#2e7d32}'
            . '#ost-sp-home .ost-sp-comp-degraded_performance .ost-sp-comp-status,'
            . '#ost-sp-home .ost-sp-comp-partial_outage .ost-sp-comp-status{color:#c79100}'
            . '#ost-sp-home .ost-sp-comp-major_outage .ost-sp-comp-status{color:#c0392b}'
            . '#ost-sp-home .ost-sp-comp-under_maintenance .ost-sp-comp-status{color:#2980b9}'
            . '@media (max-width:640px){#ost-sp-home .ost-sp-comp{flex-direction:column;gap:2px}}';
    }

    /**
     * @param string $s
     * @return string
     */
    private static function escape($s)
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $s
     * @return string
     */
    private static function escapeAttr($s)
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
