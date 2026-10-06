<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\BusinessType;
use App\Models\Product;
use App\Utils\HttpException;
use App\Utils\Request;
use App\Utils\Response;

/**
 * Read-only reference data. Not gameplay: it exists so the whole stack
 * (route -> controller -> model -> database -> JSON envelope) is exercised end to end.
 */
final class CatalogController
{
    public function businessTypes(Request $request): void
    {
        Response::success(['business_types' => BusinessType::active()]);
    }

    public function businessType(Request $request, array $params): void
    {
        $type = BusinessType::findBySlug($params['slug'])
            ?? throw HttpException::notFound('Business type not found.');

        $type['products'] = Product::forBusinessType((int) $type['id']);
        Response::success($type);
    }
}
