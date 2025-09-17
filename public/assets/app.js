// Tabs
const tabs = document.querySelectorAll('.tab');
tabs.forEach(t => t.addEventListener('click', () => {
    tabs.forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    const id = t.dataset.tab;
    document.getElementById('tab-calc').style.display = id === 'calc' ? '' : 'none';
    document.getElementById('tab-convert').style.display = id === 'convert' ? '' : 'none';
    document.getElementById('tab-graph').style.display = id === 'graph' ? '' : 'none';
}));

// Theme toggle
const htmlEl = document.documentElement;
const themeLabel = document.getElementById('themeLabel');
const savedTheme = localStorage.getItem('theme');
if (savedTheme) { htmlEl.setAttribute('data-theme', savedTheme); themeLabel.textContent = savedTheme === 'dark' ? 'Sombre' : 'Clair'; }
document.getElementById('themeToggle').addEventListener('click', () => {
    const cur = htmlEl.getAttribute('data-theme') || 'light';
    const next = cur === 'light' ? 'dark' : 'light';
    htmlEl.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    themeLabel.textContent = next === 'dark' ? 'Sombre' : 'Clair';
});

// Keypad helpers
function insertKey(k) {
    const el = document.getElementById('expression');
    const start = el.selectionStart, end = el.selectionEnd;
    let txt = el.value;
    if (k === '=') { document.getElementById('calcForm').submit(); return; }
    const insert = (['sin','cos','tan','sqrt','log','ln','pow'].includes(k)) ? (k + '(') : k;
    el.value = txt.substring(0, start) + insert + txt.substring(end);
    const caret = start + insert.length;
    el.setSelectionRange(caret, caret);
    el.focus();
    updateShare();
}
function clearExpr() { const el = document.getElementById('expression'); el.value=''; updateShare(); }
function useExpression(expr) { const el = document.getElementById('expression'); el.value = expr; updateShare(); window.scrollTo({top:0, behavior:'smooth'}); }
function reuseResult() {
    const r = document.getElementById('resultView').textContent.trim();
    if (r && r !== '—') { useExpression(r); }
}

// Share link
function updateShare() {
    const expr = document.getElementById('expression').value;
    const base = window.location.origin + window.location.pathname;
    const url = expr ? base + '?q=' + encodeURIComponent(expr) : '—';
    document.getElementById('shareBox').textContent = url;
}
function copyShare() {
    const text = document.getElementById('shareBox').textContent;
    if (text && text !== '—') { navigator.clipboard.writeText(text); }
}
updateShare();

// Conversion units
const units = { length: ['km','m','cm','mm'], currency: ['EUR','USD','GBP'] };
function updateUnits() {
    const cat = document.getElementById('categorySelect').value;
    const from = document.getElementById('fromUnit');
    const to = document.getElementById('toUnit');
    from.innerHTML=''; to.innerHTML='';
    units[cat].forEach(u => {
        const o1 = document.createElement('option'); o1.value=o1.textContent=u; from.appendChild(o1);
        const o2 = document.createElement('option'); o2.value=o2.textContent=u; to.appendChild(o2);
    });
    if (cat==='length') { from.value='m'; to.value='cm'; } else { from.value='EUR'; to.value='USD'; }
}
updateUnits();

// Chart.js plotting (very simple evaluator using Function and Math.* in JS)
let chart;
function jsEvalExpr(expr, x) {
    const safe = expr
        .replace(/sin/g, 'Math.sin')
        .replace(/cos/g, 'Math.cos')
        .replace(/tan/g, 'Math.tan')
        .replace(/sqrt/g, 'Math.sqrt')
        .replace(/log10/g, 'Math.log10')
        .replace(/log/g, 'Math.log10')
        .replace(/ln/g, 'Math.log')
        .replace(/pi/gi, 'Math.PI');
    try { return Function('x', 'return ' + safe)(x); } catch { return NaN; }
}
function plot() {
    const expr = document.getElementById('fx').value.trim();
    const xmin = parseFloat(document.getElementById('xmin').value);
    const xmax = parseFloat(document.getElementById('xmax').value);
    if (!expr) return;
    const N = 200;
    const xs = []; const ys = [];
    const step = (xmax - xmin) / (N - 1);
    for (let i=0;i<N;i++) {
        const x = xmin + i*step; const y = jsEvalExpr(expr, x);
        xs.push(x.toFixed(2)); ys.push(isFinite(y) ? y : null);
    }
    const ctx = document.getElementById('chart').getContext('2d');
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'line',
        data: { labels: xs, datasets: [{ label: 'f(x)', data: ys, borderColor: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(), tension: 0.25, pointRadius: 0 }] },
        options: { responsive: true, scales: { x: { display: false } } }
    });
}

// Web Speech API (optional)
const micBtn = document.getElementById('micBtn');
if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    micBtn.disabled = true; micBtn.title = 'Non supporté par ce navigateur';
} else {
    const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    const rec = new Rec(); rec.lang = 'fr-FR'; rec.interimResults = false; rec.maxAlternatives = 1;
    micBtn.addEventListener('click', () => { try { rec.start(); } catch(e){} });
    rec.onresult = (e) => {
        const text = e.results[0][0].transcript;
        let expr = text.toLowerCase().replaceAll('pi', '3.1415926535');
        expr = expr.replaceAll('plus', '+').replaceAll('moins', '-').replaceAll('fois', '*').replaceAll('multiplié par', '*').replaceAll('divisé par', '/');
        expr = expr.replaceAll('virgule', '.');
        useExpression(expr);
    };
}

