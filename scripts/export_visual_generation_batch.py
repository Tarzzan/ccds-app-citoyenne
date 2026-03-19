#!/usr/bin/env python3
"""
Export a ready-to-produce batch for external visual generation workflows.
"""

from __future__ import annotations

import csv
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
VISUAL_DIR = ROOT / "assets" / "visual-production"
OUTPUT_DIR = VISUAL_DIR / "generated"
MANIFEST_PATH = VISUAL_DIR / "visual-production-manifest.json"

BATCH_FILES = [
    VISUAL_DIR / "character-batch-01.json",
    VISUAL_DIR / "badge-batch-01.json",
    VISUAL_DIR / "terrain-batch-01.json",
    VISUAL_DIR / "moments-batch-01.json",
    VISUAL_DIR / "hero-batch-01.json",
]

FIRST_DROP_IDS = [
    "CHAR-01",
    "CHAR-02",
    "CHAR-03",
    "CHAR-04",
    "CHAR-05",
    "CHAR-06",
    "CAT-01",
    "CAT-02",
    "CAT-03",
    "CAT-04",
    "CAT-05",
    "CAT-06",
    "CAT-07",
    "CAT-08",
    "ILL-01",
    "ILL-02",
    "ILL-03",
    "ILL-04",
    "ILL-05",
    "ILL-06",
    "ILL-07",
    "ILL-08",
]


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def discover_assets() -> dict[str, dict]:
    assets: dict[str, dict] = {}
    for path in BATCH_FILES:
        batch = load_json(path)
        for asset in batch.get("assets", []):
            assets[asset["id"]] = {
                **asset,
                "batch_id": batch.get("batch_id", path.stem),
                "description": batch.get("description", ""),
                "style_block": batch.get("style_block", ""),
                "negative_prompt": batch.get("negative_prompt", ""),
                "batch_file": str(path.relative_to(ROOT)),
            }
    return assets


def export_json(package: dict, path: Path) -> None:
    path.write_text(json.dumps(package, indent=2, ensure_ascii=True) + "\n", encoding="utf-8")


def export_csv(assets: list[dict], path: Path) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(
            [
                "id",
                "label",
                "batch_id",
                "ratio",
                "filename",
                "surface_target",
                "style_block",
                "negative_prompt",
                "prompt",
            ]
        )
        for asset in assets:
            writer.writerow(
                [
                    asset["id"],
                    asset["label"],
                    asset["batch_id"],
                    asset["ratio"],
                    asset["filename"],
                    ", ".join(asset.get("surface_target", [])),
                    asset.get("style_block", ""),
                    asset.get("negative_prompt", ""),
                    asset.get("prompt", ""),
                ]
            )


def export_markdown(package: dict, path: Path) -> None:
    lines: list[str] = []
    lines.append("# Drop Visuel Production Batch 01")
    lines.append("")
    lines.append(f"- version : `{package['version']}`")
    lines.append(f"- product : `{package['product']}`")
    lines.append(f"- profile : `{package['profile']}`")
    lines.append(f"- assets : `{len(package['assets'])}`")
    lines.append(
        f"- duo recommande : `{package['recommended_duo']['mascot']} + {package['recommended_duo']['agent']}`"
    )
    lines.append("")
    lines.append("## Assets")
    lines.append("")
    for asset in package["assets"]:
        lines.append(f"### `{asset['id']}` — {asset['label']}")
        lines.append("")
        lines.append(f"- batch : `{asset['batch_id']}`")
        lines.append(f"- ratio : `{asset['ratio']}`")
        lines.append(f"- fichier : `{asset['filename']}`")
        lines.append(f"- cibles : `{', '.join(asset.get('surface_target', []))}`")
        lines.append(f"- source batch : `{asset['batch_file']}`")
        lines.append("")
        lines.append("Style block :")
        lines.append("")
        lines.append("```text")
        lines.append(asset.get("style_block", ""))
        lines.append("```")
        lines.append("")
        lines.append("Negative prompt :")
        lines.append("")
        lines.append("```text")
        lines.append(asset.get("negative_prompt", ""))
        lines.append("```")
        lines.append("")
        lines.append("Prompt :")
        lines.append("")
        lines.append("```text")
        lines.append(asset.get("prompt", ""))
        lines.append("```")
        lines.append("")

    path.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    manifest = load_json(MANIFEST_PATH)
    assets_by_id = discover_assets()
    selected_assets = [assets_by_id[asset_id] for asset_id in FIRST_DROP_IDS]

    package = {
        "version": manifest.get("version", "n/a"),
        "product": manifest.get("product", "Ma Commune"),
        "profile": "first-drop-batch-01",
        "recommended_duo": manifest.get("recommended_duo", {}),
        "assets": selected_assets,
    }

    json_path = OUTPUT_DIR / "visual-generation-first-drop.json"
    csv_path = OUTPUT_DIR / "visual-generation-first-drop.csv"
    md_path = OUTPUT_DIR / "visual-generation-first-drop.md"

    export_json(package, json_path)
    export_csv(selected_assets, csv_path)
    export_markdown(package, md_path)

    print(f"json={json_path}")
    print(f"csv={csv_path}")
    print(f"md={md_path}")
    print(f"assets={len(selected_assets)}")


if __name__ == "__main__":
    main()
