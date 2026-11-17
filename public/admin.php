<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Central Integration Operations</title>
  <style>
    :root {
      color-scheme: light;
      --ink: #17232d;
      --muted: #5c6c78;
      --line: #d6e0e6;
      --paper: #fff;
      --wash: #f2f6f8;
      --accent: #176b87;
      --danger: #a52a2a
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      background: var(--wash);
      color: var(--ink);
      font: 15px/1.5 system-ui, sans-serif
    }

    main {
      max-width: 1100px;
      margin: 36px auto;
      padding: 0 20px
    }

    h1 {
      font-size: 1.8rem;
      margin: 0
    }

    .muted {
      color: var(--muted)
    }

    form,
    .panel {
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 18px;
      margin: 18px 0
    }

    label {
      display: block;
      font-weight: 650;
      margin-bottom: 8px
    }

    input {
      width: min(100%, 560px);
      padding: 11px;
      border: 1px solid var(--line);
      border-radius: 7px
    }

    button {
      padding: 9px 13px;
      border: 0;
      border-radius: 7px;
      background: var(--accent);
      color: white;
      font-weight: 650;
      cursor: pointer
    }

    button[disabled] {
      opacity: .55;
      cursor: wait
    }

    .row {
      display: flex;
      gap: 10px;
      align-items: end;
      flex-wrap: wrap
    }

    .scroll {
      overflow: auto
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 720px
    }

    th,
    td {
      text-align: left;
      padding: 10px;
      border-bottom: 1px solid var(--line);
      vertical-align: top
    }

    th {
      color: var(--muted);
      font-size: .86rem
    }

    .error {
      color: var(--danger);
      min-height: 1.5em
    }

    code {
      word-break: break-all
    }
  </style>
</head>

<body>
  <main>
    <h1>Central Integration Operations</h1>
    <p class="muted">Delivery queue status and unresolved dead letters. Use the Central operator token configured on the server.</p>
    <form id="login">
      <div class="row">
        <div><label for="token">Operator token</label><input id="token" type="password" autocomplete="current-password" required></div><button>Load status</button>
      </div>
      <p id="error" class="error" role="status"></p>
    </form>
    <section class="panel">
      <div class="row" style="justify-content:space-between;align-items:center">
        <h2>Subsystem health</h2>
        <button id="refresh-health" type="button">Refresh health</button>
      </div>
      <p class="muted">Reloads the latest health values recorded in Central.</p>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Subsystem</th>
              <th>Status</th>
              <th>Last seen</th>
            </tr>
          </thead>
          <tbody id="health">
            <tr>
              <td colspan="3">Load status to view health.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <section class="panel">
      <h2>Recent deliveries</h2>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Source</th>
              <th>Event</th>
              <th>Destination</th>
              <th>Status</th>
              <th>Attempts</th>
              <th>Next retry</th>
              <th>Last error</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody id="deliveries">
            <tr>
              <td colspan="8">Load status to view deliveries.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <section class="panel">
      <h2>Open dead letters</h2>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Event</th>
              <th>Destination</th>
              <th>Attempts</th>
              <th>Reason</th>
              <th>Failed</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="dead">
            <tr>
              <td colspan="6">Load status to view dead letters.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <section class="panel">
      <h2>Reconciliation runs</h2>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Source</th>
              <th>Record type</th>
              <th>Status</th>
              <th>Scanned</th>
              <th>Enqueued</th>
              <th>Checkpoint</th>
              <th>Started</th>
              <th>Error</th>
            </tr>
          </thead>
          <tbody id="reconciliation">
            <tr>
              <td colspan="8">Load status to view reconciliation.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <script>
    const login = document.querySelector('#login'),
      tokenInput = document.querySelector('#token'),
      refreshHealthButton = document.querySelector('#refresh-health'),
      errorBox = document.querySelector('#error');

    function cell(row, value) {
      const td = document.createElement('td');
      td.textContent = value == null ? '—' : String(value);
      row.append(td);
      return td
    }

    function date(value) {
      return value ? new Date(value).toLocaleString() : '—'
    }
    async function request(path, token, body) {
      const response = await fetch(path, {
        method: body ? 'POST' : 'GET',
        headers: {
          'X-Central-Operator-Token': token,
          ...(body ? {
            'Content-Type': 'application/json'
          } : {})
        },
        body: body ? JSON.stringify(body) : undefined,
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
      return data
    }
    function renderHealth(items) {
      const health = document.querySelector('#health');
      health.replaceChildren();
      for (const item of items || []) {
        const tr = document.createElement('tr');
        cell(tr, item.display_name || item.subsystem_code);
        cell(tr, item.health_status);
        cell(tr, date(item.last_seen_at));
        health.append(tr)
      }
      if (!health.children.length) health.innerHTML = '<tr><td colspan="3">No subsystem health records.</td></tr>';
    }
    async function load(token) {
      errorBox.textContent = '';
      try {
        const data = await request('api/v1/admin/summary', token);
        renderHealth(data.subsystem_health);
        const deliveries = document.querySelector('#deliveries');
        deliveries.replaceChildren();
        for (const item of data.deliveries || []) {
          const tr = document.createElement('tr');
          cell(tr, item.source_system);
          cell(tr, item.event_type);
          cell(tr, item.destination_system);
          cell(tr, item.status);
          cell(tr, item.attempts);
          cell(tr, date(item.next_attempt_at));
          cell(tr, item.last_error);
          cell(tr, date(item.updated_at));
          deliveries.append(tr)
        }
        if (!deliveries.children.length) deliveries.innerHTML = '<tr><td colspan="8">No deliveries found.</td></tr>';
        const dead = document.querySelector('#dead');
        dead.replaceChildren();
        for (const item of data.open_dead_letters || []) {
          const tr = document.createElement('tr');
          cell(tr, item.event_id);
          cell(tr, item.destination_system);
          cell(tr, item.attempts);
          cell(tr, item.failure_reason);
          cell(tr, date(item.failed_at));
          const td = document.createElement('td'),
            button = document.createElement('button');
          button.textContent = 'Replay';
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              await request(`api/v1/admin/dead-letters/${encodeURIComponent(item.failed_event_id)}/replay`, token, {});
              await load(token)
            } catch (e) {
              errorBox.textContent = e.message;
              button.disabled = false
            }
          });
          td.append(button);
          tr.append(td);
          dead.append(tr)
        }
        if (!dead.children.length) dead.innerHTML = '<tr><td colspan="6">No open dead letters.</td></tr>';
        const runs = document.querySelector('#reconciliation');
        runs.replaceChildren();
        for (const item of data.reconciliation_runs || []) {
          const tr = document.createElement('tr');
          cell(tr, item.source_system);
          cell(tr, item.record_type);
          cell(tr, item.status);
          cell(tr, item.scanned_count);
          cell(tr, item.enqueued_count);
          cell(tr, item.checkpoint);
          cell(tr, date(item.started_at));
          cell(tr, item.error_summary);
          runs.append(tr)
        }
        if (!runs.children.length) runs.innerHTML = '<tr><td colspan="8">No reconciliation runs recorded.</td></tr>';
      } catch (e) {
        errorBox.textContent = e.message
      }
    }
    refreshHealthButton.addEventListener('click', async () => {
      const token = tokenInput.value;
      errorBox.textContent = '';
      if (!token) {
        errorBox.textContent = 'Enter the Central operator token first.';
        tokenInput.focus();
        return
      }
      refreshHealthButton.disabled = true;
      try {
        const data = await request('api/v1/admin/summary', token);
        renderHealth(data.subsystem_health);
        errorBox.textContent = 'Subsystem health refreshed.'
      } catch (e) {
        errorBox.textContent = e.message
      } finally {
        refreshHealthButton.disabled = false
      }
    });
    login.addEventListener('submit', async e => {
      e.preventDefault();
      await load(tokenInput.value)
    });
  </script>
</body>

</html>
