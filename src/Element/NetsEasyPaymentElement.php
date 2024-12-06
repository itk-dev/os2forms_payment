<?php

namespace Drupal\os2forms_payment\Element;

use Drupal\Core\Render\Element\FormElement;

/**
 * Provides an OS2forms payment element'.
 *
 * @FormElement("os2forms_payment_nets_easy_payment")
 */
class NetsEasyPaymentElement extends FormElement {

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   Return
   */
  public function getInfo() {
    return [];
  }

}
