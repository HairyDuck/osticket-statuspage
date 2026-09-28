<?php
/**
 * Offline smoke checks (no osTicket install required).
 * Run: php tests/smoke.php
 */

$root = dirname(__DIR__);
$failures = 0;

function fail($msg) {
    global $failures;
    $failures++;
    fwrite(STDERR, "FAIL: $msg\n");
}

function ok($msg) {
    fwrite(STDOUT, "OK: $msg\n");
}

$files = array(
    'plugin.php',
    'config.php',
    'osticket-statuspage-banner.php',
    'include/StatuspageClient.php',
    'include/BannerRenderer.php',
    'include/HomePanelRenderer.php',
);

foreach ($files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (!is_file($path)) {
        fail("missing $file");
        continue;
    }
    $out = array();
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fail("$file syntax: " . implode(' ', $out));
    } else {
        ok("$file syntax");
    }
}

$plugin = include $root . '/plugin.php';
if (!is_array($plugin) || empty($plugin['plugin'])) {
    fail('plugin.php metadata');
} else {
    ok('plugin.php metadata');
}
if (empty($plugin['version']) || $plugin['version'] !== '1.2.3') {
    fail('plugin version expected 1.2.3');
} else {
    ok('plugin version 1.2.3');
}
if (empty($plugin['id']) || $plugin['id'] !== 'opensource:osticket-statuspage') {
    fail('plugin id expected opensource:osticket-statuspage');
} else {
    ok('plugin id');
}
if (empty($plugin['name']) || stripos($plugin['name'], 'Statuspage Home') === false) {
    fail('plugin name expected Statuspage Home');
} else {
    ok('plugin name Statuspage Home');
}

require_once $root . '/include/StatuspageClient.php';
require_once $root . '/include/BannerRenderer.php';
require_once $root . '/include/HomePanelRenderer.php';

// URL normalisation
$cases = array(
    array('https://example.statuspage.io', 'https://example.statuspage.io'),
    array('https://example.statuspage.io/', 'https://example.statuspage.io'),
    array('https://example.statuspage.io/api/v2/summary.json', 'https://example.statuspage.io'),
    array('http://example.statuspage.io', null),
    array('ftp://example.statuspage.io', null),
    array('not-a-url', null),
    array('', null),
);
foreach ($cases as $pair) {
    $got = StatuspageClient::normaliseBaseUrl($pair[0]);
    if ($got !== $pair[1]) {
        fail('normaliseBaseUrl(' . json_encode($pair[0]) . ') expected ' . json_encode($pair[1]) . ' got ' . json_encode($got));
    } else {
        ok('normaliseBaseUrl ' . json_encode($pair[0]));
    }
}

$sumOk = json_decode(file_get_contents($root . '/tests/fixtures/summary_ok.json'), true);
$sumMinor = json_decode(file_get_contents($root . '/tests/fixtures/summary_minor.json'), true);
$sumMaint = json_decode(file_get_contents($root . '/tests/fixtures/summary_maintenance.json'), true);

if (!is_array($sumOk) || !is_array($sumMinor) || !is_array($sumMaint)) {
    fail('fixtures failed to decode');
} else {
    ok('fixtures decode');
}

$payloadOk = StatuspageClient::decideFromSummary($sumOk, 'https://example.statuspage.io');
if ($payloadOk !== null) {
    fail('OK summary must not produce a banner payload');
} else {
    ok('OK summary hides banner');
}

$payloadMinor = StatuspageClient::decideFromSummary($sumMinor, 'https://example.statuspage.io');
if ($payloadMinor === null || empty($payloadMinor['title']) || $payloadMinor['severity'] !== 'minor') {
    fail('minor summary must produce minor banner payload');
} else {
    ok('minor summary shows banner');
}

$payloadMaint = StatuspageClient::decideFromSummary($sumMaint, 'https://example.statuspage.io');
if ($payloadMaint === null || $payloadMaint['severity'] !== 'maintenance') {
    fail('maintenance summary must produce maintenance banner');
} else {
    ok('maintenance summary shows banner');
}

$scheduledOnly = $sumMaint;
$scheduledOnly['scheduled_maintenances'] = array($sumMaint['scheduled_maintenances'][1]);
$scheduledOnly['status'] = array('indicator' => 'none', 'description' => 'All Systems Operational');
if (StatuspageClient::decideFromSummary($scheduledOnly) !== null) {
    fail('upcoming scheduled maintenance alone must not show banner');
} else {
    ok('upcoming scheduled alone hidden');
}

// Home panel: always built when summary is valid (including healthy)
$homeOk = StatuspageClient::buildHomePayload($sumOk, 'https://example.statuspage.io');
if ($homeOk === null || $homeOk['severity'] !== 'none') {
    fail('healthy home payload expected severity none');
} else {
    ok('healthy home payload');
}
if ($homeOk['headline'] !== '') {
    fail('healthy home payload should clear duplicate headline');
} else {
    ok('healthy home clears duplicate headline');
}
$homeHtml = HomePanelRenderer::render($homeOk, 'View full status page', 'Subscribe to updates', true);
if ($homeHtml === '' || stripos($homeHtml, 'id="ost-sp-home"') === false) {
    fail('home panel HTML missing');
} else {
    ok('home panel HTML');
}
if (stripos($homeHtml, 'Checked just now') === false) {
    fail('home panel should say Checked just now');
} else {
    ok('home panel checked just now');
}
if (stripos($homeHtml, 'All systems operational') === false) {
    fail('home panel missing healthy state label');
} else {
    ok('home panel healthy label');
}
if (substr_count(strtolower($homeHtml), 'all systems operational') !== 1) {
    fail('healthy panel should not duplicate status wording');
} else {
    ok('healthy panel no duplicate wording');
}
if (stripos($homeHtml, 'Subscribe to updates') === false || stripos($homeHtml, 'button blue') === false) {
    fail('home panel missing subscribe button');
} else {
    ok('home panel subscribe button');
}
if (stripos($homeHtml, 'Web Application') === false || stripos($homeHtml, '<details') !== false) {
    fail('components should be listed openly without collapse');
} else {
    ok('components listed openly');
}

$homeMinor = StatuspageClient::buildHomePayload($sumMinor, 'https://example.statuspage.io');
$homeMinorHtml = HomePanelRenderer::render($homeMinor, 'View full status page', 'Subscribe to updates', true);
if (stripos($homeMinorHtml, 'Intermittent API latency') === false) {
    fail('home panel should include incident name');
} else {
    ok('home panel incident name');
}
if (stripos($homeMinorHtml, 'elevated latency') === false) {
    fail('home panel should include latest incident update');
} else {
    ok('home panel incident update');
}
if (empty($homeMinor['incidents'][0]['update'])) {
    fail('home payload missing incident update field');
} else {
    ok('home payload incident update');
}

// Strip iframe + inject
$landing = '<!DOCTYPE html><html><body><div id="container"><div id="landing_page">'
    . '<div class="main-content"><div class="thread-body">'
    . '<script src="https://rkx2qzzmzrlv.statuspage.io/embed/script.js"></script>'
    . '<iframe src="https://example.statuspage.io/" name="statuspage"></iframe>'
    . '</div></div></div></div></body></html>';
$strippedInjected = HomePanelRenderer::injectIntoLandingHtml($landing, $homeHtml);
if (stripos($strippedInjected, 'statuspage.io/embed') !== false || stripos($strippedInjected, '<iframe') !== false) {
    fail('Statuspage embeds should be stripped from landing HTML');
} else {
    ok('landing embeds stripped');
}
if (stripos($strippedInjected, 'id="ost-sp-home"') === false) {
    fail('home panel not injected into landing');
} else {
    ok('home panel injected');
}

$htmlMinor = BannerRenderer::render($payloadMinor, 'View status page');
if ($htmlMinor === '' || stripos($htmlMinor, 'statuspage-banner') === false) {
    fail('renderer produced empty/invalid HTML for minor');
} else {
    ok('renderer minor HTML');
}
if (stripos($htmlMinor, 'noopener noreferrer') === false) {
    fail('link missing rel=noopener noreferrer');
} else {
    ok('link rel noopener');
}

$xssPayload = array(
    'severity' => 'major',
    'title' => '<script>alert(1)</script>',
    'description' => 'x',
    'page_url' => 'https://example.statuspage.io',
    'dismiss_key' => 'incident:x',
);
$xssHtml = BannerRenderer::render($xssPayload, 'View status page');
if (strpos($xssHtml, '<script>alert(1)</script>') !== false) {
    fail('title not escaped');
} else {
    ok('title XSS-escaped');
}

$doc = '<!DOCTYPE html><html><body><div id="container"><div id="header"></div><p>hello</p></div></body></html>';
$injected = BannerRenderer::injectIntoHtml($doc, $htmlMinor);
if (stripos($injected, 'id="statuspage-banner"') === false) {
    fail('injectIntoHtml did not insert banner');
} else {
    ok('injectIntoHtml inserts banner');
}

$brandPatterns = array(
    '/synthetix/i',
    '/co-authored-by/i',
    '/cursor\.com/i',
    '/cursor\.sh/i',
    '/\bCursor Agent\b/',
    '/\bPowered by Cursor\b/i',
);
$scanRoots = array(
    $root . '/plugin.php',
    $root . '/config.php',
    $root . '/osticket-statuspage-banner.php',
    $root . '/include/StatuspageClient.php',
    $root . '/include/BannerRenderer.php',
    $root . '/include/HomePanelRenderer.php',
    $root . '/README.md',
    $root . '/LICENSE',
);
foreach ($scanRoots as $path) {
    if (!is_file($path)) {
        continue;
    }
    $contents = file_get_contents($path);
    foreach ($brandPatterns as $pattern) {
        if (preg_match($pattern, $contents)) {
            fail(basename($path) . ' matched forbidden pattern ' . $pattern);
        }
    }
}
ok('no forbidden brand/tooling strings in package files');

$readme = file_get_contents($root . '/README.md');
if ($readme === false) {
    fail('README.md missing');
} else {
    foreach (array('## Install', '## Configuration', '## Behaviour', 'summary.json', 'curl', 'Native Statuspage home', 'Subscribe', 'Why osTicket Statuspage') as $needle) {
        if (stripos($readme, $needle) === false) {
            fail('README missing section/content: ' . $needle);
        } else {
            ok('README has ' . $needle);
        }
    }
    if (stripos($readme, 'Metadata (version 1.2.3)') === false) {
        fail('README layout version expected 1.2.3');
    } else {
        ok('README layout version 1.2.3');
    }
    if (stripos($readme, '## Changelog') === false || stripos($readme, '### 1.2.3') === false) {
        fail('README missing Changelog 1.2.3');
    } else {
        ok('README Changelog 1.2.3');
    }
}

if (!is_file($root . '/LICENSE')) {
    fail('LICENSE missing');
} else {
    ok('LICENSE present');
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "\nAll smoke checks passed.\n");
exit(0);
