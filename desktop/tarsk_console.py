# -*- coding: utf-8 -*-
"""
Tarsk 桌面控制台（Windows · 无边框 macOS 风格玻璃版 v2）
参考 Contigo 爬虫监控台的做法：渐变光晕 + 半透明玻璃卡片全部在 ui_bg.png 里用 PIL 合成，
窗口 Canvas 只画动态内容（状态文字、导航高亮），业务控件浮在玻璃区域上。
对接 Tarsk 面板 REST API（/api/v1，Bearer Token），与 Web 端共用同一数据后端，双端互通。
运行时纯标准库；PIL 仅在打包阶段生成资源（desktop/make_assets.py）。
"""
import json
import os
import sys
import threading
import tkinter as tk
import urllib.error
import urllib.request
from tkinter import ttk, messagebox

APP_NAME = "Tarsk 控制台"
CONFIG_DIR = os.path.join(os.environ.get("APPDATA", os.path.expanduser("~")), "Tarsk")
CONFIG_FILE = os.path.join(CONFIG_DIR, "console.json")

WIN_W, WIN_H, WIN_R = 1180, 760, 16
KEY = "#FF00FE"          # 透明抠图色

# 布局（与 make_assets.py 的玻璃区域严格对应）
SB = (20, 60, 220, 736)        # 侧边栏玻璃
CN = (236, 54, 1160, 118)      # 连接条玻璃
CT = (236, 130, 1160, 736)     # 内容玻璃
LIGHT_X = (23, 49, 75)         # 窗控圆心 x
PILL = (1000, 12, 1148, 34)    # 状态药丸

# 色板（与烘焙玻璃的实测合成色对齐：sidebar #2e3040 / connect #1d2233 / content #1a2033）
INK = "#eaf0ff"
SUB = "#9aa8d4"
DIM = "#6b7694"
ACCENT = "#7d8bff"
TEAL = "#5ce0c8"
PINK = "#ff7b8a"
YELLOW = "#febc2e"
PAGE_BG = "#1a2033"       # 内容页背景（= 玻璃合成色，无缝）
TILE = "#212840"          # 指标小卡
TILE_EDGE = "#323d63"
TABLE = "#1a2033"         # 表格背景（与页背景一致，无缝）
ROW_ALT = "#1e2539"
HEAD = "#262e49"
INPUT = "#101526"
SEL = "#2c3a6e"

FONT = ("Microsoft YaHei", 9)
FONT_B = ("Microsoft YaHei", 9, "bold")
FONT_T = ("Microsoft YaHei", 15, "bold")
FONT_NUM = ("Consolas", 13, "bold")

NAV_ITEMS = [("overview", "总览"), ("sites", "站点"), ("databases", "数据库"),
             ("backups", "备份"), ("tasks", "计划任务"), ("alerts", "告警"),
             ("nginx", "Nginx")]

class _NullWriter(object):
    def write(self, _s): return 0
    def flush(self): pass
    def close(self): pass
    def isatty(self): return False

# ---------------- 配置持久化 ----------------

def load_config() -> dict:
    try:
        with open(CONFIG_FILE, encoding="utf-8") as f:
            cfg = json.load(f)
        if isinstance(cfg, dict):
            return cfg
    except Exception:
        pass
    return {}

def save_config(cfg: dict) -> None:
    try:
        os.makedirs(CONFIG_DIR, exist_ok=True)
        with open(CONFIG_FILE, "w", encoding="utf-8") as f:
            json.dump(cfg, f, ensure_ascii=False, indent=2)
    except Exception:
        pass

# ---------------- 资源加载 ----------------

def asset_dir():
    here = os.path.dirname(os.path.abspath(__file__))
    return getattr(sys, "_MEIPASS", here)

# ---------------- API 客户端 ----------------

class ApiClient:
    def __init__(self, base: str, token: str):
        self.base = base.rstrip("/")
        self.token = token

    def call(self, method: str, path: str, payload=None):
        url = self.base + path
        data = json.dumps(payload).encode("utf-8") if payload is not None else None
        req = urllib.request.Request(url, data=data, method=method)
        req.add_header("Authorization", "Bearer " + self.token)
        req.add_header("Accept", "application/json")
        if data:
            req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                body = json.loads(resp.read().decode("utf-8", "replace"))
        except urllib.error.HTTPError as e:
            try:
                body = json.loads(e.read().decode("utf-8", "replace"))
                raise RuntimeError(body.get("message") or body.get("error") or f"HTTP {e.code}")
            except (ValueError, json.JSONDecodeError):
                raise RuntimeError(f"HTTP {e.code} {e.reason}")
        if not body.get("ok"):
            raise RuntimeError(body.get("message") or body.get("error") or "接口返回失败")
        return body.get("data")

def api_async(widget, client, method, path, payload, on_ok, on_err, busy_var=None, busy_text="加载中…"):
    root = widget.winfo_toplevel()
    if busy_var is not None:
        busy_var.set(busy_text)
    def run():
        try:
            result = client.call(method, path, payload)
            root.after(0, lambda: (on_ok(result), _busy_clear(busy_var)))
        except Exception as e:
            msg = str(e)
            root.after(0, lambda: (on_err(msg), _busy_clear(busy_var)))
    threading.Thread(target=run, daemon=True).start()

def _busy_clear(var):
    try:
        var.set("")
    except Exception:
        pass

def human_size(n) -> str:
    try:
        n = float(n)
    except (TypeError, ValueError):
        return "-"
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024:
            return f"{n:.1f} {unit}" if unit != "B" else f"{int(n)} B"
        n /= 1024
    return f"{n:.1f} PB"

def human_uptime(sec) -> str:
    try:
        sec = int(sec)
    except (TypeError, ValueError):
        return "-"
    d, sec = divmod(sec, 86400)
    h, sec = divmod(sec, 3600)
    m, _ = divmod(sec, 60)
    return (f"{d}天 " if d else "") + f"{h:02d}:{m:02d}"

# ---------------- 主应用 ----------------

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title(APP_NAME)
        self.overrideredirect(True)
        sw, sh = self.winfo_screenwidth(), self.winfo_screenheight()
        self.geometry(f"{WIN_W}x{WIN_H}+{max(0, (sw - WIN_W) // 2)}+{max(0, (sh - WIN_H) // 2)}")
        self.configure(bg=KEY)
        self.wm_attributes("-transparentcolor", KEY)
        self._minimized = False
        self._minimizing = False
        self._drag = None
        self.client = None
        self._site_rows = {}
        self._task_rows = {}
        self.cfg = load_config()
        self.var_url = tk.StringVar()
        self.var_token = tk.StringVar()
        self.var_busy = tk.StringVar(value="")
        self.var_state = tk.StringVar(value="未连接")
        self._load_assets()

        self.cv = tk.Canvas(self, width=WIN_W, height=WIN_H, bg=KEY, highlightthickness=0, bd=0)
        self.cv.pack(fill="both", expand=True)
        if self.img_bg is not None:
            self.cv.create_image(0, 0, image=self.img_bg, anchor="nw", tags="shell")
            self.cv.tag_lower("shell")

        self._taskbar_entry()
        self._set_window_icon()
        self._setup_styles()
        self._bind_titlebar()
        self._build_sidebar()
        self._build_connect()
        self._build_pages()
        self._bind_window_hooks()
        self.show_page("overview")

        self.var_url.set(self.cfg.get("url", "http://127.0.0.1:8905"))
        self.var_token.set(self.cfg.get("token", ""))
        if self.var_token.get():
            self.after(200, self.connect)

    # ---- 资源 ----
    def _load_assets(self):
        # 冻结后资源在 _MEIPASS 根；源码运行在 desktop/assets/
        d = asset_dir()
        dirs = [d, os.path.join(d, "assets")]
        self.img_bg = None
        self.btn_imgs = {}
        self.img_nav = None
        try:
            for base in dirs:
                p = os.path.join(base, "ui_bg.png")
                if os.path.exists(p) and self.img_bg is None:
                    self.img_bg = tk.PhotoImage(file=p)
                p = os.path.join(base, "nav_active.png")
                if os.path.exists(p) and self.img_nav is None:
                    self.img_nav = tk.PhotoImage(file=p)
                if os.path.isdir(base):
                    for f in os.listdir(base):
                        if f.startswith("btn_") and f.endswith(".png") and f[4:-4] not in self.btn_imgs:
                            self.btn_imgs[f[4:-4]] = tk.PhotoImage(file=os.path.join(base, f))
        except Exception:
            self.img_bg = None

    # ---- 任务栏 / 图标 ----
    def _taskbar_entry(self):
        try:
            import ctypes
            self.update_idletasks()
            hwnd = ctypes.windll.user32.GetAncestor(self.winfo_id(), 2)
            GWL_EXSTYLE, WS_EX_APPWINDOW, WS_EX_TOOLWINDOW = -20, 0x00040000, 0x00000080
            get_l = ctypes.windll.user32.GetWindowLongPtrW
            set_l = ctypes.windll.user32.SetWindowLongPtrW
            get_l.argtypes = [ctypes.c_void_p, ctypes.c_int]
            get_l.restype = ctypes.c_long
            set_l.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.c_long]
            set_l.restype = ctypes.c_long
            ex = get_l(hwnd, GWL_EXSTYLE)
            want = (ex & ~WS_EX_TOOLWINDOW) | WS_EX_APPWINDOW
            u = ctypes.windll.user32
            u.ShowWindow(hwnd, 0)
            set_l(hwnd, GWL_EXSTYLE, want)
            u.ShowWindow(hwnd, 5)
            u.SetWindowPos(hwnd, None, 0, 0, 0, 0, 0x0067)
        except Exception:
            pass

    def _set_window_icon(self):
        here = os.path.dirname(os.path.abspath(__file__))
        base = asset_dir()
        for cand in (os.path.join(base, "app-icon.ico"), os.path.join(here, "app-icon.ico")):
            if os.path.exists(cand):
                try:
                    self.iconbitmap(cand)
                except Exception:
                    pass
                break

    def _bind_window_hooks(self):
        self.bind("<Map>", self._on_map)

    def _on_map(self, e=None):
        if self._minimizing:
            return
        if self._minimized:
            self._minimized = False
            self.after(12, self._restore_frameless)

    def _restore_frameless(self):
        self.overrideredirect(True)
        self.wm_attributes("-transparentcolor", KEY)
        self._taskbar_entry()

    # ---- 标题栏（烘焙在底图里，这里只挂事件与动态内容） ----
    def _bind_titlebar(self):
        # 窗控圆点（实绘，支持 hover；不能用 KEY 命中区，会把烘焙圆点抠透明）
        self._lights = []
        for cx, col, cb in ((LIGHT_X[0], "#ff5f57", self._on_close),
                            (LIGHT_X[1], "#febc2e", self.minimize),
                            (LIGHT_X[2], "#28c840", self._toggle_topmost)):
            oid = self.cv.create_oval(cx - 7, 15, cx + 7, 29, fill=col,
                                      outline="#000000", width=0, tags=("light",))
            self.cv.tag_bind(oid, "<Button-1>", lambda e, f=cb: f())
            self.cv.tag_bind(oid, "<Enter>", lambda e, o=oid, c=col: self.cv.itemconfig(
                o, outline="#eaf0ff"))
            self.cv.tag_bind(oid, "<Leave>", lambda e, o=oid: self.cv.itemconfig(o, outline="#000000"))
            self._lights.append(oid)
        # 状态文字（药丸底已烘焙）
        self.txt_state = self.cv.create_text((PILL[0] + PILL[2]) // 2, 23,
                                             text="未连接", fill=SUB, font=FONT_B)
        self._state_color = SUB
        self.var_state.trace_add("write", lambda *_: (
            self.cv.itemconfig(self.txt_state, text=self.var_state.get()),
            self.cv.itemconfig(self.txt_state, fill=self._state_color)))
        # 拖动
        self.cv.bind("<Button-1>", self._drag_start)
        self.cv.bind("<B1-Motion>", self._drag_move)

    def _drag_start(self, e):
        if e.y > 44 or e.x < 92 or e.x > WIN_W - 170:
            return
        self._drag = (e.x_root, e.y_root, self.winfo_x(), self.winfo_y())

    def _drag_move(self, e):
        if not self._drag:
            return
        x0, y0, wx, wy = self._drag
        self.geometry("+%d+%d" % (wx + e.x_root - x0, wy + e.y_root - y0))

    def minimize(self):
        self._minimized = True
        self._minimizing = True
        self.overrideredirect(False)
        self.iconify()
        self.after(150, lambda: setattr(self, "_minimizing", False))

    def _toggle_topmost(self):
        self._top = not getattr(self, "_top", False)
        self.attributes("-topmost", self._top)
        self._flash("已置顶" if self._top else "取消置顶")

    def _on_close(self):
        self.destroy()

    def _flash(self, s):
        self.var_busy.set(s)
        self.after(1600, lambda: self.var_busy.set("") if self.var_busy.get() == s else None)

    # ---- 样式 ----
    def _setup_styles(self):
        style = ttk.Style(self)
        try:
            style.theme_use("clam")
        except Exception:
            pass
        style.configure("Dark.Treeview", background=TABLE, fieldbackground=TABLE,
                        foreground="#d5ddf5", rowheight=30, font=FONT, borderwidth=0)
        style.configure("Dark.Treeview.Heading", background=HEAD, foreground="#93a3d0",
                        font=FONT_B, borderwidth=0, relief="flat")
        style.map("Dark.Treeview", background=[("selected", SEL)],
                  foreground=[("selected", INK)])
        style.configure("TScrollbar", background=HEAD, troughcolor=TABLE,
                        bordercolor=TABLE, arrowcolor=SUB)
        style.map("TScrollbar", background=[("active", SEL)])
        style.configure("TCombobox", fieldbackground=INPUT, background=HEAD,
                        foreground=INK, arrowcolor=SUB, bordercolor=TILE_EDGE,
                        insertcolor=INK, selectbackground=SEL, selectforeground=INK)
        style.map("TCombobox", fieldbackground=[("readonly", INPUT)],
                  selectbackground=[("readonly", INPUT)], selectforeground=[("readonly", INK)])

    # ---- 侧边导航（直接画在根 Canvas 上，玻璃底在烘焙底图中） ----
    def _build_sidebar(self):
        x0, y0, x1, y1 = SB
        self.side = self.cv
        self._nav_ids = {}
        self._active = None
        if self.img_nav is not None and getattr(self, "_nav_pill_id", None) is None:
            self._nav_pill_id = self.cv.create_image(0, 0, image=self.img_nav,
                                                     anchor="nw", state="hidden")
        for i, (key, label) in enumerate(NAV_ITEMS):
            cy = y0 + 24 + i * 44
            t = self.cv.create_text(x0 + 26, cy, text=label, anchor="w", fill=SUB,
                                    font=("Microsoft YaHei", 10, "bold"),
                                    tags=("nav", key), activefill="#ffffff")
            self.cv.tag_bind(t, "<Button-1>", lambda e, k=key: self.show_page(k))
            self._nav_ids[key] = (i, cy, t)
        # 底部状态灯 + busy 文字
        self.dot = self.cv.create_oval(x0 + 14, y1 - 40, x0 + 24, y1 - 30,
                                       fill=DIM, outline="", tags=("dot",))
        self.txt_busy = self.cv.create_text(x0 + 34, y1 - 35, text="就绪", anchor="w",
                                            fill=DIM, font=("Microsoft YaHei", 8),
                                            width=x1 - x0 - 44)
        self.var_busy.trace_add("write", lambda *_: self.cv.itemconfig(
            self.txt_busy, text=self.var_busy.get() or "就绪"))

    def show_page(self, key):
        self._active = key
        for k, (i, cy, tid) in self._nav_ids.items():
            self.cv.itemconfig(tid, fill="#ffffff" if k == key else SUB)
        # 高亮药丸：移到当前项位置
        if getattr(self, "_nav_pill_id", None) is not None:
            self.cv.coords(self._nav_pill_id, SB[0] + 10, self._nav_ids[key][1] - 18)
            self.cv.itemconfig(self._nav_pill_id, state="normal")
        for k, page in self._pages.items():
            if k == key:
                page.place(relx=0, rely=0, relwidth=1, relheight=1)
                page.lift()
            else:
                page.place_forget()

    # ---- 连接条（玻璃底已烘焙，控件浮上） ----
    def _build_connect(self):
        x0, y0, x1, y1 = CN
        bar = tk.Frame(self.cv, bg=PAGE_BG, bd=0, highlightthickness=0)
        self.cv.create_window(x0, y0, window=bar, width=x1 - x0, height=y1 - y0, anchor="nw")
        inner = tk.Frame(bar, bg=PAGE_BG)
        inner.place(x=14, y=(y1 - y0 - 34) // 2, relwidth=1.0, width=-28, height=34)
        tk.Label(inner, text="面板地址", bg=PAGE_BG, fg=SUB, font=FONT).pack(side="left")
        self.ent_url = tk.Entry(inner, textvariable=self.var_url, width=24, bg=INPUT, fg=INK,
                                insertbackground=INK, relief="flat", bd=0, font=FONT,
                                highlightthickness=1, highlightbackground=TILE_EDGE,
                                highlightcolor=ACCENT)
        self.ent_url.pack(side="left", padx=(8, 14), ipady=5)
        tk.Label(inner, text="API Token", bg=PAGE_BG, fg=SUB, font=FONT).pack(side="left")
        self.ent_token = tk.Entry(inner, textvariable=self.var_token, width=22, show="•", bg=INPUT,
                                  fg=INK, insertbackground=INK, relief="flat", bd=0, font=FONT,
                                  highlightthickness=1, highlightbackground=TILE_EDGE,
                                  highlightcolor=ACCENT)
        self.ent_token.pack(side="left", padx=8, ipady=5)
        self.ent_token.bind("<Return>", lambda e: self.connect())
        self.btn_connect = self._img_btn(inner, "连接", self.connect)
        self.btn_connect.pack(side="left", padx=14)
        tk.Label(inner, text="Token 在面板「API Token」页创建", bg=PAGE_BG, fg=DIM,
                 font=("Microsoft YaHei", 8)).pack(side="left")

    def _img_btn(self, parent, text, cmd):
        """药丸图片按钮；资源缺失时回落到普通按钮。"""
        img = self.btn_imgs.get(text)
        if img is None:
            return tk.Button(parent, text=text, command=cmd, bg=ACCENT, fg="#ffffff",
                             relief="flat", bd=0, font=FONT_B, padx=18, pady=6, cursor="hand2")
        lb = tk.Label(parent, image=img, bg=PAGE_BG, cursor="hand2")
        lb.bind("<Button-1>", lambda e: cmd())
        return lb

    # ---- 内容页 ----
    def _build_pages(self):
        x0, y0, x1, y1 = CT
        self.container = tk.Frame(self.cv, bg=PAGE_BG, bd=0, highlightthickness=0)
        self.cv.create_window(x0, y0, window=self.container, width=x1 - x0,
                              height=y1 - y0, anchor="nw")
        self._pages = {}
        self._page_overview()
        self._page_sites()
        self._page_databases()
        self._page_backups()
        self._page_tasks()
        self._page_alerts()
        self._page_nginx()

    def _page_frame(self):
        return tk.Frame(self.container, bg=PAGE_BG)

    def _page_title(self, page, text, actions=()):
        head = tk.Frame(page, bg=PAGE_BG)
        head.pack(fill="x", pady=(16, 8), padx=20)
        col = tk.Frame(head, bg=PAGE_BG)
        col.pack(side="left")
        tk.Label(col, text=text, bg=PAGE_BG, fg=INK, font=FONT_T).pack(anchor="w")
        tk.Frame(col, bg=ACCENT, width=30, height=3).pack(anchor="w", pady=(4, 0))
        for label, cmd, style in actions:
            b = self._img_btn(head, label, cmd) if style == "primary" else self._img_btn(head, label, cmd)
            b.pack(side="left", padx=(10, 0))

    def _card(self, parent, bg=TILE):
        return tk.Frame(parent, bg=bg, bd=0, highlightthickness=1, highlightbackground=TILE_EDGE)

    def _tree(self, parent, cols, heads, widths, height=10):
        wrap = tk.Frame(parent, bg=TABLE)
        tv = ttk.Treeview(wrap, columns=cols, show="headings", height=height,
                          style="Dark.Treeview")
        for c, h, w in zip(cols, heads, widths):
            tv.heading(c, text=h)
            tv.column(c, width=w, anchor="w")
        sb = ttk.Scrollbar(wrap, orient="vertical", command=tv.yview)
        tv.configure(yscrollcommand=sb.set)
        tv.pack(side="left", fill="both", expand=True)
        sb.pack(side="right", fill="y")
        return tv

    def _zebra(self, tv, count):
        for iid in tv.get_children():
            tv.item(iid, tags=("even",) if (tv.index(iid) % 2 == 0) else ("odd",))
        tv.tag_configure("even", background=TABLE)
        tv.tag_configure("odd", background=ROW_ALT)

    # 总览
    def _page_overview(self):
        page = self._page_frame()
        self._pages["overview"] = page
        self._page_title(page, "总览", (("刷新", self.load_overview, "dark"),))
        grid = tk.Frame(page, bg=PAGE_BG)
        grid.pack(fill="x", padx=20)
        self.metric_vars = {}
        specs = [("主机", "hostname", INK, ACCENT), ("CPU 使用率", "cpu", ACCENT, ACCENT),
                 ("内存", "mem", ACCENT, TEAL), ("磁盘", "disk", ACCENT, TEAL),
                 ("运行时长", "uptime", TEAL, TEAL), ("站点数", "sites", TEAL, ACCENT),
                 ("Nginx", "nginx", TEAL, TEAL), ("面板版本", "panel_version", SUB, PINK)]
        for i, (label, key, color, bar) in enumerate(specs):
            cell = tk.Frame(grid, bg=PAGE_BG)
            cell.grid(row=i // 4, column=i % 4, sticky="nsew", padx=5, pady=5)
            card = self._card(cell)
            card.pack(fill="both", expand=True)
            tk.Frame(card, bg=bar, width=3).pack(side="left", fill="y", padx=(0, 0))
            inner = tk.Frame(card, bg=TILE)
            inner.pack(side="left", fill="both", expand=True)
            tk.Label(inner, text=label, bg=TILE, fg=SUB, font=FONT).pack(anchor="w", padx=14, pady=(10, 0))
            v = tk.StringVar(value="-")
            self.metric_vars[key] = v
            tk.Label(inner, textvariable=v, bg=TILE, fg=color, font=FONT_NUM).pack(anchor="w", padx=14, pady=(2, 12))
        for c in range(4):
            grid.columnconfigure(c, weight=1)
        card = self._card(page, bg=TABLE)
        card.pack(fill="both", expand=True, padx=20, pady=(8, 18))
        head = tk.Frame(card, bg=TABLE)
        head.pack(fill="x", padx=14, pady=(10, 4))
        tk.Label(head, text="最近告警", bg=TABLE, fg=INK, font=FONT_B).pack(side="left")
        self.tv_alerts_mini = self._tree(card, ("time", "level", "title", "message"),
                                         ("时间", "级别", "标题", "内容"), (150, 70, 200, 420))
        self.tv_alerts_mini.master.pack(fill="both", expand=True, padx=10, pady=(0, 12))

    # 站点
    def _page_sites(self):
        page = self._page_frame()
        self._pages["sites"] = page
        self._page_title(page, "站点",
                         (("刷新", self.load_sites, "dark"),
                          ("启停所选", self.toggle_site, "primary")))
        tv = self._tree(page, ("id", "name", "domain", "status", "php", "port", "ssl"),
                        ("ID", "站点名", "域名", "状态", "PHP", "端口", "SSL"),
                        (50, 160, 260, 90, 90, 70, 60), height=13)
        tv.master.pack(fill="both", expand=True, padx=20, pady=(6, 18))
        self.tv_sites = tv
        tv.tag_configure("running", foreground=TEAL)
        tv.tag_configure("stopped", foreground=PINK)

    # 数据库
    def _page_databases(self):
        page = self._page_frame()
        self._pages["databases"] = page
        self._page_title(page, "数据库", (("刷新", self.load_databases, "dark"),))
        tv = self._tree(page, ("name", "tables", "bytes"), ("数据库", "表数量", "大小"),
                        (300, 120, 160), height=14)
        tv.master.pack(fill="both", expand=True, padx=20, pady=(6, 18))
        self.tv_dbs = tv

    # 备份
    def _page_backups(self):
        page = self._page_frame()
        self._pages["backups"] = page
        self._page_title(page, "备份",
                         (("刷新", self.load_backups, "dark"),
                          ("备份所选站点", self.backup_site, "primary"),
                          ("备份数据库", self.backup_db, "primary")))
        bar = tk.Frame(page, bg=PAGE_BG)
        bar.pack(fill="x", padx=20, pady=(2, 4))
        tk.Label(bar, text="选择数据库", bg=PAGE_BG, fg=SUB, font=FONT).pack(side="left")
        self.var_db_backup = tk.StringVar()
        self.cmb_db = ttk.Combobox(bar, textvariable=self.var_db_backup, width=22, state="readonly")
        self.cmb_db.pack(side="left", padx=10, ipady=3)
        tv = self._tree(page, ("name", "kind", "target", "size", "time"),
                        ("文件名", "类型", "目标", "大小", "时间"), (330, 70, 90, 100, 170), height=12)
        tv.master.pack(fill="both", expand=True, padx=20, pady=(6, 18))
        self.tv_backups = tv

    # 计划任务
    def _page_tasks(self):
        page = self._page_frame()
        self._pages["tasks"] = page
        self._page_title(page, "计划任务",
                         (("刷新", self.load_tasks, "dark"),
                          ("立即执行所选", self.run_task, "primary")))
        tv = self._tree(page, ("id", "name", "type", "schedule", "status", "last_run", "last_result"),
                        ("ID", "名称", "类型", "周期", "状态", "上次执行", "结果"),
                        (50, 170, 90, 110, 70, 140, 230), height=13)
        tv.master.pack(fill="both", expand=True, padx=20, pady=(6, 18))
        self.tv_tasks = tv
        tv.tag_configure("ok", foreground=TEAL)
        tv.tag_configure("fail", foreground=PINK)

    # 告警
    def _page_alerts(self):
        page = self._page_frame()
        self._pages["alerts"] = page
        self._page_title(page, "告警", (("刷新", self.load_alerts, "dark"),))
        tv = self._tree(page, ("time", "level", "title", "metric", "value", "threshold", "message"),
                        ("时间", "级别", "标题", "指标", "当前值", "阈值", "内容"),
                        (150, 60, 150, 90, 80, 70, 330), height=13)
        tv.master.pack(fill="both", expand=True, padx=20, pady=(6, 18))
        self.tv_alerts = tv
        tv.tag_configure("danger", foreground=PINK)
        tv.tag_configure("warning", foreground=YELLOW)

    # Nginx
    def _page_nginx(self):
        page = self._page_frame()
        self._pages["nginx"] = page
        self._page_title(page, "Nginx",
                         (("检测配置 (nginx -t)", self.nginx_test, "dark"),
                          ("重载 Nginx", self.nginx_reload, "primary")))
        bar = tk.Frame(page, bg=PAGE_BG)
        bar.pack(fill="x", padx=20, pady=(2, 4))
        self.var_nginx_state = tk.StringVar(value="-")
        tk.Label(bar, textvariable=self.var_nginx_state, bg=PAGE_BG, fg=INK, font=FONT_B).pack(side="left")
        card = self._card(page, bg=INPUT)
        card.pack(fill="both", expand=True, padx=20, pady=(6, 18))
        self.txt_nginx = tk.Text(card, wrap="word", relief="flat", bg=INPUT, fg="#d5ddf5",
                                 font=("Consolas", 10), padx=14, pady=12, height=6,
                                 insertbackground=INK, selectbackground=SEL, bd=0,
                                 highlightthickness=0)
        self.txt_nginx.pack(fill="both", expand=True, padx=8, pady=8)

    # ---------------- 动作 ----------------

    def _require_client(self) -> bool:
        if self.client is None:
            messagebox.showinfo(APP_NAME, "请先填写面板地址与 API Token 并连接。")
            return False
        return True

    def connect(self):
        url = self.var_url.get().strip()
        token = self.var_token.get().strip()
        if not url or not token:
            messagebox.showwarning(APP_NAME, "面板地址与 Token 不能为空")
            return
        self.client = ApiClient(url, token)
        self.btn_connect.config(state="disabled") if isinstance(self.btn_connect, tk.Button) else None
        self._state_color = YELLOW
        self.var_state.set("连接中…")

        def ok(data):
            if isinstance(self.btn_connect, tk.Button):
                self.btn_connect.config(state="normal")
            self._state_color = TEAL
            self.var_state.set(f"已连接 · v{data.get('panel_version', '?')}")
            self.side.itemconfig(self.dot, fill=TEAL)
            save_config({"url": url, "token": token})
            self._fill_overview(data)
            self.load_sites()
            self.load_databases()
            self.load_backups()
            self.load_tasks()
            self.load_alerts()
            self.load_nginx()

        def err(msg):
            if isinstance(self.btn_connect, tk.Button):
                self.btn_connect.config(state="normal")
            self.client = None
            self._state_color = PINK
            self.var_state.set("连接失败")
            self.side.itemconfig(self.dot, fill=PINK)
            messagebox.showerror(APP_NAME, "连接失败：\n" + msg)

        api_async(self, self.client, "GET", "/api/v1/status", None, ok, err,
                  self.var_busy, "正在连接面板…")

    def _fill_overview(self, data):
        m = self.metric_vars
        m["hostname"].set(str(data.get("hostname", "-")))
        m["cpu"].set(f"{float(data.get('cpu', 0)):.1f}%")
        mu, mt = int(data.get("mem_used", 0)), int(data.get("mem_total", 0))
        m["mem"].set(f"{human_size(mu)} / {human_size(mt)}")
        du, dt = int(data.get("disk_used", 0)), int(data.get("disk_total", 0))
        m["disk"].set(f"{human_size(du)} / {human_size(dt)}")
        m["uptime"].set(human_uptime(data.get("uptime")))
        m["sites"].set(str(data.get("sites", 0)))
        running = bool(data.get("nginx_running"))
        m["nginx"].set("运行中" if running else "未运行")
        m["panel_version"].set("v" + str(data.get("panel_version", "-")))

    def load_overview(self):
        if not self._require_client():
            return
        api_async(self, self.client, "GET", "/api/v1/status", None,
                  self._fill_overview, lambda m: None, self.var_busy, "刷新状态…")

    def load_sites(self):
        if not self._require_client():
            return
        tv = self.tv_sites
        def ok(rows):
            tv.delete(*tv.get_children())
            self._site_rows.clear()
            for r in rows or []:
                iid = tv.insert("", "end", values=(
                    r.get("id"), r.get("name"), r.get("domain"),
                    "运行中" if r.get("status") == "running" else "已停止",
                    r.get("php_version") or "-", r.get("port"), "是" if r.get("ssl_enabled") else "否"),
                    tags=(r.get("status") or "",))
                self._site_rows[iid] = r
            self.load_overview()
        api_async(self, self.client, "GET", "/api/v1/sites", None, ok,
                  lambda m: messagebox.showerror(APP_NAME, "读取站点失败：\n" + m),
                  self.var_busy, "读取站点…")

    def toggle_site(self):
        if not self._require_client():
            return
        sel = self.tv_sites.selection()
        if not sel:
            messagebox.showinfo(APP_NAME, "请先选择站点")
            return
        site = self._site_rows.get(sel[0])
        if not site:
            return
        api_async(self, self.client, "POST", f"/api/v1/sites/{site['id']}/toggle", None,
                  lambda d: (messagebox.showinfo(APP_NAME, d.get("message") or "已切换"), self.load_sites()),
                  lambda m: messagebox.showerror(APP_NAME, "切换失败：\n" + m),
                  self.var_busy, "切换站点状态…")

    def load_databases(self):
        if not self._require_client():
            return
        tv = self.tv_dbs
        def ok(rows):
            tv.delete(*tv.get_children())
            names = []
            for r in rows or []:
                tv.insert("", "end", values=(r.get("name"), r.get("tables"), human_size(r.get("bytes"))))
                names.append(r.get("name"))
            self._zebra(tv, len(names))
            self.cmb_db["values"] = names
            if names and not self.var_db_backup.get():
                self.var_db_backup.set(names[0])
        api_async(self, self.client, "GET", "/api/v1/databases", None, ok,
                  lambda m: messagebox.showerror(APP_NAME, "读取数据库失败：\n" + m),
                  self.var_busy, "读取数据库…")

    def load_backups(self):
        if not self._require_client():
            return
        tv = self.tv_backups
        def ok(rows):
            tv.delete(*tv.get_children())
            for r in rows or []:
                tv.insert("", "end", values=(r.get("name"), r.get("kind"), r.get("target"),
                                             human_size(r.get("size")), r.get("time")))
            self._zebra(tv, len(rows or []))
        api_async(self, self.client, "GET", "/api/v1/backups", None, ok,
                  lambda m: messagebox.showerror(APP_NAME, "读取备份失败：\n" + m),
                  self.var_busy, "读取备份…")

    def backup_site(self):
        if not self._require_client():
            return
        sel = self.tv_sites.selection()
        if not sel:
            messagebox.showinfo(APP_NAME, "请先在「站点」页选择要备份的站点")
            return
        site = self._site_rows.get(sel[0])
        if not site:
            return
        api_async(self, self.client, "POST", "/api/v1/backups/site", {"site_id": site["id"]},
                  lambda d: (messagebox.showinfo(APP_NAME, (d or {}).get("message") or "备份完成"), self.load_backups()),
                  lambda m: messagebox.showerror(APP_NAME, "备份失败：\n" + m),
                  self.var_busy, f"备份站点 {site['name']}…")

    def backup_db(self):
        if not self._require_client():
            return
        db = self.var_db_backup.get().strip()
        if not db:
            messagebox.showinfo(APP_NAME, "请先选择数据库")
            return
        api_async(self, self.client, "POST", "/api/v1/backups/db", {"database": db},
                  lambda d: (messagebox.showinfo(APP_NAME, (d or {}).get("message") or "备份完成"), self.load_backups()),
                  lambda m: messagebox.showerror(APP_NAME, "备份失败：\n" + m),
                  self.var_busy, f"备份数据库 {db}…")

    def load_tasks(self):
        if not self._require_client():
            return
        tv = self.tv_tasks
        def ok(rows):
            tv.delete(*tv.get_children())
            self._task_rows.clear()
            for r in rows or []:
                result = str(r.get("last_result") or "")
                tag = "ok" if result.lower() in ("ok", "success", "成功") else ("fail" if result else "")
                iid = tv.insert("", "end", values=(
                    r.get("id"), r.get("name"), r.get("type"), r.get("schedule") or r.get("cron_expr"),
                    r.get("status"), r.get("last_run_at") or "-", result), tags=(tag,))
                self._task_rows[iid] = r
            self._zebra(tv, len(rows or []))
        api_async(self, self.client, "GET", "/api/v1/tasks", None, ok,
                  lambda m: messagebox.showerror(APP_NAME, "读取任务失败：\n" + m),
                  self.var_busy, "读取计划任务…")

    def run_task(self):
        if not self._require_client():
            return
        sel = self.tv_tasks.selection()
        if not sel:
            messagebox.showinfo(APP_NAME, "请先选择任务")
            return
        task = self._task_rows.get(sel[0])
        if not task:
            return
        api_async(self, self.client, "POST", f"/api/v1/tasks/{task['id']}/run", None,
                  lambda d: (messagebox.showinfo(APP_NAME, (d or {}).get("message") or "已执行"), self.load_tasks()),
                  lambda m: messagebox.showerror(APP_NAME, "执行失败：\n" + m),
                  self.var_busy, f"执行任务 {task['name']}…")

    def load_alerts(self):
        if not self._require_client():
            return
        tv = self.tv_alerts
        mini = self.tv_alerts_mini
        def ok(rows):
            tv.delete(*tv.get_children())
            mini.delete(*mini.get_children())
            for i, r in enumerate(rows or []):
                tv.insert("", "end", values=(r.get("created_at"), r.get("level"), r.get("title"),
                                             r.get("metric"), r.get("value"), r.get("threshold"),
                                             r.get("message")),
                          tags=(str(r.get("level") or ""),))
                if i < 8:
                    mini.insert("", "end", values=(r.get("created_at"), r.get("level"),
                                                   r.get("title"), r.get("message")),
                                tags=(str(r.get("level") or ""),))
            self._zebra(tv, len(rows or []))
            self._zebra(mini, len(rows or []) if rows else 0)
        api_async(self, self.client, "GET", "/api/v1/alerts?limit=100", None, ok,
                  lambda m: messagebox.showerror(APP_NAME, "读取告警失败：\n" + m),
                  self.var_busy, "读取告警…")

    def load_nginx(self):
        if not self._require_client():
            return
        api_async(self, self.client, "GET", "/api/v1/nginx", None, self._show_nginx,
                  lambda m: messagebox.showerror(APP_NAME, "读取 Nginx 状态失败：\n" + m),
                  self.var_busy, "检测 Nginx…")

    def _show_nginx(self, data):
        running = bool(data.get("running"))
        self.var_nginx_state.set("● 运行中" if running else "● 未运行")
        self.var_nginx_state.config(fg=TEAL if running else PINK)
        self.txt_nginx.delete("1.0", "end")
        self.txt_nginx.insert("1.0", str(data.get("test") or ""))

    def nginx_test(self):
        if not self._require_client():
            return
        api_async(self, self.client, "GET", "/api/v1/nginx", None, self._show_nginx,
                  lambda m: messagebox.showerror(APP_NAME, "检测失败：\n" + m),
                  self.var_busy, "nginx -t 检测中…")

    def nginx_reload(self):
        if not self._require_client():
            return
        api_async(self, self.client, "POST", "/api/v1/nginx/reload", None,
                  lambda d: (messagebox.showinfo(APP_NAME, d.get("message") or "已重载"), self.load_nginx()),
                  lambda m: messagebox.showerror(APP_NAME, "重载失败：\n" + m),
                  self.var_busy, "重载 Nginx…")

def main():
    try:
        app = App()
        app.mainloop()
    except Exception:
        import traceback
        detail = traceback.format_exc()
        try:
            with open(os.path.join(CONFIG_DIR, "console-error.log"), "w", encoding="utf-8") as f:
                f.write(detail)
        except Exception:
            pass
        if sys.stdout is None:
            sys.stdout = _NullWriter()
        if sys.stderr is None:
            sys.stderr = _NullWriter()
        print(detail)
        root = tk.Tk()
        root.withdraw()
        messagebox.showerror(APP_NAME + " · 启动失败", detail)

if __name__ == "__main__":
    main()
