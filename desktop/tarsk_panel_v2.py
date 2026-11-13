# -*- coding: utf-8 -*-
"""Tarsk Control Center - independent Windows desktop client (v2).
Fresh UI/client implementation. It does not import or wrap tarsk_console.py.
"""
from __future__ import annotations

import base64
import ctypes
import json
import os
import sys
import threading
import urllib.error
import urllib.request
import tkinter as tk
from ctypes import wintypes
from tkinter import ttk, messagebox
from tkinter import font as tkfont

APP_NAME = "Tarsk Control Center"
APP_DIR = os.path.join(os.environ.get("APPDATA", os.path.expanduser("~")), "TarskControlCenter")
CONFIG_FILE = os.path.join(APP_DIR, "connection.json")

# Google/Material-inspired desktop palette; independent of the Web panel theme.
C = {
    "bg": "#F8F9FA", "surface": "#FFFFFF", "surface2": "#F1F3F4",
    "line": "#DADCE0", "text": "#202124", "sub": "#5F6368", "muted": "#80868B",
    "brand": "#1A73E8", "brand_dark": "#1765CC", "brand_soft": "#E8F0FE",
    "ok": "#188038", "ok_soft": "#E6F4EA", "warn": "#B06000",
    "danger": "#D93025", "sidebar": "#FFFFFF", "sidebar2": "#E8F0FE",
    "side_text": "#3C4043", "side_muted": "#5F6368", "white": "#FFFFFF",
}
UI_FAMILY = "Roboto"
MONO_FAMILY = "Consolas"
FONT = (UI_FAMILY, 10)
FONT_B = (UI_FAMILY, 10, "bold")
FONT_TITLE = (UI_FAMILY, 20, "bold")
FONT_KICK = (MONO_FAMILY, 8, "bold")
MONO = (MONO_FAMILY, 9)


def resource_path(name: str) -> str:
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, name)


def configure_typography(root):
    """Prefer a native CJK UI face, then a readable Windows sans-serif fallback."""
    global UI_FAMILY, MONO_FAMILY, FONT, FONT_B, FONT_TITLE, FONT_KICK, MONO
    available = set(tkfont.families(root))
    UI_FAMILY = next((f for f in ("Roboto", "Segoe UI Variable", "Microsoft YaHei UI", "Microsoft YaHei", "Noto Sans SC", "Segoe UI", "Arial") if f in available), "TkDefaultFont")
    MONO_FAMILY = next((f for f in ("Cascadia Mono", "Consolas", "Courier New") if f in available), "TkFixedFont")
    FONT = (UI_FAMILY, 10)
    FONT_B = (UI_FAMILY, 10, "bold")
    FONT_TITLE = (UI_FAMILY, 20, "bold")
    FONT_KICK = (MONO_FAMILY, 8, "bold")
    MONO = (MONO_FAMILY, 9)
    root.option_add("*Font", FONT)


def enable_dpi_awareness():
    if os.name != "nt":
        return
    try:
        ctypes.windll.user32.SetProcessDpiAwarenessContext(ctypes.c_void_p(-4))  # Per-monitor v2
    except Exception:
        try:
            ctypes.windll.shcore.SetProcessDpiAwareness(2)
        except Exception:
            pass


class DATA_BLOB(ctypes.Structure):
    _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_byte))]


def _dpapi(data: bytes, protect: bool) -> bytes:
    """Encrypt/decrypt a connection token using the current Windows user profile."""
    crypt32 = ctypes.WinDLL("crypt32", use_last_error=True)
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    source_buf = ctypes.create_string_buffer(data)
    source = DATA_BLOB(len(data), ctypes.cast(source_buf, ctypes.POINTER(ctypes.c_byte)))
    target = DATA_BLOB()
    flags = 0x1  # CRYPTPROTECT_UI_FORBIDDEN
    if protect:
        fn = crypt32.CryptProtectData
        fn.argtypes = [ctypes.POINTER(DATA_BLOB), wintypes.LPCWSTR, ctypes.POINTER(DATA_BLOB),
                       ctypes.c_void_p, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(DATA_BLOB)]
        fn.restype = wintypes.BOOL
        ok = fn(ctypes.byref(source), "Tarsk Control Center API Token", None, None, None, flags, ctypes.byref(target))
    else:
        description = wintypes.LPWSTR()
        fn = crypt32.CryptUnprotectData
        fn.argtypes = [ctypes.POINTER(DATA_BLOB), ctypes.POINTER(wintypes.LPWSTR), ctypes.POINTER(DATA_BLOB),
                       ctypes.c_void_p, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(DATA_BLOB)]
        fn.restype = wintypes.BOOL
        ok = fn(ctypes.byref(source), ctypes.byref(description), None, None, None, flags, ctypes.byref(target))
        if description:
            kernel32.LocalFree(description)
    if not ok:
        raise OSError(ctypes.get_last_error(), "Windows DPAPI operation failed")
    try:
        return ctypes.string_at(target.pbData, target.cbData)
    finally:
        kernel32.LocalFree(target.pbData)


def load_connection() -> dict:
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as f:
            cfg = json.load(f)
        encoded = cfg.get("token_dpapi", "")
        if encoded:
            cfg["token"] = _dpapi(base64.b64decode(encoded), False).decode("utf-8")
        return cfg if isinstance(cfg, dict) else {}
    except Exception:
        return {}


def save_connection(url: str, token: str, remember: bool) -> None:
    os.makedirs(APP_DIR, exist_ok=True)
    data = {"url": url, "remember_token": bool(remember)}
    if remember and token:
        data["token_dpapi"] = base64.b64encode(_dpapi(token.encode("utf-8"), True)).decode("ascii")
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


class ApiClient:
    def __init__(self, base_url: str, token: str):
        self.base_url = base_url.strip().rstrip("/")
        self.token = token.strip()

    def call(self, method: str, path: str, payload=None):
        url = self.base_url + path
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8") if payload is not None else None
        req = urllib.request.Request(url, data=body, method=method.upper())
        req.add_header("Authorization", "Bearer " + self.token)
        req.add_header("Accept", "application/json")
        req.add_header("Cache-Control", "no-cache")
        if body is not None:
            req.add_header("Content-Type", "application/json; charset=utf-8")
        try:
            with urllib.request.urlopen(req, timeout=20) as response:
                result = json.loads(response.read().decode("utf-8", "replace"))
        except urllib.error.HTTPError as exc:
            try:
                result = json.loads(exc.read().decode("utf-8", "replace"))
                raise RuntimeError(result.get("message") or result.get("error") or f"HTTP {exc.code}")
            except (ValueError, json.JSONDecodeError):
                raise RuntimeError(f"HTTP {exc.code}: {exc.reason}")
        except urllib.error.URLError as exc:
            raise RuntimeError("无法连接面板：" + str(getattr(exc, "reason", exc)))
        if not isinstance(result, dict) or not result.get("ok"):
            raise RuntimeError((result or {}).get("message", "接口返回失败"))
        return result.get("data")


def size_text(value) -> str:
    try:
        n = float(value or 0)
    except (ValueError, TypeError):
        return "—"
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024 or unit == "TB":
            return f"{n:.1f} {unit}" if unit != "B" else f"{int(n)} B"
        n /= 1024
    return "—"


def uptime_text(value) -> str:
    try:
        days, rest = divmod(int(value or 0), 86400)
        hours, rest = divmod(rest, 3600)
        minutes = rest // 60
        return (f"{days}天 " if days else "") + f"{hours:02d}:{minutes:02d}"
    except (ValueError, TypeError):
        return "—"


class TarskPanel(tk.Tk):
    NAV = [
        ("WORKSPACE", [("overview", "⌂", "总览")]),
        ("SERVER", [("sites", "◈", "网站"), ("databases", "▤", "数据库"),
                    ("backups", "⇧", "备份中心"), ("tasks", "◷", "计划任务")]),
        ("INSIGHTS", [("alerts", "◇", "告警记录")]),
        ("CONFIGURATION", [("nginx", "⌘", "Nginx")]),
    ]

    def __init__(self):
        enable_dpi_awareness()
        super().__init__()
        self.overrideredirect(True)
        configure_typography(self)
        self.title(APP_NAME)
        self.geometry("1360x860")
        self.minsize(1080, 700)
        self.configure(bg=C["bg"])
        self._is_maximized = False
        self._normal_rect = None
        self._drag_origin = None
        self._resize_drag = None
        self._resize_cursor_restore = {}
        self.mascot_image = tk.PhotoImage(file=resource_path("tarsk-mascot.png"))
        self.mascot_brand = self.mascot_image.subsample(12, 12)
        self.mascot_title = self.mascot_image.subsample(24, 24)
        try:
            self.iconbitmap(resource_path("tarsk-mascot.ico"))
        except tk.TclError:
            pass
        self.client = None
        self.connection = load_connection()
        self.url_var = tk.StringVar(value="")
        self.token_var = tk.StringVar(value="")
        self.remember_var = tk.BooleanVar(value=bool(self.connection.get("remember_token", False)))
        self.current_page = "overview"
        self.rows = {"sites": {}, "tasks": {}}
        self._setup_styles()
        self._build_shell()
        self.update_idletasks()
        self._apply_borderless_style()
        self.bind_all("<ButtonPress-1>", self._resize_start, add="+")
        self.bind_all("<B1-Motion>", self._resize_move, add="+")
        self.bind_all("<ButtonRelease-1>", self._resize_end, add="+")
        self.bind_all("<Motion>", self._resize_hover, add="+")
        if self.connection.get("url"):
            self.url_var.set(self.connection["url"])
            self.token_var.set(self.connection.get("token", ""))
            if self.token_var.get():
                self.after(350, self.connect)
        self.show_page("overview")

    def _setup_styles(self):
        s = ttk.Style(self)
        try:
            s.theme_use("clam")
        except tk.TclError:
            pass
        s.configure("TFrame", background=C["bg"])
        s.configure("Surface.TFrame", background=C["surface"])
        s.configure("Surface2.TFrame", background=C["surface2"])
        s.configure("TLabel", background=C["bg"], foreground=C["text"], font=FONT)
        s.configure("Title.TLabel", font=FONT_TITLE, foreground=C["text"])
        s.configure("Kick.TLabel", font=FONT_KICK, foreground=C["muted"])
        s.configure("Sub.TLabel", foreground=C["sub"])
        s.configure("Muted.TLabel", foreground=C["muted"])
        s.configure("CardTitle.TLabel", font=(UI_FAMILY, 11, "bold"), foreground=C["text"])
        s.configure("Metric.TLabel", font=(UI_FAMILY, 22, "bold"), foreground=C["text"])
        s.configure("Treeview", background=C["surface"], fieldbackground=C["surface"],
                    foreground=C["text"], rowheight=36, borderwidth=0, font=(UI_FAMILY, 9))
        s.configure("Treeview.Heading", background=C["surface2"], foreground=C["sub"],
                    font=(UI_FAMILY, 9, "bold"), relief="flat", padding=(10, 9))
        s.map("Treeview", background=[("selected", C["brand_soft"])], foreground=[("selected", C["text"])])
        s.configure("Vertical.TScrollbar", background=C["surface2"], troughcolor=C["surface"], arrowcolor=C["muted"])
        s.configure("Primary.TButton", background=C["brand"], foreground=C["white"], padding=(15, 9),
                    borderwidth=0, font=(UI_FAMILY, 9, "bold"))
        s.map("Primary.TButton", background=[("active", C["brand_dark"]), ("disabled", C["line"])])
        s.configure("Quiet.TButton", background=C["surface"], foreground=C["sub"], padding=(13, 8),
                    borderwidth=1, font=(UI_FAMILY, 9, "bold"))
        s.map("Quiet.TButton", background=[("active", C["surface2"])])
        s.configure("Danger.TButton", background="#FCE8E6", foreground=C["danger"], padding=(13, 8), borderwidth=0)
        s.configure("TEntry", fieldbackground=C["surface"], foreground=C["text"], padding=8)
        s.configure("TCombobox", fieldbackground=C["surface"], foreground=C["text"], padding=7)

    def _build_shell(self):
        self.grid_columnconfigure(1, weight=1)
        self.grid_rowconfigure(1, weight=1)
        self._build_window_chrome()
        self.sidebar = tk.Frame(self, bg=C["sidebar"], width=238)
        self.sidebar.grid(row=1, column=0, sticky="ns")
        self.sidebar.grid_propagate(False)
        brand = tk.Frame(self.sidebar, bg=C["sidebar"])
        brand.pack(fill="x", padx=20, pady=(23, 28))
        mark = tk.Label(brand, image=self.mascot_brand, bg=C["sidebar"], bd=0)
        mark.pack(side="left")
        stack = tk.Frame(brand, bg=C["sidebar"])
        stack.pack(side="left", padx=11)
        tk.Label(stack, text="TARSK", bg=C["sidebar"], fg=C["text"], font=(UI_FAMILY, 14, "bold")).pack(anchor="w")
        self.nav_buttons = {}
        for group, items in self.NAV:
            tk.Label(self.sidebar, text=group, bg=C["sidebar"], fg=C["muted"],
                     font=(UI_FAMILY, 8, "bold"), padx=23).pack(anchor="w", pady=(14, 7))
            for key, icon, label in items:
                btn = tk.Button(self.sidebar, text=f"{icon}    {label}", anchor="w", relief="flat",
                                bd=0, padx=18, pady=11, cursor="hand2", bg=C["sidebar"], fg=C["side_text"],
                                activebackground=C["sidebar2"], activeforeground=C["brand"],
                                font=(UI_FAMILY, 10), command=lambda k=key: self.show_page(k))
                btn.pack(fill="x", padx=11, pady=2)
                self.nav_buttons[key] = btn
        tk.Frame(self.sidebar, bg=C["line"], height=1).pack(fill="x", side="bottom", padx=18, pady=(0, 16))
        self.sidebar_status = tk.Label(self.sidebar, text="●  未连接", bg=C["sidebar"], fg=C["side_muted"],
                                       anchor="w", padx=22, pady=0, font=(UI_FAMILY, 9))
        self.sidebar_status.pack(side="bottom", fill="x", pady=(0, 13))

        self.main = ttk.Frame(self)
        self.main.grid(row=1, column=1, sticky="nsew")
        self.main.grid_columnconfigure(0, weight=1)
        self.main.grid_rowconfigure(1, weight=1)
        header = tk.Frame(self.main, bg=C["surface"], height=82, highlightbackground=C["line"], highlightthickness=1)
        header.grid(row=0, column=0, sticky="ew")
        header.grid_propagate(False)
        left = tk.Frame(header, bg=C["surface"])
        left.pack(side="left", padx=28, pady=13)
        tk.Label(left, text="TARSK  /  OPERATIONS", bg=C["surface"], fg=C["muted"], font=FONT_KICK).pack(anchor="w")
        self.title_label = tk.Label(left, text="总览", bg=C["surface"], fg=C["text"], font=(UI_FAMILY, 17, "bold"))
        self.title_label.pack(anchor="w", pady=(2, 0))
        right = tk.Frame(header, bg=C["surface"])
        right.pack(side="right", padx=25, pady=21)
        self.busy_var = tk.StringVar(value="")
        tk.Label(right, textvariable=self.busy_var, bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 9)).pack(side="left", padx=12)
        self.connect_btn = ttk.Button(right, text="连接面板", style="Quiet.TButton", command=self.open_connection)
        self.connect_btn.pack(side="left", padx=(0, 8))
        ttk.Button(right, text="刷新数据", style="Primary.TButton", command=self.refresh_current).pack(side="left")
        self.host_var = tk.StringVar(value="尚未连接服务器")
        tk.Label(self.main, textvariable=self.host_var, bg=C["bg"], fg=C["muted"],
                 font=(UI_FAMILY, 9), anchor="w").grid(row=2, column=0, sticky="ew", padx=30, pady=(0, 10))
        self.content = ttk.Frame(self.main, padding=(25, 22, 25, 8))
        self.content.grid(row=1, column=0, sticky="nsew")
        self.content.grid_columnconfigure(0, weight=1)
        self.content.grid_rowconfigure(0, weight=1)
        self.pages = {}
        self._build_pages()

    def _build_window_chrome(self):
        self.chrome = tk.Frame(self, bg=C["surface"], height=46,
                               highlightbackground=C["line"], highlightthickness=1)
        self.chrome.grid(row=0, column=0, columnspan=2, sticky="ew")
        self.chrome.grid_propagate(False)
        controls = tk.Frame(self.chrome, bg=C["surface"])
        controls.place(x=15, rely=0.5, anchor="w")
        self.close_button = self._window_button(controls, "#FF5F57", "#E0443E", "×", self.destroy)
        self.min_button = self._window_button(controls, "#FEBC2E", "#D79816", "−", self._minimize_window)
        self.max_button = self._window_button(controls, "#28C840", "#1FA832", "+", self._toggle_maximize)
        for button in (self.close_button, self.min_button, self.max_button):
            button.pack(side="left", padx=(0, 7))
        self.title_box = tk.Frame(self.chrome, bg=C["surface"])
        self.title_box.place(relx=0.5, rely=0.5, anchor="center")
        self.chrome_icon = tk.Label(self.title_box, image=self.mascot_title, bg=C["surface"], bd=0)
        self.chrome_icon.pack(side="left", padx=(0, 7))
        self.chrome_title = tk.Label(self.title_box, text="Tarsk Control Center", bg=C["surface"],
                                     fg=C["sub"], font=(UI_FAMILY, 9, "bold"))
        self.chrome_title.pack(side="left")
        for widget in (self.chrome, self.title_box, self.chrome_icon, self.chrome_title):
            widget.bind("<ButtonPress-1>", self._start_window_drag)
            widget.bind("<B1-Motion>", self._move_window)
            widget.bind("<ButtonRelease-1>", self._end_window_drag)
            widget.bind("<Double-Button-1>", lambda _event: self._toggle_maximize())

    def _window_button(self, parent, color, hover_color, symbol, command):
        button = tk.Canvas(parent, width=14, height=14, bg=C["surface"], bd=0, highlightthickness=0,
                           cursor="hand2", takefocus=0)
        dot = button.create_oval(1, 1, 13, 13, fill=color, outline=color)
        glyph = button.create_text(7, 6.5, text="", fill="#202124", font=(UI_FAMILY, 7, "bold"))
        button.bind("<Enter>", lambda _e: (button.itemconfigure(dot, fill=hover_color, outline=hover_color),
                                            button.itemconfigure(glyph, text=symbol)))
        button.bind("<Leave>", lambda _e: (button.itemconfigure(dot, fill=color, outline=color),
                                            button.itemconfigure(glyph, text="")))
        button.bind("<ButtonRelease-1>", lambda _e: command())
        return button

    def _apply_borderless_style(self):
        if os.name != "nt":
            return
        try:
            user32 = ctypes.windll.user32
            hwnd = wintypes.HWND(self.winfo_id())
            get_long = user32.GetWindowLongPtrW
            set_long = user32.SetWindowLongPtrW
            get_long.argtypes = [wintypes.HWND, ctypes.c_int]
            get_long.restype = ctypes.c_ssize_t
            set_long.argtypes = [wintypes.HWND, ctypes.c_int, ctypes.c_ssize_t]
            set_long.restype = ctypes.c_ssize_t
            style = get_long(hwnd, -16)
            style = (style & ~0x00C00000) | 0x00040000 | 0x00020000 | 0x00010000 | 0x00080000
            set_long(hwnd, -16, style)
            ex_style = get_long(hwnd, -20)
            ex_style = (ex_style & ~0x00000080) | 0x00040000
            set_long(hwnd, -20, ex_style)
            user32.SetWindowPos(hwnd, None, 0, 0, 0, 0, 0x0001 | 0x0002 | 0x0004 | 0x0020)
            try:
                corner = ctypes.c_int(2)  # DWMWCP_ROUND on Windows 11
                ctypes.windll.dwmapi.DwmSetWindowAttribute(hwnd, 33, ctypes.byref(corner), ctypes.sizeof(corner))
            except Exception:
                pass
            self._hwnd = int(self.winfo_id())
        except Exception:
            self._hwnd = int(self.winfo_id())

    def _start_window_drag(self, event):
        if self._edge_at(event.x_root, event.y_root):
            return
        self._drag_origin = (event.x_root, event.y_root, self.winfo_x(), self.winfo_y(), self._is_maximized)

    def _move_window(self, event):
        if not self._drag_origin:
            return
        sx, sy, ox, oy, was_maximized = self._drag_origin
        if was_maximized and self._is_maximized:
            w, h, _, _ = self._normal_rect or (1360, 860, 80, 40)
            x, y = event.x_root - w // 2, event.y_root - 18
            self._is_maximized = False
            self._set_window_geometry(w, h, x, y)
            self._drag_origin = (event.x_root, event.y_root, x, y, False)
            return
        self.geometry(f"+{ox + event.x_root - sx}+{oy + event.y_root - sy}")

    def _end_window_drag(self, _event):
        self._drag_origin = None

    def _edge_at(self, screen_x, screen_y):
        if self._is_maximized:
            return ""
        x0, y0, w, h = self.winfo_rootx(), self.winfo_rooty(), self.winfo_width(), self.winfo_height()
        pad = 7
        left = screen_x - x0 < pad
        right = x0 + w - screen_x <= pad
        top = screen_y - y0 < pad
        bottom = y0 + h - screen_y <= pad
        return ("n" if top else "") + ("s" if bottom else "") + ("w" if left else "") + ("e" if right else "")

    def _resize_start(self, event):
        edge = self._edge_at(event.x_root, event.y_root)
        if not edge:
            return
        self._resize_drag = (edge, event.x_root, event.y_root, self.winfo_width(), self.winfo_height(),
                             self.winfo_rootx(), self.winfo_rooty())
        return "break"

    def _resize_move(self, event):
        if not self._resize_drag:
            return
        edge, sx, sy, width, height, x, y = self._resize_drag
        dx, dy = event.x_root - sx, event.y_root - sy
        min_w, min_h = self.minsize()
        new_w, new_h, new_x, new_y = width, height, x, y
        if "e" in edge:
            new_w = max(min_w, width + dx)
        if "s" in edge:
            new_h = max(min_h, height + dy)
        if "w" in edge:
            new_w = max(min_w, width - dx)
            new_x = x + width - new_w
        if "n" in edge:
            new_h = max(min_h, height - dy)
            new_y = y + height - new_h
        self._set_window_geometry(new_w, new_h, new_x, new_y)
        return "break"

    def _resize_end(self, _event):
        self._resize_drag = None

    def _resize_hover(self, event):
        edge = self._edge_at(event.x_root, event.y_root)
        cursors = {"e": "sb_h_double_arrow", "w": "sb_h_double_arrow", "n": "sb_v_double_arrow",
                   "s": "sb_v_double_arrow", "nw": "size_nw_se", "se": "size_nw_se",
                   "ne": "size_ne_sw", "sw": "size_ne_sw"}
        widget = event.widget
        try:
            if edge:
                if widget not in self._resize_cursor_restore:
                    self._resize_cursor_restore[widget] = widget.cget("cursor")
                widget.configure(cursor=cursors.get(edge, "sb_h_double_arrow"))
            elif widget in self._resize_cursor_restore:
                widget.configure(cursor=self._resize_cursor_restore.pop(widget))
        except tk.TclError:
            pass

    def _set_window_geometry(self, width, height, x, y):
        self.geometry(f"{int(width)}x{int(height)}{int(x):+d}{int(y):+d}")
        self.update_idletasks()

    def _toggle_maximize(self):
        if self._is_maximized:
            self._is_maximized = False
            w, h, x, y = self._normal_rect or (1360, 860, 80, 40)
            self._set_window_geometry(w, h, x, y)
            return
        self._normal_rect = (self.winfo_width(), self.winfo_height(), self.winfo_x(), self.winfo_y())
        class RECT(ctypes.Structure):
            _fields_ = [("left", ctypes.c_long), ("top", ctypes.c_long),
                        ("right", ctypes.c_long), ("bottom", ctypes.c_long)]
        rect = RECT()
        ok = False
        if os.name == "nt":
            try:
                ok = bool(ctypes.windll.user32.SystemParametersInfoW(0x0030, 0, ctypes.byref(rect), 0))
            except Exception:
                pass
        if ok:
            x, y, w, h = rect.left, rect.top, rect.right - rect.left, rect.bottom - rect.top
        else:
            x, y, w, h = 0, 0, self.winfo_screenwidth(), self.winfo_screenheight() - 48
        self._is_maximized = True
        self._set_window_geometry(w, h, x, y)

    def _minimize_window(self):
        if os.name == "nt":
            try:
                ctypes.windll.user32.ShowWindow(wintypes.HWND(self.winfo_id()), 6)
                return
            except Exception:
                pass
        self.iconify()

    def _build_pages(self):
        self._page_overview()
        self._page_sites()
        self._page_databases()
        self._page_backups()
        self._page_tasks()
        self._page_alerts()
        self._page_nginx()

    def _page(self, key):
        frame = ttk.Frame(self.content)
        frame.grid(row=0, column=0, sticky="nsew")
        frame.grid_remove()
        self.pages[key] = frame
        return frame

    def _intro(self, page, eyebrow, title, desc):
        box = ttk.Frame(page)
        box.pack(fill="x", pady=(1, 18))
        ttk.Label(box, text=eyebrow.upper(), style="Kick.TLabel").pack(anchor="w")
        ttk.Label(box, text=title, style="Title.TLabel").pack(anchor="w", pady=(4, 3))
        ttk.Label(box, text=desc, style="Sub.TLabel").pack(anchor="w")

    def _card(self, parent, title, desc=""):
        shell = tk.Frame(parent, bg=C["surface"], highlightbackground=C["line"], highlightthickness=1)
        head = tk.Frame(shell, bg=C["surface"])
        head.pack(fill="x", padx=17, pady=(13, 8))
        tk.Label(head, text=title, bg=C["surface"], fg=C["text"], font=(UI_FAMILY, 10, "bold")).pack(side="left")
        if desc:
            tk.Label(head, text=desc, bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 8)).pack(side="right")
        return shell

    def _table(self, parent, columns, headings, widths, height=12):
        wrap = tk.Frame(parent, bg=C["surface"])
        wrap.pack(fill="both", expand=True, padx=13, pady=(0, 13))
        tree = ttk.Treeview(wrap, columns=columns, show="headings", height=height, selectmode="browse")
        for col, head, width in zip(columns, headings, widths):
            tree.heading(col, text=head)
            tree.column(col, width=width, minwidth=48, anchor="w", stretch=True)
        scroll = ttk.Scrollbar(wrap, orient="vertical", command=tree.yview)
        tree.configure(yscrollcommand=scroll.set)
        tree.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")
        tree.tag_configure("running", foreground=C["ok"])
        tree.tag_configure("stopped", foreground=C["muted"])
        tree.tag_configure("danger", foreground=C["danger"])
        tree.tag_configure("warning", foreground=C["warn"])
        return tree

    def _page_overview(self):
        p = self._page("overview")
        self._intro(p, "Server snapshot", "服务器总览", "核心资源、运行状态与面板告警一屏查看。")
        grid = tk.Frame(p, bg=C["bg"])
        grid.pack(fill="x", pady=(0, 16))
        self.metric_vars = {}
        specs = [("CPU 使用率", "cpu", "—", C["brand"]), ("内存", "mem", "—", C["ok"]),
                 ("磁盘", "disk", "—", C["warn"]), ("运行时长", "uptime", "—", C["sidebar2"])]
        for i, (label, key, value, accent) in enumerate(specs):
            card = tk.Frame(grid, bg=C["surface"], highlightbackground=C["line"], highlightthickness=1)
            card.grid(row=0, column=i, sticky="nsew", padx=(0 if i == 0 else 9, 0), ipadx=12, ipady=10)
            grid.columnconfigure(i, weight=1)
            tk.Frame(card, bg=accent, width=3).pack(side="left", fill="y")
            inner = tk.Frame(card, bg=C["surface"])
            inner.pack(side="left", fill="both", expand=True, padx=14, pady=11)
            tk.Label(inner, text=label, bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 9)).pack(anchor="w")
            var = tk.StringVar(value=value)
            self.metric_vars[key] = var
            tk.Label(inner, textvariable=var, bg=C["surface"], fg=C["text"], font=(UI_FAMILY, 19, "bold")).pack(anchor="w", pady=(6, 0))
        row = tk.Frame(p, bg=C["bg"])
        row.pack(fill="both", expand=True)
        left = self._card(row, "主机状态", "LIVE STATUS")
        left.pack(side="left", fill="both", expand=True, padx=(0, 8))
        self.overview_host = tk.StringVar(value="连接面板后加载主机信息")
        self.overview_sites = tk.StringVar(value="—")
        self.overview_nginx = tk.StringVar(value="—")
        self.overview_version = tk.StringVar(value="—")
        for label, var in [("主机", self.overview_host), ("站点数量", self.overview_sites),
                           ("Nginx", self.overview_nginx), ("面板版本", self.overview_version)]:
            line = tk.Frame(left, bg=C["surface"])
            line.pack(fill="x", padx=18, pady=10)
            tk.Label(line, text=label, bg=C["surface"], fg=C["muted"], width=12, anchor="w").pack(side="left")
            tk.Label(line, textvariable=var, bg=C["surface"], fg=C["text"], font=FONT_B).pack(side="left")
        right = self._card(row, "最近告警", "LATEST EVENTS")
        right.pack(side="left", fill="both", expand=True, padx=(8, 0))
        self.tv_alerts_mini = self._table(right, ("time", "level", "title"), ("时间", "级别", "告警"), (145, 80, 230), 7)

    def _page_sites(self):
        p = self._page("sites")
        self._intro(p, "Web hosting", "网站", "查看站点、管理启停与反向代理；配置变更由服务器测试并回滚保护。")
        bar = tk.Frame(p, bg=C["bg"])
        bar.pack(fill="x", pady=(0, 10))
        ttk.Button(bar, text="站点详情 / 反向代理", style="Quiet.TButton", command=self.open_site_detail).pack(side="right", padx=(0, 8))
        ttk.Button(bar, text="启停所选站点", style="Primary.TButton", command=self.toggle_site).pack(side="right")
        card = self._card(p, "站点列表", "SELECT A SITE TO MANAGE")
        card.pack(fill="both", expand=True)
        self.tv_sites = self._table(card, ("id", "name", "domain", "status", "php", "port", "ssl", "proxy"),
                                    ("ID", "站点名称", "域名", "状态", "PHP", "端口", "SSL", "反代"),
                                    (50, 145, 245, 85, 80, 60, 55, 55), 15)

    def _page_databases(self):
        p = self._page("databases")
        self._intro(p, "Data services", "数据库", "只读浏览当前数据库实例信息；更多用户与 SQL 管理 API 尚待补齐。")
        card = self._card(p, "数据库清单", "READ ONLY")
        card.pack(fill="both", expand=True)
        self.tv_dbs = self._table(card, ("name", "tables", "bytes"), ("数据库", "表数量", "占用空间"), (330, 130, 160), 16)

    def _page_backups(self):
        p = self._page("backups")
        self._intro(p, "Recovery & retention", "备份中心", "创建站点或数据库备份，并查看服务器返回的备份清单。")
        action = self._card(p, "创建备份", "START A BACKUP JOB")
        action.pack(fill="x", pady=(0, 13))
        row = tk.Frame(action, bg=C["surface"])
        row.pack(fill="x", padx=16, pady=(0, 14))
        tk.Label(row, text="站点", bg=C["surface"], fg=C["sub"]).pack(side="left")
        self.site_backup_var = tk.StringVar()
        self.site_combo = ttk.Combobox(row, textvariable=self.site_backup_var, state="readonly", width=24)
        self.site_combo.pack(side="left", padx=(8, 18))
        ttk.Button(row, text="备份所选站点", style="Quiet.TButton", command=self.backup_site).pack(side="left")
        tk.Label(row, text="数据库", bg=C["surface"], fg=C["sub"]).pack(side="left", padx=(30, 0))
        self.db_backup_var = tk.StringVar()
        self.db_combo = ttk.Combobox(row, textvariable=self.db_backup_var, state="readonly", width=22)
        self.db_combo.pack(side="left", padx=8)
        ttk.Button(row, text="备份数据库", style="Primary.TButton", command=self.backup_db).pack(side="left", padx=5)
        card = self._card(p, "备份记录", "LATEST BACKUPS")
        card.pack(fill="both", expand=True)
        self.tv_backups = self._table(card, ("name", "kind", "target", "size", "time"),
                                      ("备份名称", "类型", "目标", "大小", "时间"), (300, 85, 100, 110, 170), 11)

    def _page_tasks(self):
        p = self._page("tasks")
        self._intro(p, "Automation", "计划任务", "查看任务状态或立即运行所选任务；新增/编辑任务 API 尚待补齐。")
        bar = tk.Frame(p, bg=C["bg"])
        bar.pack(fill="x", pady=(0, 10))
        ttk.Button(bar, text="立即执行所选", style="Primary.TButton", command=self.run_task).pack(side="right")
        card = self._card(p, "任务清单", "SCHEDULED TASKS")
        card.pack(fill="both", expand=True)
        self.tv_tasks = self._table(card, ("id", "name", "type", "schedule", "status", "last_run", "result"),
                                    ("ID", "任务名称", "类型", "周期", "状态", "上次执行", "结果"),
                                    (55, 180, 100, 130, 80, 155, 240), 14)

    def _page_alerts(self):
        p = self._page("alerts")
        self._intro(p, "Observability", "告警记录", "查看最近 100 条资源与服务告警。")
        card = self._card(p, "告警历史", "LATEST 100")
        card.pack(fill="both", expand=True)
        self.tv_alerts = self._table(card, ("time", "level", "title", "metric", "value", "threshold", "message"),
                                     ("时间", "级别", "事件", "指标", "当前值", "阈值", "详情"),
                                     (150, 80, 170, 95, 80, 80, 320), 15)

    def _page_nginx(self):
        p = self._page("nginx")
        self._intro(p, "Web server", "Nginx", "查看配置检测结果与服务状态；重载失败会按 API 错误提示。")
        card = self._card(p, "服务检查", "CONFIG VALIDATION")
        card.pack(fill="both", expand=True)
        row = tk.Frame(card, bg=C["surface"])
        row.pack(fill="x", padx=16, pady=(0, 12))
        self.nginx_state = tk.StringVar(value="尚未检测")
        tk.Label(row, textvariable=self.nginx_state, bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 11, "bold")).pack(side="left")
        ttk.Button(row, text="检测配置", style="Quiet.TButton", command=self.load_nginx).pack(side="right", padx=(8, 0))
        ttk.Button(row, text="重载 Nginx", style="Primary.TButton", command=self.reload_nginx).pack(side="right")
        self.nginx_output = tk.Text(card, height=18, wrap="word", bg=C["surface2"], fg=C["text"],
                                    font=MONO, relief="flat", padx=15, pady=14, insertbackground=C["text"])
        self.nginx_output.pack(fill="both", expand=True, padx=14, pady=(0, 14))
        self.nginx_output.insert("1.0", "连接面板后可检测 Nginx 配置。")
        self.nginx_output.configure(state="disabled")

    def show_page(self, key):
        if key not in self.pages:
            return
        self.current_page = key
        for name, page in self.pages.items():
            page.grid() if name == key else page.grid_remove()
        for name, button in self.nav_buttons.items():
            active = name == key
            button.configure(bg=C["sidebar2"] if active else C["sidebar"],
                             fg=C["brand"] if active else C["side_text"],
                             font=(UI_FAMILY, 10, "bold" if active else "normal"))
        self.title_label.configure(text={"overview":"总览", "sites":"网站", "databases":"数据库",
                                         "backups":"备份中心", "tasks":"计划任务", "alerts":"告警记录", "nginx":"Nginx"}[key])
        if self.client:
            self.refresh_current()

    def open_connection(self):
        dlg = tk.Toplevel(self)
        dlg.title("连接 Tarsk 面板")
        dlg.configure(bg=C["bg"])
        dlg.resizable(False, False)
        dlg.transient(self)
        dlg.grab_set()
        body = tk.Frame(dlg, bg=C["surface"], padx=24, pady=22, highlightbackground=C["line"], highlightthickness=1)
        body.pack(fill="both", expand=True, padx=16, pady=16)
        tk.Label(body, text="连接服务器", bg=C["surface"], fg=C["text"], font=(UI_FAMILY, 16, "bold")).pack(anchor="w")
        tk.Label(body, text="桌面端通过 HTTPS REST API 访问 Tarsk。", bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 9)).pack(anchor="w", pady=(4, 17))
        tk.Label(body, text="面板地址", bg=C["surface"], fg=C["sub"]).pack(anchor="w")
        url = ttk.Entry(body, textvariable=self.url_var, width=58)
        url.pack(fill="x", pady=(5, 12))
        tk.Label(body, text="API Token", bg=C["surface"], fg=C["sub"]).pack(anchor="w")
        token = ttk.Entry(body, textvariable=self.token_var, width=58, show="●")
        token.pack(fill="x", pady=(5, 10))
        self.remember_var = tk.BooleanVar(value=bool(self.connection.get("remember_token", False)))
        remember = tk.Checkbutton(body, text="在此 Windows 账户下安全记住 Token（DPAPI 加密）",
                                  variable=self.remember_var, bg=C["surface"], fg=C["sub"],
                                  activebackground=C["surface"], selectcolor=C["surface2"], font=(UI_FAMILY, 9))
        remember.pack(anchor="w", pady=(0, 12))
        tk.Label(body, text="建议使用 HTTPS；Token 权限请按最小必要原则设置。", bg=C["surface"], fg=C["warn"], font=(UI_FAMILY, 8)).pack(anchor="w")
        actions = tk.Frame(body, bg=C["surface"])
        actions.pack(fill="x", pady=(18, 0))
        ttk.Button(actions, text="取消", style="Quiet.TButton", command=dlg.destroy).pack(side="right", padx=(8, 0))
        ttk.Button(actions, text="连接并保存", style="Primary.TButton", command=lambda: (dlg.destroy(), self.connect())).pack(side="right")
        dlg.update_idletasks()
        dlg.geometry(f"{dlg.winfo_reqwidth()}x{dlg.winfo_reqheight()}+{self.winfo_rootx()+120}+{self.winfo_rooty()+100}")

    def connect(self):
        url, token = self.url_var.get().strip(), self.token_var.get().strip()
        if not url or not token:
            self.open_connection()
            return
        if not url.startswith(("https://", "http://")):
            messagebox.showwarning(APP_NAME, "面板地址须以 http:// 或 https:// 开头")
            return
        if url.startswith("http://") and not ("127.0.0.1" in url or "localhost" in url):
            if not messagebox.askyesno(APP_NAME, "当前使用非加密 HTTP，Token 会以明文传输。仍要继续吗？"):
                return
        client = ApiClient(url, token)
        self._async(client, "GET", "/api/v1/status", None,
                    lambda data: self._connected(client, data),
                    lambda error: self._show_error("连接失败", error), "正在验证面板凭据…")

    def _connected(self, client, status):
        self.client = client
        self.connection = {"url": client.base_url, "remember_token": bool(getattr(self, "remember_var", tk.BooleanVar(value=False)).get())}
        try:
            save_connection(client.base_url, client.token, self.connection["remember_token"])
        except Exception as exc:
            self._show_error("保存连接配置失败", str(exc))
        self.sidebar_status.configure(text="●  已连接", fg=C["ok"])
        host = status.get("hostname") or client.base_url
        self.host_var.set(f"已连接  /  {host}  /  面板 v{status.get('panel_version', '—')}")
        self.busy_var.set("连接正常")
        self._fill_overview(status)
        self.refresh_current()
        self.after(2600, lambda: self.busy_var.set("") if self.busy_var.get() == "连接正常" else None)

    def _async(self, client, method, path, payload, on_ok, on_error, busy="处理中…"):
        self.busy_var.set(busy)
        def run():
            try:
                result = client.call(method, path, payload)
                self.after(0, lambda: self._deliver(on_ok, result))
            except Exception as exc:
                message = str(exc)
                self.after(0, lambda: self._deliver(on_error, message))
        threading.Thread(target=run, daemon=True).start()

    def _deliver(self, callback, value):
        try:
            callback(value)
        finally:
            self.busy_var.set("")

    def _show_error(self, title, message):
        self.busy_var.set("请求失败")
        messagebox.showerror(title, message)

    def _require_client(self):
        if not self.client:
            messagebox.showinfo(APP_NAME, "请先连接一个 Tarsk 面板。")
            self.open_connection()
            return False
        return True

    def refresh_current(self):
        if not self.client:
            return
        loaders = {"overview": self.load_overview, "sites": self.load_sites,
                   "databases": self.load_databases, "backups": self.load_backups,
                   "tasks": self.load_tasks, "alerts": self.load_alerts, "nginx": self.load_nginx}
        loaders[self.current_page]()

    def _fill_overview(self, data):
        cpu = float(data.get("cpu", 0) or 0)
        self.metric_vars["cpu"].set(f"{cpu:.1f}%")
        mem_used, mem_total = int(data.get("mem_used", 0) or 0), int(data.get("mem_total", 0) or 0)
        disk_used, disk_total = int(data.get("disk_used", 0) or 0), int(data.get("disk_total", 0) or 0)
        self.metric_vars["mem"].set(f"{size_text(mem_used)} / {size_text(mem_total)}")
        self.metric_vars["disk"].set(f"{size_text(disk_used)} / {size_text(disk_total)}")
        self.metric_vars["uptime"].set(uptime_text(data.get("uptime")))
        self.overview_host.set(str(data.get("hostname") or "—"))
        self.overview_sites.set(str(data.get("sites", "—")))
        self.overview_nginx.set("运行中" if data.get("nginx_running") else "未运行")
        self.overview_version.set("v" + str(data.get("panel_version") or "—"))

    def load_overview(self):
        if self.client:
            self._async(self.client, "GET", "/api/v1/status", None, self._fill_overview,
                        lambda e: self._show_error("读取总览失败", e), "正在读取主机状态…")
            self.load_alerts(mini_only=True)

    def load_sites(self):
        if not self._require_client(): return
        def done(rows):
            self._set_rows(self.tv_sites, rows, lambda r: (r.get("id"), r.get("name"), r.get("domain"),
                "运行中" if r.get("status") == "running" else "已停止", r.get("php_version") or "—",
                r.get("port"), "已启用" if r.get("ssl_enabled") else "—",
                "已开启" if r.get("proxy_enabled") else "—"), "sites")
            self.rows["sites"] = {str(r.get("id")): r for r in rows or []}
            self.site_combo["values"] = [f"{r.get('id')} · {r.get('name')}" for r in rows or []]
            if rows and not self.site_backup_var.get(): self.site_backup_var.set(self.site_combo["values"][0])
        self._async(self.client, "GET", "/api/v1/sites", None, done,
                    lambda e: self._show_error("读取网站失败", e), "正在读取网站列表…")

    def toggle_site(self):
        if not self._require_client(): return
        sel = self.tv_sites.selection()
        if not sel: return messagebox.showinfo(APP_NAME, "请先选择一个站点。")
        sid = str(self.tv_sites.item(sel[0], "values")[0])
        site = self.rows["sites"].get(sid, {})
        if not messagebox.askyesno("确认站点启停", f"确认切换“{site.get('name', sid)}”的运行状态？\n操作会修改服务器 Nginx 配置。"):
            return
        self._async(self.client, "POST", f"/api/v1/sites/{sid}/toggle", None,
                    lambda data: (self.load_sites(), messagebox.showinfo(APP_NAME, data.get("message", "状态已更新"))),
                    lambda e: self._show_error("站点操作失败", e), "正在应用站点配置…")

    def open_site_detail(self):
        if not self._require_client(): return
        sel = self.tv_sites.selection()
        if not sel: return messagebox.showinfo(APP_NAME, "请先选择一个站点。")
        sid = str(self.tv_sites.item(sel[0], "values")[0])
        self._async(self.client, "GET", f"/api/v1/sites/{sid}", None,
                    lambda site: self._site_detail_dialog(site),
                    lambda e: self._show_error("读取站点详情失败", e), "正在读取站点详情…")

    def _site_detail_dialog(self, site):
        dlg = tk.Toplevel(self)
        dlg.title("站点详情 · " + str(site.get("name", "")))
        dlg.configure(bg=C["bg"])
        dlg.resizable(False, False)
        dlg.transient(self)
        dlg.grab_set()
        body = tk.Frame(dlg, bg=C["surface"], padx=24, pady=20, highlightbackground=C["line"], highlightthickness=1)
        body.pack(fill="both", expand=True, padx=14, pady=14)
        tk.Label(body, text=str(site.get("name", "站点")), bg=C["surface"], fg=C["text"],
                 font=(UI_FAMILY, 16, "bold")).pack(anchor="w")
        tk.Label(body, text=f"{site.get('domain') or '未绑定域名'}    ·    {site.get('path') or ''}",
                 bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 9)).pack(anchor="w", pady=(4, 16))
        facts = tk.Frame(body, bg=C["surface2"], padx=12, pady=10)
        facts.pack(fill="x", pady=(0, 16))
        tk.Label(facts, text=f"状态  {site.get('status', '—')}     PHP  {site.get('php_version') or '默认'}     端口  {site.get('port', '—')}",
                 bg=C["surface2"], fg=C["sub"], font=(UI_FAMILY, 9)).pack(anchor="w")
        tk.Label(facts, text="HTTPS  已启用" if site.get("ssl_enabled") else "HTTPS  未启用（证书签发/绑定尚未接入桌面端）",
                 bg=C["surface2"], fg=C["ok"] if site.get("ssl_enabled") else C["muted"],
                 font=(UI_FAMILY, 9)).pack(anchor="w", pady=(7, 0))
        tk.Label(body, text="反向代理", bg=C["surface"], fg=C["text"], font=(UI_FAMILY, 10, "bold")).pack(anchor="w")
        enabled = tk.BooleanVar(master=dlg, value=bool(site.get("proxy_enabled")))
        target_var = tk.StringVar(master=dlg, value=str(site.get("proxy_pass") or ""))
        target = ttk.Entry(body, textvariable=target_var, width=60)
        target.pack(fill="x", pady=(8, 7))
        check = tk.Checkbutton(body, text="将该站点请求转发到代理目标", variable=enabled,
                               bg=C["surface"], fg=C["sub"], activebackground=C["surface"],
                               selectcolor=C["surface2"], font=(UI_FAMILY, 9),
                               command=lambda: target.configure(state="normal" if enabled.get() else "disabled"))
        check.pack(anchor="w")
        target.configure(state="normal" if enabled.get() else "disabled")
        tk.Label(body, text="示例：http://127.0.0.1:3000。保存前服务器会执行 Nginx 配置测试；失败时不会更新站点状态。",
                 bg=C["surface"], fg=C["muted"], font=(UI_FAMILY, 8), wraplength=470, justify="left").pack(anchor="w", pady=(8, 0))
        actions = tk.Frame(body, bg=C["surface"])
        actions.pack(fill="x", pady=(18, 0))
        ttk.Button(actions, text="关闭", style="Quiet.TButton", command=dlg.destroy).pack(side="right", padx=(8, 0))
        ttk.Button(actions, text="应用代理设置", style="Primary.TButton",
                   command=lambda: self._save_site_proxy(dlg, site, enabled.get(), target_var.get())).pack(side="right")
        dlg.update_idletasks()
        dlg.geometry(f"{dlg.winfo_reqwidth()}x{dlg.winfo_reqheight()}+{self.winfo_rootx()+100}+{self.winfo_rooty()+80}")

    def _save_site_proxy(self, dialog, site, enabled, target):
        if not self._require_client(): return
        if enabled and not target.strip():
            return messagebox.showwarning(APP_NAME, "请填写代理目标地址。")
        label = "启用" if enabled else "关闭"
        if not messagebox.askyesno("确认反向代理", f"确定{label}“{site.get('name')}”的反向代理配置？服务器会测试并重载 Nginx。"):
            return
        payload = {"enabled": bool(enabled), "target": target.strip()}
        self._async(self.client, "POST", f"/api/v1/sites/{site['id']}/proxy", payload,
                    lambda data: (dialog.destroy(), self.load_sites(), messagebox.showinfo(APP_NAME, data.get("message", "代理配置已应用"))),
                    lambda e: self._show_error("代理配置未应用", e), "正在测试并应用代理配置…")

    def load_databases(self):
        if not self._require_client(): return
        def done(rows):
            self._set_rows(self.tv_dbs, rows, lambda r: (r.get("name"), r.get("tables", 0), size_text(r.get("bytes"))))
            names = [str(r.get("name")) for r in rows or []]
            self.db_combo["values"] = names
            if names and not self.db_backup_var.get(): self.db_backup_var.set(names[0])
        self._async(self.client, "GET", "/api/v1/databases", None, done,
                    lambda e: self._show_error("读取数据库失败", e), "正在读取数据库…")

    def load_backups(self):
        if not self._require_client(): return
        self.load_sites()
        self.load_databases()
        self._async(self.client, "GET", "/api/v1/backups", None,
                    lambda rows: self._set_rows(self.tv_backups, rows, lambda r: (r.get("name"), r.get("kind"), r.get("target", ""), size_text(r.get("size")), r.get("time", ""))),
                    lambda e: self._show_error("读取备份失败", e), "正在读取备份清单…")

    def backup_site(self):
        if not self._require_client(): return
        val = self.site_backup_var.get().strip()
        if not val: return messagebox.showinfo(APP_NAME, "当前没有可选站点。")
        site_id = val.split("·", 1)[0].strip()
        self._start_backup("/api/v1/backups/site", {"site_id": int(site_id)}, "站点备份")

    def backup_db(self):
        if not self._require_client(): return
        db = self.db_backup_var.get().strip()
        if not db: return messagebox.showinfo(APP_NAME, "请先选择数据库。")
        self._start_backup("/api/v1/backups/db", {"database": db}, "数据库备份")

    def _start_backup(self, path, payload, label):
        if not messagebox.askyesno("确认创建备份", f"现在创建{label}？大型站点可能需要一些时间。"):
            return
        self._async(self.client, "POST", path, payload,
                    lambda data: (self.load_backups(), messagebox.showinfo(APP_NAME, data.get("message", "备份任务已完成"))),
                    lambda e: self._show_error(label + "失败", e), "正在创建备份…")

    def load_tasks(self):
        if not self._require_client(): return
        def done(rows):
            self._set_rows(self.tv_tasks, rows, lambda r: (r.get("id"), r.get("name"), r.get("type"),
                r.get("cron_expr") or r.get("schedule"), r.get("status"), r.get("last_run_at") or "—", r.get("last_result") or "—"), "tasks")
            self.rows["tasks"] = {str(r.get("id")): r for r in rows or []}
        self._async(self.client, "GET", "/api/v1/tasks", None, done,
                    lambda e: self._show_error("读取计划任务失败", e), "正在读取任务…")

    def run_task(self):
        if not self._require_client(): return
        sel = self.tv_tasks.selection()
        if not sel: return messagebox.showinfo(APP_NAME, "请先选择一个任务。")
        task_id = str(self.tv_tasks.item(sel[0], "values")[0])
        task = self.rows["tasks"].get(task_id, {})
        if not messagebox.askyesno("确认运行任务", f"立即运行“{task.get('name', task_id)}”？"):
            return
        self._async(self.client, "POST", f"/api/v1/tasks/{task_id}/run", None,
                    lambda data: (self.load_tasks(), messagebox.showinfo(APP_NAME, data.get("message", "任务已执行"))),
                    lambda e: self._show_error("任务执行失败", e), "正在执行计划任务…")

    def load_alerts(self, mini_only=False):
        if not self._require_client(): return
        def done(rows):
            values = lambda r: (r.get("created_at"), r.get("level"), r.get("title"), r.get("metric"), r.get("value"), r.get("threshold"), r.get("message"))
            if not mini_only:
                self._set_rows(self.tv_alerts, rows, values)
            self._set_rows(self.tv_alerts_mini, rows[:8] if rows else [], lambda r: (r.get("created_at"), r.get("level"), r.get("title")))
        self._async(self.client, "GET", "/api/v1/alerts?limit=100", None, done,
                    lambda e: self._show_error("读取告警失败", e), "正在读取告警…")

    def load_nginx(self):
        if not self._require_client(): return
        self._async(self.client, "GET", "/api/v1/nginx", None, self._show_nginx,
                    lambda e: self._show_error("读取 Nginx 状态失败", e), "正在检测 Nginx 配置…")

    def _show_nginx(self, data):
        running = bool(data.get("running"))
        self.nginx_state.set("● 运行中" if running else "● 目前未运行")
        test = data.get("test") or {}
        message = test.get("message", "") if isinstance(test, dict) else str(test)
        result = "配置检测通过" if isinstance(test, dict) and test.get("ok") else (message or "尚无配置检测结果")
        self.nginx_output.configure(state="normal")
        self.nginx_output.delete("1.0", "end")
        self.nginx_output.insert("1.0", f"状态：{'运行中' if running else '未运行'}\n\n{result}")
        self.nginx_output.configure(state="disabled")

    def reload_nginx(self):
        if not self._require_client(): return
        if not messagebox.askyesno("确认重载", "重载 Nginx 前服务器会再次校验配置。确定继续？"):
            return
        self._async(self.client, "POST", "/api/v1/nginx/reload", None,
                    lambda data: (self.load_nginx(), messagebox.showinfo(APP_NAME, data.get("message", "Nginx 已重载"))),
                    lambda e: self._show_error("Nginx 重载失败", e), "正在安全重载 Nginx…")

    @staticmethod
    def _set_rows(tree, rows, mapper, key=None):
        tree.delete(*tree.get_children())
        for index, row in enumerate(rows or []):
            values = mapper(row)
            tag = ""
            if key == "sites": tag = str(row.get("status", ""))
            elif key == "tasks":
                result = str(row.get("last_result") or "").lower()
                tag = "running" if result in ("ok", "success", "成功") else ("danger" if result else "")
            elif key is None:
                level = str(row.get("level") or "").lower()
                tag = "danger" if level == "danger" else ("warning" if level == "warning" else "")
            tree.insert("", "end", values=values, tags=(tag,) if tag else ())


if __name__ == "__main__":
    TarskPanel().mainloop()
