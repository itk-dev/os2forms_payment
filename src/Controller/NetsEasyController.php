<?php

namespace Drupal\os2forms_payment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\os2forms_payment\Helper\PaymentHelper;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for OS2forms payment routes.
 */
class NetsEasyController extends ControllerBase {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly PaymentHelper $paymentHelper,
    private readonly ClientInterface $httpClient,
  ) {
  }

  /**
   * Create.
   *
   * @return NetsEasyController
   *   Return paymentController
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(PaymentHelper::class),
      $container->get('http_client'),
    );
  }

  /**
   * Create payment method.
   *
   * Creates a payment object via the Nets API
   * and returns a reference to said object (paymentId).
   *
   * Derived from Nets' documentation and modified:
   *
   * @see https://developer.nexigroup.com/nexi-checkout/en-EU/docs/web-integration/integrate-nexi-checkout-on-your-website-embedded/#build-step-2-create-a-payment-object-backend
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Returns response containing paymentId from Nets endpoint.
   */
  public function createPayment(Request $request) {
    // @todo Implement error handling.
    $amountToPay = floatval($request->get('amountToPay'));
    $callbackUrl = $request->get('callbackUrl') ?? NULL;
    $endpoint = $this->paymentHelper->getTestMode()
      ? 'https://test.api.dibspayment.eu/v1/payments'
      : 'https://api.dibspayment.eu/v1/payments';
    $payload = json_encode([
      'checkout' => [
        'integrationType' => 'EmbeddedCheckout',
        'url' => $callbackUrl,
        'termsUrl' => $this->paymentHelper->getTermsUrl(),
      ],
      'order' => [
        'items' => [
            [
              'reference' => 'reference',
              'name' => 'product',
              'quantity' => 1,
              'unit' => 'pcs',
              'unitPrice' => $amountToPay,
              'grossTotalAmount' => $amountToPay,
              'netTotalAmount' => $amountToPay,
            ],
        ],
        'amount' => $amountToPay,
        'currency' => 'DKK',
        'reference' => 'reference',
      ],
    ]);

    $response = $this->httpClient->request(
      'POST',
      $endpoint,
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => $this->paymentHelper->getSecretKey(),
        ],
        'body' => $payload,
      ]
    );
    $result = $response->getBody()->getContents();

    return new Response($result);
  }

}
