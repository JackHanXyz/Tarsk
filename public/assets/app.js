/* Tarsk Panel · 交互脚本 */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ------------------------------- Toast ------------------------------- */
  function toast(message, type) {
    var stack = $('#toastStack');
    if (!stack) { return; }
    var el = document.createElement('div');
    el.className = 'toast' + (type ? ' toast--' + type : '');
    el.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6L9 17l-5-5"/></svg><span></span>';
    el.querySelector('span').textContent = message;
    stack.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .25s'; setTimeout(function () { el.remove(); }, 260); }, 3200);
  }

  /* ------------------------------- 主题 / 侧栏 ------------------------------- */
  var THEME_KEY = 'tarsk-theme';
  var redrawers = [];

  function chartPalette() {
    var style = getComputedStyle(document.documentElement);
    var pick = function (name, fallback) {
      var value = (style.getPropertyValue(name) || '').trim();
      return value || fallback;
    };
    return [
      pick('--chart-1', '#0f766e'),
      pick('--chart-2', '#c2760a'),
      pick('--chart-3', '#3b6ea5')
    ];
  }

  var themeToggle = $('[data-theme-toggle]');
  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      var root = document.documentElement;
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      if (next === 'dark') {
        root.setAttribute('data-theme', 'dark');
      } else {
        root.removeAttribute('data-theme');
      }
      try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* 忽略存储异常 */ }
      redrawers.forEach(function (redraw) { redraw(); });
    });
  }

  var appShell = $('#app');
  var navToggle = $('[data-nav-toggle]');
  if (appShell && navToggle) {
    navToggle.addEventListener('click', function () {
      appShell.classList.toggle('is-nav-open');
    });
    $$('[data-nav-close]').forEach(function (node) {
      node.addEventListener('click', function () { appShell.classList.remove('is-nav-open'); });
    });
    $$('.nav__item', appShell).forEach(function (link) {
      link.addEventListener('click', function () { appShell.classList.remove('is-nav-open'); });
    });
  }

  /* -------------------------------- 时钟 -------------------------------- */
  function tickClock() {
    var node = $('#clock');
    if (!node) { return; }
    var now = new Date();
    node.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
  }
  setInterval(tickClock, 20000);
  tickClock();

  /* ------------------------------- 提示条 ------------------------------- */
  $$('[data-dismissible]').forEach(function (box) {
    var close = box.querySelector('[data-dismiss]');
    var remove = function () {
      box.style.transition = 'opacity .2s, transform .2s';
      box.style.opacity = '0';
      box.style.transform = 'translateY(-4px)';
      setTimeout(function () { box.remove(); }, 220);
    };
    if (close) { close.addEventListener('click', remove); }
    setTimeout(remove, 7000);
  });

  /* ------------------------------- 弹层 ------------------------------- */
  function openDialog(id) {
    var dialog = document.getElementById(id);
    if (!dialog) { return; }
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', 'open');
    }
  }
  function closeDialog(id) {
    var dialog = document.getElementById(id);
    if (!dialog) { return; }
    if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
  }

  $$('[data-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      var id = trigger.getAttribute('data-open');
      if (trigger.tagName === 'A' && trigger.getAttribute('href') === '#') { event.preventDefault(); }

      if (id === 'siteDelete') {
        var siteId = trigger.getAttribute('data-site-id') || '';
        var siteName = trigger.getAttribute('data-site-name') || '';
        var form = $('#siteDeleteForm');
        if (form) { form.action = '/sites/' + siteId + '/delete'; }
        var nameNode = $('#siteDeleteName');
        if (nameNode) { nameNode.textContent = siteName; }
        var check = $('#siteDeleteDeleteFiles');
        if (check) { check.checked = false; }
      }

      openDialog(id);
    });
  });

  $$('[data-close]').forEach(function (trigger) {
    trigger.addEventListener('click', function () { closeDialog(trigger.getAttribute('data-close')); });
  });

  $$('dialog.modal').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        var rect = dialog.getBoundingClientRect();
        var inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
        if (!inside) { dialog.close(); }
      }
    });
  });

  /* ------------------------------- 确认提交 ------------------------------- */
  $$('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
    });
  });

  /* ------------------------------- 表格搜索 ------------------------------- */
  $$('input[data-table-search]').forEach(function (input) {
    var table = document.getElementById(input.getAttribute('data-table-search'));
    if (!table) { return; }
    input.addEventListener('input', function () {
      var keyword = input.value.trim().toLowerCase();
      $$('tbody tr', table).forEach(function (row) {
        var text = (row.getAttribute('data-search') || row.textContent).toLowerCase();
        row.style.display = keyword === '' || text.indexOf(keyword) !== -1 ? '' : 'none';
      });
    });
  });

  /* ------------------------------- 文件管理 ------------------------------- */
  $$('[data-file-open]').forEach(function (link) {
    link.addEventListener('click', function () {
      var path = link.getAttribute('data-file-open');
      fetch('/files/read?path=' + encodeURIComponent(path))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) { toast(data.message || '无法读取文件', 'error'); return; }
          $('#editorTitle').textContent = data.name;
          $('#editorPath').value = path;
          $('#editorContent').value = data.content;
          $('#editorMeta').textContent = 'UTF-8 · ' + path;
          openDialog('editorDialog');
        })
        .catch(function () { toast('读取失败，请重试', 'error'); });
    });
  });

  $$('[data-rename-path]').forEach(function (button) {
    button.addEventListener('click', function () {
      $('#renamePath').value = button.getAttribute('data-rename-path');
      $('#renameName').value = button.getAttribute('data-rename-name');
      openDialog('renameDialog');
    });
  });

  $$('[data-delete-path]').forEach(function (button) {
    button.addEventListener('click', function () {
      $('#deletePath').value = button.getAttribute('data-delete-path');
      $('#deleteName').textContent = button.getAttribute('data-delete-name');
      openDialog('deleteDialog');
    });
  });

  $$('[data-trigger]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var target = document.getElementById(trigger.getAttribute('data-trigger'));
      if (target) { target.click(); }
    });
  });

  var uploadInput = $('#fileUploadInput');
  if (uploadInput) {
    uploadInput.addEventListener('change', function () {
      if (uploadInput.files && uploadInput.files.length > 0) {
        var form = $('#fileUploadForm');
        if (form) { form.submit(); }
      }
    });
  }

  /* ------------------------------- 图表 ------------------------------- */
  function setupCanvas(canvas) {
    var dpr = window.devicePixelRatio || 1;
    var width = canvas.clientWidth || canvas.parentNode.clientWidth || 600;
    var height = canvas.clientHeight || 210;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx: ctx, w: width, h: height };
  }

  function drawChart(canvas, points, keys, colors) {
    if (!canvas) { return; }
    var surface = setupCanvas(canvas);
    var ctx = surface.ctx;
    var w = surface.w;
    var h = surface.h;
    var pad = { left: 32, right: 10, top: 12, bottom: 22 };
    var plotW = Math.max(10, w - pad.left - pad.right);
    var plotH = Math.max(10, h - pad.top - pad.bottom);

    ctx.clearRect(0, 0, w, h);

    ctx.strokeStyle = 'rgba(148, 163, 184, .22)';
    ctx.lineWidth = 1;
    ctx.font = '10px -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif';
    ctx.fillStyle = '#9aa6b6';
    for (var i = 0; i <= 4; i++) {
      var y = pad.top + plotH * i / 4;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(w - pad.right, y);
      ctx.stroke();
      var label = String(100 - i * 25);
      ctx.fillText(label, 8, y + 3);
    }

    if (!points || points.length === 0) {
      ctx.fillStyle = '#b0bac6';
      ctx.font = '12px -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif';
      ctx.fillText('暂无数据', pad.left + 10, pad.top + 20);
      return;
    }

    var count = points.length;
    var xFor = function (index) { return pad.left + (count === 1 ? 0 : plotW * index / (count - 1)); };
    var yFor = function (value) { return pad.top + plotH * (1 - Math.min(100, Math.max(0, value)) / 100); };

    keys.forEach(function (key, seriesIndex) {
      var color = colors[seriesIndex];
      ctx.beginPath();
      points.forEach(function (point, index) {
        var value = Number(point[key] || 0);
        var x = xFor(index);
        var y = yFor(value);
        if (index === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
      });
      ctx.strokeStyle = color;
      ctx.lineWidth = 1.6;
      ctx.stroke();

      if (seriesIndex === 0) {
        var gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotH);
        gradient.addColorStop(0, 'rgba(59, 130, 246, .16)');
        gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');
        ctx.save();
        ctx.beginPath();
        points.forEach(function (point, index) {
          var x = xFor(index);
          var y = yFor(Number(point[key] || 0));
          if (index === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
        });
        ctx.lineTo(xFor(count - 1), pad.top + plotH);
        ctx.lineTo(xFor(0), pad.top + plotH);
        ctx.closePath();
        ctx.fillStyle = gradient;
        ctx.fill();
        ctx.restore();
      }
    });

    ctx.fillStyle = '#9aa6b6';
    ctx.font = '10px -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif';
    var first = points[0].t || '';
    var last = points[count - 1].t || '';
    ctx.fillText(first, pad.left, h - 6);
    var lastWidth = ctx.measureText(last).width;
    ctx.fillText(last, w - pad.right - lastWidth, h - 6);
  }

  function debounce(fn, delay) {
    var timer = null;
    return function () {
      clearTimeout(timer);
      timer = setTimeout(fn, delay);
    };
  }

  /* ------------------------------- 概览轮询 ------------------------------- */
  var dashboardConfig = window.TARSK_DASHBOARD;
  if (dashboardConfig) {
    var pollMs = Math.max(1, Number(dashboardConfig.refresh) || 3) * 1000;
    var history = [];
    var lastNet = null;
    var dashCanvas = $('#liveChart');

    var readNumber = function (id) {
      var node = $(id);
      if (!node) { return 0; }
      return parseFloat(node.textContent.replace(/[^\d.]/g, '')) || 0;
    };

    history.push({
      t: new Date().toTimeString().slice(0, 8),
      cpu: readNumber('#statCpu'),
      mem: readNumber('#statMemPct'),
      disk: readNumber('#statDiskPct')
    });

    var redrawDashboard = function () {
      drawChart(dashCanvas, history, ['cpu', 'mem', 'disk'], chartPalette());
    };
    redrawers.push(redrawDashboard);
    redrawDashboard();

    window.addEventListener('resize', debounce(function () {
      redrawDashboard();
    }, 180));

    var formatBytes = function (bytes) {
      var units = ['B', 'KB', 'MB', 'GB', 'TB'];
      var index = 0;
      var value = Math.abs(Number(bytes) || 0);
      while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
      return (index === 0 ? value.toFixed(0) : value.toFixed(1)) + ' ' + units[index];
    };

    var applyMetrics = function (metrics) {
      var cpu = Number(metrics.cpu || 0);
      var memPct = Number(metrics.mem_percent || 0);
      var diskPct = Number(metrics.disk_percent || 0);

      if ($('#statCpu')) { $('#statCpu').innerHTML = cpu.toFixed(1) + '<small>%</small>'; }
      if ($('#statCpuBar')) { $('#statCpuBar').style.width = cpu + '%'; }
      if ($('#statMem')) { $('#statMem').textContent = formatBytes(metrics.mem_used); }
      if ($('#statMemPct')) { $('#statMemPct').textContent = memPct.toFixed(1) + '%'; }
      if ($('#statMemBar')) { $('#statMemBar').style.width = memPct + '%'; }
      if ($('#statMemFree')) { $('#statMemFree').textContent = '可用 ' + formatBytes(metrics.mem_total - metrics.mem_used); }
      if ($('#statDisk')) { $('#statDisk').textContent = formatBytes(metrics.disk_used); }
      if ($('#statDiskPct')) { $('#statDiskPct').textContent = diskPct.toFixed(1) + '%'; }
      if ($('#statDiskBar')) { $('#statDiskBar').style.width = diskPct + '%'; }
      if ($('#metricsClock')) { $('#metricsClock').textContent = metrics.time || ''; }

      if (lastNet) {
        var now = Date.now();
        var seconds = Math.max(0.5, (now - lastNet.at) / 1000);
        var downRate = (Number(metrics.net_rx) - lastNet.rx) / seconds;
        var upRate = (Number(metrics.net_tx) - lastNet.tx) / seconds;
        if ($('#statNetDown')) { $('#statNetDown').innerHTML = '↓ ' + formatBytes(Math.max(0, downRate)) + '<small>/s</small>'; }
        if ($('#statNetUp')) { $('#statNetUp').textContent = '发送 ↑ ' + formatBytes(Math.max(0, upRate)) + '/s'; }
      }
      lastNet = { at: Date.now(), rx: Number(metrics.net_rx), tx: Number(metrics.net_tx) };

      history.push({
        t: metrics.time || new Date().toTimeString().slice(0, 8),
        cpu: cpu,
        mem: memPct,
        disk: diskPct
      });
      if (history.length > 48) { history.shift(); }
      redrawDashboard();
    };

    var poll = function () {
      fetch('/api/metrics', { headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.ok && data.metrics) { applyMetrics(data.metrics); }
        })
        .catch(function () { /* 静默失败，下次重试 */ });
    };
    setInterval(poll, pollMs);
    setTimeout(poll, 800);
  }

  /* ------------------------------- 监控页 ------------------------------- */
  var monitorConfig = window.TARSK_MONITOR;
  var monitorCanvas = $('#monitorChart');
  if (monitorCanvas && monitorConfig) {
    var series = monitorConfig.series || [];
    var drawMonitor = function () {
      drawChart(monitorCanvas, series, ['cpu', 'mem', 'disk'], chartPalette());
    };
    redrawers.push(drawMonitor);
    drawMonitor();
    if (monitorCanvas.parentNode) {
      new ResizeObserver(debounce(drawMonitor, 160)).observe(monitorCanvas.parentNode);
    }
  }
})();
