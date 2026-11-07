<?php

namespace App\Services\Shopify;

use App\Models\PageProduct\PageProductSeoOptimization;
use App\Services\Products\PageProduct\PageProductGapDetectionService;
use App\Services\Products\PageProduct\ProductContentSanitizer;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Publishes an approved product-page SEO result to Shopify.
 *
 *   description + faq + category link → productUpdate.descriptionHtml (FAQ as a trailing block)
 *   seo_title / meta_description       → productUpdate.seo
 *   tags                               → tagsAdd (only new tags; nothing is removed)
 *   image_alts                         → fileUpdate (only images that still have no alt)
 *
 * Shopify has no short description, focus keyword or free-form attributes, so those are never generated.
 * Every run starts from a fresh read of the product, so a retry is safe.
 */
class ShopifyPageProductSeoPublishService
{
    public function publish(PageProductSeoOptimization $optimization): void
    {
        $optimization->loadMissing(['product', 'site.shopifyConnection']);

        $connection = $optimization->site?->shopifyConnection;
        $product = $optimization->product;

        if (! $connection?->isConnected() || ! $product || ! $product->external_id) {
            $optimization->update([
                'wp_sync_status' => 'failed',
                'publish_error' => 'The Shopify connection or the product\'s Shopify ID is missing.',
            ]);

            return;
        }

        $optimization->update(['wp_sync_status' => 'syncing', 'publish_error' => null]);

        try {
            $this->push(
                new ShopifyClient($connection),
                (string) $product->external_id,
                (array) $optimization->result,
                array_values((array) $optimization->approved_fields),
                $optimization->site->language ?? 'en',
            );

            $optimization->update([
                'status' => PageProductSeoOptimization::STATUS_PUBLISHED,
                'wp_sync_status' => 'synced',
                'publish_error' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('[PageProductSeo] Shopify publish failed', ['optimization_id' => $optimization->id, 'error' => $e->getMessage()]);

            // stays "approved" so the user can retry
            $optimization->update([
                'wp_sync_status' => 'failed',
                'publish_error' => ShopifyPublishService::friendlyError($e),
            ]);
        }
    }

    /** @param list<string> $fields */
    protected function push(ShopifyClient $client, string $productId, array $result, array $fields, string $language): void
    {
        $approved = array_fill_keys($fields, true);
        $fresh = $client->getProduct($productId);
        $update = ['id' => $productId];

        if ((isset($approved['description']) && ! empty($result['description']))
            || (isset($approved['category_link']) && ! empty($result['category_link']['url']))
            || (isset($approved['faq']) && ! empty($result['faq']))) {
            $update['descriptionHtml'] = $this->composeDescription($fresh, $result, $approved, $language);
        }

        $seo = array_filter([
            'title' => isset($approved['seo_title']) && ! empty($result['seo_title']) ? (string) $result['seo_title'] : null,
            'description' => isset($approved['meta_description']) && ! empty($result['meta_description']) ? (string) $result['meta_description'] : null,
        ]);

        if ($seo !== []) {
            $update['seo'] = $seo;
        }

        if (count($update) > 1) {
            $client->updateProduct($update);
        }

        if (isset($approved['tags']) && ! empty($result['tags'])) {
            $new = $this->newTags((array) ($fresh['tags'] ?? []), (array) $result['tags']);

            if ($new !== []) {
                $client->addTags($productId, $new);
            }
        }

        if (isset($approved['image_alts']) && ! empty($result['image_alts'])) {
            $client->updateFileAlts($this->altUpdates($fresh, (array) $result['image_alts']));
        }
    }

    protected function composeDescription(array $fresh, array $result, array $approved, string $language): string
    {
        [$body, $faqBlock] = $this->splitFaq((string) ($fresh['descriptionHtml'] ?? ''));

        if (isset($approved['description']) && ! empty($result['description'])) {
            $body = (string) $result['description'];
        }

        // Link the first mention of the category; the seller's own text is otherwise untouched.
        $link = $result['category_link'] ?? $result['category'] ?? null;

        if (is_array($link) && ! empty($link['url']) && trim($body) !== ''
            && (isset($approved['description']) || isset($approved['category_link']))) {
            $body = ProductContentSanitizer::withCategoryLink($body, (string) ($link['name'] ?? ''), (string) $link['url']);
        }

        if (isset($approved['faq']) && ! empty($result['faq'])) {
            $faqBlock = $this->buildFaqBlock((array) $result['faq'], $language);
        }

        return trim(trim($body)."\n".trim($faqBlock));
    }

    /**
     * Splits the current description into body + trailing FAQ block so only one of them is replaced.
     * Refuses to continue if the FAQ block is not at the end (to avoid deleting content).
     *
     * @return array{0: string, 1: string}
     */
    protected function splitFaq(string $html): array
    {
        $pos = strpos($html, ProductContentSanitizer::FAQ_START);

        if ($pos === false) {
            return [$html, ''];
        }

        $head = substr($html, 0, $pos);

        if (trim(ProductContentSanitizer::stripFaqBlock($html)) !== trim($head)) {
            throw new RuntimeException('The existing FAQ block is not at the end of the product description; publishing stopped to avoid removing content.');
        }

        return [$head, substr($html, $pos)];
    }

    /** @param list<array{question: string, answer: string}> $faq */
    protected function buildFaqBlock(array $faq, string $language): string
    {
        $titles = ['en' => 'Frequently asked questions', 'ar' => 'الأسئلة الشائعة', 'de' => 'Häufig gestellte Fragen',
            'fr' => 'Questions fréquentes', 'es' => 'Preguntas frecuentes', 'it' => 'Domande frequenti',
            'pt' => 'Perguntas frequentes', 'tr' => 'Sıkça sorulan sorular', 'nl' => 'Veelgestelde vragen', 'fa' => 'سوالات متداول'];

        $html = ProductContentSanitizer::FAQ_START.'<h2>'.e($titles[$language] ?? $titles['en']).'</h2>';

        foreach ($faq as $item) {
            $html .= '<h3>'.e((string) ($item['question'] ?? '')).'</h3><p>'.e((string) ($item['answer'] ?? '')).'</p>';
        }

        $endConst = ProductContentSanitizer::class.'::FAQ_END';

        return $html.(defined($endConst) ? constant($endConst) : '');
    }

    /** @return list<string> */
    protected function newTags(array $current, array $incoming): array
    {
        $seen = [];

        foreach ($current as $tag) {
            $seen[PageProductGapDetectionService::norm((string) $tag)] = true;
        }

        $out = [];

        foreach ($incoming as $tag) {
            $value = mb_substr(trim((string) $tag), 0, 255);
            $key = PageProductGapDetectionService::norm($value);

            if ($value !== '' && $key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Only images that belong to this product and still have no alt
     * (an alt written by someone between generation and approval is kept).
     *
     * @return list<array{id: string, alt: string}>
     */
    protected function altUpdates(array $fresh, array $alts): array
    {
        $current = [];

        foreach ((array) data_get($fresh, 'media.nodes', []) as $media) {
            if (($media['mediaContentType'] ?? null) === 'IMAGE' && preg_match('#/(\d+)$#', (string) ($media['id'] ?? ''), $m)) {
                $current[(int) $m[1]] = trim((string) ($media['alt'] ?? ''));
            }
        }

        $updates = [];

        foreach ($alts as $row) {
            $id = (int) ($row['id'] ?? 0);
            $alt = trim((string) ($row['alt'] ?? ''));

            if ($id > 0 && $alt !== '' && array_key_exists($id, $current) && $current[$id] === '') {
                $updates[] = ['id' => "gid://shopify/MediaImage/{$id}", 'alt' => mb_substr($alt, 0, 512)];
            }
        }

        return $updates;
    }
}
