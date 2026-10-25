'use strict';

const CSRF = document.body.dataset.csrf;
const POLL_MS = parseInt(document.body.dataset.pollMs, 10) || 5000;
const DEVICE_ID = document.body.dataset.deviceId || '';
const CAN_CONTROL = document.body.dataset.canControl === '1';

const els = {
  conn: document.getElementById('conn'),
  waterTemp: document.getElementById('waterTemp'),
  updated: document.getElementById('updated'),
  targetSlider: document.getElementById('targetSlider'),
  targetValue: document.getElementById('targetValue'),
  systemToggle: document.getElementById('systemToggle'),
  pumpToggle: document.getElementById('pumpToggle'),
  pumpStatus: document.getElementById('pumpStatus'),
  cooldownWrap: document.getElementById('cooldownWrap'),
  cooldown: document.getElementById('cooldown'),
  cancelCooldown: document.getElementById('cancelCooldown'),
  heaterIndicator: document.getElementById('heaterIndicator'),
  heaterText: document.getElementById('heaterText'),
  heaterTip: document.getElementById('heaterTip'),
  toast: document.getElementById('toast'),
  deviceSelect: document.getElementById('deviceSelect'),
  refreshBtn: document.getElementById('refreshBtn'),
};

const MANUAL_REFRESH_COOLDOWN = 30000; // min ms between manual refreshes
let lastPollAt = 0;

let targetDragging = false;   // suppress remote updates while user drags
let cooldownSeconds = null;   // local interpolation between polls
let lockoutSeconds = null;    // heater anti-short-cycle "Temp Lockout" countdown
let lastReportedAt = null;    // re-sync the countdowns only when the device reports anew

// Optimistic UI: after a command, hold the requested value so the control doesn't
// snap back to the old value while we wait for the device's next ~30s check-in.
// Cleared as soon as the device reports the new value, or after 60s as a safety net.
const optimistic = {};
function setOptimistic(field, value) { optimistic[field] = { value, until: Date.now() + 60000 }; }
function clearOptimistic() { for (const k in optimistic) delete optimistic[k]; }
function applyOptimistic(field, deviceValue) {
  const o = optimistic[field];
  if (!o) return deviceValue;
  if (Date.now() > o.until) { delete optimistic[field]; return deviceValue; }
  if (deviceValue != null && String(deviceValue) === String(o.value)) { delete optimistic[field]; return deviceValue; }
  return o.value;
}
function applyTarget(v) { els.targetSlider.value = v; els.targetValue.textContent = v + '°F'; }

// ---- helpers ---------------------------------------------------------------

function toast(msg, isError) {
  els.toast.textContent = msg;
  els.toast.classList.remove('hidden');
  els.toast.classList.toggle('toast-error', !!isError);
  clearTimeout(toast._t);
  toast._t = setTimeout(() => els.toast.classList.add('hidden'), 2500);
}

// Commands are queued server-side and pulled by the device on its next check-in
// (~30s), so there's a delay. Poll a few times after sending to catch the change.
function pollBurst() {
  [1000, 5000, 12000, 31000].forEach(ms => setTimeout(poll, ms));
}

async function postCommand(action, value) {
  // Optimistically pin + reflect the requested change right away.
  if (action === 'set_target')      { setOptimistic('targetTemp', parseInt(value, 10)); applyTarget(parseInt(value, 10)); }
  else if (action === 'system_on')  { setOptimistic('systemOn', true);  setToggle(els.systemToggle, true); }
  else if (action === 'system_off') { setOptimistic('systemOn', false); setToggle(els.systemToggle, false); }
  else if (action === 'pump_on')    { setOptimistic('pumpOn', true);  setToggle(els.pumpToggle, true); }
  else if (action === 'pump_off')   { setOptimistic('pumpOn', false); setToggle(els.pumpToggle, false); }
  else if (action === 'cancel_cooldown') {
    // Resume pump now (debt clock keeps running on the device underneath).
    setOptimistic('pumpStatus', 'ON');
    setOptimistic('pumpOn', true);
    setToggle(els.pumpToggle, true);
    els.pumpStatus.textContent = 'ON';
    els.cooldownWrap.classList.add('hidden');
    cooldownSeconds = null;
  }

  const body = new URLSearchParams({ action });
  if (value !== undefined) body.set('value', value);
  if (DEVICE_ID) body.set('device', DEVICE_ID);
  const res = await fetch('/api/ui/command', {
    method: 'POST',
    headers: {
      'X-CSRF-Token': CSRF,
      'Content-Type': 'application/x-www-form-urlencoded',
      'Accept': 'application/json',
    },
    body,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) {
    clearOptimistic(); // command rejected — let the UI reflect reality again
    toast(data.error || 'Command failed', true);
    return null;
  }
  toast('Sent — applies on the next device check-in');
  pollBurst();
  return data;
}

// ---- rendering -------------------------------------------------------------

function render(s) {
  const connection = s.connection || (s.online ? 'online' : 'offline');
  setConn(connection);

  if (s.waterTempDisplay) {
    els.waterTemp.innerHTML = (s.waterTemp != null ? Math.round(s.waterTemp) : '--') + '<span class="unit">°F</span>';
  }

  const targetTemp = applyOptimistic('targetTemp', s.targetTemp);
  if (targetTemp != null && !targetDragging) {
    applyTarget(targetTemp);
  }

  setToggle(els.systemToggle, applyOptimistic('systemOn', s.systemOn));
  setToggle(els.pumpToggle, applyOptimistic('pumpOn', s.pumpOn));

  const pumpStatus = applyOptimistic('pumpStatus', s.pumpStatus);
  els.pumpStatus.textContent = pumpStatus || '—';
  const cooling = pumpStatus === 'Cooling Down';
  els.cooldownWrap.classList.toggle('hidden', !cooling);
  const freshReport = s.reportedAt !== lastReportedAt;
  if (cooling) {
    // Only re-sync to the device value on a *new* report; otherwise let the 1s tick
    // run, so frequent polls don't reset a stale countdown and loop near zero.
    if (freshReport) {
      cooldownSeconds = parseMMSS(s.cooldownRemaining);
      els.cooldown.textContent = s.cooldownRemaining || '--:--';
    }
  } else {
    cooldownSeconds = null;
  }
  lastReportedAt = s.reportedAt;

  const heaterStatus = s.heaterStatus || '';
  const lockout = heaterStatus.indexOf('Temp Lockout') === 0;
  const heat = !!s.heaterOn;
  els.heaterIndicator.classList.toggle('on', heat);
  if (els.heaterTip) els.heaterTip.classList.toggle('hidden', !lockout);
  if (lockout) {
    // Re-sync only on a fresh report; the 1s tick interpolates between check-ins.
    if (freshReport) {
      const tm = /(\d{1,2}):(\d{2})/.exec(heaterStatus);
      lockoutSeconds = tm ? (parseInt(tm[1], 10) * 60 + parseInt(tm[2], 10)) : null;
    }
    els.heaterText.textContent = 'Temp Lockout ' + fmtMMSS(lockoutSeconds);
  } else {
    lockoutSeconds = null;
    els.heaterText.textContent = heat ? 'FIRING' : 'OFF';
  }

  if (connection === 'online') {
    els.updated.textContent = 'Updated ' + new Date().toLocaleTimeString();
  } else if (connection === 'never') {
    els.updated.textContent = 'Never Connected';
  } else {
    els.updated.textContent = s.reportedAt ? 'Device offline (last seen ' + s.reportedAt + ')' : 'Waiting for device…';
  }
}

function setConn(connection) {
  if (connection === 'online') {
    els.conn.className = 'conn conn-ok';
    els.conn.title = 'Connected';
  } else if (connection === 'never') {
    els.conn.className = 'conn conn-unknown';
    els.conn.title = 'Never Connected';
  } else {
    els.conn.className = 'conn conn-bad';
    els.conn.title = 'Offline';
  }
}

function setToggle(btn, on) {
  if (on == null) return;
  const isOn = !!on;
  btn.dataset.on = isOn ? 'true' : 'false';
  btn.classList.toggle('on', isOn);
  btn.textContent = isOn ? 'ON' : 'OFF';
}

function parseMMSS(str) {
  if (!str || str === '--:--') return null;
  const m = /^(\d{1,2}):(\d{2})$/.exec(str);
  if (!m) return null;
  return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
}

function fmtMMSS(secs) {
  if (secs == null || secs < 0) return '--:--';
  const m = Math.floor(secs / 60), s = secs % 60;
  return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

// Manual-refresh button: disabled (with a countdown) until 30s since the last poll.
function updateRefreshBtn() {
  if (!els.refreshBtn) return;
  const remain = Math.ceil((MANUAL_REFRESH_COOLDOWN - (Date.now() - lastPollAt)) / 1000);
  if (remain > 0) {
    els.refreshBtn.disabled = true;
    els.refreshBtn.textContent = '↻ ' + remain + 's';
  } else {
    els.refreshBtn.disabled = false;
    els.refreshBtn.textContent = '↻ Refresh';
  }
}

// ---- polling ---------------------------------------------------------------

async function poll() {
  if (!DEVICE_ID) return;
  lastPollAt = Date.now();
  updateRefreshBtn();
  try {
    const res = await fetch('/api/ui/state?device=' + encodeURIComponent(DEVICE_ID), {
      headers: { 'Accept': 'application/json' },
    });
    if (res.status === 401) { location.href = '/login'; return; }
    const s = await res.json();
    render(s);
  } catch (e) {
    setConn(false);
    els.updated.textContent = 'Connection error';
  }
}

// Local 1s tick for the cool-down countdown (re-synced every poll).
setInterval(() => {
  if (cooldownSeconds != null) {
    cooldownSeconds = Math.max(0, cooldownSeconds - 1);
    els.cooldown.textContent = fmtMMSS(cooldownSeconds);
  }
  if (lockoutSeconds != null) {
    lockoutSeconds = Math.max(0, lockoutSeconds - 1);
    els.heaterText.textContent = 'Temp Lockout ' + fmtMMSS(lockoutSeconds);
  }
  updateRefreshBtn();
}, 1000);

// ---- controls --------------------------------------------------------------

if (els.targetSlider && CAN_CONTROL) {
  els.targetSlider.addEventListener('input', () => {
    targetDragging = true;
    els.targetValue.textContent = els.targetSlider.value + '°F';
  });
  els.targetSlider.addEventListener('change', async () => {
    await postCommand('set_target', els.targetSlider.value);
    targetDragging = false;
  });

  els.systemToggle.addEventListener('click', () => {
    const on = els.systemToggle.dataset.on === 'true';
    postCommand(on ? 'system_off' : 'system_on');
  });

  els.pumpToggle.addEventListener('click', () => {
    const on = els.pumpToggle.dataset.on === 'true';
    postCommand(on ? 'pump_off' : 'pump_on');
  });

  els.cancelCooldown.addEventListener('click', () => postCommand('cancel_cooldown'));
}

if (els.deviceSelect) {
  els.deviceSelect.addEventListener('change', () => {
    location.href = '/?device=' + encodeURIComponent(els.deviceSelect.value);
  });
}

if (els.refreshBtn) {
  els.refreshBtn.addEventListener('click', () => {
    if (Date.now() - lastPollAt >= MANUAL_REFRESH_COOLDOWN) poll();
  });
}

// ---- go --------------------------------------------------------------------

if (DEVICE_ID) {
  poll();
  setInterval(poll, POLL_MS);
}
