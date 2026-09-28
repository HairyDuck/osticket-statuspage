# osTicket Statuspage Banner

**osTicket Statuspage plugin** that shows Atlassian Statuspage status on the client helpdesk portal, natively.

No iframe. Server-side fetch of the public Statuspage summary API. Full-width status panel on the landing page, optional slim banner when systems are degraded. No core file patches. MIT licensed.

[![Licence](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
[![osTicket](https://img.shields.io/badge/osTicket-1.17%2B%20%2F%201.18%2B-green.svg)](https://osticket.com/)
[![PHP](https://img.shields.io/badge/PHP-8-777BB4.svg)](https://www.php.net/)

Repository: <https://github.com/HairyDuck/osticket-statuspage-banner>

---

## Why osTicket Statuspage Banner?

Helpdesks often paste a Statuspage iframe into the osTicket landing page. It looks cramped, fights the portal layout, and loads third-party scripts.

This plugin instead:

| Need | Typical iframe | This plugin |
|------|----------------|-------------|
| Status on client home | Squashed Statuspage UI | Native panel matching osTicket chrome |
| Healthy state | Always shows full remote page | Clear “All systems operational” + components |
| Degraded state | Same iframe | Panel + optional slim banner on other pages |
| CSP / remote scripts | Embed script + iframe | Server-side `summary.json` only |
| Core upgrades | Often involves template edits | Plugin-only; no core patches |
| Subscribe | Remote Statuspage UI | Button linking to your Statuspage |

Ideal for: **osTicket + Statuspage**, customer portal status, incident communications, and replacing Statuspage iframes on helpdesk home pages.

---

## Features

* **Native home panel** – overall state, incidents, maintenance, component list, subscribe button
* **Strips Statuspage embeds** – removes `embed/script.js` and Statuspage iframes from landing HTML
* **Optional degraded banner** – slim top bar on other client pages when status is not healthy
* **Subscribe to updates** – button opens your public Statuspage (subscribe UI lives there)
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

   `include/plugins/osticket-statuspage-banner/`

   (A shorter folder name such as `osticket-statuspage/` is fine.)

2. Admin → Manage → Plugins → install **Statuspage Banner**.
3. Add an instance, enable it, open **Config**.
4. Set **Statuspage base URL** (HTTPS), e.g. `https://example.statuspage.io`.
5. Leave **Native status panel on client home** enabled; leave **Cache TTL** at `0` unless you need caching.
6. Save. Soft-refresh the client portal home page.

You can leave a short welcome heading in **Manage → Pages → Landing**. Remove any Statuspage iframe from that page when convenient; the plugin strips embeds on render either way.

Offline smoke check (no osTicket required):

```bash
php tests/smoke.php
```

---

## Configuration

| Setting | Default | Notes |
|---------|---------|--------|
| Enable plugin | On | When off, nothing is injected |
| Statuspage base URL | (empty) | HTTPS only; trailing slash optional |
| Native status panel on client home | On | Always shown on landing when data loads (including healthy) |
| Status page link label | `View full status page` | Text link to the public Statuspage |
| Show subscribe button on home panel | On | Opens Statuspage (subscribe UI lives there) |
| Subscribe button label | `Subscribe to updates` | Uses stock osTicket blue button class |
| Degraded banner on | Other client pages only | Slim top bar when degraded |
| Cache TTL (seconds) | `0` | 0 = no cache (recommended). Optional 1–3600 |
| Also show degraded banner for agents (SCP) | Off | Home panel is client-only |

---

## Behaviour

### Client home

1. Fetches `{base}/api/v2/summary.json` (no cache by default).
2. Removes Statuspage embed scripts and iframes from the landing HTML.
3. Injects a native panel: overall state, headline when relevant, incidents/maintenance, components, subscribe button, and a link out.
4. Shows **Checked just now** for this request (not Statuspage’s last change timestamp).
5. Fetch failure: landing still loads; embeds are still stripped; panel is omitted.

### Other pages (optional banner)

1. Banner is **hidden** when `status.indicator` is `none`, there are no unresolved incidents, and there is no maintenance with status `in_progress` or `verifying`.
2. Upcoming `scheduled` maintenance alone does not show a banner (home panel may still list upcoming within 72 hours).
3. Banner text prefers the current incident/maintenance name; otherwise `status.description`.
4. Link opens Statuspage in a new tab (`rel="noopener noreferrer"`).
5. Dismissible via `localStorage` for the current incident/maintenance key.

### Integration notes

osTicket 1.17/1.18 has no stable Signal for client chrome. This plugin uses output buffering. No core templates are modified. Data is fetched **server-side**.

### Iframe mode

Not used. Prefer this plugin over embedding Statuspage in an iframe.

---

## Statuspage API (curl)

```bash
curl -sS "https://example.statuspage.io/api/v2/summary.json"
```

Useful fields: `status.indicator`, `status.description`, `incidents[]`, `scheduled_maintenances[]`, `components[]`, `page.url`.

No API key is required for the public summary endpoint.

---

## Production rollout checklist

1. Deploy the plugin folder; confirm it appears under Manage → Plugins.
2. Enable with a real Statuspage HTTPS base URL; leave cache at `0`.
3. Soft-refresh the client home: expect native panel, no iframe.
4. Optionally open a non-home client page while Statuspage is healthy: no slim banner.
5. When you next have a real incident (or a staging Statuspage), confirm the panel and optional banner update.

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
osticket-statuspage-banner/
  plugin.php                     Metadata (version 1.2.2)
  osticket-statuspage-banner.php Bootstrap + output buffer inject
  config.php                     Admin settings
  include/
    StatuspageClient.php         HTTPS fetch + decision logic
    BannerRenderer.php           Degraded slim banner
    HomePanelRenderer.php        Native home panel
  tests/smoke.php                Offline syntax + behaviour checks
  LICENSE
  README.md
```

Offline smoke: `php tests/smoke.php`

---

## Keywords

osTicket Statuspage, osTicket status page plugin, Atlassian Statuspage osTicket, osTicket landing page status, osTicket status banner, helpdesk status page, Statuspage iframe alternative, osTicket plugin MIT, client portal status.

---

## Licence

MIT – see [LICENSE](LICENSE).
