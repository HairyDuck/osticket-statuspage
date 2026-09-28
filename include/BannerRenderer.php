<?php
/**
 * Builds accessible Statuspage banner HTML/CSS/JS for injection into client pages.
 *
 * Styled to match stock osTicket notice / warning / error bars (not an iframe).
 */

class BannerRenderer
{
    /**
     * @param array|null $payload From StatuspageClient::decideFromSummary / getBannerPayload
     * @param string     $linkLabel
     * @return string Empty string when nothing to show
     */
    public static function render($payload, $linkLabel = 'View status page')
    {
        if (!is_array($payload) || empty($payload['title']) || empty($payload['page_url'])) {
            return '';
        }

        $severity = isset($payload['severity']) ? (string) $payload['severity'] : 'minor';
        if (!in_array($severity, array('minor', 'major', 'critical', 'maintenance'), true)) {
            $severity = 'minor';
        }

        $title = self::escape((string) $payload['title']);
        $pageUrl = self::escapeAttr((string) $payload['page_url']);
        $dismissKey = self::escapeAttr(
            isset($payload['dismiss_key']) ? (string) $payload['dismiss_key'] : 'unknown'
        );
        $linkLabel = trim((string) $linkLabel);
        if ($linkLabel === '') {
            $linkLabel = 'View status page';
        }
        $linkLabelEsc = self::escape($linkLabel);

        $severityLabel = self::severityLabel($severity);
        $severityLabelEsc = self::escape($severityLabel);
        $barClass = self::barClass($severity);

        $css = self::css();
        $js = self::js();

        // Mirror stock header bars: #error_bar / #warning_bar / #notice_bar patterns.
        return
            '<div id="statuspage-banner" class="' . self::escapeAttr($barClass) . ' ost-sp-banner"'
            . ' role="region" aria-live="polite" aria-label="' . $severityLabelEsc . '"'
            . ' data-dismiss-key="' . $dismissKey . '" hidden>'
            . '<style type="text/css">' . $css . '</style>'
            . '<span class="ost-sp-text">'
            . '<strong>' . $severityLabelEsc . ':</strong> '
            . $title
            . ' &mdash; '
            . '<a class="ost-sp-link" href="' . $pageUrl . '" target="_blank" rel="noopener noreferrer">'
            . $linkLabelEsc . '</a>'
            . '</span>'
            . '<button type="button" class="ost-sp-dismiss" aria-label="Dismiss status banner" title="Dismiss">&times;</button>'
            . '<script type="text/javascript">' . $js . '</script>'
            . '</div>';
    }

    /**
     * Insert banner HTML after the opening #container div (same zone as system bars).
     *
     * @param string $html
     * @param string $banner
     * @return string
     */
    public static function injectIntoHtml($html, $banner)
    {
        if ($banner === '' || $html === '') {
            return $html;
        }
        if (stripos($html, 'id="statuspage-banner"') !== false
            || stripos($html, "id='statuspage-banner'") !== false
        ) {
            return $html;
        }

        // Prefer immediately before #header so it sits with native page chrome.
        if (preg_match('/<div[^>]*\bid=["\']header["\'][^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return substr($html, 0, $pos) . $banner . "\n" . substr($html, $pos);
        }

        if (!preg_match('/<div[^>]*\bid=["\']container["\'][^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $openTag = $m[0][0];
        $pos = $m[0][1] + strlen($openTag);
        return substr($html, 0, $pos) . "\n" . $banner . "\n" . substr($html, $pos);
    }

    /**
     * @param string $severity
     * @return string
     */
    private static function severityLabel($severity)
    {
        switch ($severity) {
            case 'critical':
                return 'Major outage';
            case 'major':
                return 'Partial outage';
            case 'maintenance':
                return 'Maintenance';
            case 'minor':
            default:
                return 'Degraded performance';
        }
    }

    /**
     * Map severity onto stock osTicket bar classes.
     *
     * @param string $severity
     * @return string
     */
    private static function barClass($severity)
    {
        switch ($severity) {
            case 'critical':
            case 'major':
                return 'error_bar';
            case 'maintenance':
                return 'notice_bar';
            case 'minor':
            default:
                return 'warning_bar';
        }
    }

    /**
     * Only supplements stock bar CSS (layout for link + dismiss).
     *
     * @return string
     */
    private static function css()
    {
        return
            '#statuspage-banner.ost-sp-banner{display:none;box-sizing:border-box;position:relative;'
            . 'height:auto;min-height:16px;line-height:1.35;padding-right:32px;white-space:normal}'
            . '#statuspage-banner.ost-sp-banner[data-visible="1"]{display:block}'
            . '#statuspage-banner .ost-sp-text{display:inline}'
            . '#statuspage-banner .ost-sp-link{font-weight:bold;text-decoration:underline}'
            . '#statuspage-banner .ost-sp-dismiss{position:absolute;right:8px;top:50%;'
            . 'transform:translateY(-50%);background:transparent;border:0;font-size:18px;'
            . 'line-height:1;cursor:pointer;padding:2px 6px;opacity:0.7}'
            . '#statuspage-banner .ost-sp-dismiss:hover,#statuspage-banner .ost-sp-dismiss:focus{opacity:1}'
            // Maintenance uses notice_bar greens; keep readable link colour.
            . '#statuspage-banner.notice_bar .ost-sp-link{color:inherit}'
            . '#statuspage-banner.warning_bar .ost-sp-link{color:inherit}'
            . '#statuspage-banner.error_bar .ost-sp-link{color:inherit}';
    }

    /**
     * Minimal dismiss logic; CSP-safe as inline script under stock osTicket client CSP.
     *
     * @return string
     */
    private static function js()
    {
        return
            '(function(){'
            . 'var el=document.getElementById("statuspage-banner");'
            . 'if(!el)return;'
            . 'var key=el.getAttribute("data-dismiss-key")||"";'
            . 'var storeKey="ost_sp_banner_dismissed";'
            . 'try{if(key&&window.localStorage&&localStorage.getItem(storeKey)===key){el.parentNode&&el.parentNode.removeChild(el);return;}}catch(e){}'
            . 'el.removeAttribute("hidden");'
            . 'el.setAttribute("data-visible","1");'
            . 'var btn=el.querySelector(".ost-sp-dismiss");'
            . 'if(btn){btn.addEventListener("click",function(){'
            . 'try{if(key&&window.localStorage)localStorage.setItem(storeKey,key);}catch(e){}'
            . 'el.parentNode&&el.parentNode.removeChild(el);'
            . '});}'
            . '})();';
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
