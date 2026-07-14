# Partial Capture for Fluent Forms

A WordPress add-on for **Fluent Forms Conversational Forms** (the Typeform-style
`?fluent-form={id}` renderer). It captures qualified partial leads, sends them to any
endpoint with conditional webhooks, and adds `$` / `%` formatting to number questions.

Works with the **free** Fluent Forms plugin — Fluent Forms Pro is not required.

## Features

### 1. Partial lead capture

Drop a **Partial Store** element into the form editor to mark where a session starts
counting as a lead. Everything before it is throwaway and never leaves the browser.

Each Partial Store has a **settle timer**. When a visitor passes the checkpoint a
countdown starts; every further answer restarts it. The partial is captured and its
webhooks fire once the countdown elapses (they paused) **or** the moment they leave the
page — whichever comes first, once per checkpoint. Reaching a deeper checkpoint arms a
fresh countdown; fully submitting cancels it (that's a conversion, linked to the entry).

Captured partials appear in a **Partial Leads** tab on the form, with status
(in‑progress / abandoned / converted), stage reached, time on page, answers, per‑feed
webhook logs with resend, and CSV export.

### 2. Conditional webhooks

Feeds live in Fluent Forms' native **Settings → Integrations** screen, built from the
same field‑mapping and conditional‑logic UI as a normal integration. Per feed you get:

- **Trigger** — partial (pause / leave) or the long‑inactivity abandonment fallback
- **URL, method, format** (JSON or form‑encoded) and custom headers
- **Payload mapping** — any key → smart code
- **Only send if these fields are FILLED / EMPTY** — the exists / not‑exists gate that
  Fluent Forms' own conditional logic can't express
- Native conditional logic, once‑per‑session, enable/disable

Smart codes available in the payload:

| Code | Value |
|---|---|
| `{inputs.field_name}` | the visitor's answers so far (raw numbers, e.g. `450000`) |
| `{bfcf.checkpoint}` `{bfcf.status}` `{bfcf.step}` `{bfcf.seconds}` `{bfcf.partial_id}` `{bfcf.source_url}` `{bfcf.referrer}` | partial metadata |
| `{bfcf.utm_source}` `{bfcf.utm_medium}` `{bfcf.gclid}` … | UTM / click IDs from the landing URL |

Standard Fluent Forms codes (`{ip}`, `{date.Y-m-d}`, `{browser.name}`) work too.

### 3. Number formatting

Three checkboxes on any Number field (conversational forms only):

- Show `$` before the number
- Show `%` after the number
- Auto‑format with commas (`1,234,567`)

The visitor sees `$450,000`; the stored value, the entry, and every webhook receive the
raw `450000`, so Fluent Forms' validation and calculations keep working.

## Requirements

- WordPress with **Fluent Forms** ≥ 5.2 (verified against 5.2 and 6.2)
- PHP 7.4+

## Installation

1. Download this repository as a zip, or clone it into `wp-content/plugins/`.
2. Activate **Partial Capture for Fluent Forms** in the WordPress Plugins screen.
3. Open a conversational form → drag in a **Partial Store** element, tick number
   formatting on any Number field, and add webhook feeds under
   **Settings → Integrations**.

## License

GPLv2 or later.
