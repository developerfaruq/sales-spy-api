from enum import StrEnum


class WebsiteStatus(StrEnum):
    ACTIVE = "active"
    INACTIVE = "inactive"
    PARKED = "parked"
    UNREACHABLE = "unreachable"
    UNKNOWN = "unknown"


class CommercePlatform(StrEnum):
    SHOPIFY = "shopify"
    WOOCOMMERCE = "woocommerce"
    WIX = "wix"
    BIGCOMMERCE = "bigcommerce"
    MAGENTO = "magento"
    PRESTASHOP = "prestashop"
    SQUARESPACE = "squarespace"
    CUSTOM = "custom"
    UNKNOWN = "unknown"


class ProductStatus(StrEnum):
    ACTIVE = "active"
    DRAFT = "draft"
    ARCHIVED = "archived"
    UNAVAILABLE = "unavailable"
