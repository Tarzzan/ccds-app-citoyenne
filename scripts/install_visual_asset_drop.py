#!/usr/bin/env python3
"""
Install a generated visual asset drop into Ma Commune target folders.
"""

from __future__ import annotations

import argparse
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
VISUAL_DIR = ROOT / "assets" / "visual-production"
GENERATED_DIR = VISUAL_DIR / "generated"
MOBILE_GENERATED_TS = ROOT / "mobile" / "src" / "theme" / "generatedVisualSources.ts"
VISUAL_MANIFEST_PATH = VISUAL_DIR / "visual-production-manifest.json"
SITE_GENERATED_REGISTRY = ROOT / "site" / "assets" / "generated-visuals" / "registry.json"

BATCH_FILES = {
    "badge-batch-01.json": "badges",
    "character-batch-01.json": "characters",
    "terrain-batch-01.json": "terrain",
    "moments-batch-01.json": "moments",
    "hero-batch-01.json": "hero",
}

TARGETS = {
    "mobile": ROOT / "mobile" / "assets" / "generated-visuals",
    "admin": ROOT / "admin" / "assets" / "img" / "generated-visuals",
    "site": ROOT / "site" / "assets" / "generated-visuals",
    "installed": VISUAL_DIR / "installed" / "current",
}

RATIO_TARGET_SIZES = {
    "1x1": (512, 512),
    "4x5": (768, 960),
    "16x9": (1280, 720),
    "9x16": (720, 1280),
}


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def to_posix_relative(path: Path, start: Path) -> str:
    return path.relative_to(start).as_posix()


def ensure_dirs() -> None:
    for base in TARGETS.values():
        for section in ("badges", "characters", "terrain", "moments", "hero"):
            (base / section).mkdir(parents=True, exist_ok=True)


def install_drop(source_dir: Path) -> dict:
    ensure_dirs()
    installed_assets = []

    for batch_file, section in BATCH_FILES.items():
        batch_path = VISUAL_DIR / batch_file
        batch = load_json(batch_path)
        for asset in batch.get("assets", []):
            filename = asset["filename"]
            source = source_dir / filename
            if not source.exists():
                raise FileNotFoundError(f"Asset manquant dans le drop: {source}")

            relative_targets = {}
            for target_name, target_base in TARGETS.items():
                destination = target_base / section / filename
                shutil.copy2(source, destination)
                relative_targets[target_name] = str(destination.relative_to(ROOT))

            installed_assets.append(
                {
                    "id": asset["id"],
                    "label": asset["label"],
                    "section": section,
                    "filename": filename,
                    "ratio": asset["ratio"],
                    "surface_target": asset.get("surface_target", []),
                    "targets": relative_targets,
                }
            )

    manifest = {
        "installed_at_utc": datetime.now(timezone.utc).isoformat(),
        "source_dir": str(source_dir),
        "assets_count": len(installed_assets),
        "assets": installed_assets,
    }
    return manifest


def write_outputs(manifest: dict) -> None:
    GENERATED_DIR.mkdir(parents=True, exist_ok=True)
    json_path = GENERATED_DIR / "installed-visual-assets.json"
    md_path = GENERATED_DIR / "installed-visual-assets.md"

    json_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=True) + "\n", encoding="utf-8")

    lines = [
        "# Installed Visual Assets",
        "",
        f"- installed_at_utc: `{manifest['installed_at_utc']}`",
        f"- source_dir: `{manifest['source_dir']}`",
        f"- assets_count: `{manifest['assets_count']}`",
        "",
        "| ID | Section | Label | Ratio | Mobile | Admin | Site |",
        "|---|---|---|---|---|---|---|",
    ]

    for asset in manifest["assets"]:
        lines.append(
            f"| `{asset['id']}` | `{asset['section']}` | {asset['label']} | `{asset['ratio']}` | `{asset['targets']['mobile']}` | `{asset['targets']['admin']}` | `{asset['targets']['site']}` |"
        )

    md_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def write_mobile_generated_sources(manifest: dict) -> None:
    visual_manifest = load_json(VISUAL_MANIFEST_PATH)
    mobile_sources: dict[str, str] = {}

    for asset in manifest["assets"]:
        mobile_rel = asset["targets"]["mobile"]
        mobile_sources[asset["id"]] = "../../" + Path(mobile_rel).relative_to("mobile").as_posix()

    category_badges: dict[str, str] = {}
    category_scenes: dict[str, str] = {}
    for category in visual_manifest.get("categories", []):
        category_id = category.get("id")
        badge_id = category.get("asset_badge_id")
        scene_id = category.get("asset_scene_id")
        if category_id and badge_id and badge_id in mobile_sources:
            category_badges[category_id] = mobile_sources[badge_id]
        if category_id and scene_id and scene_id in mobile_sources:
            category_scenes[category_id] = mobile_sources[scene_id]

    def format_require_map(name: str, mapping: dict[str, str]) -> str:
        lines = [f"export const {name}: Record<string, ImageSourcePropType> = {{"]
        for key in sorted(mapping):
            lines.append(f"  {json.dumps(key)}: require({json.dumps(mapping[key])}),")
        lines.append("};")
        return "\n".join(lines)

    content = "\n".join([
        "import { ImageSourcePropType } from 'react-native';",
        "",
        "// Fichier genere automatiquement par scripts/install_visual_asset_drop.py.",
        "// Ne pas editer manuellement : reinstaller le drop de visuels a la place.",
        "",
        format_require_map("GENERATED_VISUAL_SOURCES", mobile_sources),
        "",
        format_require_map("GENERATED_CATEGORY_BADGE_SOURCES", category_badges),
        "",
        format_require_map("GENERATED_CATEGORY_SCENE_SOURCES", category_scenes),
        "",
    ])
    MOBILE_GENERATED_TS.write_text(content, encoding="utf-8")


def write_site_generated_registry(manifest: dict) -> None:
    visual_manifest = load_json(VISUAL_MANIFEST_PATH)
    site_sources: dict[str, str] = {}
    for asset in manifest["assets"]:
        site_sources[asset["id"]] = "/" + asset["targets"]["site"].replace("\\", "/")

    category_badges: dict[str, str] = {}
    category_scenes: dict[str, str] = {}
    for category in visual_manifest.get("categories", []):
        category_id = category.get("id")
        badge_id = category.get("asset_badge_id")
        scene_id = category.get("asset_scene_id")
        if category_id and badge_id and badge_id in site_sources:
            category_badges[category_id] = site_sources[badge_id]
        if category_id and scene_id and scene_id in site_sources:
            category_scenes[category_id] = site_sources[scene_id]

    registry = {
        "installed_at_utc": manifest["installed_at_utc"],
        "hero": {
            "landing": site_sources.get("HERO-01"),
            "store": site_sources.get("HERO-02"),
        },
        "companion": {
            "agent_relations": site_sources.get("CHAR-05"),
            "duo_reference": site_sources.get("CHAR-06"),
            "welcome_duo": site_sources.get("MOM-01"),
        },
        "categories": {
            "badges": category_badges,
            "scenes": category_scenes,
        },
    }
    SITE_GENERATED_REGISTRY.parent.mkdir(parents=True, exist_ok=True)
    SITE_GENERATED_REGISTRY.write_text(json.dumps(registry, indent=2, ensure_ascii=True) + "\n", encoding="utf-8")


def optimize_mobile_assets(manifest: dict) -> None:
    for asset in manifest["assets"]:
        mobile_rel = asset["targets"]["mobile"]
        mobile_path = ROOT / mobile_rel
        ratio = asset.get("ratio")
        target_size = RATIO_TARGET_SIZES.get(ratio)
        if not target_size or not mobile_path.exists():
            continue

        with Image.open(mobile_path) as image:
            image.load()
            if image.mode not in ("RGBA", "LA"):
                image = image.convert("RGBA")
            if image.size != target_size:
                image = image.resize(target_size, Image.Resampling.LANCZOS)
            image.save(mobile_path, format="PNG", optimize=True, compress_level=9)


def main() -> None:
    parser = argparse.ArgumentParser(description="Install generated visual asset drop into target folders.")
    parser.add_argument("source_dir", help="Directory containing generated asset files named as expected by the batches.")
    args = parser.parse_args()

    source_dir = Path(args.source_dir).resolve()
    if not source_dir.exists() or not source_dir.is_dir():
        raise SystemExit(f"Source introuvable: {source_dir}")

    manifest = install_drop(source_dir)
    optimize_mobile_assets(manifest)
    write_outputs(manifest)
    write_mobile_generated_sources(manifest)
    write_site_generated_registry(manifest)
    print(f"installed_assets={manifest['assets_count']}")
    print(f"manifest={GENERATED_DIR / 'installed-visual-assets.json'}")
    print(f"report={GENERATED_DIR / 'installed-visual-assets.md'}")
    print(f"mobile_bindings={MOBILE_GENERATED_TS}")
    print(f"site_registry={SITE_GENERATED_REGISTRY}")


if __name__ == "__main__":
    main()
