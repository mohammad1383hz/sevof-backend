<?php

namespace App\Enums;

/**
 * باید دقیقاً با id های IMAGE_STYLES در
 * src/components/article-generate/ModelSelectModal.tsx هماهنگ بماند.
 */
enum ImageStyle: string
{
    case Minimal = 'minimal';
    case Infographic = 'infographic';
    case Conceptual = 'conceptual';
    case Realistic = 'realistic';
    case Product = 'product';

    public static function default(): self
    {
        return self::Minimal;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * ورودی خام (از request) را resolve می‌کند؛
     * اگر نامعتبر یا خالی باشد دیفالت برمی‌گردد.
     */
    public static function resolve(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::Minimal => 'مینیمال',
            self::Infographic => 'اینفوگرافی',
            self::Conceptual => 'مفهومی',
            self::Realistic => 'واقعی',
            self::Product => 'محصول',
        };
    }
}
