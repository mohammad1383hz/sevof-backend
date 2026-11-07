<?php

namespace App\Services\Shopify;

use NumberFormatter;

/**
 * Product cards (inline-styled HTML, works in any Shopify theme) for product articles.
 * Labels follow the site's content language.
 */
class ShopifyProductCardRenderer
{
    private const LABELS = [
        'en' => ['view' => 'View product', 'related' => 'Recommended products', 'out' => 'Out of stock'],
        'ar' => ['view' => 'عرض المنتج', 'related' => 'منتجات مقترحة', 'out' => 'غير متوفر'],
        'de' => ['view' => 'Produkt ansehen', 'related' => 'Empfohlene Produkte', 'out' => 'Nicht vorrätig'],
        'fr' => ['view' => 'Voir le produit', 'related' => 'Produits recommandés', 'out' => 'Rupture de stock'],
        'es' => ['view' => 'Ver producto', 'related' => 'Productos recomendados', 'out' => 'Agotado'],
        'it' => ['view' => 'Vedi prodotto', 'related' => 'Prodotti consigliati', 'out' => 'Esaurito'],
        'pt' => ['view' => 'Ver produto', 'related' => 'Produtos recomendados', 'out' => 'Esgotado'],
        'tr' => ['view' => 'Ürünü görüntüle', 'related' => 'Önerilen ürünler', 'out' => 'Stokta yok'],
        'nl' => ['view' => 'Bekijk product', 'related' => 'Aanbevolen producten', 'out' => 'Uitverkocht'],
        'fa' => ['view' => 'مشاهده محصول', 'related' => 'محصولات پیشنهادی', 'out' => 'ناموجود'],
    ];

    private string $lang = 'en';

    public function forLanguage(string $code): self
    {
        $clone = clone $this;
        $clone->lang = isset(self::LABELS[$code]) ? $code : 'en';

        return $clone;
    }

    public function showcase(array $products): string
    {
        return $this->grid($products);
    }

    public function related(array $products): string
    {
        return $products === [] ? '' : '<h2>'.e($this->label('related')).'</h2>'.$this->grid($products);
    }

    private function grid(array $products): string
    {
        if ($products === []) {
            return '';
        }

        return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin:24px 0">'
            .implode('', array_map(fn (array $p) => $this->card($p), $products))
            .'</div>';
    }

    private function card(array $p): string
    {
        $url = $this->safeUrl($p['url'] ?? null);
        $image = $this->safeUrl($p['image'] ?? $this->firstImage($p['images'] ?? null));
        $name = e(html_entity_decode((string) ($p['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $title = $url
            ? '<a href="'.e($url).'" style="color:inherit;text-decoration:none">'.$name.'</a>'
            : $name;

        $html = '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;display:flex;flex-direction:column;gap:8px">';

        if ($image) {
            $html .= '<img src="'.e($image).'" alt="'.$name.'" loading="lazy" style="width:100%;height:180px;object-fit:contain;border-radius:8px">';
        }

        $html .= '<div style="font-weight:700;line-height:1.6">'.$title.'</div>';
        $html .= $this->price($p);

        if ($url) {
            $html .= '<a href="'.e($url).'" style="margin-top:auto;text-align:center;padding:8px 12px;border-radius:8px;background:#111827;color:#fff;text-decoration:none;font-size:14px">'.e($this->label('view')).'</a>';
        }

        return $html.'</div>';
    }

    private function price(array $p): string
    {
        if (($p['stock_status'] ?? null) === 'outofstock' || ($p['in_stock'] ?? null) === false) {
            return '<div style="color:#b91c1c;font-size:14px">'.e($this->label('out')).'</div>';
        }

        $price = is_numeric($p['price'] ?? null) ? (float) $p['price'] : null;

        if ($price === null || $price <= 0) {
            return '';
        }

        $currency = strtoupper((string) ($p['currency'] ?? '')) ?: null;
        $html = '<div style="font-size:15px;font-weight:700">'.e($this->money($price, $currency)).'</div>';

        $regular = is_numeric($p['regular_price'] ?? null) ? (float) $p['regular_price'] : null;

        if ($regular !== null && $regular > $price) {
            $html = '<div style="font-size:13px;color:#9ca3af;text-decoration:line-through">'.e($this->money($regular, $currency)).'</div>'.$html;
        }

        return $html;
    }

    private function money(float $amount, ?string $currency): string
    {
        if ($currency && preg_match('/^[A-Z]{3}$/', $currency) && class_exists(NumberFormatter::class)) {
            $formatted = (new NumberFormatter($this->lang, NumberFormatter::CURRENCY))->formatCurrency($amount, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return number_format($amount, 2).($currency ? ' '.$currency : '');
    }

    private function firstImage(mixed $images): ?string
    {
        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        $first = is_array($images) ? ($images[0] ?? null) : null;

        return is_array($first) ? ($first['src'] ?? $first['url'] ?? null) : (is_string($first) ? $first : null);
    }

    private function label(string $key): string
    {
        return self::LABELS[$this->lang][$key];
    }

    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', trim($url)) ? trim($url) : null;
    }
}
