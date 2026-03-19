#!/usr/bin/env python3
"""
Generate a first studio-style visual drop for Ma Commune.
"""

from __future__ import annotations

import argparse
import json
import math
from datetime import datetime
from pathlib import Path
from typing import Iterable

from PIL import Image, ImageChops, ImageColor, ImageDraw, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
VISUAL_DIR = ROOT / "assets" / "visual-production"

PALETTE = {
    "canopy": "#174B3A",
    "canopy_deep": "#0E3127",
    "river": "#2D6F86",
    "laterite": "#A64B2A",
    "awara": "#D2A13A",
    "leaf": "#4D8A5B",
    "mist": "#F4F1E7",
    "sand": "#E6DCC8",
    "cloud": "#FAF8F2",
    "ink": "#183229",
    "slate": "#5E6C67",
    "white": "#FFFFFF",
    "warm": "#F3D59B",
    "sunset": "#E28C5A",
    "danger": "#C94B3C",
    "success": "#2F7D50",
}

SIZE_BY_RATIO = {
    "1:1": (768, 768),
    "4:5": (960, 1200),
    "16:9": (1600, 900),
    "9:16": (1080, 1920),
}

BATCH_FILES = [
    "character-batch-01.json",
    "badge-batch-01.json",
    "terrain-batch-01.json",
    "moments-batch-01.json",
    "hero-batch-01.json",
]


def hex_rgba(value: str, alpha: int = 255) -> tuple[int, int, int, int]:
    r, g, b = ImageColor.getrgb(value)
    return r, g, b, alpha


def lerp(a: float, b: float, t: float) -> float:
    return a + (b - a) * t


def mix(color_a: str, color_b: str, t: float) -> tuple[int, int, int]:
    a = ImageColor.getrgb(color_a)
    b = ImageColor.getrgb(color_b)
    return tuple(int(lerp(a[i], b[i], t)) for i in range(3))


def vertical_gradient(size: tuple[int, int], top: str, bottom: str) -> Image.Image:
    w, h = size
    img = Image.new("RGBA", size)
    px = img.load()
    for y in range(h):
        t = y / max(h - 1, 1)
        color = mix(top, bottom, t)
        for x in range(w):
            px[x, y] = (*color, 255)
    return img


def soft_glow(base: Image.Image, center: tuple[float, float], radius: float, color: str, alpha: int) -> None:
    overlay = Image.new("RGBA", base.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    cx, cy = center
    for i in range(6, 0, -1):
        scale = i / 6
        r = radius * scale
        a = int(alpha * scale * scale)
        draw.ellipse((cx - r, cy - r, cx + r, cy + r), fill=hex_rgba(color, a))
    overlay = overlay.filter(ImageFilter.GaussianBlur(radius=radius * 0.16))
    base.alpha_composite(overlay)


def add_vignette(base: Image.Image, strength: int = 90) -> None:
    w, h = base.size
    overlay = Image.new("L", (w, h), 0)
    draw = ImageDraw.Draw(overlay)
    draw.ellipse((-w * 0.15, -h * 0.1, w * 1.15, h * 1.1), fill=255)
    overlay = overlay.filter(ImageFilter.GaussianBlur(radius=min(w, h) * 0.12))
    inv = ImageChops.invert(overlay)
    rgba = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    rgba.putalpha(inv.point(lambda p: min(255, int(p * strength / 255))))
    base.alpha_composite(rgba)


def add_grain(base: Image.Image, step: int = 32, opacity: int = 20) -> None:
    w, h = base.size
    overlay = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    for y in range(0, h, step):
        for x in range(0, w, step):
            tone = 255 if (x // step + y // step) % 2 == 0 else 0
            draw.rectangle((x, y, x + step, y + step), fill=(tone, tone, tone, opacity))
    overlay = overlay.filter(ImageFilter.GaussianBlur(radius=step * 0.45))
    base.alpha_composite(overlay)


def add_foliage(base: Image.Image, density: int = 18, area: str = "top") -> None:
    w, h = base.size
    overlay = Image.new("RGBA", base.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    positions: list[tuple[float, float, float]] = []
    for i in range(density):
        t = i / max(density - 1, 1)
        x = lerp(-w * 0.1, w * 1.05, t)
        if area == "top":
            y = lerp(-h * 0.05, h * 0.24, (i % 5) / 4)
        elif area == "bottom":
            y = lerp(h * 0.72, h * 1.05, (i % 4) / 3)
        else:
            y = lerp(h * 0.15, h * 0.85, ((i * 13) % 10) / 9)
        size = lerp(w * 0.04, w * 0.12, ((i * 7) % 9) / 8)
        positions.append((x, y, size))
    for idx, (x, y, size) in enumerate(positions):
        color = PALETTE["leaf"] if idx % 2 else PALETTE["canopy"]
        draw.ellipse((x, y, x + size, y + size * 0.7), fill=hex_rgba(color, 190))
        draw.ellipse((x + size * 0.18, y - size * 0.18, x + size * 1.02, y + size * 0.52), fill=hex_rgba(PALETTE["canopy_deep"], 120))
    overlay = overlay.filter(ImageFilter.GaussianBlur(radius=max(10, w * 0.01)))
    base.alpha_composite(overlay)


def draw_horizon(base: Image.Image, sky_top: str, sky_bottom: str, ground_top: str, ground_bottom: str, water: bool = False) -> None:
    w, h = base.size
    bg = vertical_gradient(base.size, sky_top, sky_bottom)
    base.alpha_composite(bg)
    soft_glow(base, (w * 0.72, h * 0.18), w * 0.24, PALETTE["warm"], 115)

    overlay = Image.new("RGBA", base.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    horizon_y = int(h * (0.56 if water else 0.5))
    draw.rectangle((0, horizon_y, w, h), fill=hex_rgba(ground_top))
    draw.rectangle((0, int(horizon_y + h * 0.1), w, h), fill=hex_rgba(ground_bottom))
    draw.polygon([(0, horizon_y), (w * 0.18, horizon_y - h * 0.05), (w * 0.42, horizon_y), (w * 0.65, horizon_y - h * 0.07), (w, horizon_y)], fill=hex_rgba(PALETTE["canopy_deep"], 220))
    if water:
        draw.rectangle((0, horizon_y - h * 0.06, w, horizon_y + h * 0.08), fill=hex_rgba(PALETTE["river"], 180))
    base.alpha_composite(overlay.filter(ImageFilter.GaussianBlur(radius=max(4, w * 0.008))))
    add_foliage(base, density=22, area="top")
    add_vignette(base, strength=70)


def draw_badge_frame(size: tuple[int, int], accent: str, center_fill: str) -> Image.Image:
    img = Image.new("RGBA", size, (0, 0, 0, 0))
    w, h = size
    draw = ImageDraw.Draw(img)
    draw.rounded_rectangle((w * 0.08, h * 0.08, w * 0.92, h * 0.92), radius=w * 0.26, fill=hex_rgba(PALETTE["cloud"], 255))
    soft_glow(img, (w * 0.5, h * 0.34), w * 0.33, PALETTE["warm"], 100)
    draw.ellipse((w * 0.18, h * 0.18, w * 0.82, h * 0.82), fill=hex_rgba(center_fill, 255), outline=hex_rgba(accent, 190), width=int(w * 0.03))
    draw.arc((w * 0.14, h * 0.14, w * 0.86, h * 0.86), 210, 330, fill=hex_rgba(PALETTE["white"], 120), width=int(w * 0.024))
    add_vignette(img, strength=35)
    return img


def draw_road(draw: ImageDraw.ImageDraw, w: int, h: int, left: float, top: float, right: float, bottom: float, color: str) -> None:
    draw.polygon(
        [
            (w * left, h * top),
            (w * right, h * top),
            (w * (right - 0.12), h * bottom),
            (w * (left + 0.12), h * bottom),
        ],
        fill=hex_rgba(color, 255),
    )
    draw.rectangle((w * 0.48, h * (top + 0.06), w * 0.52, h * bottom), fill=hex_rgba(PALETTE["sand"], 140))


def draw_bird(draw: ImageDraw.ImageDraw, box: tuple[float, float, float, float], palette: tuple[str, str, str]) -> None:
    x1, y1, x2, y2 = box
    w = x2 - x1
    h = y2 - y1
    body = palette[0]
    chest = palette[1]
    beak = palette[2]
    draw.ellipse((x1 + w * 0.18, y1 + h * 0.22, x1 + w * 0.78, y1 + h * 0.86), fill=hex_rgba(body))
    draw.ellipse((x1 + w * 0.38, y1 + h * 0.04, x1 + w * 0.78, y1 + h * 0.42), fill=hex_rgba(body))
    draw.ellipse((x1 + w * 0.24, y1 + h * 0.42, x1 + w * 0.72, y1 + h * 0.82), fill=hex_rgba(chest, 215))
    draw.polygon([(x1 + w * 0.72, y1 + h * 0.2), (x1 + w * 0.95, y1 + h * 0.28), (x1 + w * 0.74, y1 + h * 0.34)], fill=hex_rgba(beak))
    draw.ellipse((x1 + w * 0.58, y1 + h * 0.17, x1 + w * 0.65, y1 + h * 0.24), fill=hex_rgba(PALETTE["white"]))
    draw.ellipse((x1 + w * 0.6, y1 + h * 0.185, x1 + w * 0.635, y1 + h * 0.22), fill=hex_rgba(PALETTE["ink"]))
    draw.polygon([(x1 + w * 0.1, y1 + h * 0.58), (x1 - w * 0.05, y1 + h * 0.47), (x1 + w * 0.12, y1 + h * 0.42)], fill=hex_rgba(body, 230))
    for dx in (0.38, 0.49):
        draw.line((x1 + w * dx, y1 + h * 0.82, x1 + w * (dx - 0.03), y1 + h * 1.02), fill=hex_rgba(PALETTE["ink"]), width=max(2, int(w * 0.02)))


def draw_agent(draw: ImageDraw.ImageDraw, box: tuple[float, float, float, float], vest: str, skin: str, hair: str) -> None:
    x1, y1, x2, y2 = box
    w = x2 - x1
    h = y2 - y1
    draw.rounded_rectangle((x1 + w * 0.18, y1 + h * 0.4, x1 + w * 0.82, y2), radius=w * 0.12, fill=hex_rgba(vest))
    draw.ellipse((x1 + w * 0.3, y1 + h * 0.08, x1 + w * 0.7, y1 + h * 0.48), fill=hex_rgba(skin))
    draw.pieslice((x1 + w * 0.25, y1 + h * 0.02, x1 + w * 0.74, y1 + h * 0.4), 180, 360, fill=hex_rgba(hair))
    draw.rectangle((x1 + w * 0.42, y1 + h * 0.37, x1 + w * 0.58, y1 + h * 0.49), fill=hex_rgba(skin))
    draw.ellipse((x1 + w * 0.42, y1 + h * 0.24, x1 + w * 0.48, y1 + h * 0.3), fill=hex_rgba(PALETTE["white"]))
    draw.ellipse((x1 + w * 0.52, y1 + h * 0.24, x1 + w * 0.58, y1 + h * 0.3), fill=hex_rgba(PALETTE["white"]))
    draw.ellipse((x1 + w * 0.445, y1 + h * 0.255, x1 + w * 0.47, y1 + h * 0.28), fill=hex_rgba(PALETTE["ink"]))
    draw.ellipse((x1 + w * 0.535, y1 + h * 0.255, x1 + w * 0.56, y1 + h * 0.28), fill=hex_rgba(PALETTE["ink"]))
    draw.arc((x1 + w * 0.4, y1 + h * 0.29, x1 + w * 0.6, y1 + h * 0.39), 15, 165, fill=hex_rgba(PALETTE["ink"]), width=max(2, int(w * 0.02)))
    draw.rectangle((x1 + w * 0.31, y1 + h * 0.53, x1 + w * 0.69, y1 + h * 0.59), fill=hex_rgba(PALETTE["awara"], 210))


def make_character(asset_id: str, size: tuple[int, int]) -> Image.Image:
    img = Image.new("RGBA", size, (0, 0, 0, 0))
    draw_horizon(img, PALETTE["cloud"], PALETTE["sand"], PALETTE["leaf"], PALETTE["canopy_deep"], water=True)
    w, h = size
    overlay = Image.new("RGBA", size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    draw.rounded_rectangle((w * 0.08, h * 0.08, w * 0.92, h * 0.92), radius=w * 0.09, fill=hex_rgba(PALETTE["white"], 86))
    img.alpha_composite(overlay.filter(ImageFilter.GaussianBlur(radius=w * 0.012)))
    if asset_id == "CHAR-01":
        draw = ImageDraw.Draw(img)
        draw_bird(draw, (w * 0.2, h * 0.18, w * 0.78, h * 0.9), (PALETTE["canopy"], PALETTE["awara"], PALETTE["laterite"]))
    elif asset_id == "CHAR-02":
        draw = ImageDraw.Draw(img)
        draw_bird(draw, (w * 0.16, h * 0.2, w * 0.84, h * 0.88), (PALETTE["river"], PALETTE["mist"], PALETTE["awara"]))
        draw.ellipse((w * 0.21, h * 0.27, w * 0.78, h * 0.79), outline=hex_rgba(PALETTE["white"], 90), width=int(w * 0.02))
    elif asset_id == "CHAR-03":
        draw = ImageDraw.Draw(img)
        draw_bird(draw, (w * 0.14, h * 0.16, w * 0.84, h * 0.9), (PALETTE["laterite"], PALETTE["warm"], PALETTE["ink"]))
        draw.arc((w * 0.2, h * 0.22, w * 0.8, h * 0.78), 190, 320, fill=hex_rgba(PALETTE["leaf"], 110), width=int(w * 0.04))
    elif asset_id == "CHAR-04":
        draw = ImageDraw.Draw(img)
        draw_agent(draw, (w * 0.18, h * 0.12, w * 0.82, h * 0.92), PALETTE["river"], "#8E5A3A", PALETTE["ink"])
    elif asset_id == "CHAR-05":
        draw = ImageDraw.Draw(img)
        draw_agent(draw, (w * 0.16, h * 0.1, w * 0.84, h * 0.92), PALETTE["laterite"], "#A86D49", PALETTE["canopy_deep"])
        soft_glow(img, (w * 0.5, h * 0.36), w * 0.2, PALETTE["warm"], 90)
    elif asset_id == "CHAR-06":
        draw = ImageDraw.Draw(img)
        draw_agent(draw, (w * 0.46, h * 0.24, w * 0.83, h * 0.86), PALETTE["laterite"], "#A86D49", PALETTE["canopy_deep"])
        draw_bird(draw, (w * 0.15, h * 0.3, w * 0.43, h * 0.74), (PALETTE["canopy"], PALETTE["awara"], PALETTE["laterite"]))
        draw.rounded_rectangle((w * 0.07, h * 0.1, w * 0.93, h * 0.88), radius=w * 0.04, outline=hex_rgba(PALETTE["white"], 95), width=int(w * 0.008))
    add_grain(img, step=max(24, w // 40), opacity=16)
    return img


def draw_badge_symbol(img: Image.Image, asset_id: str) -> None:
    w, h = img.size
    draw = ImageDraw.Draw(img)
    accent = PALETTE["ink"]
    line = int(w * 0.05)
    if asset_id == "CAT-01":
        draw_road(draw, w, h, 0.28, 0.24, 0.72, 0.82, PALETTE["slate"])
        draw.ellipse((w * 0.39, h * 0.48, w * 0.61, h * 0.64), fill=hex_rgba(PALETTE["laterite"]))
    elif asset_id == "CAT-02":
        draw.line((w * 0.5, h * 0.28, w * 0.5, h * 0.7), fill=hex_rgba(accent), width=line)
        draw.ellipse((w * 0.36, h * 0.2, w * 0.64, h * 0.42), fill=hex_rgba(PALETTE["awara"]))
        draw.line((w * 0.42, h * 0.69, w * 0.36, h * 0.8), fill=hex_rgba(accent), width=line)
        draw.line((w * 0.58, h * 0.69, w * 0.64, h * 0.8), fill=hex_rgba(accent), width=line)
    elif asset_id == "CAT-03":
        draw.polygon([(w * 0.5, h * 0.23), (w * 0.68, h * 0.5), (w * 0.5, h * 0.77), (w * 0.32, h * 0.5)], fill=hex_rgba(PALETTE["leaf"]))
        draw.line((w * 0.5, h * 0.28, w * 0.5, h * 0.72), fill=hex_rgba(PALETTE["white"], 170), width=int(w * 0.03))
    elif asset_id == "CAT-04":
        draw.polygon([(w * 0.28, h * 0.38), (w * 0.44, h * 0.32), (w * 0.54, h * 0.56), (w * 0.36, h * 0.66)], fill=hex_rgba(PALETTE["laterite"]))
        draw.rectangle((w * 0.52, h * 0.28, w * 0.64, h * 0.68), fill=hex_rgba(PALETTE["slate"]))
        draw.arc((w * 0.48, h * 0.22, w * 0.68, h * 0.4), 180, 360, fill=hex_rgba(PALETTE["slate"]), width=int(w * 0.03))
    elif asset_id == "CAT-05":
        draw.rectangle((w * 0.3, h * 0.44, w * 0.7, h * 0.56), fill=hex_rgba(PALETTE["laterite"]))
        draw.rectangle((w * 0.3, h * 0.56, w * 0.36, h * 0.74), fill=hex_rgba(PALETTE["ink"]))
        draw.rectangle((w * 0.64, h * 0.56, w * 0.7, h * 0.74), fill=hex_rgba(PALETTE["ink"]))
    elif asset_id == "CAT-06":
        draw.rounded_rectangle((w * 0.34, h * 0.28, w * 0.66, h * 0.66), radius=w * 0.06, outline=hex_rgba(PALETTE["ink"]), width=line)
        for i in range(3):
            y = h * (0.44 + i * 0.08)
            draw.arc((w * 0.28, y - h * 0.08, w * 0.72, y + h * 0.04), 200, 340, fill=hex_rgba(PALETTE["river"]), width=int(w * 0.03))
    elif asset_id == "CAT-07":
        draw.polygon([(w * 0.5, h * 0.22), (w * 0.74, h * 0.44), (w * 0.5, h * 0.68), (w * 0.26, h * 0.44)], fill=hex_rgba(PALETTE["awara"]), outline=hex_rgba(PALETTE["ink"]), width=int(w * 0.03))
        draw.line((w * 0.46, h * 0.34, w * 0.54, h * 0.54), fill=hex_rgba(PALETTE["ink"]), width=int(w * 0.04))
    elif asset_id == "CAT-08":
        draw.rectangle((w * 0.3, h * 0.42, w * 0.7, h * 0.72), fill=hex_rgba(PALETTE["river"]))
        draw.polygon([(w * 0.26, h * 0.42), (w * 0.5, h * 0.24), (w * 0.74, h * 0.42)], fill=hex_rgba(PALETTE["laterite"]))
        for i in range(3):
            draw.rectangle((w * (0.36 + i * 0.1), h * 0.5, w * (0.42 + i * 0.1), h * 0.58), fill=hex_rgba(PALETTE["white"], 210))


def make_badge(asset_id: str, size: tuple[int, int]) -> Image.Image:
    accent_map = {
        "CAT-01": PALETTE["laterite"],
        "CAT-02": PALETTE["awara"],
        "CAT-03": PALETTE["leaf"],
        "CAT-04": PALETTE["river"],
        "CAT-05": PALETTE["laterite"],
        "CAT-06": PALETTE["river"],
        "CAT-07": PALETTE["awara"],
        "CAT-08": PALETTE["canopy"],
    }
    fill_map = {
        "CAT-01": PALETTE["sand"],
        "CAT-02": PALETTE["mist"],
        "CAT-03": PALETTE["mist"],
        "CAT-04": PALETTE["sand"],
        "CAT-05": PALETTE["mist"],
        "CAT-06": PALETTE["mist"],
        "CAT-07": PALETTE["sand"],
        "CAT-08": PALETTE["mist"],
    }
    img = draw_badge_frame(size, accent_map[asset_id], fill_map[asset_id])
    draw_badge_symbol(img, asset_id)
    add_grain(img, step=max(20, size[0] // 30), opacity=14)
    return img


def make_terrain(asset_id: str, size: tuple[int, int]) -> Image.Image:
    img = Image.new("RGBA", size, (0, 0, 0, 0))
    w, h = size
    draw_horizon(img, PALETTE["cloud"], PALETTE["sand"], PALETTE["leaf"], PALETTE["canopy_deep"], water=False)
    draw = ImageDraw.Draw(img)
    if asset_id == "ILL-01":
        draw_road(draw, w, h, 0.2, 0.34, 0.8, 0.95, PALETTE["slate"])
        draw.ellipse((w * 0.38, h * 0.58, w * 0.64, h * 0.73), fill=hex_rgba(PALETTE["laterite"]))
        draw.ellipse((w * 0.41, h * 0.6, w * 0.61, h * 0.69), fill=hex_rgba(PALETTE["river"], 170))
        for x in (0.28, 0.72):
            draw.line((w * x, h * 0.18, w * (x - 0.05), h * 0.95), fill=hex_rgba(PALETTE["white"], 90), width=int(w * 0.012))
    elif asset_id == "ILL-02":
        img = vertical_gradient(size, PALETTE["canopy_deep"], PALETTE["river"])
        soft_glow(img, (w * 0.58, h * 0.28), w * 0.18, PALETTE["awara"], 130)
        draw = ImageDraw.Draw(img)
        draw_road(draw, w, h, 0.18, 0.42, 0.82, 0.96, PALETTE["ink"])
        draw.line((w * 0.58, h * 0.2, w * 0.58, h * 0.74), fill=hex_rgba(PALETTE["slate"]), width=int(w * 0.024))
        draw.ellipse((w * 0.49, h * 0.16, w * 0.67, h * 0.34), fill=hex_rgba(PALETTE["warm"], 240))
    elif asset_id == "ILL-03":
        draw_road(draw, w, h, 0.08, 0.48, 0.92, 0.95, PALETTE["slate"])
        draw.polygon([(w * 0.18, h * 0.57), (w * 0.36, h * 0.49), (w * 0.46, h * 0.66), (w * 0.3, h * 0.72)], fill=hex_rgba(PALETTE["laterite"]))
        draw.rectangle((w * 0.48, h * 0.5, w * 0.63, h * 0.68), fill=hex_rgba(PALETTE["slate"]))
        draw.arc((w * 0.45, h * 0.45, w * 0.66, h * 0.58), 180, 360, fill=hex_rgba(PALETTE["slate"]), width=int(w * 0.02))
    elif asset_id == "ILL-04":
        draw.rectangle((0, h * 0.56, w, h), fill=hex_rgba(PALETTE["laterite"]))
        draw.rectangle((w * 0.12, h * 0.46, w * 0.88, h * 0.88), fill=hex_rgba(PALETTE["slate"]))
        draw.rounded_rectangle((w * 0.36, h * 0.56, w * 0.64, h * 0.7), radius=w * 0.04, fill=hex_rgba(PALETTE["ink"]))
        for i in range(4):
            x = w * (0.18 + i * 0.16)
            draw.arc((x, h * 0.52, x + w * 0.18, h * 0.8), 190, 350, fill=hex_rgba(PALETTE["river"]), width=int(w * 0.025))
    elif asset_id == "ILL-05":
        draw_road(draw, w, h, 0.18, 0.42, 0.82, 0.95, PALETTE["sand"])
        for x in (0.14, 0.7):
            draw.ellipse((w * x, h * 0.3, w * (x + 0.24), h * 0.92), fill=hex_rgba(PALETTE["leaf"], 220))
            draw.ellipse((w * (x + 0.08), h * 0.18, w * (x + 0.3), h * 0.7), fill=hex_rgba(PALETTE["canopy"], 170))
    elif asset_id == "ILL-06":
        draw.rectangle((0, h * 0.6, w, h), fill=hex_rgba(PALETTE["sand"]))
        draw.rectangle((w * 0.24, h * 0.5, w * 0.74, h * 0.61), fill=hex_rgba(PALETTE["laterite"]))
        draw.rectangle((w * 0.24, h * 0.61, w * 0.3, h * 0.82), fill=hex_rgba(PALETTE["ink"]))
        draw.rectangle((w * 0.68, h * 0.61, w * 0.74, h * 0.82), fill=hex_rgba(PALETTE["ink"]))
        draw.line((w * 0.28, h * 0.47, w * 0.39, h * 0.6), fill=hex_rgba(PALETTE["danger"]), width=int(w * 0.02))
    elif asset_id == "ILL-07":
        draw_road(draw, w, h, 0.24, 0.4, 0.76, 0.95, PALETTE["slate"])
        draw.line((w * 0.58, h * 0.24, w * 0.52, h * 0.76), fill=hex_rgba(PALETTE["ink"]), width=int(w * 0.025))
        draw.polygon([(w * 0.38, h * 0.25), (w * 0.62, h * 0.31), (w * 0.54, h * 0.52), (w * 0.31, h * 0.46)], fill=hex_rgba(PALETTE["awara"]))
    elif asset_id == "ILL-08":
        draw.rectangle((w * 0.18, h * 0.34, w * 0.82, h * 0.86), fill=hex_rgba(PALETTE["river"]))
        draw.polygon([(w * 0.13, h * 0.34), (w * 0.5, h * 0.16), (w * 0.87, h * 0.34)], fill=hex_rgba(PALETTE["laterite"]))
        draw.line((w * 0.22, h * 0.84, w * 0.78, h * 0.4), fill=hex_rgba(PALETTE["danger"]), width=int(w * 0.03))
        for i in range(3):
            draw.rectangle((w * (0.28 + i * 0.14), h * 0.47, w * (0.36 + i * 0.14), h * 0.59), fill=hex_rgba(PALETTE["white"], 210))
    add_foliage(img, density=14, area="bottom")
    add_grain(img, step=max(24, w // 36), opacity=14)
    return img


def make_moment(asset_id: str, size: tuple[int, int]) -> Image.Image:
    img = Image.new("RGBA", size, (0, 0, 0, 0))
    w, h = size
    draw_horizon(img, PALETTE["cloud"], PALETTE["mist"], PALETTE["sand"], PALETTE["leaf"], water=True)
    draw = ImageDraw.Draw(img)
    if asset_id == "MOM-01":
        draw_agent(draw, (w * 0.42, h * 0.22, w * 0.82, h * 0.9), PALETTE["laterite"], "#A86D49", PALETTE["canopy_deep"])
        draw_bird(draw, (w * 0.12, h * 0.34, w * 0.4, h * 0.76), (PALETTE["canopy"], PALETTE["awara"], PALETTE["laterite"]))
    elif asset_id == "MOM-02":
        draw_agent(draw, (w * 0.4, h * 0.2, w * 0.82, h * 0.92), PALETTE["river"], "#8E5A3A", PALETTE["ink"])
        draw.rounded_rectangle((w * 0.18, h * 0.48, w * 0.36, h * 0.76), radius=w * 0.04, fill=hex_rgba(PALETTE["white"]), outline=hex_rgba(PALETTE["river"]), width=int(w * 0.015))
        draw.ellipse((w * 0.22, h * 0.54, w * 0.32, h * 0.64), fill=hex_rgba(PALETTE["success"]))
    elif asset_id == "MOM-03":
        draw_road(draw, w, h, 0.14, 0.5, 0.86, 0.96, PALETTE["slate"])
        draw_agent(draw, (w * 0.54, h * 0.28, w * 0.84, h * 0.88), PALETTE["awara"], "#8E5A3A", PALETTE["ink"])
        draw.ellipse((w * 0.26, h * 0.62, w * 0.48, h * 0.74), fill=hex_rgba(PALETTE["laterite"]))
    elif asset_id == "MOM-04":
        draw_agent(draw, (w * 0.52, h * 0.28, w * 0.82, h * 0.9), PALETTE["success"], "#8E5A3A", PALETTE["ink"])
        draw.line((w * 0.28, h * 0.28, w * 0.28, h * 0.76), fill=hex_rgba(PALETTE["slate"]), width=int(w * 0.024))
        draw.ellipse((w * 0.18, h * 0.18, w * 0.38, h * 0.38), fill=hex_rgba(PALETTE["warm"]))
        draw.arc((w * 0.16, h * 0.16, w * 0.4, h * 0.4), 210, 330, fill=hex_rgba(PALETTE["white"], 180), width=int(w * 0.03))
    elif asset_id == "MOM-05":
        draw_road(draw, w, h, 0.16, 0.46, 0.84, 0.95, PALETTE["sand"])
        draw_bird(draw, (w * 0.3, h * 0.24, w * 0.68, h * 0.76), (PALETTE["river"], PALETTE["mist"], PALETTE["awara"]))
    elif asset_id == "MOM-06":
        draw_agent(draw, (w * 0.4, h * 0.22, w * 0.8, h * 0.9), PALETTE["river"], "#A86D49", PALETTE["canopy_deep"])
        for i in range(3):
            y = h * (0.42 + i * 0.11)
            draw.rounded_rectangle((w * 0.14, y, w * 0.34, y + h * 0.075), radius=w * 0.03, fill=hex_rgba(PALETTE["white"], 220))
    add_grain(img, step=max(24, w // 36), opacity=14)
    return img


def make_hero(asset_id: str, size: tuple[int, int]) -> Image.Image:
    img = Image.new("RGBA", size, (0, 0, 0, 0))
    w, h = size
    water = True
    draw_horizon(img, PALETTE["cloud"], PALETTE["sand"], PALETTE["leaf"], PALETTE["canopy_deep"], water=water)
    draw = ImageDraw.Draw(img)
    draw_road(draw, w, h, 0.28, 0.56, 0.72, 1.02, PALETTE["slate"])
    draw.rectangle((w * 0.08, h * 0.58, w * 0.22, h * 0.84), fill=hex_rgba(PALETTE["river"], 215))
    draw.polygon([(w * 0.07, h * 0.58), (w * 0.15, h * 0.49), (w * 0.23, h * 0.58)], fill=hex_rgba(PALETTE["laterite"]))
    if asset_id == "HERO-01":
        draw_agent(draw, (w * 0.56, h * 0.24, w * 0.82, h * 0.9), PALETTE["laterite"], "#A86D49", PALETTE["canopy_deep"])
        draw_bird(draw, (w * 0.28, h * 0.38, w * 0.48, h * 0.74), (PALETTE["canopy"], PALETTE["awara"], PALETTE["laterite"]))
    else:
        draw_agent(draw, (w * 0.3, h * 0.46, w * 0.76, h * 0.93), PALETTE["laterite"], "#A86D49", PALETTE["canopy_deep"])
        draw_bird(draw, (w * 0.1, h * 0.58, w * 0.32, h * 0.84), (PALETTE["canopy"], PALETTE["awara"], PALETTE["laterite"]))
        draw.rounded_rectangle((w * 0.1, h * 0.1, w * 0.9, h * 0.94), radius=w * 0.05, outline=hex_rgba(PALETTE["white"], 90), width=int(w * 0.012))
    add_grain(img, step=max(26, w // 44), opacity=13)
    return img


def render_asset(asset: dict) -> Image.Image:
    ratio = asset["ratio"]
    size = SIZE_BY_RATIO[ratio]
    asset_id = asset["id"]
    if asset_id.startswith("CHAR-"):
        return make_character(asset_id, size)
    if asset_id.startswith("CAT-"):
        return make_badge(asset_id, size)
    if asset_id.startswith("ILL-"):
        return make_terrain(asset_id, size)
    if asset_id.startswith("MOM-"):
        return make_moment(asset_id, size)
    if asset_id.startswith("HERO-"):
        return make_hero(asset_id, size)
    raise ValueError(f"Asset non gere: {asset_id}")


def load_assets() -> list[dict]:
    assets: list[dict] = []
    for batch_file in BATCH_FILES:
        data = json.loads((VISUAL_DIR / batch_file).read_text(encoding="utf-8"))
        assets.extend(data.get("assets", []))
    return assets


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate a local studio-style visual drop for Ma Commune.")
    parser.add_argument(
        "--output-dir",
        help="Target directory for generated PNG files. Defaults to /tmp/ma-commune-studio-drop-<timestamp>.",
    )
    args = parser.parse_args()

    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    output_dir = Path(args.output_dir).expanduser().resolve() if args.output_dir else Path(f"/tmp/ma-commune-studio-drop-{stamp}")
    output_dir.mkdir(parents=True, exist_ok=True)

    manifest = {
        "generated_at": datetime.now().isoformat(),
        "generator": "generate_studio_visual_drop.py",
        "assets": [],
    }

    for asset in load_assets():
        image = render_asset(asset).convert("RGB")
        target = output_dir / asset["filename"]
        image.save(target, format="PNG", optimize=True)
        manifest["assets"].append(
            {
                "id": asset["id"],
                "filename": asset["filename"],
                "ratio": asset["ratio"],
            }
        )

    (output_dir / "studio-drop-manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )

    print(f"output_dir={output_dir}")
    print(f"assets_count={len(manifest['assets'])}")


if __name__ == "__main__":
    main()
