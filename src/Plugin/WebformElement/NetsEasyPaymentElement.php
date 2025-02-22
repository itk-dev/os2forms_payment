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

  const PAYMENT_REFERENCE_NAME = 'os2forms_payment_reference_field';

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
      'checkout_language' => [],
      'amount_to_pay' => '',
      'checkout_page_description' => '',
      'terms_and_conditions_url' => $this->paymentHelper->getTermsUrl(),
      'merchant_terms_url' => $this->paymentHelper->getMerchantTermsUrl(),
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
    $availableLanguages = $this->getAvailableLanguages();
    $form['element']['checkout_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Select checkout language'),
      '#required' => FALSE,
      '#options' => $availableLanguages,
    ];
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
    $form['element']['terms_and_conditions_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Terms and conditions URL'),
      '#description' => $this->t('The complete URL to the terms and conditions, including the protocol. Example: https://www.example.com/terms-and-conditions'),
      '#required' => TRUE,
    ];
    $form['element']['merchant_terms_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The merchant terms URL'),
      '#description' => $this->t('The complete URL to the merchant terms, including the protocol. Example: https://www.example.com/merchant-terms'),
      '#required' => TRUE,
    ];

    $form['element']['payment_posting'] = [
      '#type' => 'textfield',
      '#title' => $this
        ->t('Suffix for Internal reference on payment'),
    ];

    $form['element']['payment_methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select available payment methods'),
      '#options' => [
        'Card' => $this->t('Kortbetaling'),
        'MobilePay' => $this->t('MobilePay'),
      ],
      '#required' => TRUE,
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
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state object.
   *
   * @return void
   *   Return
   */
  public function alterForm(array &$element, array &$form, FormStateInterface $formState): void {
    $form['#attached']['library'][] = 'os2forms_payment/os2forms_payment';
    $form['#attached']['library'][] = $this->paymentHelper->getTestMode()
      ? 'os2forms_payment/nets_easy_test'
      : 'os2forms_payment/nets_easy_prod';
    $callbackUrl = Url::fromRoute('<current>')->setAbsolute()->toString(TRUE)->getGeneratedUrl();

    $webformCurrentPage = $formState->get('current_page');
    // Check if we are on the preview page.
    if ($webformCurrentPage === "webform_preview") {
      $amountToPay = $this->paymentHelper->getAmountToPay($formState->getUserInput(), $this->getElementProperty($element, 'amount_to_pay'));
      $this->paymentHelper->setAmountToPayTemp($amountToPay);
      /*
       * If amount to pay is present,
       * inject placeholder for nets gateway containing amount to pay.
       */
      if (!empty($element['#checkout_page_description'])) {
        $form['os2forms_payment_content']['#markup'] = $element['#checkout_page_description'];
      }

      $paymentMethods = array_values(array_filter($element['#payment_methods'] ?? []));
      $paymentPosting = $element['#payment_posting'] ?? 'undefined';
      $checkoutLanguage = $element['#checkout_language'] ?? 'da-DK';
      $termsAndConditionsUrl = $element['#terms_and_conditions_url'] ?? '';
      $merchantTermsUrl = $element['#merchant_terms_url'] ?? '';

      $form['os2forms_payment_checkout_container'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'checkout-container-div',
          'data-checkout-key' => $this->paymentHelper->getCheckoutKey(),
          'data-checkout-language' => $checkoutLanguage,
          'data-payment-error-message' => $this->t('An error has occurred. Please try again later.'),
          'data-create-payment-url' => Url::fromRoute("os2forms_payment.createPayment",
            [
              'amountToPay' => $amountToPay,
              'callbackUrl' => $callbackUrl,
              'paymentMethods' => $paymentMethods,
              'paymentPosting' => $paymentPosting,
              'termsAndConditionsUrl' => $termsAndConditionsUrl,
              'merchantTermsUrl' => $merchantTermsUrl,
            ])->toString(TRUE)->getGeneratedUrl(),
        ],
        '#limit_validation_errors' => [],
        '#element_validate' => [[$this::class, 'validatePayment']],
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
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state object.
   *
   * @return mixed
   *   Returns validation results.
   */
  public static function validatePayment(array &$element, FormStateInterface $formState): mixed {
    $paymentHelper = \Drupal::service(PaymentHelper::class);

    $paymentHelper->validatePayment($element, $formState);

    return TRUE;
  }

  /**
   * Modifies how the payment details is displayed under "Resultater".
   *
   * @param array<mixed> $element
   *   Form element object.
   * @param \Drupal\webform\WebformSubmissionInterface $webformSubmission
   *   Form submission object.
   * @param array<mixed> $options
   *   Result view details.
   *
   * @return mixed
   *   Returns content for the results view.
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webformSubmission, array $options = []): mixed {
    $paymentElementData = $webformSubmission->getData()[$element['#webform_key']] ?? NULL;

    if ($paymentElementData) {
      $paymentData = json_decode($paymentElementData)->paymentObject ?? NULL;
      if ($paymentData) {
        $form['payment_id'] = [
          '#type' => 'item',
          '#title' => $this->t('Payment ID'),
          '#markup' => $paymentData->payment_id ?? '👻',
        ];
        $form['amount'] = [
          '#type' => 'item',
          '#title' => $this->t('Amount'),
          '#markup' => $paymentData->amount,
        ];
        $form['status'] = [
          '#type' => 'item',
          '#title' => $this->t('Status'),
          '#markup' => $paymentData->status ?? $this->t('undefined'),
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

  /**
   * Retrieves an array of available languages.
   *
   * @return array<string, string>
   *   Array containing available languages.
   */
  private function getAvailableLanguages(): array {
    return [
      'da-DK' => 'Dansk',
      'en-GB' => 'English',
    ];

  }

}
