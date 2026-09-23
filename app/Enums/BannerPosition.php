<?php

namespace App\Enums;

enum BannerPosition: string
{
    case Main = 'main';
    case Carousel = 'carousel';
    case Sub = 'sub';
    case FooterLink = 'footer_link';

    /**
     * 推奨サイズの幅（px。4.7.2）。素材が未用意のため暫定値であり、
     * 実素材の用意に合わせて見直す（12章 残課題）。
     */
    public function recommendedWidth(): int
    {
        return match ($this) {
            self::Main => 970,
            self::Carousel => 1200,
            self::Sub => 620,
            self::FooterLink => 728,
        };
    }

    /**
     * 推奨サイズの高さ（px。4.7.2）。
     */
    public function recommendedHeight(): int
    {
        return match ($this) {
            self::Main => 250,
            self::Carousel => 628,
            self::Sub => 130,
            self::FooterLink => 90,
        };
    }
}
