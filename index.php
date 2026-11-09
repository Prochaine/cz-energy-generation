<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Výroba elektřiny v ČR</title>
<style>
  :root {
    --bg: #f5f6f8; --card: #fff; --text: #1d2330; --muted: #6a7385; --line: #e1e4ea; --accent: #2b6cb0;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #14171d; --card: #1c2029; --text: #e6e9ef; --muted: #99a2b3; --line: #2c3240; --accent: #6aa7e6; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.45 system-ui, "Segoe UI", sans-serif; }
  main { max-width: 1100px; margin: 0 auto; padding: 20px 16px 48px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 0 0 12px; }
  .sub { color: var(--muted); font-size: 13px; }
  #status { margin: 6px 0 16px; font-size: 13px; color: var(--muted); }
  #status.err { color: #c0392b; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  .bar { display: flex; height: 26px; border-radius: 6px; overflow: hidden; margin: 8px 0 14px; background: var(--line); }
  .bar div { height: 100%; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 6px 8px; text-align: right; border-bottom: 1px solid var(--line); }
  th:first-child, td:first-child { text-align: left; }
  th { font-weight: 600; font-size: 13px; color: var(--muted); }
  tfoot td { font-weight: 600; border-bottom: 0; }
  .dot { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: 8px; vertical-align: -1px; }
  .ctl { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
  button, input[type=date] { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--line);
    border-radius: 6px; padding: 5px 12px; }
  button { cursor: pointer; }
  button:hover:not(:disabled) { border-color: var(--accent); }
  button:disabled { opacity: .4; cursor: default; }
  .chartbox { margin-bottom: 18px; }
  .chartbox:last-child { margin-bottom: 0; }
  .ct { font-size: 13px; font-weight: 600; color: var(--muted); margin-bottom: 4px; }
  #nodata { color: var(--muted); display: none; padding: 30px 0; text-align: center; }
</style>
</head>
<body>
<main>
  <h1>Výroba elektřiny v ČR podle zdroje</h1>
  <div class="sub">Zdroj dat: ČEPS, a.s. – brutto výroba, hodinové průměry výkonu (MW)</div>
  <div id="status">Načítám…</div>

  <section class="card">
    <h2>Aktuálně <span class="sub" id="curTime"></span></h2>
    <div class="bar" id="curBar"></div>
    <table>
      <thead><tr><th>Zdroj</th><th>Výkon [MW]</th><th>Podíl [%]</th></tr></thead>
      <tbody id="curBody"></tbody>
      <tfoot id="curFoot"></tfoot>
    </table>
  </section>

  <section class="card">
    <h2>Denní grafy</h2>
    <div class="ctl">
      <button id="prev" title="Předchozí den">‹</button>
      <input type="date" id="day">
      <button id="next" title="Další den">›</button>
      <button id="today">Poslední den</button>
      <span class="sub" id="dayInfo"></span>
    </div>
    <div id="nodata">Pro tento den nejsou data.</div>
    <div id="charts">
      <div class="ct">Výkon [MW]</div>
      <div class="chartbox"><div id="cMW"></div></div>
      <div class="ct">Podíl na výrobě [%]</div>
      <div class="chartbox"><div id="cPct"></div></div>
    </div>
  </section>
</main>

<script src="vendor/apexcharts.min.js"></script>
<script>
// Pořadí = od spodu grafu (základní zdroje dole)
const SRC = [
  {k: 'NPP',   n: 'Jaderné',       c: '#8e6bbf'},
  {k: 'TPP',   n: 'Parní (uhelné)',c: '#6b5b4e'},
  {k: 'CCGT',  n: 'Paroplynové',   c: '#e08a3c'},
  {k: 'AltPP', n: 'Alternativní',  c: '#6fae5b'},
  {k: 'ApPP',  n: 'Závodní',       c: '#9aa0a6'},
  {k: 'HPP',   n: 'Vodní',         c: '#3b7dd8'},
  {k: 'PsPP',  n: 'Přečerpávací',  c: '#7fb8e6'},
  {k: 'WPP',   n: 'Větrné',        c: '#2aa6a0'},
  {k: 'PVPP',  n: 'Fotovoltaické', c: '#e6c229'},
];
const $ = id => document.getElementById(id);
const fmt = (x, d = 0) => x.toLocaleString('cs-CZ', {minimumFractionDigits: d, maximumFractionDigits: d});
let first = null, last = null, cMW = null, cPct = null;

async function api(a, p = {}) {
  const r = await fetch('api.php?' + new URLSearchParams({a, ...p}));
  const j = await r.json();
  if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
}

function tsLabel(ts) {
  return new Date(ts * 1000).toLocaleString('cs-CZ', {day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'});
}

async function loadLatest() {
  const j = await api('latest');
  first = j.first; last = j.last;
  const day = $('day');
  if (first) { day.min = first; day.max = last; }
  if (!j.row) { $('curBody').innerHTML = '<tr><td colspan="3">Zatím žádná data.</td></tr>'; return; }
  const v = j.row.v;
  const tot = SRC.reduce((s, x) => s + (v[x.k] || 0), 0);
  $('curTime').textContent = '– hodina od ' + tsLabel(j.row.ts);
  const sorted = [...SRC].sort((a, b) => (v[b.k] || 0) - (v[a.k] || 0));
  $('curBar').innerHTML = sorted.map(x => {
    const p = 100 * (v[x.k] || 0) / tot;
    return `<div style="width:${p}%;background:${x.c}" title="${x.n}: ${fmt(p, 1)} %"></div>`;
  }).join('');
  $('curBody').innerHTML = sorted.map(x => {
    const mw = v[x.k] || 0;
    return `<tr><td><span class="dot" style="background:${x.c}"></span>${x.n}</td><td>${fmt(mw, 1)}</td><td>${fmt(100 * mw / tot, 1)}</td></tr>`;
  }).join('');
  $('curFoot').innerHTML = `<tr><td>Celkem</td><td>${fmt(tot, 1)}</td><td>100,0</td></tr>`;
}

const dark = matchMedia('(prefers-color-scheme: dark)').matches;

function chartOpts(el, yTitle, yMax) {
  return {
    chart: {type: 'area', height: 360, stacked: true, animations: {enabled: false}, background: 'transparent',
            fontFamily: 'inherit', foreColor: dark ? '#99a2b3' : '#6a7385',
            zoom: {enabled: false},
            toolbar: {show: true, tools: {download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false}},
            locales: [{name: 'cs', options: {toolbar: {download: 'Stáhnout', exportToSVG: 'Stáhnout SVG', exportToPNG: 'Stáhnout PNG',
              exportToCSV: 'Stáhnout CSV', zoomIn: 'Přiblížit', zoomOut: 'Oddálit', pan: 'Posun', reset: 'Původní zobrazení'}}}], defaultLocale: 'cs'},
    theme: {mode: dark ? 'dark' : 'light'},
    series: [],
    colors: SRC.map(x => x.c),
    stroke: {width: 1, curve: 'straight'},
    fill: {type: 'solid', opacity: 0.9},
    dataLabels: {enabled: false},
    legend: {position: 'bottom'},
    grid: {borderColor: dark ? '#2c3240' : '#e1e4ea'},
    xaxis: {categories: [], tickAmount: 12, labels: {rotate: 0, hideOverlappingLabels: true}, tooltip: {enabled: false}},
    yaxis: {min: 0, max: yMax, decimalsInFloat: 0, title: {text: yTitle}, labels: {formatter: v => fmt(v, 0)}},
    tooltip: {shared: true, intersect: false, theme: dark ? 'dark' : 'light', y: {formatter: v => fmt(v, 1)}},
  };
}

function render(rows) {
  const labels = rows.map(r => r.t);
  const tot = r => SRC.reduce((s, x) => s + (r.v[x.k] || 0), 0);
  const sMW = SRC.map(x => ({name: x.n, data: rows.map(r => r.v[x.k] || 0)}));
  const sPct = SRC.map(x => ({name: x.n, data: rows.map(r => tot(r) ? Math.round(1000 * (r.v[x.k] || 0) / tot(r)) / 10 : 0)}));
  if (!cMW) {
    cMW = new ApexCharts($('cMW'), chartOpts('cMW', 'MW'));
    cPct = new ApexCharts($('cPct'), chartOpts('cPct', '%', 100));
    cMW.render(); cPct.render();
  }
  cMW.updateOptions({series: sMW, xaxis: {categories: labels}});
  cPct.updateOptions({series: sPct, xaxis: {categories: labels}});
}

async function loadDay(d) {
  if (!d) return;
  $('day').value = d;
  $('prev').disabled = !first || d <= first;
  $('next').disabled = !last || d >= last;
  const j = await api('day', {d});
  const has = j.rows.length > 0;
  $('nodata').style.display = has ? 'none' : 'block';
  $('charts').style.display = has ? 'block' : 'none';
  $('dayInfo').textContent = has ? j.rows.length + ' hodin' : '';
  if (has) render(j.rows);
}

function shift(n) {
  const d = new Date($('day').value + 'T12:00:00');
  d.setDate(d.getDate() + n);
  const s = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  if ((first && s < first) || (last && s > last)) return;
  loadDay(s);
}

$('prev').onclick = () => shift(-1);
$('next').onclick = () => shift(1);
$('today').onclick = () => last && loadDay(last);
$('day').onchange = e => loadDay(e.target.value);

(async function main() {
  const st = $('status');
  try {
    await loadLatest();                       // co už máme v DB, hned zobrazíme
    if (last) await loadDay(last);
  } catch (e) { /* DB je ještě prázdná */ }

  try {                                       // doplnění chybějících dat z webové služby
    let total = 0, r;
    do {
      st.textContent = 'Stahuji data z ČEPS…' + (total ? ` (${total} hodin, hotovo do ${r.upto})` : '');
      r = await api('sync');
      total += r.rows;
    } while (!r.done);
    st.textContent = 'Data aktuální (poslední synchronizace ' + new Date().toLocaleTimeString('cs-CZ') + ').';
    st.className = '';
    const prevLast = last;
    await loadLatest();
    const cur = $('day').value;
    await loadDay(!cur || cur === prevLast ? last : cur);   // pokud jsme byli na posledním dni, posuň se na nový
  } catch (e) {
    st.textContent = 'Synchronizace selhala: ' + e.message + (last ? ' – zobrazuji uložená data.' : '');
    st.className = 'err';
  }
})();
</script>
</body>
</html>
