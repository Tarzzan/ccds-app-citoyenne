#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

python3 - "$ROOT_DIR" <<'PY'
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
catalog_path = root / "assets" / "category-visuals" / "category-visuals.json"
generated_dir = root / "assets" / "category-visuals" / "generated"
mobile_dir = root / "mobile" / "assets" / "category-icons"
admin_dir = root / "admin" / "assets" / "img" / "category-icons"
mobile_theme_path = root / "mobile" / "src" / "theme" / "categoryVisuals.ts"
generated_catalog_copy = generated_dir / "category-visuals.json"
preview_png = generated_dir / "category-visuals-preview.png"
preview_html = root / "assets" / "category-visuals" / "index.html"

errors: list[str] = []
required_fields = {"key", "label", "short_label", "description", "accent", "glow", "aliases"}

if not catalog_path.is_file():
    errors.append(f"catalogue introuvable: {catalog_path}")
else:
    try:
        catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        errors.append(f"catalogue JSON invalide: {exc}")
        catalog = []

    if not isinstance(catalog, list) or not catalog:
        errors.append("catalogue vide ou format inattendu")
        catalog = []

    keys: list[str] = []
    for index, entry in enumerate(catalog):
        if not isinstance(entry, dict):
            errors.append(f"entree catalogue #{index + 1} invalide")
            continue

        missing = sorted(required_fields - entry.keys())
        if missing:
            errors.append(f"{entry.get('key', f'entree#{index + 1}')} champs manquants: {', '.join(missing)}")

        key = entry.get("key")
        if not isinstance(key, str) or not key:
            errors.append(f"entree catalogue #{index + 1}: cle absente ou invalide")
            continue

        keys.append(key)

        aliases = entry.get("aliases")
        if not isinstance(aliases, list) or not aliases:
            errors.append(f"{key}: aliases absents ou vides")

        for directory, label in (
            (generated_dir, "generated"),
            (mobile_dir, "mobile"),
            (admin_dir, "admin"),
        ):
            icon_path = directory / f"{key}.png"
            if not icon_path.is_file():
                errors.append(f"{label}: icone manquante pour {key} -> {icon_path}")

    duplicate_keys = sorted({key for key in keys if keys.count(key) > 1})
    if duplicate_keys:
        errors.append(f"cles dupliquees dans le catalogue: {', '.join(duplicate_keys)}")

    if preview_png.is_file() is False:
        errors.append(f"apercu PNG manquant: {preview_png}")

    if preview_html.is_file() is False:
        errors.append(f"apercu HTML manquant: {preview_html}")

    if not generated_catalog_copy.is_file():
        errors.append(f"copie du catalogue manquante: {generated_catalog_copy}")
    elif catalog_path.read_bytes() != generated_catalog_copy.read_bytes():
        errors.append("la copie du catalogue dans assets/category-visuals/generated n'est pas synchronisee")

    if mobile_theme_path.is_file():
        mobile_theme_source = mobile_theme_path.read_text(encoding="utf-8")
        mapping_keys = sorted(
            set(
                match[0] or match[1]
                for match in re.findall(
                    r"^\s*(?:'([^']+)'|([A-Za-z0-9_-]+)):\s*require\('../../assets/category-icons/[^']+\.png'\),\s*$",
                    mobile_theme_source,
                    flags=re.MULTILINE,
                )
            )
        )
        catalog_keys = sorted(set(keys))
        if mapping_keys != catalog_keys:
            missing_in_theme = sorted(set(catalog_keys) - set(mapping_keys))
            extra_in_theme = sorted(set(mapping_keys) - set(catalog_keys))
            if missing_in_theme:
                errors.append(f"mapping mobile incomplet: {', '.join(missing_in_theme)}")
            if extra_in_theme:
                errors.append(f"mapping mobile en trop: {', '.join(extra_in_theme)}")
    else:
        errors.append(f"registre mobile introuvable: {mobile_theme_path}")

if errors:
    print("[category-visuals] KO   incoherences detectees", file=sys.stderr)
    for error in errors:
        print(f" - {error}", file=sys.stderr)
    raise SystemExit(1)

print("[category-visuals] OK   catalogue, assets et mapping mobile alignes")
PY
