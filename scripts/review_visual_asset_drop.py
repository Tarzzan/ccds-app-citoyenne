#!/usr/bin/env python3
"""
Review a generated visual asset drop before installation.
"""

from __future__ import annotations

import argparse
import json
import struct
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
GENERATED_DIR = ROOT / "assets" / "visual-production" / "generated"
DEFAULT_PROFILE = GENERATED_DIR / "visual-generation-first-drop.json"


@dataclass
class ReviewedAsset:
    asset_id: str
    filename: str
    ratio: str
    path: Path
    width: int | None
    height: int | None
    status: str
    note: str


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def read_png_dimensions(path: Path) -> tuple[int, int]:
    with path.open("rb") as fh:
        signature = fh.read(8)
        if signature != b"\x89PNG\r\n\x1a\n":
            raise ValueError("signature PNG invalide")
        chunk_len = struct.unpack(">I", fh.read(4))[0]
        chunk_type = fh.read(4)
        if chunk_type != b"IHDR":
            raise ValueError("IHDR introuvable")
        ihdr = fh.read(chunk_len)
        if len(ihdr) != chunk_len:
            raise ValueError("IHDR incomplet")
        width, height = struct.unpack(">II", ihdr[:8])
    return width, height


def ratio_matches(width: int, height: int, ratio: str) -> bool:
    left, right = ratio.split(":")
    return width * int(right) == height * int(left)


def review_drop(source_dir: Path, profile_path: Path) -> dict:
    profile = load_json(profile_path)
    expected_assets = profile.get("assets", [])
    expected_files = {asset["filename"]: asset for asset in expected_assets}

    reviewed: list[ReviewedAsset] = []
    missing: list[str] = []
    unexpected: list[str] = []

    for path in sorted(source_dir.iterdir()):
        if path.is_dir():
            continue
        if path.name not in expected_files:
            unexpected.append(path.name)

    for asset in expected_assets:
        filename = asset["filename"]
        ratio = asset["ratio"]
        path = source_dir / filename
        if not path.exists():
            missing.append(filename)
            reviewed.append(
                ReviewedAsset(
                    asset_id=asset["id"],
                    filename=filename,
                    ratio=ratio,
                    path=path,
                    width=None,
                    height=None,
                    status="missing",
                    note="fichier absent",
                )
            )
            continue

        try:
            width, height = read_png_dimensions(path)
        except Exception as exc:  # pragma: no cover - defensive
            reviewed.append(
                ReviewedAsset(
                    asset_id=asset["id"],
                    filename=filename,
                    ratio=ratio,
                    path=path,
                    width=None,
                    height=None,
                    status="invalid",
                    note=f"png invalide: {exc}",
                )
            )
            continue

        if not ratio_matches(width, height, ratio):
            reviewed.append(
                ReviewedAsset(
                    asset_id=asset["id"],
                    filename=filename,
                    ratio=ratio,
                    path=path,
                    width=width,
                    height=height,
                    status="ratio_mismatch",
                    note=f"ratio obtenu {width}:{height}",
                )
            )
            continue

        reviewed.append(
            ReviewedAsset(
                asset_id=asset["id"],
                filename=filename,
                ratio=ratio,
                path=path,
                width=width,
                height=height,
                status="ok",
                note="pret a installer",
            )
        )

    invalid_count = sum(1 for item in reviewed if item.status != "ok")
    return {
        "reviewed_at_utc": datetime.now(timezone.utc).isoformat(),
        "source_dir": str(source_dir),
        "profile": profile.get("profile"),
        "product": profile.get("product"),
        "expected_assets_count": len(expected_assets),
        "reviewed_assets_count": len(reviewed),
        "unexpected_files": unexpected,
        "missing_files": missing,
        "invalid_assets_count": invalid_count,
        "assets": [
            {
                "id": item.asset_id,
                "filename": item.filename,
                "ratio": item.ratio,
                "width": item.width,
                "height": item.height,
                "status": item.status,
                "note": item.note,
            }
            for item in reviewed
        ],
    }


def write_report(report: dict, output_dir: Path) -> tuple[Path, Path]:
    output_dir.mkdir(parents=True, exist_ok=True)
    slug = Path(report["source_dir"]).name
    json_path = output_dir / f"visual-drop-review-{slug}.json"
    md_path = output_dir / f"visual-drop-review-{slug}.md"

    json_path.write_text(json.dumps(report, indent=2, ensure_ascii=True) + "\n", encoding="utf-8")

    lines = [
        "# Visual Drop Review",
        "",
        f"- source_dir: `{report['source_dir']}`",
        f"- product: `{report['product']}`",
        f"- profile: `{report['profile']}`",
        f"- expected_assets_count: `{report['expected_assets_count']}`",
        f"- invalid_assets_count: `{report['invalid_assets_count']}`",
        "",
    ]

    if report["missing_files"]:
        lines.append("## Missing")
        for item in report["missing_files"]:
            lines.append(f"- `{item}`")
        lines.append("")

    if report["unexpected_files"]:
        lines.append("## Unexpected")
        for item in report["unexpected_files"]:
            lines.append(f"- `{item}`")
        lines.append("")

    lines.extend(
        [
            "## Assets",
            "",
            "| ID | Fichier | Ratio Attendu | Dimensions | Statut | Note |",
            "|---|---|---|---|---|---|",
        ]
    )

    for asset in report["assets"]:
        dimensions = "n/a"
        if asset["width"] and asset["height"]:
            dimensions = f"{asset['width']}x{asset['height']}"
        lines.append(
            f"| `{asset['id']}` | `{asset['filename']}` | `{asset['ratio']}` | `{dimensions}` | `{asset['status']}` | {asset['note']} |"
        )

    md_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return json_path, md_path


def main() -> None:
    parser = argparse.ArgumentParser(description="Review a generated visual asset drop before installation.")
    parser.add_argument("source_dir", help="Directory containing the generated files to validate.")
    parser.add_argument(
        "--profile",
        default=str(DEFAULT_PROFILE),
        help="JSON export describing the expected asset list. Defaults to the latest first drop export.",
    )
    parser.add_argument(
        "--output-dir",
        default=str(GENERATED_DIR),
        help="Directory where the review report will be written.",
    )
    args = parser.parse_args()

    source_dir = Path(args.source_dir).resolve()
    profile_path = Path(args.profile).resolve()
    output_dir = Path(args.output_dir).resolve()

    if not source_dir.exists() or not source_dir.is_dir():
        raise SystemExit(f"Source introuvable: {source_dir}")
    if not profile_path.exists():
        raise SystemExit(f"Profil introuvable: {profile_path}")

    report = review_drop(source_dir, profile_path)
    json_path, md_path = write_report(report, output_dir)

    print(f"review_json={json_path}")
    print(f"review_md={md_path}")
    print(f"invalid_assets_count={report['invalid_assets_count']}")

    if report["invalid_assets_count"] > 0 or report["unexpected_files"]:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
