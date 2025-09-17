<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller;

use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Monolog\Level;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\LogEntryCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Wexo\Quickpay\WexoQuickpay;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class QuickpayStorefrontController
{
    /**
     * @param EntityRepository<LogEntryCollection> $logEntryRepository
     */
    public function __construct(
        protected EntityRepository $logEntryRepository,
        protected PaymentProcessor $paymentProcessor,
        protected TokenFactoryInterfaceV2 $tokenFactory,
        protected AbstractCartPersister $cartPersister,
        protected UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        path: '/payment/quickpay-finalize-transaction',
        defaults: ['auth_required' => false, 'csrf_protected' => false],
        methods: ['POST', 'GET']
    )]
    public function quickpayFinalizeTransaction(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse|RedirectResponse {
        $finalizeAllowed = true;
        $status = $request->query->get('status');
        $forbiddenStatuses = [30100, 30101,40000, 40001, 50000, 50300];

        $operations = $request->get('operations');
        if ($operations !== null && count($operations) > 0) {
            $operation = end($operations);
            /*
             * 30100 and 30101 indicate errors based on rejected 3D Secure
             * https://learn.quickpay.net/tech-talk/appendixes/errors/
             */
            if (!isset($operation['qp_status_code']) || in_array($operation['qp_status_code'], $forbiddenStatuses, true)) {
                $finalizeAllowed = false;
            }
        }

        $this->logEntryRepository->create(
            [
                [
                    'message' => 'quickpay_finalize_transaction_debug',
                    'context' => (array)$request->getContent(),
                    'level' => Level::Debug->value,
                    'channel' => WexoQuickpay::LOG_CHANNEL
                ]
            ],
            $context->getContext()
        );

        /* Delete cart when either customer or quickpay reaches this page.
         * This just runs executeStatement, which just returns number of rows affected,
         * so multiple runs on runs non existing cart does not throw an error */
        if ($status === "accepted") {
            $this->cartPersister->delete($context->getToken(), $context);
        }

        if (in_array($status, ['accepted', 'cancel'], true)) {
            $paymentToken = $request->get('_sw_payment_token');
            $token = $this->tokenFactory->parseToken($paymentToken);
            $url = ($status === 'accepted' ? 
                ($token->getFinishUrl() ?? $this->urlGenerator->generate(
                    'frontend.checkout.confirm.page',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )) :
                ($token->getErrorUrl() ??
                    $this->urlGenerator->generate(
                        'frontend.checkout.confirm.page',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ))
            );
            return new RedirectResponse($url);
        } else {
            sleep(10);
        }

        $data = [];
        $paymentToken = $request->get('_sw_payment_token');
        $token = $this->tokenFactory->parseToken($paymentToken);
        if ($finalizeAllowed) {
            try {
                $result = $this->paymentProcessor->finalize(
                    $token,
                    $request,
                    $context
                );

                $exception = $result->getException();
                if ($exception !== null) {
                    $data = [
                        'error' => 'payment_finalize_exception',
                        'errorMessage' => $exception->getMessage(),
                        'sw_status_code' => 400001
                    ];
                }
            } catch (PaymentException $exception) {
                $data = [
                    'error' => 'token_invalidated_exception',
                    'errorMessage' => $exception->getMessage(),
                    'sw_status_code' => 400002
                ];
            } catch (\Exception $exception) {
                $data = [
                    'error' => 'quick_pay_finalize_exception',
                    'errorMessage' => $exception->getMessage(),
                    'sw_status_code' => 400003
                ];
            }
        }

        if (count($data) > 0) {
            if ($request->getContent() !== null && $request->getContent() !== '') {
                $data['content'] = json_decode((string) $request->getContent(), true);
            }
            $errorLevel = Level::Error;
            $logMessage = 'quickpay_finalize_transaction_error';
            if ($data['sw_status_code'] === 400002) {
                $errorLevel = Level::Warning;
                $logMessage = 'quickpay_finalize_transaction_token_invalidated';
            }
            $this->logEntryRepository->create(
                [
                    [
                        'message' => $logMessage,
                        'context' => $data,
                        'level' => $errorLevel,
                        'channel' => WexoQuickpay::LOG_CHANNEL
                    ]
                ],
                Context::createDefaultContext()
            );
            unset($data['errorMessage']);
        }

        return new JsonResponse($data, count($data) > 1? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }
}
