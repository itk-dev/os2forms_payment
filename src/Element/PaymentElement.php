<?php

namespace Drupal\os2forms_payment\Element;

use Drupal\Core\Render\Element\FormElement;

/**
 * Provides an OS2forms payment element'.
 *
 * @FormElement("os2forms_payment")
 */
class PaymentElement extends FormElement {

  /**
   * {@inheritdoc}
   * @return array<mixed>
   */
  public function getInfo() {
    return array();
  }

}
