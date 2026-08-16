<?php

namespace App\Enums;

enum CrawlStatus: string
{
    case PENDING = 'pending';
    case CRAWLING = 'crawling';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case BLOCKED = 'blocked';
}
