(function () {
  var grid = document.getElementById('mon-grid');
  var upd = document.getElementById('mon-updated');
  var clock = document.getElementById('mon-clock');
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
  function t12(d) { return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' }); }
  function liveTag(r) {
    if (!r.shotId) return r.onDuty
      ? '<span class="badge bg-dark">Waiting for screen&hellip;</span>'
      : '<span class="badge bg-secondary">Off duty</span>';
    var ago = r.shotAgeSec != null ? r.shotAgeSec : 9999;
    if (r.onDuty && ago <= 45) {
      return '<span class="badge bg-danger"><span class="live-dot"></span>LIVE &middot; ' + ago + 's ago</span>';
    }
    return '<span class="badge bg-dark">' + esc(r.shotAtFmt || 'shot') + ' &middot; ' + ago + 's old</span>';
  }
  function shotTile(r) {
    if (!r.shotId) {
      return '<div class="shot-tile"><div class="off-tile">' + (r.onDuty ? 'No screenshot yet &ndash; is the agent running?' : 'No screen when off duty') + '</div></div>';
    }
    return '<div class="shot-tile">'
      + '<img class="d-block w-100" loading="lazy" style="cursor:zoom-in" alt="screen"'
      + ' data-shot="' + r.shotId + '" data-email="' + escapeHtmlAttr(r.email) + '" data-name="' + escapeHtmlAttr(r.name) + '"'
      + ' src="/api/monitor/shot/' + r.shotId + '?v=' + Date.now() + '">'
      + '<span class="shot-badge">' + liveTag(r) + '</span>'
      + '<div class="shot-overlay">'
      + '<div class="fw-semibold">' + esc(r.currentApp || r.currentHost || 'No active window') + '</div>'
      + (r.currentTitle ? '<div class="text-truncate">' + esc(r.currentTitle) + '</div>' : '')
      + '</div></div>';
  }
  function escapeHtmlAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
  function card(r) {
    var duty = r.onDuty ? '<span class="badge bg-success">On Duty</span>' : '<span class="badge bg-secondary">Off</span>';
    var extra = r.idle ? ' <span class="badge bg-warning text-dark">Idle</span>' : '';
    if (r.status === 'ON_BREAK') extra += ' <span class="badge bg-info">Break</span>';
    var role = r.role ? ' <span class="badge bg-light text-dark border">' + esc(r.role) + '</span>' : '';
    return '<div class="col-12 col-md-6 col-xl-4"><div class="card p-2 h-100">'
      + '<div class="d-flex justify-content-between align-items-start px-1 pt-1">'
      + '<div><h6 class="mb-0">' + esc(r.name) + role + '</h6>'
      + '<div class="text-muted small">' + esc(r.designation) + ' &middot; ' + esc(r.department) + '</div></div>'
      + '<div class="text-end"><div>' + duty + '</div><div class="mt-1">' + (extra || '&nbsp;') + '</div></div>'
      + '</div>'
      + '<div class="mt-2">' + shotTile(r) + '</div>'
      + '<div class="small mt-2 d-flex justify-content-between px-1">'
      + '<span>Worked <b>' + esc(r.worked) + '</b></span>'
      + '<span>Active ' + esc(r.active) + '</span>'
      + '<span>Idle ' + esc(r.idleTime) + '</span>'
      + '<span>Break ' + esc(r.breakTime) + '</span>'
      + '</div>'
      + '<div class="small text-muted mt-1 d-flex justify-content-between px-1">'
      + '<span>Keys ' + esc(r.keystrokes) + ' &middot; Clicks ' + esc(r.mouseClicks) + '</span>'
      + '<span>seen ' + esc(r.lastSeenAgo) + '</span>'
      + '</div>'
      + '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-primary w-100" data-dd="' + escapeHtmlAttr(r.email) + '"><i class="bi bi-bar-chart me-1"></i>Apps / Sites</button></div>'
      + '</div></div>';
  }
  function openDD(email) {
    fetch('/api/monitor/usage?email=' + encodeURIComponent(email))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        document.getElementById('ddTitle').textContent = d.email + ' - ' + d.date;
        var apps = (d.apps || []).map(function (a) { return '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.category) + '</td><td>' + esc(a.time) + '</td></tr>'; }).join('');
        var sites = (d.sites || []).map(function (a) { return '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.category) + '</td><td>' + esc(a.time) + '</td></tr>'; }).join('');
        document.getElementById('ddBody').innerHTML =
          '<div class="row g-2 mb-2"><div class="col-3 small">Worked <b>' + fmt(d.workedSec) + '</b></div>'
          + '<div class="col-3 small">Active <b>' + fmt(d.activeSec) + '</b></div>'
          + '<div class="col-3 small">Idle <b>' + fmt(d.idleSec) + '</b></div>'
          + '<div class="col-3 small">Shots <b>' + d.screenshotCount + '</b></div></div>'
          + '<h6 class="text-muted">Apps (' + d.appCount + ')</h6><div class="table-responsive"><table class="table table-sm">' + (apps || '<tr><td class="text-muted">none</td></tr>') + '</table></div>'
          + '<h6 class="text-muted">Sites (' + d.siteCount + ')</h6><div class="table-responsive"><table class="table table-sm">' + (sites || '<tr><td class="text-muted">none</td></tr>') + '</table></div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ddModal')).show();
      });
  }
  function fmt(secs) { secs = secs || 0; var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60); return (h < 10 ? '0' : '') + h + 'h ' + (m < 10 ? '0' : '') + m + 'm'; }
  function shotImg(id) { return '<img src="/api/monitor/shot/' + id + '?v=' + Date.now() + '" class="img-fluid" style="max-height:78vh" alt="screenshot">'; }
  grid.addEventListener('click', function (e) {
    var n = e.target;
    while (n && n !== grid && !(n.dataset && (n.dataset.shot || n.dataset.dd))) n = n.parentNode;
    if (!n || n === grid) return;
    if (n.dataset.shot) {
      document.getElementById('shotTitle').textContent = n.dataset.name + ' \u2013 live screen';
      document.getElementById('shotBody').innerHTML = shotImg(n.dataset.shot);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('shotModal')).show();
    } else if (n.dataset.dd) {
      openDD(n.dataset.dd);
    }
  });
  function refresh() {
    fetch('/api/monitor/live').then(function (r) { return r.json(); }).then(function (rows) {
      grid.innerHTML = rows.map(card).join('') || '<div class="col-12 text-center text-muted py-4">No people visible</div>';
      upd.textContent = 'updated ' + t12(new Date());
    }).catch(function () { upd.textContent = 'offline \u2013 retrying\u2026'; });
  }
  function tick() { clock.textContent = t12(new Date()); }
  refresh(); tick();
  setInterval(refresh, 10000);
  setInterval(tick, 1000);
})();
