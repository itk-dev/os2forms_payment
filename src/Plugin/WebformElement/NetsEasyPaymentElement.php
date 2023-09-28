<?php

namespace Drupal\os2forms_payment\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\os2forms_payment\Helper\PaymentHelper;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'OS2forms_payment' element.
 *
 * @WebformElement(
 *   id = "os2forms_payment",
 *   label = @Translation("OS2forms payment element"),
 *   description = @Translation("Provides a os2forms_payment element."),
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

  public const PAYMENT_REFERENCE_NAME = 'os2forms_payment_reference_field';

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
      'payment_methods' => ['Card'],
      'payment_posting' => '',
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
    $availableElements = $this->getAmountElements();
    $form['element']['amount_to_pay'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the element, containing the amount to pay'),
      '#required' => FALSE,
      '#options' => $availableElements,
      '#description' => $this
        ->t('The field containing the amount to pay can be of type: textfield, hidden or select.'),
    ];

    $form['element']['checkout_page_description'] = [
      '#type' => 'textarea',
      '#title' => $this
        ->t('Content to display on checkout page'),
      '#description' => $this
        ->t('This field supports simple html'),
    ];

    $form['element']['payment_posting'] = [
      '#type' => 'textfield',
      '#title' => $this
        ->t('Internal reference for where the payment belongs'),
    ];

    $form['element']['payment_methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select available payment methods'),
      '#options' => [
        'Card' => $this->t('Kortbetaling'),
        'MobilePay' => $this->t('MobilePay'),
      ],
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
    $form['#attached']['library'][] = $this->paymentHelper->getTestMode()
      ? 'os2forms_payment/nets_easy_test'
      : 'os2forms_payment/nets_easy_prod';
    $callback_url = Url::fromRoute('<current>')->setAbsolute()->toString(TRUE)->getGeneratedUrl();
    $webform_current_page = $form['progress']['#current_page'];
    // Check if we are on the preview page.
    if ($webform_current_page === "webform_preview") {

      $amount_to_pay = $this->paymentHelper->getAmountToPay($form_state->getUserInput(), $this->getElementProperty($element, 'amount_to_pay'));
      /*
       * If amount to pay is present,
       * inject placeholder for nets gateway containing amount to pay.
       */
      if (!empty($element['#checkout_page_description'])) {
        $form['os2forms_payment_content']['#markup'] = $element['#checkout_page_description'];
      }

      $paymentMethods = [];
      if (!empty($element['#payment_methods'])) {
        $paymentMethods = http_build_query(array_filter(array_values($element['#payment_methods'])));
      }

      $paymentPosting = $element['#payment_posting'] ?? 'unset';

      $form['os2forms_payment_checkout_container'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'checkout-container-div',
          'data-checkout-key' => $this->paymentHelper->getCheckoutKey(),
          'data-payment-error-message' => $this->t('An error has occurred. Please try again later.'),
          'data-create-payment-url' => Url::fromRoute("os2forms_payment.createPayment",
            [
              'amountToPay' => $amount_to_pay,
              'callbackUrl' => $callback_url,
              'paymentMethods' => $paymentMethods,
              'paymentPosting' => $paymentPosting,
            ])->toString(TRUE)->getGeneratedUrl(),
        ],
        '#limit_validation_errors' => [],
        '#element_validate' => [[get_class($this), 'validatePayment']],
      ];
      $form['os2forms_payment_reference_field'] = [
        '#type' => 'hidden',
        '#name' => $this::PAYMENT_REFERENCE_NAME,
      ];
    }
  }

  /**
   * Validates the payment object.
   *
   * @param array<mixed> $element
   *   Form element object.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return mixed
   *   Returns validation results.
   */
  public static function validatePayment(array &$element, FormStateInterface $form_state): mixed {
    $paymentHelper = \Drupal::service('Drupal\os2forms_payment\Helper\PaymentHelper');
    $paymentElementClass = get_called_class();

    $paymentId = $form_state->getValue($paymentElementClass::PAYMENT_REFERENCE_NAME);
    if (!$paymentId) {
      return $form_state->setError(
        $element,
        t('The form could not be submitted. Please try again.')
      );
    }

    $endpoint = $paymentHelper->getPaymentEndpoint() . $paymentId;

    $paymentValidated = $paymentHelper->validatePayment($endpoint);

    if (!$paymentValidated) {
      return $form_state->setError(
        $element,
        t('The payment could not be validated. Please try again.')
      );
    }

    return TRUE;
  }

  /**
   * Modifies how the payment details is displayed under "Resultater".
   *
   * @param array<mixed> $element
   *   Form element object.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Form submission object.
   * @param array<mixed> $options
   *   Result view details.
   *
   * @return mixed
   *   Returns content for the results view.
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []): mixed {
    $payment_element_data = $webform_submission->getData()[$element['#webform_key']] ?? NULL;

    if ($payment_element_data) {
      $payment_data = json_decode($payment_element_data)->paymentObject ?? NULL;
      if ($payment_data) {
        $form['payment_id'] = [
          '#type' => 'item',
          '#title' => $this->t('Payment ID'),
          '#markup' => $payment_data->payment_id ?? '👻' ?: '👻',
        ];
        $form['amount'] = [
          '#type' => 'item',
          '#title' => $this->t('Amount'),
          '#markup' => $payment_data->amount,
        ];
        $form['posting'] = [
          '#type' => 'item',
          '#title' => $this->t('Posting'),
          '#markup' => $payment_data->posting ?? $this->t('undefined') ?: $this->t('undefined'),
        ];
        return $form;
      }
      else {
        return $this->t('No payment data found');
      }
    }
    else {
      return $this->t('No payment data found');
    }

  }

  /**
   * Returns available elements for containing amount to pay.
   *
   * @return array<mixed>
   *   Returns array of elements.
   */
  private function getAmountElements(): array {
    $elements = $this->getWebform()->getElementsDecodedAndFlattened();

    $elementTypes = [
      'textfield',
      'hidden',
      'select',
    ];
    $elements = array_filter(
      $elements,
      static fn(array $element) => in_array($element['#type'], $elementTypes, TRUE)
    );

    return array_map(
      static fn(array $element) => $element['#title'],
      $elements
    );
  }

}
