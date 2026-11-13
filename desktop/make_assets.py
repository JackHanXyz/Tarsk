# -*- coding: utf-8 -*-
"""生成 Tarsk 控制台的 UI 资源：整张玻璃底图 + 药丸按钮贴图（打包时执行一次）。"""
import math
import os
import random

from PIL import Image, ImageDraw, ImageFilter, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
ASSETS = os.path.join(HERE, "assets")
W, H, R = 1180, 760, 16

# 玻璃区域（与 tarsk_console.py 的布局严格对应）
GLASS_SIDEBAR = (20, 60, 220, 736)
GLASS_CONNECT = (236, 54, 1160, 118)
GLASS_CONTENT = (236, 130, 1160, 736)


def _font(size, bold=False):
    for name in (("msyhbd.ttc", "msyh.ttc") if bold else ("msyh.ttc",)):
        p = os.path.join("C:/Windows/Fonts", name)
        if os.path.exists(p):
            try:
                return ImageFont.truetype(p, size)
            except Exception:
                pass
    return ImageFont.load_default()


def _text_size(f, text):
    try:
        return f.getbbox(text)
    except AttributeError:   # Pillow<8.0
        w, h = f.getsize(text)
        return (0, 0, w, h)


def _rrect(d, box, r, fill=None, outline=None, width=1):
    """Pillow<8.2 兼容圆角矩形。"""
    x0, y0, x1, y1 = box
    if fill:
        d.rectangle((x0 + r, y0, x1 - r, y1), fill=fill)
        d.rectangle((x0, y0 + r, x1, y1 - r), fill=fill)
    if outline:
        d.rectangle((x0 + r, y0, x1 - r, y0 + width - 1), fill=outline)
        d.rectangle((x0 + r, y1 - width + 1, x1 - r, y1), fill=outline)
        d.rectangle((x0, y0 + r, x0 + width - 1, y1 - r), fill=outline)
        d.rectangle((x1 - width + 1, y0 + r, x1, y1 - r), fill=outline)
        d.arc((x0, y0, x0 + 2 * r, y0 + 2 * r), 180, 270, fill=outline, width=width)
        d.arc((x1 - 2 * r, y0, x1, y0 + 2 * r), 270, 360, fill=outline, width=width)
        d.arc((x0, y1 - 2 * r, x0 + 2 * r, y1), 90, 180, fill=outline, width=width)
        d.arc((x1 - 2 * r, y1 - 2 * r, x1, y1), 0, 90, fill=outline, width=width)


def _vgradient(w, h, top, bottom):
    im = Image.new("RGBA", (w, h))
    row = Image.new("RGBA", (w, 1))
    for y in range(h):
        t = y / max(1, h - 1)
        c = tuple(int(a + (b - a) * t) for a, b in zip(top, bottom)) + (255,)
        row.putpixel((0, 0), c)
        # 整行同色：用 putdata 一次性
        row.putdata([c] * w)
        im.paste(row, (0, y))
    return im


def _glows(im):
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(glow)
    d.ellipse((-300, -260, 420, 340), fill=(92, 224, 200, 30))
    d.ellipse((W - 460, 160, W + 180, 720), fill=(125, 139, 255, 36))
    d.ellipse((-240, H - 400, 380, H + 140), fill=(255, 123, 138, 26))
    d.ellipse((300, -200, 900, 200), fill=(125, 139, 255, 14))
    return Image.alpha_composite(im, glow.filter(ImageFilter.GaussianBlur(130)))


def _glass(im, box, r=14, alpha=20, border=48, top_glow=90):
    """半透明白玻璃卡片：填充 + 描边 + 顶边高光（全部走 alpha_composite，避免与透明色混合）。"""
    x0, y0, x1, y1 = box
    ov = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)
    _rrect(d, box, r, fill=(255, 255, 255, alpha), outline=(255, 255, 255, border), width=1)
    im.alpha_composite(ov)
    hl = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    dh = ImageDraw.Draw(hl)
    dh.line((x0 + r, y0 + 1, x1 - r, y0 + 1), fill=(255, 255, 255, top_glow), width=1)
    im.alpha_composite(hl)


def _deco(im):
    """标题栏不透明装饰：logo、标题、状态药丸底。"""
    ov = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)
    d.ellipse((96, 9, 122, 35), fill=(125, 139, 255, 255))
    f_logo = _font(14, bold=True)
    tb = _text_size(f_logo, "T")
    d.text((109 - (tb[2] - tb[0]) / 2, 22 - (tb[3] - tb[1]) / 2 - tb[1]), "T",
           font=f_logo, fill=(255, 255, 255, 255))
    d.text((132, 13), "Tarsk 控制台", font=_font(15, bold=True), fill=(234, 240, 255, 255))
    _rrect(d, (1000, 12, 1148, 34), 11, fill=(54, 62, 92, 255), outline=(120, 132, 170, 255), width=1)
    im.alpha_composite(ov)


def make_bg():
    im = _vgradient(W, H, (10, 14, 28), (17, 24, 48))
    im = _glows(im)
    # 细噪点，让玻璃有磨砂质感
    noise = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    nd = ImageDraw.Draw(noise)
    rnd = random.Random(42)
    for _ in range(2600):
        x, y = rnd.randrange(W), rnd.randrange(H)
        nd.point((x, y), fill=(255, 255, 255, rnd.randrange(4, 10)))
    noise = noise.filter(ImageFilter.GaussianBlur(0.6))
    im = Image.alpha_composite(im, noise)

    # 三块玻璃卡
    _glass(im, GLASS_SIDEBAR, alpha=22, border=52)
    _glass(im, GLASS_CONNECT, alpha=16, border=44)
    _glass(im, GLASS_CONTENT, alpha=12, border=40)
    _deco(im)

    # 圆角窗口 + 描边
    mask = Image.new("L", (W, H), 0)
    _rrect(ImageDraw.Draw(mask), (0, 0, W - 1, H - 1), R, fill=255)
    out = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    out.paste(im, (0, 0), mask)
    d = ImageDraw.Draw(out)
    _rrect(d, (0, 0, W - 1, H - 1), R, outline=(49, 66, 122, 255), width=1)
    out.save(os.path.join(ASSETS, "ui_bg.png"))
    # 采样玻璃内部合成色，供控件底色对齐
    for name, (px, py) in (("sidebar", (120, 400)), ("connect", (700, 86)), ("content", (700, 400))):
        print("glass %s => #%02x%02x%02x" % ((name,) + out.convert("RGB").getpixel((px, py))))
    return out


def _pill(text, style, height=32):
    f = _font(12, bold=True)
    bb = _text_size(f, text)
    tw = bb[2] - bb[0]
    w = tw + 40
    r = height // 2
    im = Image.new("RGBA", (w, height), (0, 0, 0, 0))
    if style == "primary":
        c0, c1 = (139, 152, 255, 255), (100, 112, 240, 255)
    elif style == "danger":
        c0, c1 = (255, 130, 140, 255), (240, 100, 115, 255)
    else:  # dark
        c0, c1 = (44, 53, 88, 255), (33, 40, 68, 255)
    row = Image.new("RGBA", (w, 1))
    for y in range(height):
        t = y / (height - 1)
        c = tuple(int(a + (b - a) * t) for a, b in zip(c0, c1))
        row.putdata([c] * w)
        im.paste(row, (0, y))
    mask = Image.new("L", (w, height), 0)
    _rrect(ImageDraw.Draw(mask), (0, 0, w - 1, height - 1), r, fill=255)
    base = Image.new("RGBA", (w, height), (0, 0, 0, 0))
    base.paste(im, (0, 0), mask)
    d = ImageDraw.Draw(base)
    tcol = (255, 255, 255, 255) if style != "dark" else (201, 212, 255, 255)
    d.text(((w - tw) / 2 - bb[0], (height - (bb[3] - bb[1])) / 2 - bb[1]), text, font=f, fill=tcol)
    return base


def make_buttons():
    specs = [
        ("连接", "primary"), ("刷新", "dark"), ("启停所选", "primary"),
        ("备份所选站点", "primary"), ("备份数据库", "primary"),
        ("立即执行所选", "primary"), ("重载 Nginx", "primary"),
        ("检测配置 (nginx -t)", "dark"),
    ]
    out = {}
    for i, (text, style) in enumerate(specs):
        im = _pill(text, style)
        name = "btn_%d_%s.png" % (i, style)
        im.save(os.path.join(ASSETS, name))
        out[text] = name
    return out


def make_nav_pill():
    w, h, r = 180, 36, 12
    im = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    row = Image.new("RGBA", (w, 1))
    c0, c1 = (132, 146, 255, 235), (98, 110, 240, 235)
    for y in range(h):
        t = y / (h - 1)
        c = tuple(int(a + (b - a) * t) for a, b in zip(c0, c1))
        row.putdata([c] * w)
        im.paste(row, (0, y))
    mask = Image.new("L", (w, h), 0)
    _rrect(ImageDraw.Draw(mask), (0, 0, w - 1, h - 1), r, fill=255)
    base = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    base.paste(im, (0, 0), mask)
    base.save(os.path.join(ASSETS, "nav_active.png"))


if __name__ == "__main__":
    os.makedirs(ASSETS, exist_ok=True)
    make_bg()
    print("buttons:", make_buttons())
    make_nav_pill()
    for f in sorted(os.listdir(ASSETS)):
        print(" ", f, os.path.getsize(os.path.join(ASSETS, f)), "bytes")
