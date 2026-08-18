<?php

namespace App\Enums;

enum ProductStatus: string
{
    case ACTIVE = 'active';
    case DRAFT = 'draft';
    case ARCHIVED = 'archived';
    case UNAVAILABLE = 'unavailable';
}
