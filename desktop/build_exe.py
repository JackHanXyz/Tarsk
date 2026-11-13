# -*- coding: utf-8 -*-
"""Tarsk 控制台打包脚本：app-icon.png -> 多尺寸 ico -> PyInstaller 单文件 exe"""
import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PNG = os.path.join(HERE, "app-icon.png")
ICO = os.path.join(HERE, "app-icon.ico")
WORK = os.path.join(HERE, "build")
DIST = os.path.join(HERE, "dist")
NAME = "Tarsk控制台"


def make_ico():
    from PIL import Image
    im = Image.open(PNG).convert("RGBA")
    w, h = im.size
    s = min(w, h)
    if w != h:
        im = im.crop(((w - s) // 2, (h - s) // 2, (w - s) // 2 + s, (h - s) // 2 + s))
    im.save(ICO, format="ICO", sizes=[(n, n) for n in (256, 128, 64, 48, 32, 24, 16)])
    print("ico ok:", ICO, os.path.getsize(ICO), "bytes")


def make_assets():
    assets = os.path.join(HERE, "assets")
    os.makedirs(assets, exist_ok=True)
    subprocess.call([sys.executable, os.path.join(HERE, "make_assets.py")], cwd=HERE)
    return [os.path.join(assets, f) for f in os.listdir(assets) if f.endswith(".png")]


def build():
    asset_files = make_assets()
    data_args = []
    for a in [ICO] + asset_files:
        data_args += ["--add-data", a + os.pathsep + "."]
    cmd = [
        sys.executable, "-m", "PyInstaller", "--noconfirm", "--clean",
        "--onefile", "--noupx", "--windowed",
        "--icon", ICO,
        *data_args,
        "--name", NAME,
        "--workpath", WORK, "--specpath", WORK, "--distpath", DIST,
        "--exclude-module", "numpy", "--exclude-module", "matplotlib",
        "--exclude-module", "PIL", "--exclude-module", "scipy",
        "--exclude-module", "pandas", "--exclude-module", "pytest",
        os.path.join(HERE, "tarsk_console.py"),
    ]
    rc = subprocess.call(cmd, cwd=HERE)
    exe = os.path.join(DIST, NAME + ".exe")
    if rc == 0 and os.path.exists(exe):
        print("exe:", exe, os.path.getsize(exe), "bytes")
    else:
        print("BUILD FAILED rc=", rc)
    return rc


if __name__ == "__main__":
    make_ico()
    rc = build()
    if rc == 0:
        shutil.rmtree(WORK, ignore_errors=True)
    sys.exit(rc)
