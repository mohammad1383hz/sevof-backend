"""
Google Trends API Service (geo=IR)
-----------------------------------
یک سرویس FastAPI ساده که با استفاده از کتابخانه‌ی متن‌باز pytrends
(رابط غیررسمی اما پرکاربرد برای Google Trends) داده‌های زیر را برمی‌گرداند:

  1) نمودار "Search interest" برای هر کلمه (interest_over_time)
  2) "Top queries" و "Rising queries" (همان related_queries گوگل ترندز)

نکات مهم:
  - geo همیشه IR است (طبق درخواست شما).
  - گوگل ترندز در هر درخواست حداکثر ۵ کلمه را قبول می‌کند،
    بنابراین اگر لیست کلمات‌تان بزرگ است (مثلاً کلمات ۹ تا ۱۰۰)،
    این سرویس خودش آن‌ها را به دسته‌های ۵تایی تقسیم می‌کند.
  - بین درخواست‌ها یک فاصله‌ی زمانی (delay) رعایت می‌شود تا با
    محدودیت نرخ درخواست گوگل (rate limit) به شکل معمول و منصفانه
    کار کنید. این تاخیر برای پایداری سرویس است، نه برای فریب دادن
    سامانه‌های ضدربات — این پروژه هیچ تکنیک مخفی‌سازی هویت
    (چرخش پروکسی، جعل fingerprint، دور زدن کپچا و ...) پیاده‌سازی نمی‌کند.
  - اگر گوگل به‌طور موقت درخواست‌ها را محدود کند (HTTP 429)، سرویس
    خطای مشخصی برمی‌گرداند تا خودتان با فاصله‌ی زمانی بیشتر تلاش کنید.
"""

import time
import logging
from typing import List, Optional

from fastapi import FastAPI, HTTPException, Query
from pydantic import BaseModel
from pytrends.request import TrendReq

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("trends_service")

app = FastAPI(
    title="Iran Google Trends Service",
    description="سرویس دریافت داده‌های Google Trends برای geo=IR با pytrends",
    version="1.0.0",
)

GEO = "IR"
BATCH_SIZE = 5          # محدودیت خود گوگل ترندز
REQUEST_DELAY_SEC = 1.5  # فاصله‌ی معقول بین درخواست‌ها برای رعایت نرخ مجاز


def get_client() -> TrendReq:
    # hl فارسی/انگلیسی هر دو کار می‌کند؛ اینجا انگلیسی برای پایداری بیشتر پارسر
    # timeout و retries/backoff استاندارد pytrends برای مقابله با کندی/خطای موقت شبکه
    return TrendReq(
        hl="en-US",
        tz=210,  # تقریباً معادل تهران (UTC+3:30)
        timeout=(10, 25),
        retries=2,
        backoff_factor=0.5,
    )


def chunk_list(items: List[str], size: int) -> List[List[str]]:
    return [items[i:i + size] for i in range(0, len(items), size)]


class KeywordsRequest(BaseModel):
    keywords: List[str]
    timeframe: str = "today 12-m"  # معادل "Past year" در تصویر شما


class TrendResult(BaseModel):
    keyword: str
    interest_over_time: dict
    top_queries: list
    rising_queries: list
    error: Optional[str] = None


def fetch_single_keyword(pytrends: TrendReq, keyword: str, timeframe: str) -> TrendResult:
    try:
        pytrends.build_payload([keyword], cat=0, timeframe=timeframe, geo=GEO, gprop="")

        # 1) نمودار Search interest
        df = pytrends.interest_over_time()
        if not df.empty and keyword in df.columns:
            series = df[keyword].to_dict()
            interest_over_time = {str(k): int(v) for k, v in series.items()}
        else:
            interest_over_time = {}

        # 2) Top queries و Rising queries
        related = pytrends.related_queries()
        kw_related = related.get(keyword, {}) if related else {}

        top_df = kw_related.get("top")
        rising_df = kw_related.get("rising")

        top_queries = (
            top_df.to_dict(orient="records") if top_df is not None and not top_df.empty else []
        )
        rising_queries = (
            rising_df.to_dict(orient="records") if rising_df is not None and not rising_df.empty else []
        )

        return TrendResult(
            keyword=keyword,
            interest_over_time=interest_over_time,
            top_queries=top_queries,
            rising_queries=rising_queries,
        )

    except Exception as e:
        error_msg = f"{type(e).__name__}: {e}"
        logger.warning(f"خطا در دریافت داده برای «{keyword}»: {error_msg}")
        return TrendResult(
            keyword=keyword,
            interest_over_time={},
            top_queries=[],
            rising_queries=[],
            error=error_msg,
        )


@app.get("/")
def root():
    return {
        "service": "Iran Google Trends Service",
        "geo": GEO,
        "endpoints": {
            "/trends": "POST - لیست کلمات را بده، برای همه‌شان نمودار و کوئری‌های مرتبط برگردد",
            "/trends/single": "GET - فقط یک کلمه، ساده‌ترین حالت",
        },
    }


@app.get("/trends/single")
def trends_single(
    keyword: str = Query(..., description="کلمه‌ی مورد جستجو"),
    timeframe: str = Query("today 12-m", description="بازه‌ی زمانی، پیش‌فرض: یک سال اخیر"),
):
    pytrends = get_client()
    result = fetch_single_keyword(pytrends, keyword, timeframe)
    return result


@app.post("/trends")
def trends_bulk(req: KeywordsRequest):
    """
    لیست کلمات را می‌گیرد (مثلاً کلمات شماره‌ی ۹ تا ۱۰۰ از فایل خودتان)،
    آن‌ها را به دسته‌های ۵تایی تقسیم می‌کند و برای هر کلمه به‌صورت
    جداگانه interest_over_time و related_queries می‌گیرد.
    """
    if not req.keywords:
        raise HTTPException(status_code=400, detail="لیست کلمات خالی است")

    pytrends = get_client()
    results: List[TrendResult] = []

    batches = chunk_list(req.keywords, BATCH_SIZE)
    for batch in batches:
        for kw in batch:
            result = fetch_single_keyword(pytrends, kw, req.timeframe)
            results.append(result)
            time.sleep(REQUEST_DELAY_SEC)  # فاصله‌ی معقول بین درخواست‌ها

    return {"geo": GEO, "count": len(results), "results": results}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)