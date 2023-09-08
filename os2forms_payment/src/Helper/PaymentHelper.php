<?php

namespace Drupal\os2forms_payment\Helper;

use Drupal\Core\Http\RequestStack;
use Drupal\Core\Site\Settings;
use Drupal\webform\Entity\Webform;


class PaymentHelper {

  protected $secret_key;

  protected $checkout_key;

  protected $terms_url;

  protected $callback_url;

  public function __construct(
    private readonly RequestStack $requestStack
  ) {
    $this->secret_key = Settings::get('os2forms_payment_secret_key');
    $this->checkout_key = Settings::get('os2forms_payment_checkout_key');
    $this->terms_url = Settings::get('os2forms_payment_terms_url');
    $this->callback_url = Settings::get('os2forms_payment_callback_url');

  }

  public function webform_submission_presave($submission) {
    $submission_data = $submission->getData();
    $webform_elements = $submission->getWebform()->getElementsDecoded();

    foreach ($webform_elements as $key => $webform_element) {
        if ($webform_element['#type'] === "os2forms_payment") {
          $payment_element_key = $key;
        }
    }
    $amount_to_pay_selector = $submission->getWebform()->getElement($payment_element_key)['#amount_to_pay'];
    $amount_to_pay = $this->getAmountToPay($submission_data, $amount_to_pay_selector);


    $request = $this->requestStack->getCurrentRequest()->request->all();
    $payment_object = json_decode($request['payment_reference_field']);
    $payment_object->details['payment_amount'] = $amount_to_pay;

    if ($request && $payment_element_key) {
      $submission_data[$payment_element_key] = json_encode($payment_object);
      $submission->setData($submission_data);
    }
  }

  public function getAmountToPay(array $values, string $key):float {
    $amount_to_pay = $values[$key] ?? null;

    if (is_array($amount_to_pay)) {
      $amount_to_pay = array_sum(array_values(array_filter($amount_to_pay)));
    }

    return (float)$amount_to_pay;
  }

  public function getSecretKey() {
    return $this->secret_key;
  }

  public function getCheckoutKey() {
    return $this->checkout_key;
  }

  public function getTermsUrl() {
    return $this->terms_url;
  }

  public function getCallbackUrl() {
    return $this->callback_url;
  }
}
