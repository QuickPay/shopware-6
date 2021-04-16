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
use Shopware\Core\Framework\Routing\ApiContextRouteScopeDependant;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Routing\RouteScopeCheckTrait;
use Shopware\Core\Framework\Routing\RouteScopeRegistry;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiAuthenticationListenerOverride extends ApiAuthenticationListener
{
    use RouteScopeCheckTrait;

    /**
     * @var ResourceServer
     */
    private $resourceServer;

    /**
     * @var AuthorizationServer
     */
    private $authorizationServer;

    /**
     * @var UserRepositoryInterface
     */
    private $userRepository;

    /**
     * @var RefreshTokenRepositoryInterface
     */
    private $refreshTokenRepository;

    /**
     * @var PsrHttpFactory
     */
    private $psrHttpFactory;

    /**
     * @var RouteScopeRegistry
     */
    private $routeScopeRegistry;

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

    public function setupOAuth(RequestEvent $event): void
    {
        if (!$event->isMasterRequest()) {
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
