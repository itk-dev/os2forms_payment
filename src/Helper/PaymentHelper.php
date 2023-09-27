<?php

namespace Drupal\os2forms_payment\Helper;

use Drupal\Core\Http\RequestStack;
use Drupal\Core\Site\Settings;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Payment helper class.
 */
class PaymentHelper {

  /**
   * {@inheritDoc}
   */
  public function __construct(
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Hook on webform submission presave, modifying data before submission.
   *
   * @return void
   *   Return
   */
  public function webformSubmissionPresave(WebFormSubmissionInterface $submission) {
    $webform_elements = $submission->getWebform()->getElementsDecodedAndFlattened();
    $payment_element = NULL;
    $payment_element_machine_name = NULL;

    foreach ($webform_elements as $key => $webform_element) {
      if ('os2forms_payment' === ($webform_element['#type'] ?? NULL)) {
        $payment_element = $webform_element;
        $payment_element_machine_name = $key;
        break;
      }
    }
    if (NULL === $payment_element) {
      return;
    }

    $submission_data = $submission->getData();
    $amount_to_pay = $this->getAmountToPay($submission_data, $payment_element['#amount_to_pay']);
    /*
     * The payment_reference_field is not a part of the form submission,
     * so we get it from the POST payload.
     * The goal here is to store the payment_id and amount_to_pay
     * as a JSON object in the os2forms_payment submission value.
     */
    $request = $this->requestStack->getCurrentRequest();
    $payment_reference_field = $request->request->get('payment_reference_field');

    if ($request && $amount_to_pay) {
      $payment_object = [
        'paymentObject' => [
          'payment_id' => $payment_reference_field,
          'amount' => $amount_to_pay,
        ],
      ];

      $submission_data[$payment_element_machine_name] = json_encode($payment_object);
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
    $amount_to_pay = $values[$key] ?? NULL;

    if (is_array($amount_to_pay)) {
      $amount_to_pay = array_sum(array_values(array_filter($amount_to_pay)));
    }

    return (float) $amount_to_pay;
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

}
