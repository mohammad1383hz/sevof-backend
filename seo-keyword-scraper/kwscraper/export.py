"""Export keyword results to CSV or JSON."""

import csv
import json
from pathlib import Path

from .suggest import ResultRow


def to_csv(rows: list[ResultRow], path: str | Path) -> None:
    """Write (seed_query, keyword) rows to CSV with header seed_query, keyword."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["seed_query", "keyword"])
        writer.writerows(rows)


def to_json(
    rows: list[ResultRow] | None,
    path: str | Path,
    meta: dict | None = None,
    groups: dict[str, list[str]] | None = None,
) -> None:
    """Write results to JSON. If groups is set: { \"meta\", \"groups\": { seed: [kw, ...] } }; else { \"meta\", \"results\": [...] }."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    payload: dict = {"meta": meta or {}}
    if groups is not None:
        payload["groups"] = groups
    else:
        payload["results"] = (
            [{"seed_query": seed, "keyword": kw} for seed, kw in rows]
            if rows
            else []
        )
    with path.open("w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2, ensure_ascii=False)
