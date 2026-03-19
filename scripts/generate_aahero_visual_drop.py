#!/usr/bin/env python3
"""
Generate a first production-ready visual drop from AAhero source assets.

This script:
- maps AAhero badges into the existing badge filenames expected by the app
- maps AAhero agents into character slots
- generates simple branded composites for duo/hero/moment slots
- reuses the current installed terrain assets as fallback for missing scenes
"""

from __future__ import annotations

import argparse
import shutil
from datetime import datetime
from pathlib import Path

from PIL import Image, ImageColor, ImageDraw, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
AAHERO_DIR = Path("/home/tarzzan/AAhero")
CURRENT_GENERATED_DIR = ROOT / "mobile" / "assets" / "generated-visuals"

BRAND = {
    "sand": "#F4E7CF",
    "sun": "#D2A24C",
    "canopy": "#1E5A46",
    "deep": "#123A31",
    "mist": "#EDF3EE",
    "ember": "#C96B34",
    "river": "#3C8390",
}

BADGE_MAP = {
    "cat-01-road-badge.png": "cat-01-road-badge-v2-1x1.png",
    "cat-02-lighting-badge.png": "cat-02-lighting-badge-v2-1x1.png",
    "cat-03-greenery-badge.png": "cat-03-greenery-badge-v2-1x1.png",
    "cat-04-cleanliness-badge.png": "cat-04-cleanliness-badge-v2-1x1.png",
    "cat-05-furniture-badge.png": "cat-05-furniture-badge-v2-1x1.png",
    "cat-06-networks-badge.png": "cat-06-networks-badge-v2-1x1.png",
    "cat-07-signage-badge.png": "cat-07-signage-badge-v2-1x1.png",
    "cat-08-buildings-badge.png": "cat-08-buildings-badge-v2-1x1.png",
}

CHARACTER_TARGETS = {
    "char-04-agent-a1-bust-4x5.png": AAHERO_DIR / "agent-02-field-services.png",
    "char-05-agent-a2-bust-4x5.png": AAHERO_DIR / "agent-01-citizen-relations.png",
}

FALLBACK_COPY = {
    "characters": [
        "char-01-mascot-m1-portrait-4x5.png",
        "char-02-mascot-m2-portrait-4x5.png",
        "char-03-mascot-m3-portrait-4x5.png",
    ],
    "terrain": [
        "ill-01-road-pothole-rain-4x5.png",
        "ill-02-lighting-streetlight-dusk-4x5.png",
        "ill-03-cleanliness-illegal-dumping-4x5.png",
        "ill-04-drain-runoff-clogged-4x5.png",
        "ill-05-greenery-overgrown-path-4x5.png",
        "ill-06-urban-furniture-damaged-4x5.png",
        "ill-07-signage-damaged-4x5.png",
        "ill-08-municipal-building-degraded-4x5.png",
    ],
}


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def load_rgba(path: Path) -> Image.Image:
    return Image.open(path).convert("RGBA")


def resize_contain(image: Image.Image, max_width: int, max_height: int) -> Image.Image:
    clone = image.copy()
    clone.thumbnail((max_width, max_height), Image.Resampling.LANCZOS)
    return clone


def background(size: tuple[int, int], top: str, bottom: str) -> Image.Image:
    width, height = size
    base = Image.new("RGBA", size, top)
    draw = ImageDraw.Draw(base)
    top_rgb = ImageColor.getrgb(top)
    bottom_rgb = ImageColor.getrgb(bottom)

    for y in range(height):
        ratio = y / max(height - 1, 1)
        color = tuple(
            int(top_rgb[i] + (bottom_rgb[i] - top_rgb[i]) * ratio)
            for i in range(3)
        )
        draw.line((0, y, width, y), fill=color)

    return base


def add_blur_blob(canvas: Image.Image, bbox: tuple[int, int, int, int], color: str, blur: int) -> None:
    overlay = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    draw.ellipse(bbox, fill=ImageColor.getrgb(color) + (140,))
    overlay = overlay.filter(ImageFilter.GaussianBlur(blur))
    canvas.alpha_composite(overlay)


def add_floor_shadow(canvas: Image.Image, center_x: int, baseline_y: int, width: int, height: int = 54) -> None:
    overlay = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    draw.ellipse(
        (center_x - width // 2, baseline_y - height // 2, center_x + width // 2, baseline_y + height // 2),
        fill=(18, 58, 49, 72),
    )
    overlay = overlay.filter(ImageFilter.GaussianBlur(18))
    canvas.alpha_composite(overlay)


def paste_character(canvas: Image.Image, character: Image.Image, left: int, top: int, max_width: int, max_height: int) -> None:
    sprite = resize_contain(character, max_width, max_height)
    shadow = Image.new("RGBA", sprite.size, (0, 0, 0, 0))
    shadow.paste(sprite.split()[-1], (0, 0))
    shadow = shadow.filter(ImageFilter.GaussianBlur(10))
    shadow_layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    shadow_layer.alpha_composite(shadow, (left + 14, top + 18))
    canvas.alpha_composite(shadow_layer)
    canvas.alpha_composite(sprite, (left, top))


def compose_duo(size: tuple[int, int], output: Path, left_agent: Path, right_agent: Path) -> None:
    canvas = background(size, BRAND["mist"], BRAND["sand"])
    width, height = size
    add_blur_blob(canvas, (40, 40, width // 2 + 180, height // 2 + 100), BRAND["sun"], 52)
    add_blur_blob(canvas, (width // 3, height // 4, width - 40, height - 90), BRAND["river"], 58)
    add_blur_blob(canvas, (width // 2 - 120, 80, width - 80, height // 2 + 120), BRAND["canopy"], 64)

    floor_y = height - 118
    add_floor_shadow(canvas, width // 2 - 120, floor_y, 290)
    add_floor_shadow(canvas, width // 2 + 140, floor_y + 12, 330)

    left_img = load_rgba(left_agent)
    right_img = load_rgba(right_agent)
    paste_character(canvas, left_img, 70, 150, width // 2 - 120, height - 220)
    paste_character(canvas, right_img, width // 2, 120, width // 2 - 90, height - 180)

    output.parent.mkdir(parents=True, exist_ok=True)
    canvas.save(output)


def compose_single(size: tuple[int, int], output: Path, source: Path, accent: str, align: str = "center") -> None:
    canvas = background(size, BRAND["mist"], BRAND["sand"])
    width, height = size
    add_blur_blob(canvas, (40, 60, width - 40, height // 2 + 120), accent, 60)
    add_blur_blob(canvas, (width // 3, height // 3, width - 50, height - 80), BRAND["deep"], 72)
    add_floor_shadow(canvas, width // 2, height - 120, min(width - 160, 520))

    sprite = load_rgba(source)
    fitted = resize_contain(sprite, int(width * 0.72), int(height * 0.76))
    if align == "left":
        left = 56
    elif align == "right":
        left = width - fitted.width - 56
    else:
        left = (width - fitted.width) // 2
    top = max(48, height - fitted.height - 120)

    paste_character(canvas, sprite, left, top, int(width * 0.72), int(height * 0.76))

    output.parent.mkdir(parents=True, exist_ok=True)
    canvas.save(output)


def copy_existing(section: str, filename: str, destination: Path) -> None:
    source = CURRENT_GENERATED_DIR / section / filename
    if not source.exists():
        raise FileNotFoundError(f"Fallback manquant: {source}")
    shutil.copy2(source, destination)


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate AAhero-based visual drop.")
    parser.add_argument("--output-dir", default=None, help="Drop output directory")
    args = parser.parse_args()

    timestamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    output_dir = Path(args.output_dir).resolve() if args.output_dir else Path("/tmp") / f"aahero-visual-drop-{timestamp}"
    ensure_dir(output_dir)

    for source_name, target_name in BADGE_MAP.items():
        shutil.copy2(AAHERO_DIR / source_name, output_dir / target_name)

    for target_name, source_path in CHARACTER_TARGETS.items():
        shutil.copy2(source_path, output_dir / target_name)

    for section, filenames in FALLBACK_COPY.items():
        for filename in filenames:
            copy_existing(section, filename, output_dir / filename)

    # Reuse the available agents to synthesize the currently most visible slots.
    agent_relations = AAHERO_DIR / "agent-01-citizen-relations.png"
    agent_field = AAHERO_DIR / "agent-02-field-services.png"

    compose_duo((1536, 1920), output_dir / "mom-01-welcome-duo-4x5.png", agent_relations, agent_field)
    compose_single((1536, 1920), output_dir / "mom-02-thank-you-after-report-4x5.png", agent_relations, BRAND["sun"], "center")
    compose_single((1536, 1920), output_dir / "mom-03-incident-in-progress-4x5.png", agent_field, BRAND["ember"], "right")
    compose_single((1536, 1920), output_dir / "mom-04-incident-resolved-4x5.png", agent_relations, BRAND["canopy"], "left")
    compose_single((1536, 1920), output_dir / "mom-05-no-incidents-yet-4x5.png", agent_relations, BRAND["river"], "center")
    compose_single((1536, 1920), output_dir / "mom-06-no-important-notifications-4x5.png", agent_field, BRAND["deep"], "center")
    compose_duo((1920, 1080), output_dir / "char-06-duo-m1-a2-hero-16x9.png", agent_relations, agent_field)
    compose_duo((1920, 1080), output_dir / "hero-01-landing-ma-commune-16x9.png", agent_relations, agent_field)
    compose_single((1080, 1920), output_dir / "hero-02-store-ma-commune-9x16.png", agent_relations, BRAND["canopy"], "center")

    print(f"drop_dir={output_dir}")


if __name__ == "__main__":
    main()
