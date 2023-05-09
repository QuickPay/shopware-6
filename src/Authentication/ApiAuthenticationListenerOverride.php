<?php declare(strict_types=1);

namespace Wexo\Quickpay\Authentication;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Shopware\Core\Framework\Api\EventListener\Authentication\ApiAuthenticationListener;
use Shopware\Core\Framework\Routing\RouteScopeCheckTrait;
use Shopware\Core\Framework\Routing\RouteScopeRegistry;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class ApiAuthenticationListenerOverride extends ApiAuthenticationListener
{
    use RouteScopeCheckTrait;

    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly AuthorizationServer $authorizationServer,
        private readonly UserRepositoryInterface $userRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly RouteScopeRegistry $routeScopeRegistry
    ) {
        parent::__construct(
            $resourceServer,
            $authorizationServer,
            $userRepository,
            $refreshTokenRepository,
            $psrHttpFactory,
            $routeScopeRegistry
        );
    }

    /**
     * @param RequestEvent $event
     */
    public function setupOAuth(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenMinuteInterval = new \DateInterval('PT1H');
        $oneWeekInterval = new \DateInterval('P1W');

        $passwordGrant = new PasswordGrant($this->userRepository, $this->refreshTokenRepository);
        $passwordGrant->setRefreshTokenTTL($oneWeekInterval);

        $refreshTokenGrant = new RefreshTokenGrant($this->refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL($oneWeekInterval);

        $this->authorizationServer->enableGrantType($passwordGrant, $tenMinuteInterval);
        $this->authorizationServer->enableGrantType($refreshTokenGrant, $tenMinuteInterval);
        $this->authorizationServer->enableGrantType(new ClientCredentialsGrant(), $tenMinuteInterval);
    }
}
