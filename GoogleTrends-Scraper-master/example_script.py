from src.GoogleTrendsScraper import GoogleTrendsScraper
import os

print("Starting...")

gts = GoogleTrendsScraper(
    sleep=5,
    path_driver=os.environ['CHROMEDRIVER'],
    headless=False
)

print("Browser started")

data = gts.get_trends(
    'foo',
    '2026-07-02',
    '2026-07-07',
    'US'
)

print(data)

del gts