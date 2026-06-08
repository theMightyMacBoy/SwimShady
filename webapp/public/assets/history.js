'use strict';

const canvas = document.getElementById('chart');
const ctx = canvas.getContext('2d');
const empty = document.getElementById('chartEmpty');

const COLORS = { water: '#2b9bd6', target: '#e0883a', heat: '#d6453b', grid: '#e2e6ea', axis: '#8a929b' };
const DEVICE_ID = document.body.dataset.deviceId || '';

async function load(hours) {
  let url = 'api/history.php?hours=' + encodeURIComponent(hours);
  if (DEVICE_ID) url += '&device=' + encodeURIComponent(DEVICE_ID);
  const res = await fetch(url);
  if (res.status === 401) { location.href = 'login.php'; return; }
  const data = await res.json();
  draw(data.points || []);
}

// API returns UTC timestamps; ensure they parse as UTC so the chart renders in local time.
function parseTs(s) {
  if (!s) return NaN;
  return new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? s : s + 'Z').getTime();
}

function draw(points) {
  const W = canvas.width, H = canvas.height;
  ctx.clearRect(0, 0, W, H);

  empty.classList.toggle('hidden', points.length > 0);
  if (points.length === 0) return;

  const pad = { l: 44, r: 12, t: 14, b: 28 };
  const plotW = W - pad.l - pad.r, plotH = H - pad.t - pad.b;

  const times = points.map(p => parseTs(p.recordedAt));
  const temps = [];
  points.forEach(p => {
    if (p.waterTemp != null) temps.push(+p.waterTemp);
    if (p.targetTemp != null) temps.push(+p.targetTemp);
  });
  let tMin = Math.min(...temps), tMax = Math.max(...temps);
  if (!isFinite(tMin)) { tMin = 60; tMax = 100; }
  tMin = Math.floor(tMin - 2); tMax = Math.ceil(tMax + 2);

  const xMin = times[0], xMax = times[times.length - 1] || xMin + 1;
  const x = t => pad.l + ((t - xMin) / (xMax - xMin || 1)) * plotW;
  const y = v => pad.t + (1 - (v - tMin) / (tMax - tMin || 1)) * plotH;

  // gridlines + y labels
  ctx.strokeStyle = COLORS.grid;
  ctx.fillStyle = COLORS.axis;
  ctx.font = '11px system-ui, sans-serif';
  ctx.lineWidth = 1;
  const steps = 5;
  for (let i = 0; i <= steps; i++) {
    const v = tMin + (tMax - tMin) * (i / steps);
    const yy = y(v);
    ctx.beginPath(); ctx.moveTo(pad.l, yy); ctx.lineTo(W - pad.r, yy); ctx.stroke();
    ctx.fillText(Math.round(v) + '°', 6, yy + 3);
  }

  // heater-on shading
  ctx.fillStyle = 'rgba(214,69,59,0.12)';
  for (let i = 0; i < points.length; i++) {
    if (points[i].heaterOn === true || +points[i].heaterOn === 1) {
      const x0 = x(times[i]);
      const x1 = i + 1 < points.length ? x(times[i + 1]) : x0 + 2;
      ctx.fillRect(x0, pad.t, Math.max(1, x1 - x0), plotH);
    }
  }

  line(points, times, x, y, 'waterTemp', COLORS.water, 2);
  line(points, times, x, y, 'targetTemp', COLORS.target, 1.5, true);

  // time labels (start / end)
  ctx.fillStyle = COLORS.axis;
  ctx.fillText(new Date(xMin).toLocaleString(), pad.l, H - 10);
  const endLabel = new Date(xMax).toLocaleString();
  ctx.fillText(endLabel, W - pad.r - ctx.measureText(endLabel).width, H - 10);
}

function line(points, times, x, y, key, color, width, dashed) {
  ctx.strokeStyle = color;
  ctx.lineWidth = width;
  ctx.setLineDash(dashed ? [5, 4] : []);
  ctx.beginPath();
  let started = false;
  for (let i = 0; i < points.length; i++) {
    const v = points[i][key];
    if (v == null) { started = false; continue; }
    const px = x(times[i]), py = y(+v);
    if (!started) { ctx.moveTo(px, py); started = true; } else { ctx.lineTo(px, py); }
  }
  ctx.stroke();
  ctx.setLineDash([]);
}

document.querySelectorAll('.range-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    load(btn.dataset.hours);
  });
});

load(24);
