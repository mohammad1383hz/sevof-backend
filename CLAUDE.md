# Sevof — backend (Laravel API)

Sevof is the global (non-Iranian) edition of the seobot/Youtix SEO agent: it analyzes a website,
finds keyword/content opportunities, writes SEO articles and product articles with AI, publishes them
to the customer's site, and keeps them fresh with Google Search Console data.

This repo was forked from `D:\seobot` (Iranian product). **Never copy changes back into seobot, and never
push this repo to seobot's remote** (`origin` still points at `seobot-back` — give Sevof its own remote first).
The front is `E:\backup\front-univeciry\seobot-national` (React, 8 UI languages).

## What differs from seobot

| Area | seobot | Sevof |
|---|---|---|
| Base language | Persian | English; each site's content language is auto-detected |
| Login | phone OTP (SMS), email, Google | **email (+ emailed 6-digit code) and Google only** — no SMS anywhere |
| Payments | Zarinpal / Saman (Toman) | **Stripe Checkout**, integer cents in `STRIPE_CURRENCY` (default `usd`) |
| Store platforms | WordPress/WooCommerce plugin, Mixin | WordPress/WooCommerce plugin, **Shopify (OAuth app)**; Wix/Webflow/Notion planned |
| AI provider | GapGPT/AvalAI proxies | OpenAI directly (`gapgpt` table is just the key pool; set `base_url=https://api.openai.com/v1`) |
| Timezone | Asia/Tehran | `APP_TIMEZONE` (default UTC) |
| Passwords | compared in plain text (bug in seobot) | `Hash::make` / `Hash::check` |

## Stack
PHP 8.2+, Laravel 12, Sanctum (Bearer tokens for the SPA), Horizon on Redis, MySQL.
Queues: `default` (user-facing jobs) and `redis-background` connection with `background` and `products` queues
(see `config/horizon.php`). Scheduler in `routes/console.php` (autopilot every minute, nightly product refresh,
Search Console sync, auto-refresh, index checks).

Commands: `php artisan serve`, `php artisan horizon`, `php artisan schedule:work`,
`php vendor/bin/phpunit` (sqlite in-memory; see "Tests").

## Key flows and where they live

- **Auth** — `app/Http/Controllers/Api/AuthController.php`, `app/Services/Auth/*`.
  `POST /api/v1/auth/email/login` creates the account on first use and emails a code; `/auth/email/verify`
  completes it; `/auth/google` takes a Google ID token (only `email_verified` emails may link to an existing account).
  Admin API login is email + hashed password on `users.is_admin`. All auth routes are throttled.
- **Billing** — `SubscriptionController` + `app/Services/Billing/StripeService.php`.
  `POST /plans/{plan}/subscribe` → pending `payments` row + Checkout Session (`payments.authority` = session id) →
  Stripe redirects to `{FRONT_URL}/payment/result?session_id=…` → front calls `POST /payments/stripe/confirm`;
  the webhook `POST /payments/stripe/webhook` does the same. `StripeService::fulfill()` is idempotent (row lock),
  checks amount + currency, then `SubscriptionService::purchaseSubscription()` (activate now, or queue behind an
  active paid plan). Plans are one-time purchases of 1/3/6 months, not recurring Stripe subscriptions.
  Plan `price` is integer cents; `PlanResource` exposes `currency`.
  Credits: `TokenUsageService` converts AI cost (USD × `USD_MULTIPLIER`) into credits at `seobot.credit_value_usd`
  (default $0.01 per credit). Set plan `credit` values in those units.
- **Publishing targets** — resolved per site, Shopify first:
  `ArticlePublisherResolver`, `ProductArticlePublisherResolver`, `PageProductSeoPublisherResolver`.
  Keep the three rules identical (`$site->shopifyConnection?->isConnected()` → Shopify, else WordPress).
- **Shopify** — `app/Services/Shopify/*`, `app/Http/Controllers/Shopify/ShopifyConnectionController.php`,
  `config/shopify.php`, routes in `routes/wordpress.php`.
  - OAuth: `POST /v1/sites/{site}/shopify/install {shop}` returns the consent URL; Shopify returns to
    `GET /api/v1/shopify/callback` (HMAC + one-time encrypted state verified) → token stored encrypted in
    `shopify_connections` → first product sync is queued.
    `GET /api/v1/shopify/entry` is the App URL for installs started from Shopify (redirects to the front's
    `/shopify/connect?shop=` so the user picks a site).
  - Webhooks: `POST /api/shopify/webhooks` (HMAC) handles `app/uninstalled`, `shop/redact`, and the GDPR
    customer topics (we store no customer data).
  - Articles and product articles are Shopify **blog articles** (`articleCreate/Update/Delete`); the article
    GID lives in `articles.shopify_article_id` / `product_articles.shopify_article_id`, the public URL in `shopify_url`.
    Images are uploaded to Shopify Files (staged upload → `fileCreate` → poll the CDN URL), cached per store + hash.
  - Product SEO writes `descriptionHtml`, `seo{title,description}`, `tagsAdd`, image alts via `fileUpdate`.
    Shopify has no short description / focus keyword / free-form attributes, so `PageProductGapDetectionService`
    never reports those gaps for `platform = shopify`.
  - Catalog: `SiteProductService::sync()` reads products + collections through `ShopifyProductSync`
    (`site_products.source = shopify`, `external_id` = product GID, image ids = numeric MediaImage ids).
  - GraphQL Admin API version: `SHOPIFY_API_VERSION` (default `2026-10`); bump it quarterly and re-test.
- **WordPress / WooCommerce** — `app/Services/Wp/*`, plugin-facing routes under `/api/agent/*` with HMAC
  (`VerifyAgentSignature`). The plugin is **Sevof Connector** (1.0.0+, slug `sevof-connector`): REST namespace
  `sevof-connector/v1`, markers `<!--sevof-faq-->` / `<!--sevof-desc-->`, error codes `sevof_*`. Older Youtix plugins
  are not supported. Sevof always calls WordPress directly (there is no relay).
- **Content language** — `app/Support/ContentLanguage.php` is the single source of truth.
  `SiteLanguageDetector` decides each site's language (content > declared > AI vote); unknown → English (`DEFAULT='en'`),
  Arabic-script ties → Arabic. Prompts are still written in Persian internally; `ContentLanguage::localize()` rewrites
  Persian mentions and appends a top-priority "OUTPUT LANGUAGE" directive, so output is always in the site's language.
  SERP calls use `ContentLanguage::country()` (default `us`). Translating the prompts themselves to English is open work.
- **Search Console / refreshes / past articles** — `GoogleSearchConsoleService`, `SearchConsoleInsightsService`,
  `ArticleRefreshService`, `PastArticlesController` (past posts currently come from the WordPress plugin only;
  Shopify past articles via `ShopifyClient::listArticles()` are not wired yet).

## Conventions
- API messages returned to users are **English** (the front shows them as-is or maps them). Don't add Persian strings.
- Money: integer minor units in DB and API; never floats for billing.
- Secrets only from `.env` (see `.env.example`). Never hard-code keys in `config/*.php`.
- Every site-scoped controller checks `$site->user_id === $request->user()->id`.
- New publishing platforms (Wix, Webflow, Notion): implement `App\Contracts\ArticlePublisher` (+ `ProductArticlePublisher`
  if they sell products), add a `{platform}_connections` table, and add the branch to all three resolvers.

## Tests
`php vendor/bin/phpunit`. `tests/Unit/SevofIntegrationsTest.php` covers Stripe webhook signatures and Shopify
OAuth/webhook HMACs and shop-domain validation. Seven tests were already failing when this repo was forked from
seobot (ChatModelResolver ×3, SiteDomainLimit, SeobotApi ×2, ExampleTest) — fix or delete them rather than ignoring new failures.

## Local setup safety
`.env` must point at its **own** database (`DB_DATABASE=sevof`). The copied seobot `.env` pointed at seobot's
database; running `migrate` or `cache:clear` against it would damage seobot. Delete `bootstrap/cache/*.php`
after copying env files.
