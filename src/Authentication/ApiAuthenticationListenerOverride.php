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

/**
 * Class ApiAuthenticationListenerOverride
 * @package Wexo\Quickpay\Authentication
 */
class ApiAuthenticationListenerOverride extends ApiAuthenticationListener
{
    use RouteScopeCheckTrait;

    private ResourceServer $resourceServer;
    private AuthorizationServer $authorizationServer;
    private UserRepositoryInterface $userRepository;
    private RefreshTokenRepositoryInterface $refreshTokenRepository;
    private PsrHttpFactory $psrHttpFactory;
    private RouteScopeRegistry $routeScopeRegistry;

    /**
     * ApiAuthenticationListenerOverride constructor.
     * @param ResourceServer $resourceServer
     * @param AuthorizationServer $authorizationServer
     * @param UserRepositoryInterface $userRepository
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     * @param PsrHttpFactory $psrHttpFactory
     * @param RouteScopeRegistry $routeScopeRegistry
     */
    public function __construct(
        ResourceServer $resourceServer,
        AuthorizationServer $authorizationServer,
        UserRepositoryInterface $userRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        PsrHttpFactory $psrHttpFactory,
        RouteScopeRegistry $routeScopeRegistry
    ) {
        parent::__construct(
            $resourceServer,
            $authorizationServer,
            $userRepository,
            $refreshTokenRepository,
            $psrHttpFactory,
            $routeScopeRegistry
        );

        $this->resourceServer = $resourceServer;
        $this->authorizationServer = $authorizationServer;
        $this->userRepository = $userRepository;
        $this->refreshTokenRepository = $refreshTokenRepository;
        $this->psrHttpFactory = $psrHttpFactory;
        $this->routeScopeRegistry = $routeScopeRegistry;
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
