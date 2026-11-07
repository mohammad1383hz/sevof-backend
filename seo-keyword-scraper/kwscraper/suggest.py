"""Fetch Google autocomplete keyword suggestions."""

import time
from typing import Any

import requests

SUGGEST_URL = "https://suggestqueries.google.com/complete/search"
DEFAULT_CLIENT = "firefox"

# (seed_query, keyword)
ResultRow = tuple[str, str]


def fetch_suggestions(
    query: str,
    seed_query: str | None = None,
    client: str = DEFAULT_CLIENT,
    lang: str = "en",
    country: str = "GB",
    timeout: float = 10.0,
    retries: int = 2,
    sleep_after: float = 0.0,
) -> list[ResultRow]:
    """
    Fetch autocomplete suggestions for a single query from Google.

    Args:
        query: The search term to send to the API.
        seed_query: Tag results with this seed (default: query).
        client: Client parameter (e.g. 'firefox').
        lang: hl parameter (language code).
        country: gl parameter (country code).
        timeout: Request timeout in seconds.
        retries: Number of retries on transient failure.
        sleep_after: Seconds to sleep after this request (0 = no sleep).

    Returns:
        List of (seed_query, keyword) tuples. Empty list on error.
    """
    seed = seed_query if seed_query is not None else query
    params: dict[str, Any] = {
        "client": client,
        "q": query,
        "hl": lang,
        "gl": country,
    }
    last_error: Exception | None = None
    for attempt in range(retries + 1):
        try:
            resp = requests.get(SUGGEST_URL, params=params, timeout=timeout)
            resp.raise_for_status()
            data = resp.json()
            if sleep_after > 0:
                time.sleep(sleep_after)
            if isinstance(data, list) and len(data) >= 2:
                keywords = [str(s).strip() for s in data[1] if s]
                return [(seed, k) for k in keywords]
            return []
        except (requests.RequestException, ValueError, IndexError, KeyError) as e:
            last_error = e
            if attempt < retries:
                time.sleep(sleep_after * (attempt + 1))
    return []


def fetch_with_alpha(
    base_query: str,
    client: str = DEFAULT_CLIENT,
    lang: str = "en",
    country: str = "GB",
    timeout: float = 10.0,
    retries: int = 2,
    sleep_between: float = 0.2,
    sort: bool = True,
) -> list[ResultRow]:
    """
    Fetch suggestions for base_query and for base_query + " a" through " z".

    Args:
        base_query: Base search term (e.g. "coffee table").
        client: Client parameter for the suggest API.
        lang: hl parameter.
        country: gl parameter.
        timeout: Request timeout in seconds.
        retries: Retries per request.
        sleep_between: Delay between HTTP requests in seconds.
        sort: If True, return sorted and deduplicated; if False, preserve collection order with first-seen dedupe.

    Returns:
        List of (seed_query, keyword) tuples, deduplicated. Sorted if sort=True, else natural collection order.
    """
    base = base_query.strip()
    if not base:
        return []
    seed = base
    ordered: list[ResultRow] = []

    for row in fetch_suggestions(
        base,
        seed_query=seed,
        client=client,
        lang=lang,
        country=country,
        timeout=timeout,
        retries=retries,
        sleep_after=sleep_between,
    ):
        ordered.append(row)

    for letter in "abcdefghijklmnopqrstuvwxyz":
        q = f"{base} {letter}"
        for row in fetch_suggestions(
            q,
            seed_query=seed,
            client=client,
            lang=lang,
            country=country,
            timeout=timeout,
            retries=retries,
            sleep_after=sleep_between,
        ):
            ordered.append(row)

    # Ordered dedupe: keep first occurrence
    deduped = list(dict.fromkeys(ordered))
    return sorted(deduped) if sort else deduped
