<?php

namespace Drupal\os2forms_payment\Plugin\WebformElement;

use Drupal\Core\Http\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\os2forms_payment\Helper\PaymentHelper;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Url;
use Drupal\quickedit_test\Plugin\InPlaceEditor\WysiwygEditor;

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
class PaymentElement extends WebformElementBase
{

  private readonly PaymentHelper $paymentHelper;

  private readonly RequestStack $requestStack;


  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->paymentHelper = $container->get(PaymentHelper::class);
    $instance->requestStack = $container->get("request_stack");
    return $instance;
  }


  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties()
  {
    // Here you define your webform element's default properties,
    // which can be inherited.
    //
    // @see \Drupal\webform\Plugin\WebformElementBase::defaultProperties
    // @see \Drupal\webform\Plugin\WebformElementBase::defaultBaseProperties
    return [
      'amount_to_pay' => '',
      'checkout_page_description' => '',
    ] + parent::defineDefaultProperties();
  }

  /* ************************************************************************ */

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL)
  {
    parent::prepare($element, $webform_submission);

    // Here you can customize the webform element's properties.
    // You can also customize the form/render element's properties via the
    // FormElement.
    //
    // @see \Drupal\webform_example_element\Element\WebformExampleElement::processWebformElementExample
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state)
  {
    $form = parent::form($form, $form_state);

    $form['element']['amount_to_pay'] = [
      '#type' => 'textfield',
      '#title' => $this
        ->t('Machine name of field containing amount to pay'),
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
   * Alters form.
   *
   * @phpstan-param array<string, mixed> $element
   * @phpstan-param array<string, mixed> $form
   */
  public function alterForm(array &$element, array &$form, FormStateInterface $form_state): void
  {

    $form['#attached']['library'][] = 'os2forms_payment/os2forms_payment';

    $webform_current_page = $form['progress']['#current_page'];
    // Check if we are on the preview page
    if ($webform_current_page === "webform_preview") {

      $amount_to_pay = $this->paymentHelper->getAmountToPay($form_state->getUserInput(), $this->getElementProperty($element, 'amount_to_pay'));

      // If amount to pay is present, inject placeholder for nets gateway containing amount to pay
      if (!is_null($amount_to_pay) && $amount_to_pay > 0) {
        $form['content']['#markup'] =  $element['#checkout_page_description'];

        $form['checkout_container'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'checkout-container-div',
            'data-checkout-key' => $this->paymentHelper->getCheckoutKey(),
            'data-create-payment-url' => Url::fromRoute("os2forms_payment.createPayment", ['amountToPay' => $amount_to_pay])->toString(true)->getGeneratedUrl()
          ],
        ];
        $form['payment_reference_field'] = [
          '#type' => 'hidden',
          '#name' => 'payment_reference_field'
        ];
      }
    }

  }
}
