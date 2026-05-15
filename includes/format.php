<?php

declare(strict_types=1);

function extractor_money(float $amount): string
{
    return 'R$ ' . number_format($amount, 2, ',', '.');
}

function extractor_currency_code(): string
{
    return 'BRL';
}
