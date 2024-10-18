<?php

namespace Drupal\os2forms_payment\Helper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\os2forms_payment\Plugin\WebformElement\NetsEasyPaymentElement;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Payment helper class.
 */
class PaymentHelper {

  use StringTranslationTrait;
  const AMOUNT_TO_PAY = 'AMOUNT_TO_PAY';

  /**
   * Private temp store.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  private readonly PrivateTempStore $privateTempStore;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ClientInterface $httpClient,
    PrivateTempStoreFactory $tempStore,
  ) {
    $this->privateTempStore = $tempStore->get('os2forms_payment');
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

    /*
     * The paymentReferenceField is not a part of the form submission,
     * so we get it from the POST payload.
     */
    $request = $this->requestStack->getCurrentRequest();
    $paymentReferenceField = $request->request->get('os2forms_payment_reference_field');

    /*
     * The goal here is to store the payment_id, amount_to_pay and posting
     * as a JSON object in the os2forms_payment submission value.
     */
    $submissionData = $submission->getData();
    $amountToPay = $this->getAmountToPay($submissionData, $paymentElement['#amount_to_pay']);

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
   * @param array<mixed> $element
   *   Element array.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state object.
   */
  public function validatePayment(array &$element, FormStateInterface $formState): void {
    // @FIXME: make error messages translateable.
    $paymentId = $formState->getValue(NetsEasyPaymentElement::PAYMENT_REFERENCE_NAME);

    if (!$paymentId) {
      $formState->setError(
        $element,
        $this->t('No payment found.')
      );
      return;
    }

    $paymentEndpoint = $this->getPaymentEndpoint() . $paymentId;

    $response = $this->httpClient->request(
      'GET',
      $paymentEndpoint,
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => $this->getSecretKey(),
        ],
      ]
    );
    $result = $this->responseToObject($response);

    $amountToPay = floatval($this->getAmountToPayTemp() * 100);
    $reservedAmount = floatval($result->payment->summary->reservedAmount ?? 0);
    $chargedAmount = floatval($result->payment->summary->chargedAmount ?? 0);

    if ($amountToPay !== $reservedAmount) {
      // Reserved amount mismatch.
      $formState->setError(
        $element,
        $this->t('Reserved amount mismatch')
      );
      return;
    }

    if ($reservedAmount > 0 && $chargedAmount == 0) {
      // Payment is reserved, but not yet charged.
      $paymentChargeId = $this->chargePayment($paymentEndpoint, $reservedAmount);
      if (!$paymentChargeId) {
        $formState->setError(
          $element,
          $this->t('Payment was not charged')
        );
        return;
      }

      /*
      Right after charging the amount, the charge is validated via another
      endpoint. Even though the charge is confirmed previously, due to
      timing, the charge confirmation endpoint may return a 404 error.

      Therefore, it is wrapped in a try/catch to allow it to pass,
      even when it fails, essentially letting this serve as an optional check.
       */
      try {
        $chargeEndpoint = $this->getChargeEndpoint() . $paymentChargeId;
        $response = $this->httpClient->request(
          'GET',
          $chargeEndpoint,
          [
            'headers' => [
              'Authorization' => $this->getSecretKey(),
            ],
          ]
        );

        $result = $this->responseToObject($response);

        $chargedAmountValidated = floatval($result->amount);

        if ($reservedAmount !== $chargedAmountValidated) {
          // Payment amount mismatch.
          $formState->setError(
            $element,
            $this->t('Payment amount mismatch')
          );
          return;
        }
      }
      catch (\Exception $e) {
      }
    }
  }

  /**
   * Charges a given payment via the Nets Payment API.
   *
   * @param string $endpoint
   *   Nets Payment API endpoint.
   * @param float $reservedAmount
   *   The reserved amount to be charged.
   *
   * @return string
   *   Returns charge id.
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
    return $result->chargeId;
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
   * Returns the Nets API charge endpoint.
   *
   * @return string
   *   The endpoint URL.
   */
  public function getChargeEndpoint(): string {
    return $this->getTestMode()
      ? 'https://test.api.dibspayment.eu/v1/charges/'
      : 'https://api.dibspayment.eu/v1/charges/';
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

  /**
   * Sets the amount to pay in private temporary storage.
   *
   * @param float $amountToPay
   *   The amount to pay.
   *
   * @return void
   *   Sets the tempoary storage.
   */
  public function setAmountToPayTemp(float $amountToPay): void {
    $this->privateTempStore->set(self::AMOUNT_TO_PAY, $amountToPay);
  }

  /**
   * Gets the amount to pay from private temporary storage.
   *
   * @return float
   *   The amount to pay.
   */
  public function getAmountToPayTemp(): float {
    return $this->privateTempStore->get(self::AMOUNT_TO_PAY);
  }

}
