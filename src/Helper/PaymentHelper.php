<?php

namespace Drupal\os2forms_payment\Helper;

use Drupal\Core\Http\RequestStack;
use Drupal\Core\Site\Settings;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Payment helper class.
 */
class PaymentHelper {

  /**
   * {@inheritDoc}
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ClientInterface $httpClient,
  ) {
  }

  /**
   * Hook on webform submission presave, modifying data before submission.
   *
   * @return void
   *   Return
   */
  public function webformSubmissionPresave(WebFormSubmissionInterface $submission) {
    $webformElements = $submission->getWebform()->getElementsDecodedAndFlattened();
    $paymentElement = NULL;
    $paymentElementMachineName = NULL;

    foreach ($webformElements as $key => $webformElement) {
      if ('os2forms_payment' === ($webformElement['#type'] ?? NULL)) {
        $paymentElement = $webformElement;
        $paymentElementMachineName = $key;
        break;
      }
    }
    if (NULL === $paymentElement) {
      return;
    }

    $submissionData = $submission->getData();
    $amountToPay = $this->getAmountToPay($submissionData, $paymentElement['#amount_to_pay']);
    /*
     * The paymentReferenceField is not a part of the form submission,
     * so we get it from the POST payload.
     * The goal here is to store the payment_id and amount_to_pay
     * as a JSON object in the os2forms_payment submission value.
     */
    $request = $this->requestStack->getCurrentRequest();
    $paymentReferenceField = $request->request->get('os2forms_payment_reference_field');
    $paymentPosting = $paymentElement['#payment_posting'] ?? 'undefined';

    if ($request && $amountToPay) {
      $payment_object = [
        'paymentObject' => [
          'payment_id' => $paymentReferenceField,
          'amount' => $amountToPay,
          'posting' => $paymentPosting,
        ],
      ];

      $submission_data[$paymentElementMachineName] = json_encode($payment_object);
      $submission->setData($submission_data);
    }
  }

  /**
   * Returns amount to pay, based on fields responsible for the value.
   *
   * @param array<mixed> $values
   *   Values contained in the element.
   * @param string $key
   *   Selector for the amount to pay, defined in the module.
   *
   * @return float
   *   Returns the total amount the user has to pay.
   */
  public function getAmountToPay(array $values, string $key): float {
    $amountToPay = $values[$key] ?? NULL;

    if (is_array($amountToPay)) {
      $amountToPay = array_sum(array_values(array_filter($amountToPay)));
    }

    return (float) $amountToPay;
  }

  /**
   * Validates a given payment via the Nets Payment API.
   *
   * @param string $endpoint
   *   Nets Payment API endpoint.
   *
   * @return bool
   *   Returns validation results.
   */
  public function validatePayment($endpoint): bool {
    $response = $this->httpClient->request(
      'GET',
      $endpoint,
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => $this->getSecretKey(),
        ],
      ]
    );
    $result = $this->responseToObject($response);

    $reservedAmount = $result->payment->summary->reservedAmount ?? NULL;
    $chargedAmount = $result->payment->summary->chargedAmount ?? NULL;

    if ($reservedAmount && !$chargedAmount) {
      // Payment is reserved, but not yet charged.
      $paymentCharged = $this->chargePayment($endpoint, $reservedAmount);
      return $paymentCharged;
    }

    if (!$reservedAmount && !$chargedAmount) {
      // Reservation was not made.
      return FALSE;
    }

    return $reservedAmount === $chargedAmount;
  }

  /**
   * Charges a given payment via the Nets Payment API.
   *
   * @param string $endpoint
   *   Nets Payment API endpoint.
   * @param string $reservedAmount
   *   The reserved amount to be charged.
   *
   * @return bool
   *   Returns whether the payment was charged.
   */
  private function chargePayment($endpoint, $reservedAmount) {
    $endpoint = $endpoint . '/charges';

    $response = $this->httpClient->request(
      'POST',
      $endpoint,
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => $this->getSecretKey(),
        ],
        'json' => [
          'amount' => $reservedAmount,
        ],

      ]
    );

    $result = $this->responseToObject($response);
    return (bool) $result->chargeId;
  }

  /**
   * Returns the settings for the os2forms_payment module.
   *
   * @return array<mixed>
   *   Returns the settings for the os2forms_payment module.
   */
  private function getPaymentSettings(): array {
    return Settings::get('os2forms_payment') ?? [];
  }

  /**
   * Returns the checkout key for the nets implementation.
   *
   * @return string
   *   The Checkout key.
   */
  public function getCheckoutKey(): ?string {
    return $this->getPaymentSettings()['checkout_key'] ?? NULL;
  }

  /**
   * Returns the secret key for the nets implementation.
   *
   * @return string
   *   The Secret Key.
   */
  public function getSecretKey(): ?string {
    return $this->getPaymentSettings()['secret_key'] ?? NULL;
  }

  /**
   * Returns the url for the page displaying terms and conditions.
   *
   * @return string
   *   The terms and conditions url.
   */
  public function getTermsUrl(): ?string {
    return $this->getPaymentSettings()['terms_url'] ?? NULL;
  }

  /**
   * Returns whether the module is operated in test mode.
   *
   * @return bool
   *   The test mode boolean.
   */
  public function getTestMode(): bool {
    return $this->getPaymentSettings()['test_mode'] ?? TRUE;
  }

  /**
   * Returns the Nets API payment endpoint.
   *
   * @return string
   *   The endpoint URL.
   */
  public function getPaymentEndpoint(): string {
    return $this->getTestMode()
    ? 'https://test.api.dibspayment.eu/v1/payments/'
    : 'https://api.dibspayment.eu/v1/payments/';
  }

  /**
   * Converts a JSON response to an object.
   *
   * @return object
   *   The response object.
   */
  public function responseToObject(ResponseInterface $response): object {
    return json_decode($response->getBody()->getContents());
  }

}
