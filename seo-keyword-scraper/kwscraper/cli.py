"""CLI for the SEO keyword scraper."""

import argparse
import re
import sys
from pathlib import Path
from typing import Any

from . import __version__
from .export import to_csv, to_json
from .suggest import ResultRow, fetch_suggestions, fetch_with_alpha


def _use_rich() -> tuple[bool, Any, Any, Any, Any]:
    """Use rich for progress and console if available."""
    try:
        from rich.console import Console
        from rich.progress import Progress, SpinnerColumn, TextColumn

        return True, Console(), Progress, SpinnerColumn, TextColumn
    except ImportError:
        return False, None, None, None, None


def _read_seeds(path: Path) -> list[str]:
    """Read seed queries from file: one per line, skip empty and # lines."""
    seeds: list[str] = []
    text = path.read_text(encoding="utf-8")
    for line in text.splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            seeds.append(line)
    return seeds


def run() -> None:
    parser = argparse.ArgumentParser(
        description="Fetch Google autocomplete keyword suggestions and export them.",
        prog="python -m kwscraper",
    )
    parser.add_argument(
        "query",
        type=str,
        nargs="?",
        default=None,
        help='Single seed query (e.g. "coffee table"). Optional if --file is set.',
    )
    parser.add_argument(
        "--file",
        type=Path,
        default=None,
        metavar="PATH",
        help="Path to file with one seed query per line (empty and # lines ignored).",
    )
    parser.add_argument(
        "--alpha",
        action="store_true",
        help='Expand each seed with a-z: "query a", "query b", ... for more suggestions',
    )
    parser.add_argument(
        "-o",
        "--out",
        type=str,
        default="keywords.csv",
        metavar="FILE",
        help="Output file path (default: keywords.csv).",
    )
    parser.add_argument(
        "--format",
        type=str,
        choices=("csv", "json"),
        default=None,
        help="Output format: csv or json (overrides extension from --out). Default: infer from --out; if no extension, csv.",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="(Deprecated: prefer --format json.) Force JSON output.",
    )
    parser.add_argument(
        "--lang",
        type=str,
        default="en",
        metavar="CODE",
        help="Language code for suggestions, hl= (default: en).",
    )
    parser.add_argument(
        "--country",
        type=str,
        default="GB",
        metavar="CODE",
        help="Country code for suggestions, gl= (default: GB).",
    )
    parser.add_argument(
        "--sleep",
        type=float,
        default=0.2,
        metavar="SECS",
        help="Delay in seconds between HTTP requests (default: 0.2).",
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=10.0,
        metavar="SECS",
        help="Request timeout in seconds (default: 10).",
    )
    parser.add_argument(
        "--retries",
        type=int,
        default=2,
        metavar="N",
        help="Number of retries for transient failures (default: 2).",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        metavar="N",
        help="Max number of keywords in output (0 = no limit, default: 0).",
    )
    parser.add_argument(
        "--min-len",
        type=int,
        default=0,
        metavar="N",
        help="Drop keywords shorter than this (default: 0).",
    )
    parser.add_argument(
        "--exclude",
        type=str,
        default=None,
        metavar="REGEX",
        help="Exclude keywords matching this regex (case-insensitive).",
    )
    parser.add_argument(
        "--no-sort",
        action="store_true",
        help="Preserve collection order (seed-by-seed, then alpha); dedupe by first-seen, no alphabetical sort.",
    )
    parser.add_argument(
        "--group-by-seed",
        action="store_true",
        help="Group output by seed_query (CSV: rows grouped; JSON: groups object).",
    )
    parser.add_argument(
        "-V",
        "--version",
        action="version",
        version=f"%(prog)s {__version__}",
    )

    args = parser.parse_args()

    # Resolve seeds: --file or positional query (at least one required)
    seeds: list[str] = []
    if args.file is not None:
        if not args.file.is_file():
            print(f"Error: file not found: {args.file}", file=sys.stderr)
            sys.exit(1)
        seeds = _read_seeds(args.file)
    if args.query is not None and args.query.strip():
        seeds.append(args.query.strip())
    if not seeds:
        parser.error("Provide at least one seed: a positional query and/or --file PATH")

    out_path = Path(args.out)
    if args.format is not None:
        use_json = args.format == "json"
    elif args.json:
        use_json = True
    else:
        ext = out_path.suffix.lower()
        use_json = ext == ".json" if ext else False  # no extension -> csv

    exclude_re = re.compile(args.exclude, re.IGNORECASE) if args.exclude else None
    min_len = max(0, args.min_len)

    has_rich, console, ProgressCls, SpinnerColumn, TextColumn = _use_rich()
    if has_rich and console:
        console.print(
            f"[bold]kwscraper[/] v{__version__} — [cyan]{len(seeds)} seed(s)[/], "
            f"lang=[cyan]{args.lang}[/], country=[cyan]{args.country}[/]"
        )
        if args.alpha:
            console.print("[dim]Using --alpha: expanding each seed with a–z[/]")

    all_rows: list[ResultRow] = []
    failed_seeds: list[str] = []
    for seed in seeds:
        try:
            if args.alpha:
                rows = fetch_with_alpha(
                    seed,
                    lang=args.lang,
                    country=args.country,
                    timeout=args.timeout,
                    retries=args.retries,
                    sleep_between=args.sleep,
                    sort=not args.no_sort,
                )
            else:
                rows = fetch_suggestions(
                    seed,
                    seed_query=seed,
                    lang=args.lang,
                    country=args.country,
                    timeout=args.timeout,
                    retries=args.retries,
                    sleep_after=args.sleep,
                )
            all_rows.extend(rows)
        except Exception as e:
            failed_seeds.append(seed)
            if has_rich and console:
                console.print(f"[yellow]Warning: seed {seed!r} failed: {e}[/]")
            else:
                print(f"Warning: seed {seed!r} failed: {e}", file=sys.stderr)

    if not seeds or len(failed_seeds) == len(seeds):
        if has_rich and console:
            console.print("[red]All seeds failed. Exiting.[/]")
        else:
            print("All seeds failed. Exiting.", file=sys.stderr)
        sys.exit(1)

    # Filter: min-len, exclude
    filtered: list[ResultRow] = []
    for seed, kw in all_rows:
        if len(kw) < min_len:
            continue
        if exclude_re is not None and exclude_re.search(kw):
            continue
        filtered.append((seed, kw))

    # Dedupe by (seed, keyword); sort or preserve first-seen order; limit
    if args.no_sort:
        unique = list(dict.fromkeys(filtered))
    else:
        unique = sorted(set(filtered))
    if args.limit > 0:
        unique = unique[: args.limit]

    if not unique:
        if has_rich and console:
            console.print("[yellow]No keywords after filtering.[/]")
        else:
            print("No keywords after filtering.", file=sys.stderr)
        sys.exit(1)

    meta: dict = {
        "version": __version__,
        "lang": args.lang,
        "country": args.country,
        "num_seeds": len(seeds),
        "num_keywords": len(unique),
    }

    if args.group_by_seed:
        order: list[str] = []
        groups: dict[str, list[str]] = {}
        for seed, kw in unique:
            if seed not in groups:
                order.append(seed)
                groups[seed] = []
            groups[seed].append(kw)
        groups_ordered = {s: groups[s] for s in order}
        if use_json:
            to_json(None, out_path, meta=meta, groups=groups_ordered)
            fmt = "JSON"
        else:
            rows_out = [(s, k) for s in order for k in groups[s]]
            to_csv(rows_out, out_path)
            fmt = "CSV"
    else:
        if use_json:
            to_json(unique, out_path, meta=meta)
            fmt = "JSON"
        else:
            to_csv(unique, out_path)
            fmt = "CSV"

    if has_rich and console:
        console.print(
            f"[green]Exported [bold]{len(unique)}[/] keywords to [bold]{out_path}[/] ({fmt})[/]"
        )
        if failed_seeds:
            console.print(f"[dim]{len(failed_seeds)} seed(s) had errors.[/]")
    else:
        print(f"Exported {len(unique)} keywords to {out_path} ({fmt})", file=sys.stderr)
        if failed_seeds:
            print(f"{len(failed_seeds)} seed(s) had errors.", file=sys.stderr)


def main() -> None:
    """Entry point for the console script (pip install -e .)."""
    run()
