<?php

namespace Drupal\os2forms_payment\Helper;

use Drupal\Core\Http\RequestStack;
use Drupal\Core\Site\Settings;

/**
 * Payment helper class.
 */
class PaymentHelper {

  /**
   * Secret key for Nets EasyPay integration.
   *
   * @var string
   */
  protected $secretKey;

  /**
   * Checkout key for Nets EasyPay integration.
   *
   * @var string
   */
  protected $checkoutKey;

  /**
   * Terms URL for Nets EasyPay integration.
   *
   * @var string
   */
  protected $termsUrl;

  /**
   * Callback URL for Nets EasyPay integration.
   *
   * @var string
   */
  protected $callbackUrl;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    private readonly RequestStack $requestStack
  ) {
    $this->secretKey = Settings::get('os2forms_payment_secret_key');
    $this->checkoutKey = Settings::get('os2forms_payment_checkout_key');
    $this->termsUrl = Settings::get('os2forms_payment_terms_url');
    $this->callbackUrl = Settings::get('os2forms_payment_callback_url');

  }

  /**
   * Hook on webform submission presave, modifying data before submission.
   *
   * @return void
   */
  public function webformSubmissionPresave(mixed $submission) {
    $submission_data = $submission->getData();
    $webform_elements = $submission->getWebform()->getElementsDecoded();
    $payment_element_key = false;

    foreach ($webform_elements as $key => $webform_element) {
      if ($webform_element['#type'] === "os2forms_payment") {
        $payment_element_key = $key;
      }
    }
    if (!$payment_element_key) {
      return;
    }
    $amount_to_pay_selector = $submission->getWebform()->getElement($payment_element_key)['#amount_to_pay'];
    $amount_to_pay = $this->getAmountToPay($submission_data, $amount_to_pay_selector);

    $request = $this->requestStack->getCurrentRequest()->request->all();
    $payment_object = json_decode($request['payment_reference_field']);
    $payment_object->details['payment_amount'] = $amount_to_pay;

    if ($request) {
      $submission_data[$payment_element_key] = json_encode($payment_object);
      $submission->setData($submission_data);
    }
  }

  /**
   * Returns amount to pay, based on fields responsible for the value.
   *
   * @param array<mixed> $values
   * @return float
   */
  public function getAmountToPay(array $values, string $key): float {
    $amount_to_pay = $values[$key] ?? NULL;

    if (is_array($amount_to_pay)) {
      $amount_to_pay = array_sum(array_values(array_filter($amount_to_pay)));
    }

    return (float) $amount_to_pay;
  }

  /**
   * Returns the secret key for NETS EasyPay.
   *
   * @return string
   */
  public function getSecretKey() {
    return $this->secretKey;
  }

  /**
   * Returns the checkout key for NETS EasyPay.
   *
   * @return string
   */
  public function getCheckoutKey() {
    return $this->checkoutKey;
  }

  /**
   * Returns the url displaying the terms and conditions.
   *
   * @return string
   */
  public function getTermsUrl() {
    return $this->termsUrl;
  }

  /**
   * Returns the callback URL for the gateway.
   *
   * @return string
   */
  public function getCallbackUrl() {
    return $this->callbackUrl;
  }

}
