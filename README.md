# osTicket Statuspage

**Native Statuspage home page for osTicket** – Atlassian Statuspage on your client helpdesk portal, without an iframe.

Turns the osTicket landing page into a proper status home: overall state, components, incidents, maintenance, and a subscribe button. Optional slim banner on other client pages when something is wrong. Server-side `summary.json` only. No core file patches. MIT licensed.

[![Licence](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
[![osTicket](https://img.shields.io/badge/osTicket-1.17%2B%20%2F%201.18%2B-green.svg)](https://osticket.com/)
[![PHP](https://img.shields.io/badge/PHP-8-777BB4.svg)](https://www.php.net/)

Repository: <https://github.com/HairyDuck/osticket-statuspage>

---

## Why osTicket Statuspage?

Helpdesks often paste a Statuspage iframe into the osTicket landing page. It looks cramped, fights the portal layout, and loads third-party scripts. Customers land on support looking for answers, not a squeezed remote page.

**osTicket Statuspage** makes the **client home page** the status experience:

| Need | Typical iframe | This plugin |
|------|----------------|-------------|
| Status on client home | Squashed Statuspage UI | Native home panel matching osTicket chrome |
| Healthy state | Always shows full remote page | Clear “All systems operational” + full component list |
| Degraded state | Same iframe | Home panel + optional slim banner elsewhere |
| CSP / remote scripts | Embed script + iframe | Server-side `summary.json` only |
| Core upgrades | Often involves template edits | Plugin-only; no core patches |
| Subscribe | Remote Statuspage UI | Button linking to your Statuspage |

Ideal for: **osTicket Statuspage integration**, customer portal status home, incident communications, and replacing Statuspage iframes on helpdesk landing pages.

---

## Features

* **Statuspage home on osTicket** – overall state, incidents, maintenance, component list, subscribe button
* **Strips Statuspage embeds** – removes `embed/script.js` and Statuspage iframes from landing HTML
* **Optional degraded banner** – slim top bar on other client pages when status is not healthy
* **Subscribe to updates** – button opens your public Statuspage
* **No cache by default** – fresh fetch on every page view (`cache_ttl=0`)
* **Fail closed** – fetch errors never break the portal
* **Production-safe** – drop-in under `include/plugins/`; stock templates untouched

---

## Requirements

* osTicket **1.17+** or **1.18+** (PHP 8 recommended)
* A public Atlassian Statuspage URL (HTTPS), e.g. `https://example.statuspage.io`
* Plugin folder under `include/plugins/`

No Statuspage API key is required for the public summary endpoint.

---

## Install

1. Copy this folder to your osTicket install as:

   `include/plugins/osticket-statuspage/`

2. Admin → Manage → Plugins → install **Statuspage Home**.
3. Add an instance, enable it, open **Config**.
4. Set **Statuspage base URL** (HTTPS), e.g. `https://example.statuspage.io`.
5. Leave **Native status panel on client home** enabled; leave **Cache TTL** at `0` unless you need caching.
6. Save. Soft-refresh the client portal home page.

You can leave a short welcome heading in **Manage → Pages → Landing**. Remove any Statuspage iframe from that page when convenient; the plugin strips embeds on render either way.

Offline smoke check (no osTicket required):

```bash
php tests/smoke.php
```

Upgrade note: older builds used the plugin id `opensource:osticket-statuspage-banner`. After upgrading files, re-install / re-add the instance if osTicket does not pick up **Statuspage Home** automatically.

---

## Configuration

| Setting | Default | Notes |
|---------|---------|--------|
| Enable plugin | On | When off, nothing is injected |
| Statuspage base URL | (empty) | HTTPS only; trailing slash optional |
| Native status panel on client home | On | The Statuspage home experience on the landing page |
| Status page link label | `View full status page` | Text link to the public Statuspage |
| Show subscribe button on home panel | On | Opens Statuspage (subscribe UI lives there) |
| Subscribe button label | `Subscribe to updates` | Uses stock osTicket blue button class |
| Degraded banner on | Other client pages only | Slim top bar when degraded |
| Cache TTL (seconds) | `0` | 0 = no cache (recommended). Optional 1–3600 |
| Also show degraded banner for agents (SCP) | Off | Home panel is client-only |

---

## Behaviour

### Client home (primary)

1. Fetches `{base}/api/v2/summary.json` (no cache by default).
2. Removes Statuspage embed scripts and iframes from the landing HTML.
3. Injects a native **Statuspage home** panel: overall state, headline when relevant, incidents/maintenance, components, subscribe button, and a link out.
4. Shows **Checked just now** for this request.
5. Fetch failure: landing still loads; embeds are still stripped; panel is omitted.

### Other pages (optional banner)

1. Banner is **hidden** when everything is healthy.
2. Shows when unresolved incidents, active/verifying maintenance, or a non-`none` indicator is present.
3. Dismissible via `localStorage` for the current incident/maintenance key.

### Integration notes

osTicket 1.17/1.18 has no stable Signal for client chrome. This plugin uses output buffering. No core templates are modified. Data is fetched **server-side**.

### Iframe mode

Not used. Prefer this plugin over embedding Statuspage in an iframe on the helpdesk home page.

---

## Statuspage API (curl)

```bash
curl -sS "https://example.statuspage.io/api/v2/summary.json"
```

Useful fields: `status.indicator`, `status.description`, `incidents[]`, `scheduled_maintenances[]`, `components[]`, `page.url`.

No API key is required for the public summary endpoint.

---

## Production rollout checklist

1. Deploy the plugin folder; confirm **Statuspage Home** appears under Manage → Plugins.
2. Enable with a real Statuspage HTTPS base URL; leave cache at `0`.
3. Soft-refresh the client home: expect a native Statuspage home panel, no iframe.
4. Optionally open a non-home client page while healthy: no slim banner.
5. When you next have a real incident (or a staging Statuspage), confirm the home panel and optional banner update.

---

## Features we will consider if requested

These are **not implemented yet**. Open an issue or pull request if you need them:

* Staff SCP home panel (banner-only is available today)
* Built-in email subscribe form without leaving the portal
* Multi-Statuspage / multi-brand instances on one helpdesk
* Dark-theme / custom CSS overrides in admin
* OpenAPI notes for the Statuspage summary fields used

Community contributions welcome under the MIT licence.

---

## Changelog

### 1.2.3

* Rebrand to **Statuspage Home**: native Statuspage home page for osTicket (banner is optional secondary)
* Repository and docs focused on the client home experience

### 1.2.2

* Default cache TTL is 0 (no cache); fetch Statuspage on every page view
* Home panel shows “Checked just now” from this request, not Statuspage’s last change time

### 1.2.1

* Always show the full component list (no collapse when healthy)

### 1.2.0

* Subscribe to updates button on the home panel
* Remove duplicate healthy status wording
* Style home hero like osTicket notice / warning / error bars
* Latest incident update body; quiet notice for upcoming maintenance within 72 hours

### 1.1.0

* Native homepage status panel
* Automatically strips Statuspage embed script/iframe from the landing page
* Degraded slim banner configurable for other client pages

### 1.0.0

* Initial release: Statuspage summary fetch, degraded client banner, optional SCP, dismissible session, offline smoke tests

---

## Layout

```
osticket-statuspage/
  plugin.php                     Metadata (version 1.2.3)
  osticket-statuspage-banner.php Bootstrap + output buffer inject
  config.php                     Admin settings
  include/
    StatuspageClient.php         HTTPS fetch + decision logic
    BannerRenderer.php           Optional degraded slim banner
    HomePanelRenderer.php        Native Statuspage home panel
  tests/smoke.php                Offline syntax + behaviour checks
  LICENSE
  README.md
```

Offline smoke: `php tests/smoke.php`

---

## Keywords

osTicket Statuspage, osTicket status page, Statuspage home page osTicket, Atlassian Statuspage osTicket plugin, osTicket landing page status, helpdesk status home, Statuspage iframe alternative, osTicket plugin MIT, client portal status page.

---

## Licence

MIT – see [LICENSE](LICENSE).
