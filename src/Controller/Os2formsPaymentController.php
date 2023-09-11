<?php

namespace Drupal\os2forms_payment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\os2forms_payment\Helper\PaymentHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for OS2forms payment routes.
 */
class Os2formsPaymentController extends ControllerBase {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly PaymentHelper $paymentHelper
  ) {

  }

  /**
   * Create.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(PaymentHelper::class),
    );
  }

  /**
   * Create payment.
   */
  public function createPayment(Request $request) {
    $amountToPay = floatval($request->get('amountToPay'));

    $payload = json_encode([
      "checkout" => [
        "integrationType" => "EmbeddedCheckout",
        "url" => $this->paymentHelper->getCallbackUrl(),
        "termsUrl" => $this->paymentHelper->getTermsUrl(),
      ],
      "order" => [
        "items" => [
          [
            "reference" => "reference",
            "name" => "product",
            "quantity" => 1,
            "unit" => "pcs",
            "unitPrice" => $amountToPay,
            "grossTotalAmount" => $amountToPay,
            "netTotalAmount" => $amountToPay,
          ],
        ],
        "amount" => $amountToPay,
        "currency" => "DKK",
        "reference" => "reference",
      ],
    ]);

    $ch = curl_init('https://test.api.dibspayment.eu/v1/payments');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt(
      $ch,
      CURLOPT_HTTPHEADER,
      [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: ' . $this->paymentHelper->getSecretKey(),
      ]
    );
    $result = curl_exec($ch);
    $response = new Response();
    $response->setContent($result);
    return $response;

  }

}
