<?php declare(strict_types=1);

namespace Wexo\Quickpay\Framework\Routing;

use Shopware\Core\Framework\Routing\AbstractRouteScope;
use Shopware\Core\Framework\Routing\ApiContextRouteScopeDependant;
use Symfony\Component\HttpFoundation\Request;

/**
 * Route allows omitting SalesChannelContext,
 *  as we don't need it for Quickpay callback since we use salesChannelId from the order and "base Context".
 */
class QuickpayRouteScope extends AbstractRouteScope implements ApiContextRouteScopeDependant
{
    final public const ID = 'quickpay';

    // Trim leading slash to ensure correct matching.
    protected $allowedPaths = ['quickpay/callback'];

    public function isAllowedPath(string $path): bool
    {
        // Expect the full path.
        return in_array($path, array_map(fn (string $path) => "/$path", $this->allowedPaths), true);
    }

    public function isAllowed(Request $request): bool
    {
        return true;
    }

    public function getId(): string
    {
        return self::ID;
    }
}