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
   *   Return NetsEasyController
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
  public function createPayment(Request $request): Response {
    $amountToPay = floatval($request->get('amountToPay'));
    // Amount to pay is defined in the lowest monetary unit.
    $amountToPay *= 100;
    $callbackUrl = $request->get('callbackUrl');
    $paymentPosting = $request->get('paymentPosting');
    $paymentMethods = $request->get('paymentMethods');
    if ($paymentMethods) {
      $paymentMethodsConfiguration = array_map(
        static fn ($name) => ['name' => $name, 'enabled' => TRUE],
        $paymentMethods
      );
    }


    if (!$callbackUrl || $amountToPay <= 0) {
      return new Response(json_encode(['error' => $this->t('An error has occurred. Please try again later.')]));
    }
    $endpoint = $this->paymentHelper->getPaymentEndpoint();
    $payload = json_encode([
      'checkout' => [
        'integrationType' => 'EmbeddedCheckout',
        'url' => $callbackUrl,
        'termsUrl' => $this->paymentHelper->getTermsUrl(),
      ],
      'paymentMethodsConfiguration' => $paymentMethodsConfiguration,
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
        'reference' => $paymentPosting,
      ],
      'paymentPosting' => $paymentPosting,
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
