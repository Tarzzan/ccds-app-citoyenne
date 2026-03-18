#!/usr/bin/env python3
"""
Generate the Ma Commune category icon set.

The family is built around a single civic visual language:
- deep canopy background inspired by Guyane
- warm accent ring
- subtle river and foliage overlays
- central pictogram tailored to each terrain category
"""

from __future__ import annotations

import json
import math
import shutil
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
CATALOG_PATH = ROOT / "assets" / "category-visuals" / "category-visuals.json"
MASTER_DIR = ROOT / "assets" / "category-visuals" / "generated"
MOBILE_DIR = ROOT / "mobile" / "assets" / "category-icons"
ADMIN_DIR = ROOT / "admin" / "assets" / "img" / "category-icons"
SIZE = 512
RADIUS = 112


def hex_to_rgb(value: str) -> tuple[int, int, int]:
    value = value.lstrip("#")
    return tuple(int(value[i:i + 2], 16) for i in (0, 2, 4))


def mix(c1: tuple[int, int, int], c2: tuple[int, int, int], factor: float) -> tuple[int, int, int]:
    return tuple(int(a + (b - a) * factor) for a, b in zip(c1, c2))


def vertical_gradient(size: int, top: tuple[int, int, int], bottom: tuple[int, int, int]) -> Image.Image:
    gradient = Image.new("RGBA", (size, size))
    draw = ImageDraw.Draw(gradient)
    for y in range(size):
        factor = y / max(size - 1, 1)
        draw.line((0, y, size, y), fill=mix(top, bottom, factor) + (255,), width=1)
    return gradient


def rounded_mask(size: int, radius: int) -> Image.Image:
    mask = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(mask)
    draw.rounded_rectangle((14, 14, size - 14, size - 14), radius=radius, fill=255)
    return mask


def add_shadow(base: Image.Image) -> Image.Image:
    shadow = Image.new("RGBA", base.size, (0, 0, 0, 0))
    shadow_mask = rounded_mask(SIZE, RADIUS).filter(ImageFilter.GaussianBlur(12))
    shadow.paste((6, 25, 18, 100), (12, 18), shadow_mask)
    return Image.alpha_composite(shadow, base)


def draw_background_layers(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    draw.ellipse((326, 18, 506, 196), fill=accent + (42,))
    draw.ellipse((14, 304, 206, 500), fill=glow + (28,))
    draw.ellipse((-34, -10, 166, 152), fill=(255, 255, 255, 18))
    draw.pieslice((284, 268, 608, 592), 184, 346, fill=(255, 255, 255, 14))
    draw.pieslice((-74, 246, 182, 556), 198, 350, fill=(255, 255, 255, 10))

    for y, alpha in [(352, 22), (382, 16)]:
        draw.arc((-24, y - 42, 540, y + 36), 200, 356, fill=(255, 255, 255, alpha), width=6)

    leaf_fill = accent + (26,)
    draw.polygon([(72, 84), (114, 62), (168, 96), (120, 120)], fill=leaf_fill)
    draw.polygon([(96, 126), (132, 110), (176, 138), (134, 156)], fill=leaf_fill)
    draw.polygon([(352, 118), (392, 88), (452, 108), (404, 146)], fill=leaf_fill)
    draw.polygon([(382, 164), (426, 142), (474, 170), (428, 196)], fill=leaf_fill)


def create_canvas(accent_hex: str, glow_hex: str) -> tuple[Image.Image, ImageDraw.ImageDraw]:
    canopy_top = hex_to_rgb("#0D392C")
    canopy_bottom = hex_to_rgb("#1C705C")
    accent = hex_to_rgb(accent_hex)
    glow = hex_to_rgb(glow_hex)

    base = vertical_gradient(SIZE, canopy_top, canopy_bottom)
    draw = ImageDraw.Draw(base, "RGBA")
    draw_background_layers(draw, accent, glow)

    draw.rounded_rectangle((18, 18, SIZE - 18, SIZE - 18), radius=RADIUS, outline=(255, 255, 255, 58), width=5)
    draw.rounded_rectangle((26, 26, SIZE - 26, SIZE - 26), radius=RADIUS - 12, outline=(255, 255, 255, 18), width=2)
    draw.ellipse((116, 116, 396, 396), fill=(255, 255, 255, 36))
    draw.ellipse((132, 132, 380, 380), fill=(255, 255, 255, 18))

    return base, draw


def centered_shadow(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int]) -> None:
    x1, y1, x2, y2 = box
    draw.ellipse((x1 + 30, y2 - 4, x2 - 30, y2 + 28), fill=(5, 26, 20, 64))


def draw_road(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (120, 110, 392, 392))
    draw.polygon([(212, 114), (300, 114), (364, 386), (148, 386)], fill=(246, 244, 236, 255))
    draw.line((256, 144, 256, 362), fill=accent + (255,), width=18)
    for y in range(160, 340, 48):
        draw.rectangle((246, y, 266, y + 24), fill=(255, 255, 255, 255))
    draw.ellipse((204, 256, 308, 344), fill=(57, 42, 26, 190))
    draw.ellipse((220, 270, 292, 332), fill=accent + (235,))
    draw.arc((88, 328, 420, 458), 190, 350, fill=glow + (120,), width=8)


def draw_streetlight(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (140, 108, 372, 398))
    draw.ellipse((160, 118, 390, 352), fill=glow + (72,))
    draw.ellipse((182, 138, 370, 334), fill=glow + (44,))
    draw.line((224, 372, 224, 198), fill=(246, 244, 236, 255), width=18)
    draw.line((224, 202, 320, 202), fill=(246, 244, 236, 255), width=16)
    draw.arc((286, 184, 354, 252), 180, 320, fill=(246, 244, 236, 255), width=14)
    draw.rounded_rectangle((286, 218, 354, 274), radius=24, fill=(246, 244, 236, 255))
    draw.rectangle((206, 372, 246, 392), fill=(246, 244, 236, 255))
    draw.polygon([(334, 208), (362, 230), (336, 236)], fill=accent + (255,))
    for angle in range(0, 360, 45):
        dx = math.cos(math.radians(angle)) * 78
        dy = math.sin(math.radians(angle)) * 78
        draw.line((320, 246, 320 + dx, 246 + dy), fill=glow + (210,), width=8)


def draw_tree(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (118, 102, 394, 404))
    draw.rectangle((238, 252, 274, 378), fill=accent + (255,))
    frond_color = (246, 244, 236, 255)
    fronds = [
        [(256, 176), (176, 160), (120, 120), (164, 196)],
        [(256, 176), (176, 194), (108, 224), (188, 234)],
        [(256, 176), (196, 118), (170, 66), (250, 120)],
        [(256, 176), (316, 114), (352, 74), (320, 164)],
        [(256, 176), (334, 182), (402, 206), (324, 236)],
        [(256, 176), (304, 216), (334, 292), (270, 246)],
    ]
    for frond in fronds:
        draw.polygon(frond, fill=frond_color)
    draw.ellipse((214, 146, 298, 210), fill=glow + (90,))
    draw.arc((116, 308, 424, 430), 192, 348, fill=glow + (130,), width=8)


def draw_trash(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (120, 118, 392, 392))
    draw.rounded_rectangle((188, 176, 324, 366), radius=34, fill=(246, 244, 236, 255))
    draw.rounded_rectangle((170, 154, 342, 198), radius=22, fill=accent + (255,))
    draw.rectangle((224, 128, 288, 154), fill=(246, 244, 236, 255))
    draw.rectangle((216, 220, 232, 336), fill=(23, 91, 72, 78))
    draw.rectangle((248, 220, 264, 336), fill=(23, 91, 72, 78))
    draw.rectangle((280, 220, 296, 336), fill=(23, 91, 72, 78))
    draw.polygon([(120, 324), (186, 300), (206, 344), (140, 372)], fill=glow + (220,))
    draw.arc((112, 314, 420, 430), 196, 352, fill=glow + (110,), width=8)
    for center in [(358, 202), (390, 236)]:
        x, y = center
        draw.line((x - 12, y, x + 12, y), fill=glow + (220,), width=5)
        draw.line((x, y - 12, x, y + 12), fill=glow + (220,), width=5)


def draw_bench(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (116, 126, 396, 396))
    draw.line((168, 142, 168, 392), fill=(246, 244, 236, 255), width=16)
    draw.line((168, 158, 330, 158), fill=(246, 244, 236, 255), width=14)
    draw.polygon([(300, 158), (364, 204), (300, 204)], fill=(246, 244, 236, 255))
    draw.rounded_rectangle((186, 248, 338, 286), radius=18, fill=accent + (255,))
    draw.rounded_rectangle((184, 206, 340, 244), radius=18, fill=(246, 244, 236, 255))
    draw.line((220, 286, 206, 352), fill=(246, 244, 236, 255), width=14)
    draw.line((308, 286, 320, 352), fill=(246, 244, 236, 255), width=14)
    draw.arc((86, 314, 426, 446), 194, 348, fill=glow + (118,), width=8)


def draw_drain(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (114, 116, 398, 392))
    draw.rounded_rectangle((146, 230, 366, 330), radius=34, fill=(246, 244, 236, 255))
    for x in range(182, 342, 34):
        draw.rectangle((x, 244, x + 12, 316), fill=(22, 71, 116, 80))
    draw.arc((138, 160, 374, 328), 204, 348, fill=glow + (220,), width=16)
    draw.arc((154, 194, 390, 364), 204, 348, fill=accent + (255,), width=12)
    draw.arc((82, 316, 426, 446), 194, 348, fill=glow + (118,), width=8)
    draw.ellipse((346, 126, 404, 184), fill=glow + (210,))


def draw_sign(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (116, 104, 396, 392))
    draw.line((258, 214, 258, 384), fill=(246, 244, 236, 255), width=18)
    draw.rectangle((238, 384, 278, 400), fill=(246, 244, 236, 255))
    draw.polygon([(256, 112), (358, 286), (154, 286)], fill=(246, 244, 236, 255))
    draw.polygon([(256, 146), (330, 272), (182, 272)], fill=accent + (255,))
    draw.line((256, 184, 256, 244), fill=(246, 244, 236, 255), width=20)
    draw.ellipse((246, 248, 266, 268), fill=(246, 244, 236, 255))
    draw.arc((88, 316, 424, 446), 194, 348, fill=glow + (118,), width=8)


def draw_building(draw: ImageDraw.ImageDraw, accent: tuple[int, int, int], glow: tuple[int, int, int]) -> None:
    centered_shadow(draw, (114, 116, 398, 396))
    draw.polygon([(150, 210), (256, 136), (362, 210)], fill=accent + (255,))
    draw.rounded_rectangle((172, 204, 340, 360), radius=24, fill=(246, 244, 236, 255))
    draw.rounded_rectangle((234, 264, 278, 360), radius=14, fill=accent + (255,))
    for x in (194, 286):
        draw.rounded_rectangle((x, 236, x + 28, 278), radius=10, fill=(23, 91, 72, 78))
        draw.rounded_rectangle((x, 292, x + 28, 334), radius=10, fill=(23, 91, 72, 78))
    draw.line((256, 144, 256, 98), fill=(246, 244, 236, 255), width=8)
    draw.polygon([(256, 98), (304, 116), (256, 134)], fill=glow + (225,))
    draw.arc((88, 318, 424, 448), 194, 348, fill=glow + (118,), width=8)


DRAWERS = {
    "road": draw_road,
    "lightbulb": draw_streetlight,
    "tree": draw_tree,
    "trash": draw_trash,
    "bench": draw_bench,
    "droplets": draw_drain,
    "triangle-alert": draw_sign,
    "building-2": draw_building,
}


def save_icon(icon: Image.Image, key: str) -> None:
    for directory in (MASTER_DIR, MOBILE_DIR, ADMIN_DIR):
        directory.mkdir(parents=True, exist_ok=True)
        icon.save(directory / f"{key}.png", format="PNG", optimize=True)


def save_preview(icons: list[tuple[str, Image.Image]]) -> None:
    columns = 4
    rows = math.ceil(len(icons) / columns)
    tile_w = 312
    tile_h = 374
    sheet = Image.new("RGBA", (columns * tile_w, rows * tile_h), (244, 241, 231, 255))
    draw = ImageDraw.Draw(sheet, "RGBA")

    for index, (label, icon) in enumerate(icons):
        col = index % columns
        row = index // columns
        x = col * tile_w
        y = row * tile_h
        draw.rounded_rectangle((x + 18, y + 18, x + tile_w - 18, y + tile_h - 18), radius=28, fill=(255, 253, 248, 255), outline=(220, 214, 198, 255), width=2)
        sheet.alpha_composite(icon.resize((212, 212), Image.LANCZOS), (x + 50, y + 42))
        label_bar = Image.new("RGBA", (tile_w - 56, 58), (18, 61, 49, 255))
        sheet.alpha_composite(label_bar, (x + 28, y + 280))
        label_draw = ImageDraw.Draw(sheet, "RGBA")
        label_draw.text((x + 48, y + 296), label, fill=(255, 255, 255, 255))

    sheet.save(MASTER_DIR / "category-visuals-preview.png", format="PNG", optimize=True)


def main() -> None:
    entries = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    icons: list[tuple[str, Image.Image]] = []

    for entry in entries:
        key = entry["key"]
        accent = hex_to_rgb(entry["accent"])
        glow = hex_to_rgb(entry["glow"])
        icon, draw = create_canvas(entry["accent"], entry["glow"])
        drawer = DRAWERS.get(key)
        if drawer is None:
            raise SystemExit(f"Missing drawer for category key: {key}")
        drawer(draw, accent, glow)
        final_icon = add_shadow(icon)
        save_icon(final_icon, key)
        icons.append((entry["short_label"], final_icon))

    save_preview(icons)

    # Keep a copy of the catalog next to generated assets for inspection.
    shutil.copy2(CATALOG_PATH, MASTER_DIR / "category-visuals.json")


if __name__ == "__main__":
    main()
