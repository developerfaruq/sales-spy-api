<?php

namespace App\Models;

use App\Enums\CrawlStatus;
use App\Enums\WebsiteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Website extends Model
{
    /**
     * Columns the list endpoints actually read.
     *
     * `source_payload` is bounded only at ~100 KB per row by ingestion, and
     * `description`/`technologies` are detail-only in LeadPresenter, so a
     * 100-row page would otherwise detoast and transfer payloads the response
     * discards. Detail requests select every column.
     */
    public const LIST_COLUMNS = [
        'id',
        'domain',
        'canonical_url',
        'name',
        'website_status',
        'is_ecommerce',
        'platform',
        'cms',
        'niche',
        'country_code',
        'region',
        'city',
        'language_code',
        'email',
        'phone',
        'contact_page_url',
        'logo_url',
        'favicon_url',
        'http_status',
        'estimated_monthly_traffic',
        'domain_age_days',
        'social_links',
        'last_seen_at',
    ];

    protected $fillable = [
        'domain',
        'canonical_url',
        'name',
        'description',
        'website_status',
        'is_ecommerce',
        'platform',
        'cms',
        'niche',
        'country_code',
        'region',
        'city',
        'language_code',
        'email',
        'phone',
        'contact_page_url',
        'logo_url',
        'favicon_url',
        'http_status',
        'estimated_monthly_traffic',
        'domain_age_days',
        'source',
        'source_external_id',
        'crawl_status',
        'crawl_attempts',
        'last_crawl_error',
        'technologies',
        'social_links',
        'source_payload',
        'discovered_at',
        'last_seen_at',
        'last_crawled_at',
        'next_crawl_at',
    ];

    protected function casts(): array
    {
        return [
            'website_status' => WebsiteStatus::class,
            'crawl_status' => CrawlStatus::class,
            'is_ecommerce' => 'boolean',
            'http_status' => 'integer',
            'estimated_monthly_traffic' => 'integer',
            'domain_age_days' => 'integer',
            'crawl_attempts' => 'integer',
            'technologies' => 'array',
            'social_links' => 'array',
            'source_payload' => 'array',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_crawled_at' => 'datetime',
            'next_crawl_at' => 'datetime',
        ];
    }

    public function ecommerceStore()
    {
        return $this->hasOne(EcommerceStore::class);
    }

    public function scopeDueForCrawl(Builder $query): Builder
    {
        return $query
            ->whereIn('crawl_status', [
                CrawlStatus::PENDING,
                CrawlStatus::FAILED,
                CrawlStatus::COMPLETED,
            ])
            ->where(function (Builder $crawlQuery): void {
                $crawlQuery
                    ->whereNull('next_crawl_at')
                    ->orWhere('next_crawl_at', '<=', now());
            });
    }

    public function setDomainAttribute(string $domain): void
    {
        $this->attributes['domain'] = self::normalizeDomain($domain);
    }

    public function setCountryCodeAttribute(?string $countryCode): void
    {
        $this->attributes['country_code'] = $countryCode
            ? strtoupper(trim($countryCode))
            : null;
    }

    /**
     * Normalize a domain to the exact form the ingestion worker stores.
     *
     * The Python worker punycodes hosts before writing (normalize_domain in
     * sales-spy-scrapers), so this must apply the same IDNA step or a lead
     * discovered as "münchen.example" would be unreachable through the API by
     * the name a caller would actually type.
     */
    public static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $host = parse_url(str_contains($value, '://') ? $value : "https://{$value}", PHP_URL_HOST);
        $domain = trim((string) $host, '.');

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        if ($domain !== '' && ! preg_match('/^[\x20-\x7f]*$/', $domain)) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                throw new InvalidArgumentException('A valid registrable domain is required.');
            }

            $domain = strtolower($ascii);
        }

        if (
            $domain === ''
            || strlen($domain) > 253
            || filter_var($domain, FILTER_VALIDATE_IP)
            || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)
        ) {
            throw new InvalidArgumentException('A valid registrable domain is required.');
        }

        return $domain;
    }
}
