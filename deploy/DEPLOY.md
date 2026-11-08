# راه‌اندازی Sevof روی سرور (195.248.240.173)

این راهنما فقط چیز **اضافه** می‌کند: دیتابیس جدید `sevof`، فایل Nginx جدید `sevof`، برنامه‌ی Supervisor جدید
`sevof-horizon` و کران جدید `/etc/cron.d/sevof`. هیچ فایلی از seobot، youtix.site، aliupfit، ایمیل یا بقیه
پاک یا ویرایش نمی‌شود و هیچ سرویسی ری‌استارت نمی‌شود (Nginx فقط `reload` می‌شود که اتصال‌ها را قطع نمی‌کند).

| دامنه | کار |
|---|---|
| `sevof.com`, `www.sevof.com` | ریدایرکت 301 به `app.sevof.com` (با همان مسیر) |
| `app.sevof.com` | پنل React از `/var/www/sevof-front/build` |
| `api.sevof.com` | Laravel از `/var/www/sevof-backend/public` با PHP 8.5 FPM |

وضعیت سرور (بررسی‌شده): Ubuntu 24.04، ۱ هسته، ۱٫۹ گیگ RAM، ۲ گیگ swap (۱٫۳ گیگ پر)، دیسک ۹۲٪ پر (۳٫۴ گیگ خالی).
برای همین Sevof با تعداد worker کم تنظیم شده (`HORIZON_*` در `.env`) و فرانت روی کامپیوتر خودتان build می‌شود.

هر مرحله را کامل اجرا کنید و خروجی را نگاه کنید؛ اگر خطا دیدید ادامه ندهید.

---

## ۰. نسخه‌ی پشتیبان از تنظیمات (فقط کپی، چیزی تغییر نمی‌کند)

```bash
mkdir -p /root/backup-before-sevof
cp -a /etc/nginx /root/backup-before-sevof/nginx
cp -a /etc/supervisor /root/backup-before-sevof/supervisor
cp -a /etc/cron.d /root/backup-before-sevof/cron.d
ls /root/backup-before-sevof
```

## ۱. گرفتن آخرین کد بک‌اند

```bash
cd /var/www/sevof-backend
git pull
git log --oneline -3
ls deploy
```

## ۲. ساخت دیتابیس و کاربر (دستورات امن)

یک رمز قوی بسازید و جایی نگه دارید:

```bash
openssl rand -base64 32 | tr -d '/+=' | cut -c1-32
```

وارد MySQL شوید (روی Ubuntu معمولاً `sudo mysql` کار می‌کند؛ اگر root رمز دارد از `mysql -u root -p` استفاده کنید):

```bash
sudo mysql
```

داخل MySQL این‌ها را بزنید و `PASTE_PASSWORD_HERE` را در هر دو خط با رمز بالا عوض کنید.
همه‌ی دستورها `IF NOT EXISTS` دارند و فقط روی دیتابیس `sevof` دسترسی می‌دهند؛ به دیتابیس `seobot` و بقیه دست نمی‌زنند.

```sql
CREATE DATABASE IF NOT EXISTS `sevof` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sevof'@'localhost' IDENTIFIED BY 'PASTE_PASSWORD_HERE';
CREATE USER IF NOT EXISTS 'sevof'@'127.0.0.1' IDENTIFIED BY 'PASTE_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON `sevof`.* TO 'sevof'@'localhost';
GRANT ALL PRIVILEGES ON `sevof`.* TO 'sevof'@'127.0.0.1';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'sevof'@'127.0.0.1';
EXIT;
```

خروجی `SHOW GRANTS` باید فقط `` ON `sevof`.* `` باشد. تست اتصال:

```bash
mysql -u sevof -p -h 127.0.0.1 -e "SELECT DATABASE(); SHOW DATABASES;" sevof
```

باید فقط `sevof` (و `information_schema`/`performance_schema`) دیده شود.

## ۳. فایل `.env` بک‌اند

```bash
cd /var/www/sevof-backend
test -e .env && echo "STOP: .env already exists" || cp deploy/env/backend.env.production .env
nano .env
```

همه‌ی مقدارهای `<...>` را پر کنید. `REDIS_PASSWORD` همان مقدار seobot است؛ برای دیدنش:

```bash
grep '^REDIS_PASSWORD=' /var/www/seobot-back/.env
```

مهم: `DB_DATABASE=sevof` و `DB_USERNAME=sevof` باشد، نه seobot.

## ۴. نصب وابستگی‌ها و آماده‌سازی Laravel

```bash
cd /var/www/sevof-backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate --force
chown -R www-data:www-data storage bootstrap/cache
chown www-data:www-data .env
chmod 640 .env
chmod -R ug+rwX storage bootstrap/cache
```

قبل از migrate مطمئن شوید Laravel به دیتابیس درست وصل است (باید `sevof` چاپ شود):

```bash
sudo -u www-data php artisan tinker --execute="echo DB::connection()->getDatabaseName(), PHP_EOL;"
```

فقط اگر `sevof` چاپ شد:

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan optimize
```

`db:seed` را **اجرا نکنید** (seeder داده‌ی سایت‌های seobot را می‌خواهد). پلن رایگان خودکار ساخته می‌شود؛ پلن‌های پولی را از پنل ادمین بسازید (قیمت به سنت).

## ۵. محیط پایتون برای Keyword Scraper (فقط داخل پوشه‌ی Sevof)

```bash
cd /var/www/sevof-backend/seo-keyword-scraper
python3 -m venv .venv
.venv/bin/pip install --no-cache-dir -r requirements.txt
.venv/bin/python -c "import requests, rich; print('ok')"
```

## ۶. کلید OpenAI برای چت (جدول `gapgpt` در دیتابیس sevof)

با کاربر `sevof` (که فقط به دیتابیس sevof دسترسی دارد):

```bash
mysql -u sevof -p -h 127.0.0.1 sevof
```

```sql
INSERT INTO gapgpt (api_key, base_url, status, active_requests, created_at, updated_at)
VALUES ('PASTE_OPENAI_KEY', 'https://api.openai.com/v1', 'active', 0, NOW(), NOW());
SELECT id, base_url, status FROM gapgpt;
EXIT;
```

اختیاری، کلید Serper برای جست‌وجوی گوگل:

```sql
INSERT INTO serp_fallback_keys (provider, api_key, base_url, status, created_at, updated_at)
VALUES ('serper', 'PASTE_SERPER_KEY', 'https://google.serper.dev', 'active', NOW(), NOW());
```

## ۷. Nginx و SSL

اول مطمئن شوید فایلی به اسم sevof وجود ندارد:

```bash
ls -la /etc/nginx/sites-available/sevof /etc/nginx/sites-enabled/sevof 2>&1
```

باید «No such file» بگوید. مرحله‌ی ۱ (فقط برای گرفتن گواهی):

```bash
cp /var/www/sevof-backend/deploy/nginx/sevof-acme.conf /etc/nginx/sites-available/sevof
ln -s /etc/nginx/sites-available/sevof /etc/nginx/sites-enabled/sevof
nginx -t && systemctl reload nginx
```

گرفتن یک گواهی برای هر چهار دامنه (ایمیل را عوض کنید):

```bash
certbot certonly --webroot -w /var/www/html \
  --cert-name sevof.com \
  -d sevof.com -d www.sevof.com -d app.sevof.com -d api.sevof.com \
  --email YOUR_EMAIL --agree-tos --no-eff-email \
  --deploy-hook "systemctl reload nginx"
```

مرحله‌ی ۲ (کانفیگ کامل):

```bash
cp /var/www/sevof-backend/deploy/nginx/sevof.conf /etc/nginx/sites-available/sevof
nginx -t && systemctl reload nginx
certbot renew --dry-run --cert-name sevof.com
```

اگر `nginx -t` خطا داد، `reload` انجام نمی‌شود و سایت‌های فعلی دست‌نخورده می‌مانند.

## ۸. فرانت (build روی کامپیوتر خودتان، نه روی سرور)

روی ویندوز، در `E:\backup\front-univeciry\seobot-national`:

```bash
cp "D:/seobot-national/deploy/env/front.env.production" .env
```

`VITE_GOOGLE_CLIENT_ID` را در `.env` پر کنید، بعد:

```bash
npm ci
npm run build
scp -r build root@195.248.240.173:/var/www/sevof-front/
```

روی سرور بررسی کنید:

```bash
ls /var/www/sevof-front/build | head
```

## ۹. صف‌ها (Supervisor)

```bash
test -e /etc/supervisor/conf.d/sevof-horizon.conf && echo "STOP: exists" || cp /var/www/sevof-backend/deploy/supervisor/sevof-horizon.conf /etc/supervisor/conf.d/sevof-horizon.conf
supervisorctl reread
supervisorctl add sevof-horizon
supervisorctl status
```

باید هم `horizon` (seobot) و هم `sevof-horizon` در حالت RUNNING باشند. `add` فقط برنامه‌ی جدید را اضافه می‌کند و به horizon seobot دست نمی‌زند.

```bash
sudo -u www-data php /var/www/sevof-backend/artisan horizon:status
```

## ۱۰. کارهای زمان‌بندی‌شده (Cron)

```bash
test -e /etc/cron.d/sevof && echo "STOP: exists" || install -o root -g root -m 644 /var/www/sevof-backend/deploy/cron/sevof /etc/cron.d/sevof
cat /etc/cron.d/sevof
sudo -u www-data php /var/www/sevof-backend/artisan schedule:list
```

`schedule:list` باید این‌ها را نشان دهد: ایجنت هر دقیقه، `products:refresh` و `agent:keyword-research` ساعت 03:00،
`search-console:sync-all` ساعت 04:30، `search-console:check-indexing` ساعت 05:30، `articles:auto-refresh` ساعت 07:00،
`subscriptions:enforce-article-limits` ساعت 00:30 (همه UTC). کران root (مال seobot) دست نمی‌خورد.

## ۱۱. تست نهایی

```bash
curl -sS -o /dev/null -w "api  %{http_code}\n" https://api.sevof.com/up
curl -sS -o /dev/null -w "app  %{http_code}\n" https://app.sevof.com/
curl -sS -o /dev/null -w "root %{http_code} -> %{redirect_url}\n" https://sevof.com/subscription
curl -sS -o /dev/null -w "seobot api %{http_code}\n" https://api.youtix.site/up
curl -sS -o /dev/null -w "seobot app %{http_code}\n" https://app.youtix.site/
tail -n 50 /var/www/sevof-backend/storage/logs/laravel-$(date +%F).log 2>/dev/null
free -h
```

انتظار: `api 200`، `app 200`، `root 301 -> https://app.sevof.com/subscription` و seobot مثل قبل 200.

## ۱۲. تنظیمات بیرون از سرور

- **Google Cloud (OAuth client Sevof):** Authorized JavaScript origin `https://app.sevof.com`؛
  Redirect URI سرچ کنسول `https://api.sevof.com/api/v1/google-search-console/callback`.
- **Stripe:** Webhook روی `https://api.sevof.com/api/v1/payments/stripe/webhook` و secret آن در `STRIPE_WEBHOOK_SECRET`.
- **Shopify Partner:** App URL `https://api.sevof.com/api/v1/shopify/entry`، Redirect `https://api.sevof.com/api/v1/shopify/callback`،
  Webhooks `https://api.sevof.com/api/shopify/webhooks`.
- **ایمیل از `noreply@sevof.com`:** برای اینکه اسپم نشود، رکورد SPF و DKIM برای `sevof.com` لازم است (OpenDKIM فعلاً فقط youtix.site را امضا می‌کند).

## به‌روزرسانی‌های بعدی

بک‌اند:

```bash
cd /var/www/sevof-backend
git pull
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan horizon:terminate
```

`horizon:terminate` فقط Horizon سووف را (با prefix خودش) ری‌استارت می‌کند؛ Supervisor دوباره بالایش می‌آورد.

فرانت (بدون پاک کردن نسخه‌ی قبلی):

```bash
# روی ویندوز
npm run build
scp -r build root@195.248.240.173:/var/www/sevof-front/build-new
```

```bash
# روی سرور
cd /var/www/sevof-front
mv build "build-old-$(date +%F-%H%M)" && mv build-new build
```

## خاموش کردن موقت (بدون حذف)

```bash
mv /etc/nginx/sites-enabled/sevof /root/backup-before-sevof/sevof.disabled && nginx -t && systemctl reload nginx
supervisorctl stop sevof-horizon
mv /etc/cron.d/sevof /root/backup-before-sevof/cron-sevof.disabled
```
