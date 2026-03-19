#!/usr/bin/env python3
"""
Build a human-readable index for the Ma Commune visual production pipeline.
"""

from __future__ import annotations

import html
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
VISUAL_DIR = ROOT / "assets" / "visual-production"
OUTPUT_DIR = VISUAL_DIR / "generated"
MANIFEST_PATH = VISUAL_DIR / "visual-production-manifest.json"


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def discover_batches() -> list[tuple[Path, dict]]:
    batches: list[tuple[Path, dict]] = []
    for path in sorted(VISUAL_DIR.glob("*.json")):
      if path.name == MANIFEST_PATH.name:
          continue
      data = load_json(path)
      if "assets" in data:
          batches.append((path, data))
    return batches


def md_escape(value: str) -> str:
    return value.replace("|", "\\|")


def build_markdown(manifest: dict, batches: list[tuple[Path, dict]]) -> str:
    lines: list[str] = []
    lines.append("# Index Production Visuelle Ma Commune")
    lines.append("")
    lines.append(f"- version : `{manifest.get('version', 'n/a')}`")
    lines.append(f"- duo recommande : `{manifest.get('recommended_duo', {}).get('mascot', 'n/a')} + {manifest.get('recommended_duo', {}).get('agent', 'n/a')}`")
    lines.append(f"- batches detectes : `{len(batches)}`")
    lines.append(f"- assets total : `{sum(len(batch.get('assets', [])) for _, batch in batches)}`")
    lines.append("")
    lines.append("## Batches")
    lines.append("")

    for path, batch in batches:
        assets = batch.get("assets", [])
        lines.append(f"### `{batch.get('batch_id', path.stem)}`")
        lines.append("")
        if batch.get("description"):
            lines.append(batch["description"])
            lines.append("")
        lines.append(f"- fichier : `{path.relative_to(ROOT)}`")
        lines.append(f"- assets : `{len(assets)}`")
        lines.append("")
        lines.append("| ID | Label | Ratio | Cibles | Fichier |")
        lines.append("|---|---|---|---|---|")
        for asset in assets:
            targets = ", ".join(asset.get("surface_target", []))
            lines.append(
                f"| `{md_escape(asset.get('id', ''))}` | {md_escape(asset.get('label', ''))} | `{md_escape(asset.get('ratio', ''))}` | {md_escape(targets)} | `{md_escape(asset.get('filename', ''))}` |"
            )
        lines.append("")

    lines.append("## Categories")
    lines.append("")
    lines.append("| Categorie | Badge | Scene |")
    lines.append("|---|---|---|")
    for category in manifest.get("categories", []):
        lines.append(
            f"| {md_escape(category.get('label', ''))} | `{category.get('asset_badge_id', '')}` | `{category.get('asset_scene_id', '')}` |"
        )
    lines.append("")
    return "\n".join(lines) + "\n"


def build_html(manifest: dict, batches: list[tuple[Path, dict]]) -> str:
    cards: list[str] = []
    for path, batch in batches:
        rows = []
        for asset in batch.get("assets", []):
            targets = ", ".join(asset.get("surface_target", []))
            rows.append(
                "<tr>"
                f"<td><code>{html.escape(asset.get('id', ''))}</code></td>"
                f"<td>{html.escape(asset.get('label', ''))}</td>"
                f"<td><code>{html.escape(asset.get('ratio', ''))}</code></td>"
                f"<td>{html.escape(targets)}</td>"
                f"<td><code>{html.escape(asset.get('filename', ''))}</code></td>"
                "</tr>"
            )
        cards.append(
            "<section class='card'>"
            f"<h2>{html.escape(batch.get('batch_id', path.stem))}</h2>"
            f"<p>{html.escape(batch.get('description', ''))}</p>"
            f"<p class='meta'>{html.escape(str(path.relative_to(ROOT)))}</p>"
            "<table>"
            "<thead><tr><th>ID</th><th>Label</th><th>Ratio</th><th>Cibles</th><th>Fichier</th></tr></thead>"
            f"<tbody>{''.join(rows)}</tbody>"
            "</table>"
            "</section>"
        )

    category_rows = []
    for category in manifest.get("categories", []):
        category_rows.append(
            "<tr>"
            f"<td>{html.escape(category.get('label', ''))}</td>"
            f"<td><code>{html.escape(category.get('asset_badge_id', ''))}</code></td>"
            f"<td><code>{html.escape(category.get('asset_scene_id', ''))}</code></td>"
            "</tr>"
        )

    return f"""<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Index Production Visuelle Ma Commune</title>
  <style>
    :root {{
      --bg: #f5f4ef;
      --ink: #132118;
      --muted: #5b6b61;
      --card: #fffdf8;
      --line: #dfd8c9;
      --canopy: #0f4c2a;
      --awara: #d79f2b;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      font-family: Manrope, system-ui, sans-serif;
      background: linear-gradient(180deg, #f7f3eb 0%, #eef4ef 100%);
      color: var(--ink);
    }}
    .shell {{
      max-width: 1220px;
      margin: 0 auto;
      padding: 28px;
    }}
    h1 {{ margin: 0 0 10px; font-size: 40px; }}
    .lead {{
      color: var(--muted);
      margin: 0 0 20px;
      line-height: 1.6;
    }}
    .meta-grid {{
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 22px;
    }}
    .meta-card, .card {{
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 20px;
      box-shadow: 0 18px 40px rgba(15, 76, 42, 0.06);
    }}
    .meta-card {{
      padding: 16px 18px;
    }}
    .meta-card strong {{
      display: block;
      color: var(--canopy);
      margin-bottom: 6px;
    }}
    .grid {{
      display: grid;
      gap: 18px;
    }}
    .card {{
      padding: 20px;
    }}
    .card h2 {{
      margin: 0 0 8px;
      font-size: 22px;
    }}
    .meta {{
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 14px;
    }}
    table {{
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }}
    th, td {{
      text-align: left;
      padding: 10px 8px;
      border-top: 1px solid var(--line);
      vertical-align: top;
    }}
    th {{
      color: var(--canopy);
      font-size: 12px;
      letter-spacing: .04em;
      text-transform: uppercase;
    }}
    code {{
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 12px;
      color: #12486a;
    }}
    .category {{
      margin-top: 18px;
    }}
    @media (max-width: 900px) {{
      .meta-grid {{ grid-template-columns: 1fr; }}
      table {{ display: block; overflow-x: auto; }}
    }}
  </style>
</head>
<body>
  <div class="shell">
    <h1>Index Production Visuelle Ma Commune</h1>
    <p class="lead">Vue d'ensemble de la production visuelle cadrée pour <strong>Ma Commune</strong> : personnages, terrain, moments produit et héros commerciaux.</p>
    <div class="meta-grid">
      <div class="meta-card">
        <strong>Version</strong>
        <span>{html.escape(manifest.get('version', 'n/a'))}</span>
      </div>
      <div class="meta-card">
        <strong>Duo recommandé</strong>
        <span>{html.escape(manifest.get('recommended_duo', {}).get('mascot', 'n/a'))} + {html.escape(manifest.get('recommended_duo', {}).get('agent', 'n/a'))}</span>
      </div>
      <div class="meta-card">
        <strong>Assets total</strong>
        <span>{sum(len(batch.get('assets', [])) for _, batch in batches)}</span>
      </div>
    </div>
    <div class="grid">
      {''.join(cards)}
      <section class="card category">
        <h2>Correspondance catégories</h2>
        <table>
          <thead><tr><th>Catégorie</th><th>Badge</th><th>Scène</th></tr></thead>
          <tbody>{''.join(category_rows)}</tbody>
        </table>
      </section>
    </div>
  </div>
</body>
</html>
"""


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    manifest = load_json(MANIFEST_PATH)
    batches = discover_batches()
    markdown = build_markdown(manifest, batches)
    html_output = build_html(manifest, batches)
    (OUTPUT_DIR / "visual-production-index.md").write_text(markdown, encoding="utf-8")
    (OUTPUT_DIR / "visual-production-index.html").write_text(html_output, encoding="utf-8")
    print(f"generated_md={OUTPUT_DIR / 'visual-production-index.md'}")
    print(f"generated_html={OUTPUT_DIR / 'visual-production-index.html'}")
    print(f"batches={len(batches)}")
    print(f"assets={sum(len(batch.get('assets', [])) for _, batch in batches)}")


if __name__ == "__main__":
    main()
