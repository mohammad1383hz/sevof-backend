import traceback

try:
    from pytrends.request import TrendReq
    pytrends = TrendReq(hl="en-US", tz=210, timeout=(10, 25))
    pytrends.build_payload(["ساخت ایران"], cat=0, timeframe="today 12-m", geo="IR", gprop="")
    df = pytrends.interest_over_time()
    print("موفق! تعداد ردیف‌ها:", len(df))
    print(df.head())
except Exception:
    print("خطای واقعی:")
    traceback.print_exc()