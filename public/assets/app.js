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

  /* ------------------------------- 图表配色 ------------------------------- */
  var redrawers = [];

  function chartPalette() {
    var style = getComputedStyle(document.documentElement);
    var pick = function (name, fallback) {
      var value = (style.getPropertyValue(name) || '').trim();
      return value || fallback;
    };
    return [
      pick('--chart-1', '#2563eb'),
      pick('--chart-2', '#7c3aed'),
      pick('--chart-3', '#0e9f6e')
    ];
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

  /* ----------------------------- 导航搜索 ----------------------------- */
  var navSearch = $('[data-nav-search]');
  var nav = $('.nav');
  if (navSearch && nav) {
    var filterNav = function () {
      var query = navSearch.value.trim().toLocaleLowerCase();
      $$('.nav__group', nav).forEach(function (heading) {
        var entries = [];
        var sibling = heading.nextElementSibling;
        while (sibling && !sibling.classList.contains('nav__group')) {
          if (sibling.classList.contains('nav__item')) { entries.push(sibling); }
          sibling = sibling.nextElementSibling;
        }
        var headingMatches = query && heading.textContent.toLocaleLowerCase().indexOf(query) !== -1;
        var visible = 0;
        entries.forEach(function (entry) {
          var match = !query || headingMatches || entry.textContent.toLocaleLowerCase().indexOf(query) !== -1;
          entry.hidden = !match;
          if (match) { visible += 1; }
        });
        heading.hidden = visible === 0;
      });
    };
    navSearch.addEventListener('input', filterNav);
    document.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        navSearch.focus();
        navSearch.select();
      } else if (event.key === 'Escape' && document.activeElement === navSearch) {
        navSearch.value = '';
        filterNav();
        navSearch.blur();
      }
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

  /* 备份恢复弹层：按行填充目标、加密状态 */
  $$('[data-restore]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-kind');
      var enc = btn.getAttribute('data-enc') === '1';
      var set = function (id, val) { var n = document.getElementById(id); if (n) { n.value = val; } };
      set('rstKind', kind);
      set('rstName', btn.getAttribute('data-name') || '');
      set('rstSiteId', btn.getAttribute('data-site-id') || '');
      set('rstDbName', btn.getAttribute('data-db-name') || '');
      var target = document.getElementById('rstTarget');
      if (target) { target.textContent = btn.getAttribute('data-target') || ''; }
      var hint = document.getElementById('rstHint');
      if (hint) {
        hint.textContent = kind === 'db'
          ? '将把 SQL 导入数据库，同名表数据会被覆盖。'
          : '将把备份文件覆盖到站点目录。';
      }
      var encHint = document.getElementById('rstEncHint');
      if (encHint) {
        encHint.textContent = enc ? '该备份已加密，请填写创建时的口令' : '该备份未加密，口令可留空';
      }
      var pwd = document.getElementById('rstPwd');
      if (pwd) { pwd.value = ''; pwd.required = enc; }
      openDialog('restoreBackup');
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

  $$('form[data-busy]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (event.defaultPrevented) { return; }
      var button = form.querySelector('button[type="submit"]');
      if (!button) { return; }
      button.disabled = true;
      button.textContent = form.getAttribute('data-busy') || '处理中…';
      toast(form.getAttribute('data-busy') || '处理中…');
    });
  });

  /* ------------------------------- 全选 ------------------------------- */
  $$('[data-check-all]').forEach(function (master) {
    var scope = master.closest('form') || document;
    var boxes = $$('input[type="checkbox"][name]', scope).filter(function (box) { return box !== master; });
    master.addEventListener('change', function () {
      boxes.forEach(function (box) { box.checked = master.checked; });
    });
    boxes.forEach(function (box) {
      box.addEventListener('change', function () {
        master.checked = boxes.length > 0 && boxes.every(function (b) { return b.checked; });
      });
    });
  });

  /* ------------------------------- Web 终端 ------------------------------- */
  var terminalForm = $('#terminalForm');
  if (terminalForm) {
    var termScreen = $('#terminalScreen');
    var termInput = $('#terminalCommand');
    var termSubmit = $('#terminalSubmit');

    var appendTermEntry = function (entry) {
      var wrap = document.createElement('div');
      wrap.className = 'term-entry';

      var line = document.createElement('div');
      line.className = 'term-line term-cmd';
      var prompt = document.createElement('span');
      prompt.className = 'term-prompt';
      prompt.textContent = '$';
      line.appendChild(prompt);
      line.appendChild(document.createTextNode(entry.command));
      var meta = document.createElement('span');
      meta.className = 'term-meta';
      meta.textContent = entry.time + ' · ' + entry.duration + 's · exit ' + entry.code;
      line.appendChild(meta);

      var out = document.createElement('pre');
      out.className = 'term-out';
      out.textContent = entry.output;

      wrap.appendChild(line);
      wrap.appendChild(out);
      termScreen.appendChild(wrap);
    };

    terminalForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var command = termInput.value.trim();
      if (command === '') { return; }

      termSubmit.disabled = true;
      var payload = new FormData(terminalForm);
      payload.set('command', command);

      fetch('/terminal/run', { method: 'POST', body: payload })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) { toast(data.message || '执行失败', 'error'); return; }
          appendTermEntry(data.entry);
          termInput.value = '';
          termScreen.scrollTop = termScreen.scrollHeight;
        })
        .catch(function () { toast('终端请求失败，请重试', 'error'); })
        .finally(function () { termSubmit.disabled = false; termInput.focus(); });
    });

    $$('[data-term-fill]').forEach(function (button) {
      button.addEventListener('click', function () {
        termInput.value = button.getAttribute('data-term-fill');
        termInput.focus();
      });
    });
  }

  /* ------------------------------- 软件日志 ------------------------------- */
  $$('[data-store-log]').forEach(function (button) {
    button.addEventListener('click', function () {
      var key = button.getAttribute('data-store-log');
      var name = button.getAttribute('data-store-name') || key;
      $('#storeLogTitle').textContent = name + ' 运行日志';
      $('#storeLogTail').textContent = '加载中…';
      $('#storeLogPath').textContent = '';
      openDialog('storeLogDialog');

      fetch('/store/log?key=' + encodeURIComponent(key))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) { $('#storeLogTail').textContent = data.message || '读取失败'; return; }
          $('#storeLogTail').textContent = data.tail || '日志为空（服务可能尚未产生输出）';
          $('#storeLogPath').textContent = (data.running ? '运行中' : '未运行') + ' · ' + (data.path || '');
        })
        .catch(function () { $('#storeLogTail').textContent = '读取日志失败'; });
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
        gradient.addColorStop(0, 'rgba(217, 110, 78, .18)');
        gradient.addColorStop(1, 'rgba(217, 110, 78, 0)');
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

    var lastMonitorNet = null;
    var lastMonitorChartAt = Date.now();
    var monitorBytes = function (value) {
      var units = ['B', 'KB', 'MB', 'GB', 'TB'];
      var n = Math.max(0, Number(value) || 0);
      var i = 0;
      while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
      return (i ? n.toFixed(1) : n.toFixed(0)) + ' ' + units[i];
    };
    var monitorPercent = function (used, total) {
      return total > 0 ? Math.max(0, Math.min(100, Number(used) / Number(total) * 100)) : 0;
    };
    var renderInterfaces = function (rows) {
      var host = $('#monNetInterfaces');
      if (!host || !Array.isArray(rows)) { return; }
      host.textContent = '';
      if (!rows.length) {
        var empty = document.createElement('div');
        empty.className = 'service-row';
        empty.textContent = '暂未读取到网卡明细';
        host.appendChild(empty);
        return;
      }
      rows.forEach(function (item) {
        var row = document.createElement('div');
        var name = document.createElement('strong');
        var counters = document.createElement('span');
        row.className = 'service-row';
        name.className = 'mono';
        counters.className = 'small muted';
        name.textContent = String(item.name || 'interface');
        counters.textContent = 'RX ' + monitorBytes(item.rx) + ' · TX ' + monitorBytes(item.tx);
        row.appendChild(name);
        row.appendChild(counters);
        host.appendChild(row);
      });
    };
    var applyMonitorMetrics = function (metrics) {
      var cpu = Number(metrics.cpu || 0);
      var memPct = monitorPercent(metrics.mem_used, metrics.mem_total);
      var diskPct = monitorPercent(metrics.disk_used, metrics.disk_total);
      if ($('#monCpu')) { $('#monCpu').innerHTML = cpu.toFixed(1) + '<small>%</small>'; }
      if ($('#monCpuTick')) { $('#monCpuTick').textContent = metrics.time || ''; }
      if ($('#monCpuCores')) { $('#monCpuCores').textContent = metrics.cpu_cores ? String(metrics.cpu_cores) : '—'; }
      if ($('#monLoad1')) { $('#monLoad1').textContent = Number(metrics.load1 || 0).toFixed(2); }
      if ($('#monLoad5')) { $('#monLoad5').textContent = Number(metrics.load5 || 0).toFixed(2); }
      if ($('#monLoad15')) { $('#monLoad15').textContent = Number(metrics.load15 || 0).toFixed(2); }
      if ($('#monMem')) { $('#monMem').textContent = monitorBytes(metrics.mem_used); }
      if ($('#monMemPct')) { $('#monMemPct').textContent = memPct.toFixed(1) + '%'; }
      if ($('#monMemBar')) { $('#monMemBar').style.width = memPct + '%'; }
      if ($('#monSwap')) { $('#monSwap').textContent = monitorBytes(metrics.swap_used) + ' / ' + monitorBytes(metrics.swap_total); }
      if ($('#monDisk')) { $('#monDisk').textContent = monitorBytes(metrics.disk_used); }
      if ($('#monDiskPct')) { $('#monDiskPct').textContent = diskPct.toFixed(1) + '%'; }
      if ($('#monDiskBar')) { $('#monDiskBar').style.width = diskPct + '%'; }

      var sampleAt = Number(metrics.sampled_at);
      if (!Number.isFinite(sampleAt) || sampleAt <= 0) { sampleAt = performance.now() / 1000; }
      var currentRx = Number(metrics.net_rx || 0);
      var currentTx = Number(metrics.net_tx || 0);
      if (lastMonitorNet && sampleAt > lastMonitorNet.at) {
        var elapsed = sampleAt - lastMonitorNet.at;
        if (elapsed >= 0.1) {
          var down = Math.max(0, currentRx - lastMonitorNet.rx) / elapsed;
          var up = Math.max(0, currentTx - lastMonitorNet.tx) / elapsed;
          if ($('#monNetDown')) { $('#monNetDown').textContent = monitorBytes(down) + '/s'; }
          if ($('#monNetUp')) { $('#monNetUp').textContent = monitorBytes(up) + '/s'; }
        }
      }
      if (!lastMonitorNet || sampleAt > lastMonitorNet.at) {
        lastMonitorNet = { at: sampleAt, rx: currentRx, tx: currentTx };
      }
      renderInterfaces(metrics.net_interfaces || []);

      if (Date.now() - lastMonitorChartAt >= 30000) {
        series.push({
          t: metrics.time || new Date().toTimeString().slice(0, 5),
          cpu: cpu,
          mem: memPct,
          disk: diskPct
        });
        if (series.length > 2880) { series.shift(); }
        lastMonitorChartAt = Date.now();
        drawMonitor();
      }
    };
    var monitorStatus = $('#monitorStatus');
    var monitorStatusChip = $('#monitorStatusChip');
    var monitorPending = false;
    var setMonitorStatus = function (message, failed) {
      if (monitorStatus) { monitorStatus.textContent = message; }
      if (monitorStatusChip) { monitorStatusChip.classList.toggle('chip--danger', !!failed); }
    };
    var monitorPoll = function () {
      if (monitorPending) { return; }
      monitorPending = true;
      var startedAt = performance.now();
      fetch('/api/metrics?fresh=1', { cache: 'no-store', headers: { 'Accept': 'application/json' } })
        .then(function (response) {
          if (!response.ok) { throw new Error(response.status === 401 ? '登录状态已失效' : 'HTTP ' + response.status); }
          return response.json();
        })
        .then(function (data) {
          if (!data || !data.ok || !data.metrics) { throw new Error((data && data.message) || '监控接口响应异常'); }
          applyMonitorMetrics(data.metrics);
          var latency = Math.round(performance.now() - startedAt);
          setMonitorStatus('已更新 ' + (data.metrics.time || '') + ' · RTT ' + latency + 'ms', false);
        })
        .catch(function (error) {
          setMonitorStatus((error && error.message) ? '刷新失败：' + error.message : '刷新失败，保留上次数据', true);
        })
        .finally(function () { monitorPending = false; });
    };
    var monitorPollMs = Math.max(3, Number(window.TARSK_REFRESH_INTERVAL) || 3) * 1000;
    setInterval(monitorPoll, monitorPollMs);
    setTimeout(monitorPoll, 700);
  }
})();

/* -------------------------- 明暗主题切换 -------------------------- */
(function () {
  var root = document.documentElement;
  function apply(theme) {
    root.setAttribute('data-theme', theme);
    try { localStorage.setItem('tarsk-theme', theme); } catch (e) {}
    document.dispatchEvent(new CustomEvent('tarsk:theme', { detail: { theme: theme } }));
  }
  document.addEventListener('click', function (event) {
    var btn = event.target.closest && event.target.closest('[data-theme-toggle]');
    if (!btn) { return; }
    apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  });
})();
