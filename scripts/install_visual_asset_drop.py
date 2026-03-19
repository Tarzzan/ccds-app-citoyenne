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


ROOT = Path(__file__).resolve().parents[1]
VISUAL_DIR = ROOT / "assets" / "visual-production"
GENERATED_DIR = VISUAL_DIR / "generated"

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


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


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


def main() -> None:
    parser = argparse.ArgumentParser(description="Install generated visual asset drop into target folders.")
    parser.add_argument("source_dir", help="Directory containing generated asset files named as expected by the batches.")
    args = parser.parse_args()

    source_dir = Path(args.source_dir).resolve()
    if not source_dir.exists() or not source_dir.is_dir():
        raise SystemExit(f"Source introuvable: {source_dir}")

    manifest = install_drop(source_dir)
    write_outputs(manifest)
    print(f"installed_assets={manifest['assets_count']}")
    print(f"manifest={GENERATED_DIR / 'installed-visual-assets.json'}")
    print(f"report={GENERATED_DIR / 'installed-visual-assets.md'}")


if __name__ == "__main__":
    main()
