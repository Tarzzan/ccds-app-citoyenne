#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
from typing import Iterable

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
MOBILE_GENERATED = ROOT / "mobile" / "assets" / "generated-visuals"

RATIO_TARGET_SIZES = {
    "1x1": (512, 512),
    "4x5": (768, 960),
    "16x9": (1280, 720),
    "9x16": (720, 1280),
}


def iter_pngs() -> Iterable[Path]:
    for path in sorted(MOBILE_GENERATED.rglob("*.png")):
        if path.is_file():
            yield path


def resolve_target_size(path: Path) -> tuple[int, int] | None:
    name = path.stem
    for suffix, size in RATIO_TARGET_SIZES.items():
        if name.endswith(suffix):
            return size
    return None


def optimize_png(path: Path) -> tuple[tuple[int, int], tuple[int, int]] | None:
    target_size = resolve_target_size(path)
    if not target_size:
        return None

    with Image.open(path) as image:
        image.load()
        original = image.size

        if image.mode not in ("RGBA", "LA"):
            image = image.convert("RGBA")

        if image.size != target_size:
            image = image.resize(target_size, Image.Resampling.LANCZOS)

        image.save(path, format="PNG", optimize=True, compress_level=9)

    return original, target_size


def main() -> int:
    if not MOBILE_GENERATED.exists():
        raise SystemExit(f"Missing directory: {MOBILE_GENERATED}")

    optimized = 0
    for path in iter_pngs():
        result = optimize_png(path)
        if not result:
            continue
        original, resized = result
        optimized += 1
        print(f"[optimized] {path.relative_to(ROOT)} {original} -> {resized}")

    print(f"[summary] optimized_files={optimized}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
