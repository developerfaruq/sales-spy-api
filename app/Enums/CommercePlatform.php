<?php

namespace App\Enums;

enum CommercePlatform: string
{
    case SHOPIFY = 'shopify';
    case WOOCOMMERCE = 'woocommerce';
    case WIX = 'wix';
    case BIGCOMMERCE = 'bigcommerce';
    case MAGENTO = 'magento';
    case PRESTASHOP = 'prestashop';
    case SQUARESPACE = 'squarespace';
    case CUSTOM = 'custom';
    case UNKNOWN = 'unknown';
}
