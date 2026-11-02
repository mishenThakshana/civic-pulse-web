<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Integration API — CivicPulse Developers</title>
  <meta name="description" content="Connect your department's systems to CivicPulse: read assigned citizen reports and post status updates." />
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<div class="docs-root">

  <header class="panel-header">
    <div class="panel-header-left">
      <a href="{{ route('developers') }}" class="panel-logo">
        <div class="panel-logo-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="2 14 8 14 11 20 14 4 17 14 22 14"/>
          </svg>
        </div>
        <span class="panel-logo-name">CivicPulse</span>
      </a>
      <div class="panel-header-sep"></div>
      <span style="font-size:13px; color:var(--text-muted);">Developers</span>
    </div>
    <div class="panel-header-right">
      <a href="{{ $specUrl }}" class="btn-ghost btn-ghost--sm" download>OpenAPI spec</a>
    </div>
  </header>

  <div class="docs-body">

    <nav class="docs-toc" aria-label="Documentation sections">
      <div class="docs-toc-group">
        <div class="docs-toc-label">Getting started</div>
        <a href="#introduction">Introduction</a>
        <a href="#quick-start">Quick start</a>
        <a href="#authentication">Authentication</a>
      </div>
      <div class="docs-toc-group">
        <div class="docs-toc-label">Basics</div>
        <a href="#conventions">Requests &amp; responses</a>
        <a href="#errors">Errors</a>
        <a href="#rate-limits">Rate limits</a>
        <a href="#statuses">Report statuses</a>
      </div>
      <div class="docs-toc-group">
        <div class="docs-toc-label">Endpoints</div>
        <a href="#get-department">GET /department</a>
        <a href="#list-reports">GET /reports</a>
        <a href="#get-report">GET /reports/{id}</a>
        <a href="#create-update">POST /reports/{id}/updates</a>
        <a href="#list-categories">GET /categories</a>
      </div>
      <div class="docs-toc-group">
        <div class="docs-toc-label">Reference</div>
        <a href="#report-object">The report object</a>
      </div>
      <div class="docs-toc-group">
        <div class="docs-toc-label">Guides</div>
        <a href="#guide-sync">Keeping your system in sync</a>
        <a href="#guide-updates">Posting updates well</a>
        <a href="#security">Key security</a>
        <a href="#versioning">Versioning &amp; support</a>
      </div>
    </nav>

    <main class="docs-main">

      {{-- Introduction --}}
      <section class="docs-section" id="introduction">
        <h1>CivicPulse Integration API</h1>
        <p class="docs-lead">Connect your department's own systems — work-order, asset management, ticketing or reporting tools — to the citizen reports CivicPulse routes to you.</p>
        <p>When a citizen reports a problem in the CivicPulse app, council staff assign it to the department responsible. With this API your systems can:</p>
        <ul>
          <li><strong>Pull</strong> every report assigned to your department, with its location, photo, category and how many citizens it affects.</li>
          <li><strong>Sync</strong> incrementally, fetching only what changed since you last checked.</li>
          <li><strong>Push</strong> status updates back (for example "work started", "resolved") with a note and photo. The citizen gets a notification straight away, and the update appears on the report's public timeline.</li>
        </ul>
        <p>Your key only ever sees reports assigned to your own department.</p>
        <h3>Base URL</h3>
        <pre>{{ $baseUrl }}</pre>
        <p>A machine-readable <a href="{{ $specUrl }}">OpenAPI 3 specification</a> is also available. Import it into Postman, Insomnia or Swagger UI, or use it to generate a client library.</p>
      </section>

      {{-- Quick start --}}
      <section class="docs-section" id="quick-start">
        <h2>Quick start</h2>
        <ol>
          <li><strong>Request a key.</strong> Ask a CivicPulse administrator to issue an API key for your department. Say whether you need <em>read only</em> access (pulling reports) or <em>read &amp; update</em> access (also posting status updates). The key starts with <code>cpk_</code>.</li>
          <li><strong>Check the connection.</strong>
<pre>curl {{ $baseUrl }}/department \
  -H "Authorization: Bearer cpk_your_key_here" \
  -H "Accept: application/json"</pre>
          </li>
          <li><strong>Fetch your open reports.</strong>
<pre>curl "{{ $baseUrl }}/reports?status=open&amp;sort=-votes_count" \
  -H "Authorization: Bearer cpk_your_key_here" \
  -H "Accept: application/json"</pre>
          </li>
        </ol>
      </section>

      {{-- Authentication --}}
      <section class="docs-section" id="authentication">
        <h2>Authentication</h2>
        <p>Send your API key as a bearer token in the <code>Authorization</code> header of every request:</p>
        <pre>Authorization: Bearer cpk_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0</pre>
        <p>Each key belongs to one department and has one of two access levels:</p>
        <div class="docs-table-wrap">
          <table class="docs-table">
            <thead><tr><th>Access</th><th>Can do</th></tr></thead>
            <tbody>
              <tr><td>Read only</td><td>All <code>GET</code> endpoints.</td></tr>
              <tr><td>Read &amp; update</td><td>Everything above, plus <code>POST /reports/{id}/updates</code>.</td></tr>
            </tbody>
          </table>
        </div>
        <p>A missing, mistyped or revoked key gets <code>401 Unauthorized</code>. A read-only key calling an update endpoint gets <code>403 Forbidden</code>.</p>
        <div class="docs-callout">
          <span>Keys are secrets. Use them only from your servers, never from a browser, mobile app or public repository. See <a href="#security">Key security</a>.</span>
        </div>
      </section>

      {{-- Conventions --}}
      <section class="docs-section" id="conventions">
        <h2>Requests &amp; responses</h2>
        <ul>
          <li>All requests use HTTPS. Send <code>Accept: application/json</code>.</li>
          <li>Request bodies are JSON (<code>Content-Type: application/json</code>). The one exception is uploading a photo, which uses <code>multipart/form-data</code>.</li>
          <li>Successful responses wrap the result in a top-level <code>data</code> key.</li>
          <li>Timestamps are ISO 8601 in UTC, e.g. <code>2026-11-02T09:15:00.000000Z</code>.</li>
          <li>IDs are integers. A report's <code>id</code> is the same reference number citizens and council staff see (shown as <code>#1042</code>).</li>
        </ul>
        <h3>Pagination</h3>
        <p>List endpoints return pages. Use <code>page</code> and <code>per_page</code> (1–100, default 25), or simply follow <code>links.next</code> until it is <code>null</code>.</p>
<pre>{
  "data": [ … ],
  "links": { "first": "…?page=1", "last": "…?page=4", "prev": null, "next": "…?page=2" },
  "meta":  { "current_page": 1, "last_page": 4, "per_page": 25, "total": 87, "from": 1, "to": 25 }
}</pre>
      </section>

      {{-- Errors --}}
      <section class="docs-section" id="errors">
        <h2>Errors</h2>
        <p>Errors use standard HTTP status codes and always include a human-readable <code>message</code>.</p>
        <div class="docs-table-wrap">
          <table class="docs-table">
            <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
            <tbody>
              <tr><td><code>401</code></td><td>The API key is missing, malformed or revoked.</td></tr>
              <tr><td><code>403</code></td><td>The key is read-only and the endpoint changes data.</td></tr>
              <tr><td><code>404</code></td><td>The report doesn't exist <em>or is not assigned to your department</em>. Both cases look the same on purpose.</td></tr>
              <tr><td><code>422</code></td><td>Validation failed. An <code>errors</code> object lists the problems per field.</td></tr>
              <tr><td><code>429</code></td><td>Rate limit exceeded. See <a href="#rate-limits">Rate limits</a>.</td></tr>
              <tr><td><code>5xx</code></td><td>Something went wrong on our side. Retry with backoff; if it persists, contact us.</td></tr>
            </tbody>
          </table>
        </div>
<pre>HTTP/1.1 422 Unprocessable Content

{
  "message": "The selected status is invalid.",
  "errors": { "status": ["The selected status is invalid."] }
}</pre>
      </section>

      {{-- Rate limits --}}
      <section class="docs-section" id="rate-limits">
        <h2>Rate limits</h2>
        <p>Each key can make <strong>120 requests per minute</strong>. Every response includes <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code> headers. If you go over, you get <code>429 Too Many Requests</code> with a <code>Retry-After</code> header giving the seconds to wait.</p>
        <p>Polling every few minutes with <code>updated_since</code> (see <a href="#guide-sync">Keeping your system in sync</a>) stays far below the limit.</p>
      </section>

      {{-- Statuses --}}
      <section class="docs-section" id="statuses">
        <h2>Report statuses</h2>
        <div class="docs-table-wrap">
          <table class="docs-table">
            <thead><tr><th>Value</th><th>Label</th><th>Meaning</th></tr></thead>
            <tbody>
              @foreach ($statuses as $status)
                <tr>
                  <td><code>{{ $status->value }}</code></td>
                  <td>{{ $status->label() }}</td>
                  <td>
                    @switch($status->value)
                      @case('open') Received and assigned, but work has not started. @break
                      @case('in_progress') Your team is working on it. @break
                      @case('resolved') The problem has been fixed. @break
                    @endswitch
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <p>You can move a report between any statuses, including reopening a resolved one.</p>
      </section>

      {{-- Endpoints --}}
      <section class="docs-section">
        <h2>Endpoints</h2>

        <div class="docs-endpoint" id="get-department">
          <div class="docs-endpoint-line"><span class="docs-method docs-method--get">GET</span> /department</div>
          <p>Returns the department your key belongs to, its current workload and what the key may do. Use it to check that a key works.</p>
<pre>{
  "data": {
    "id": 3,
    "name": "Roads and Highways",
    "contact_email": "roads@council.lk",
    "report_counts": { "open": 12, "in_progress": 5, "resolved": 140 },
    "api_key": { "name": "Works management system", "prefix": "cpk_a1B2c3D4", "can_update_reports": true }
  }
}</pre>
        </div>

        <div class="docs-endpoint" id="list-reports">
          <div class="docs-endpoint-line"><span class="docs-method docs-method--get">GET</span> /reports</div>
          <p>Lists reports assigned to your department, one page at a time. List results leave out <code>status_history</code>; fetch a single report to get it.</p>
          <h3>Query parameters</h3>
          <div class="docs-table-wrap">
            <table class="docs-table">
              <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td><code>status</code></td><td>Only reports with this status: <code>open</code>, <code>in_progress</code> or <code>resolved</code>.</td></tr>
                <tr><td><code>category_id</code></td><td>Only reports in this category (see <a href="#list-categories">GET /categories</a>).</td></tr>
                <tr><td><code>updated_since</code></td><td>Only reports changed at or after this ISO 8601 timestamp, e.g. <code>2026-11-02T09:00:00Z</code>. Any timezone offset is accepted.</td></tr>
                <tr><td><code>sort</code></td><td><code>-created_at</code> (default, newest first), <code>created_at</code>, <code>-updated_at</code>, <code>updated_at</code>, or <code>-votes_count</code> (most affected citizens first).</td></tr>
                <tr><td><code>per_page</code></td><td>Results per page, 1–100. Default 25.</td></tr>
                <tr><td><code>page</code></td><td>Page number, starting at 1.</td></tr>
              </tbody>
            </table>
          </div>
<pre>curl "{{ $baseUrl }}/reports?status=open&amp;sort=-votes_count&amp;per_page=50" \
  -H "Authorization: Bearer $CIVICPULSE_KEY" -H "Accept: application/json"</pre>
        </div>

        <div class="docs-endpoint" id="get-report">
          <div class="docs-endpoint-line"><span class="docs-method docs-method--get">GET</span> /reports/{id}</div>
          <p>Returns one report with its full <code>status_history</code>, newest first. If the report has since been reassigned to another department, you get <code>404</code>. Treat that as "no longer ours" and close it on your side.</p>
<pre>curl {{ $baseUrl }}/reports/1042 \
  -H "Authorization: Bearer $CIVICPULSE_KEY" -H "Accept: application/json"</pre>
          <p>See <a href="#report-object">The report object</a> for the response.</p>
        </div>

        <div class="docs-endpoint" id="create-update">
          <div class="docs-endpoint-line"><span class="docs-method docs-method--post">POST</span> /reports/{id}/updates</div>
          <p>Posts a status update. CivicPulse records it on the report's timeline, labelled with your department's name, and <strong>sends the citizen a push notification</strong>. Requires a <em>read &amp; update</em> key.</p>
          <h3>Body</h3>
          <div class="docs-table-wrap">
            <table class="docs-table">
              <thead><tr><th>Field</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td><code>status</code> <em>required</em></td><td><code>open</code>, <code>in_progress</code> or <code>resolved</code>. Send the current status to add a note without changing it.</td></tr>
                <tr><td><code>note</code></td><td>Up to 1,000 characters. <strong>Shown publicly</strong> to the citizen and anyone following the report.</td></tr>
                <tr><td><code>photo</code></td><td>Optional progress photo (image, max 6 MB). Send the request as <code>multipart/form-data</code> to include it.</td></tr>
              </tbody>
            </table>
          </div>
<pre>curl -X POST {{ $baseUrl }}/reports/1042/updates \
  -H "Authorization: Bearer $CIVICPULSE_KEY" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"status": "in_progress", "note": "A repair crew is scheduled for Thursday morning."}'

# With a photo
curl -X POST {{ $baseUrl }}/reports/1042/updates \
  -H "Authorization: Bearer $CIVICPULSE_KEY" -H "Accept: application/json" \
  -F status=resolved -F "note=Pothole filled and resurfaced." -F photo=@after.jpg</pre>
          <p>Responds <code>201 Created</code> with the new timeline entry and the updated report:</p>
<pre>{
  "data": {
    "update": {
      "id": 5531,
      "from_status": "open",
      "to_status": "in_progress",
      "note": "A repair crew is scheduled for Thursday morning.",
      "photo_url": null,
      "changed_by": "Roads and Highways (via API)",
      "created_at": "2026-11-02T09:15:00.000000Z"
    },
    "report": { "id": 1042, "status": "in_progress", … }
  }
}</pre>
        </div>

        <div class="docs-endpoint" id="list-categories">
          <div class="docs-endpoint-line"><span class="docs-method docs-method--get">GET</span> /categories</div>
          <p>Lists all report categories, so you can map them to your own codes or filter <code>/reports</code> by <code>category_id</code>.</p>
<pre>{ "data": [ { "id": 1, "name": "Potholes", "slug": "potholes" }, { "id": 2, "name": "Street Lights", "slug": "street-lights" } ] }</pre>
        </div>
      </section>

      {{-- Report object --}}
      <section class="docs-section" id="report-object">
        <h2>The report object</h2>
<pre>{
  "id": 1042,
  "title": "Large pothole near the junction, dangerous for motorcycles.",
  "description": "Large pothole near the junction, dangerous for motorcycles. Getting bigger after the rain.",
  "status": "open",
  "status_label": "Open",
  "category": { "id": 1, "name": "Potholes", "slug": "potholes" },
  "location": {
    "latitude": 6.84120,
    "longitude": 79.87310,
    "address": "Galle Road, near the Moratuwa junction",
    "map_url": "https://www.google.com/maps/search/?api=1&amp;query=6.8412,79.8731"
  },
  "reporter": { "name": "Nimal Perera", "contact_phone": "0771234567" },
  "photo_url": "https://…/storage/report-photos/7c1e….jpg",
  "votes_count": 23,
  "status_history": [ { "id": 5530, "from_status": null, "to_status": "open", "note": "Report received.", "photo_url": null, "changed_by": "Nimal Perera", "created_at": "…" } ],
  "created_at": "2026-10-30T07:42:11.000000Z",
  "updated_at": "2026-11-01T12:03:54.000000Z"
}</pre>
        <div class="docs-table-wrap">
          <table class="docs-table">
            <thead><tr><th>Field</th><th>Description</th></tr></thead>
            <tbody>
              <tr><td><code>id</code></td><td>The report's reference number.</td></tr>
              <tr><td><code>title</code>, <code>description</code></td><td>The citizen's description of the problem. <code>title</code> is a shortened version.</td></tr>
              <tr><td><code>status</code>, <code>status_label</code></td><td>Machine value and display label. See <a href="#statuses">Report statuses</a>.</td></tr>
              <tr><td><code>category</code></td><td>The category, or <code>null</code> if uncategorised.</td></tr>
              <tr><td><code>location</code></td><td>GPS coordinates (WGS 84), the address the citizen entered (may be <code>null</code>), and a ready-made map link.</td></tr>
              <tr><td><code>reporter</code></td><td>The reporter's name and contact phone, so field crews can reach them if needed. Use it only for handling this report.</td></tr>
              <tr><td><code>photo_url</code></td><td>The citizen's photo of the problem.</td></tr>
              <tr><td><code>votes_count</code></td><td>How many citizens say the problem affects them. A good priority signal.</td></tr>
              <tr><td><code>status_history</code></td><td>Timeline entries, newest first. Only in single-report responses.</td></tr>
              <tr><td><code>created_at</code>, <code>updated_at</code></td><td>When the report was submitted and last changed.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      {{-- Sync guide --}}
      <section class="docs-section" id="guide-sync">
        <h2>Keeping your system in sync</h2>
        <p>The most reliable integration is a scheduled job, every 5–15 minutes, that asks only for what has changed:</p>
        <ol>
          <li>The first time, fetch everything: <code>GET /reports?sort=updated_at&amp;per_page=100</code>, following <code>links.next</code> to the end.</li>
          <li>Store the largest <code>updated_at</code> you saw.</li>
          <li>Next time, request <code>GET /reports?updated_since=&lt;that timestamp&gt;&amp;sort=updated_at&amp;per_page=100</code>.</li>
          <li>Upsert each report into your system <strong>by <code>id</code></strong>. <code>updated_since</code> includes the boundary, so you may see the last report again. That's expected, so you never miss one.</li>
        </ol>
        <p>A report's <code>updated_at</code> changes whenever its status changes, a status update is posted, or it is assigned to your department.</p>
<pre># Python example (requests)
import os, requests

BASE = "{{ $baseUrl }}"
HEADERS = {"Authorization": f"Bearer {os.environ['CIVICPULSE_KEY']}", "Accept": "application/json"}

def sync(since=None):
    params = {"sort": "updated_at", "per_page": 100}
    if since:
        params["updated_since"] = since
    url, latest = f"{BASE}/reports", since
    while url:
        page = requests.get(url, headers=HEADERS, params=params, timeout=30)
        page.raise_for_status()
        body = page.json()
        for report in body["data"]:
            upsert_work_order(report)          # your code
            latest = report["updated_at"]
        url, params = body["links"]["next"], None   # next link already carries the query
    return latest                               # save and pass in next time</pre>
        <div class="docs-callout">
          <span><strong>Reassigned reports:</strong> if council staff move a report to another department, it stops appearing in your results. To catch this, periodically re-check reports that are open on your side with <code>GET /reports/{id}</code>. A <code>404</code> means it is no longer yours.</span>
        </div>
      </section>

      {{-- Updates guide --}}
      <section class="docs-section" id="guide-updates">
        <h2>Posting updates well</h2>
        <ul>
          <li><strong>Write notes for citizens.</strong> They are public. Say what is happening and when ("Crew scheduled for Thursday"). Leave out internal job codes, staff names and other personal details.</li>
          <li><strong>Every update notifies the citizen.</strong> Post when something meaningful changes, not on every internal step.</li>
          <li><strong>Updates are not idempotent.</strong> Each successful <code>POST</code> adds a timeline entry and sends a notification. If a request times out, <code>GET</code> the report and check <code>status_history</code> before retrying, so citizens don't get duplicates.</li>
          <li><strong>Attach an "after" photo when resolving.</strong> Citizens trust the fix more when they can see it.</li>
        </ul>
      </section>

      {{-- Security --}}
      <section class="docs-section" id="security">
        <h2>Key security</h2>
        <ul>
          <li>Keep keys in a secret store or environment variable, never in source code.</li>
          <li>Use a separate key for each system, named after it, so one can be revoked without breaking the others.</li>
          <li>Ask for <em>read only</em> access unless the system really needs to post updates.</li>
          <li>CivicPulse stores only a hash of your key and can't show it again. If a key is lost or might have leaked, ask an administrator to revoke it and issue a new one. Revocation takes effect immediately.</li>
          <li>Administrators can see when each key was last used. Every update posted through the API is recorded in CivicPulse's audit log.</li>
        </ul>
      </section>

      {{-- Versioning --}}
      <section class="docs-section" id="versioning">
        <h2>Versioning &amp; support</h2>
        <p>This is version 1 of the API (<code>/api/integrations/v1</code>). Within v1 we may add new endpoints, optional parameters and response fields, so ignore fields you don't recognise. Anything that would break existing integrations will ship as a new version, announced in advance, with v1 kept running during the changeover.</p>
        <p>To request a key, report a problem or ask for a feature, contact the CivicPulse administrators at your council.</p>
      </section>

    </main>
  </div>
</div>

</body>
</html>
