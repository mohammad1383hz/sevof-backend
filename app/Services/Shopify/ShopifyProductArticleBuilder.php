<?php

namespace App\Services\Shopify;

use App\Models\ProductArticle;
use App\Services\Products\ProductCatalog;
use App\Support\ContentLanguage;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use RuntimeException;

/**
 * ProductArticle → Shopify blog article input, with product showcases under "product" sections
 * and the remaining article products as "recommended products" at the end.
 */
class ShopifyProductArticleBuilder
{
    private const MAX_SECTION_PRODUCTS = 12;
    private const MAX_RELATED_PRODUCTS = 24;

    private MarkdownConverter $markdown;

    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly ShopifyProductCardRenderer $cards,
        private readonly ShopifyArticleBuilder $articles,
    ) {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $this->markdown = new MarkdownConverter($environment);
    }

    public function build(ProductArticle $article, ShopifyClient $client, bool $published, bool $forCreate): array
    {
        $input = [
            'title' => $article->title,
            'body' => $this->buildBody($article),
            'handle' => $article->slug ?: null,
            'summary' => $article->meta_description ? '<p>'.e($article->meta_description).'</p>' : null,
            'isPublished' => $published,
            'author' => ['name' => $article->site->name ?: config('app.name', 'Sevof')],
        ];

        if ($forCreate) {
            $input['blogId'] = $this->articles->blogId($client);
        }

        if ($article->image_status === ProductArticle::IMAGE_STATUS_GENERATED
            && ($file = ShopifyArticleBuilder::readStoredFile($article->image_path))) {
            $alt = (string) ($article->image_alt ?: $article->title);
            $input['image'] = ['url' => $client->uploadImage($file['contents'], $file['filename'], $alt), 'altText' => $alt];
        }

        if ($article->meta_title && $article->meta_title !== $article->title) {
            $input['metafields'] = [[
                'namespace' => 'global',
                'key' => 'title_tag',
                'type' => 'single_line_text_field',
                'value' => $article->meta_title,
            ]];
        }

        return array_filter($input, fn ($v) => $v !== null && $v !== '') + ['isPublished' => $published];
    }

    private function buildBody(ProductArticle $article): string
    {
        $language = ContentLanguage::forSite($article->site);
        $cards = $this->cards->forLanguage($language);
        $sections = $article->sections()->orderBy('position')->get();

        $allIds = $this->catalog->ids($article->product_ids ?? null);

        foreach ($sections as $section) {
            $allIds = array_merge($allIds, $this->catalog->ids($section->product_ids ?? null));
        }

        $products = $this->catalog->load($article->site_id, $allIds);
        $comparisonTable = $this->extractTable((string) ($article->content ?? ''));

        $html = '';
        $shown = [];
        $unresolved = [];

        foreach ($sections as $section) {
            $type = (string) ($section->type ?? '');
            $ids = $this->catalog->ids($section->product_ids ?? null);

            $sectionProducts = array_slice(
                array_values(array_filter(array_map(fn ($id) => $products[$id] ?? null, $ids))),
                0,
                self::MAX_SECTION_PRODUCTS
            );

            // Showcase only under "product" sections (or untyped sections with one product).
            $wants = $ids !== [] && ($type === '' || $type === 'product');
            $shows = $sectionProducts !== [] && ($type !== '' ? $type === 'product' : count($sectionProducts) === 1);

            if ($wants && $sectionProducts === []) {
                $unresolved[] = trim(strip_tags((string) $section->heading));
            }

            $markdown = (string) ($section->content ?? '');

            if ($type === 'comparison' && $comparisonTable !== '' && $this->extractTable($markdown) === '') {
                $markdown = $comparisonTable."\n\n".$markdown;
            }

            $html .= '<h2>'.$this->inline($section->heading).'</h2>'.$this->block($markdown);

            if ($shows) {
                $html .= $cards->showcase($sectionProducts);

                foreach ($sectionProducts as $product) {
                    $shown[$product['id']] = true;
                }
            }
        }

        if ($unresolved !== []) {
            $names = implode(', ', array_map(fn ($h) => "\"{$h}\"", array_slice($unresolved, 0, 5)));

            throw new RuntimeException("Products for section {$names} were not found in the synced catalog. The article was not published so it doesn't go live without its product cards.");
        }

        $related = array_values(array_filter($products, fn ($p) => ! isset($shown[$p['id']])));
        $html .= $cards->related(array_slice($related, 0, self::MAX_RELATED_PRODUCTS));

        return ContentLanguage::isRtl($language)
            ? '<div dir="rtl" style="direction:rtl;text-align:right">'.$html.'</div>'
            : $html;
    }

    private function block(?string $text): string
    {
        return $text ? trim((string) $this->markdown->convert($text)->getContent()) : '';
    }

    private function inline(?string $text): string
    {
        if (! $text) {
            return '';
        }

        $html = trim((string) $this->markdown->convert($text)->getContent());

        return trim(preg_replace('/^<p>(.*)<\/p>$/s', '$1', $html) ?? $html);
    }

    /** First Markdown table in the text, or ''. */
    private function extractTable(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count - 1; $i++) {
            if (! str_contains($lines[$i], '|') || ! $this->isSeparator($lines[$i + 1])) {
                continue;
            }

            $table = [$lines[$i], $lines[$i + 1]];

            for ($j = $i + 2; $j < $count && trim($lines[$j]) !== '' && str_contains($lines[$j], '|'); $j++) {
                $table[] = $lines[$j];
            }

            return implode("\n", $table);
        }

        return '';
    }

    private function isSeparator(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $line) && str_contains($line, '|');
    }
}
