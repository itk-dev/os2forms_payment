<?php

namespace Drupal\os2forms_payment\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\os2forms_payment\Helper\PaymentHelper;
use Drupal\webform\Plugin\WebformElementBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'OS2forms_payment' element.
 *
 * @WebformElement(
 *   id = "os2forms_payment",
 *   label = @Translation("OS2forms payment element"),
 *   description = @Translation("Provides a payment element."),
 *   category = @Translation("Payment"),
 * )
 */
class NetsEasyPaymentElement extends WebformElementBase {
  /**
   * Payment helper class.
   *
   * @var \Drupal\os2forms_payment\Helper\PaymentHelper
   */
  private PaymentHelper $paymentHelper;

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->paymentHelper = $container->get(PaymentHelper::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   Returns array of default properties.
   */
  protected function defineDefaultProperties() {
    return [
      'amount_to_pay' => '',
      'checkout_page_description' => '',
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   Form object.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return array<mixed>
   *   Returns modified form object.
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $availableElements = $this->getRecipientElements();
    $form['element']['amount_to_pay'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the element, containing the amount to pay'),
      '#required' => FALSE,
      '#options' => $availableElements,
    ];

    $form['element']['checkout_page_description'] = [
      '#type' => 'textarea',
      '#title' => $this
        ->t('Content to display on checkout page'),
      '#description' => $this
        ->t('This field supports simple html'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $element
   *   Form element object.
   * @param array<mixed> $form
   *   Form object.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return void
   *   Return
   */
  public function alterForm(array &$element, array &$form, FormStateInterface $form_state): void {
    $form['#attached']['library'][] = 'os2forms_payment/os2forms_payment';
    $is_test_mode = \Drupal::service(PaymentHelper::class)->getTestMode();

    if ($is_test_mode) {
      $form['#attached']['library'][] = 'os2forms_payment/nets_easy_test';
    }
    else {
      $form['#attached']['library'][] = 'os2forms_payment/nets_easy_prod';
    }
    $webform_current_page = $form['progress']['#current_page'];
    // Check if we are on the preview page.
    if ($webform_current_page === "webform_preview") {

      $amount_to_pay = $this->paymentHelper->getAmountToPay($form_state->getUserInput(), $this->getElementProperty($element, 'amount_to_pay'));

      /*
       * If amount to pay is present,
       * inject placeholder for nets gateway containing amount to pay.
       */
      if ($amount_to_pay > 0) {
        $form['content']['#markup'] = $element['#checkout_page_description'] ?? NULL;

        $form['checkout_container'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'checkout-container-div',
            'data-checkout-key' => \Drupal::service(PaymentHelper::class)->getCheckoutKey(),
            'data-create-payment-url' => Url::fromRoute("os2forms_payment.createPayment", ['amountToPay' => $amount_to_pay])->toString(TRUE)->getGeneratedUrl(),
          ],
        ];
        $form['payment_reference_field'] = [
          '#type' => 'hidden',
          '#name' => 'payment_reference_field',
        ];
      }
    }
  }

  /**
   * Get recipient elements.
   *
   * @phpstan-return array<string, mixed>
   */
  private function getRecipientElements(): array {
    $elements = $this->getWebform()->getElementsDecodedAndFlattened();

    $elementTypes = [
      'textfield',
      'hidden',
      'select',
    ];
    $elements = array_filter(
      $elements,
      static function (array $element) use ($elementTypes) {
        return in_array($element['#type'], $elementTypes, TRUE);
      }
    );

    return array_map(static function (array $element) {
      return $element['#title'];
    }, $elements);
  }

}