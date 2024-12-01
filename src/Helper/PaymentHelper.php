<?php

namespace Drupal\os2forms_payment\Helper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\os2forms_payment\Plugin\AdvancedQueue\JobType\NetsEasyPaymentHandler;
use Drupal\os2forms_payment\Plugin\WebformElement\NetsEasyPaymentElement;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Payment helper class.
 */
class PaymentHelper {
  /**
   * The submission logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $submissionLogger;

  use StringTranslationTrait;

  const AMOUNT_TO_PAY = 'AMOUNT_TO_PAY';
  /**
   * The ID of the queue.
   *
   * @var string
   */
  private string $queueId = 'os2forms_payment';

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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    PrivateTempStoreFactory $tempStore,
  ) {
    $this->privateTempStore = $tempStore->get('os2forms_payment');
    $this->submissionLogger = $this->loggerFactory->get('webform_submission');
  }

  /**
   * Hook on webform submission presave, modifying data before submission.
   *
   * @return void
   *   Return
   *
   * @throws \Exception
   */
  public function webformSubmissionPresave(WebFormSubmissionInterface $submission): void {
    ['paymentElement' => $paymentElement, 'paymentElementMachineName' => $paymentElementMachineName] = $this->getWebformElementNames($submission);
    $submissionData = $submission->getData();
    if (isset($submissionData[$paymentElementMachineName])) {
      return;
    }

    if (NULL === $paymentElement) {
      throw new \Exception('Could not determine payment element.');
    }

    if (NULL === $paymentElementMachineName) {
      throw new \Exception('Could not determine payment element machine name.');
    }
    /*
     * The paymentReferenceField is not a part of the form submission,
     * so we get it from the POST payload.
     */
    $request = $this->requestStack->getCurrentRequest();
    $paymentReferenceField = $request->request->get('os2forms_payment_reference_field');

    /*
     * The goal here is to store the payment_id, amount_to_pay and status
     * as a JSON object in the os2forms_payment submission value.
     */

    $amountToPay = $this->getAmountToPay($submissionData, $paymentElement['#amount_to_pay']);

    if ($request && $amountToPay) {

      $this->updateWebformSubmissionPaymentObject($submission, NULL, NULL, [
        'payment_id' => $paymentReferenceField,
        'amount' => $amountToPay,
        'status' => 'not charged',
      ]);

    }
  }

  /**
   * Updates the payment object in a webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webformSubmission
   *   The webform submission to update.
   * @param string|null $key
   *   The key of the payment object to update.
   * @param mixed|null $value
   *   The value to set for the payment object key.
   * @param array|null $paymentObject
   *   The payment object to replace the existing with.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Throws Exception.
   */
  public function updateWebformSubmissionPaymentObject(WebformSubmissionInterface $webformSubmission, ?string $key = NULL, mixed $value = NULL, ?array $paymentObject = NULL): WebformSubmissionInterface {
    $submissionData = $webformSubmission->getData();
    $paymentElementMachineName = $this->findPaymentElement($webformSubmission);

    if ($paymentElementMachineName !== NULL) {
      if ($paymentObject !== NULL) {
        $submissionData[$paymentElementMachineName] = json_encode(['paymentObject' => $paymentObject]);
      }
      elseif ($key !== NULL && $value !== NULL) {
        $paymentData = json_decode($submissionData[$paymentElementMachineName], TRUE);
        $paymentData['paymentObject'][$key] = $value;
        $submissionData[$paymentElementMachineName] = json_encode($paymentData);
      }
      $webformSubmission->setData($submissionData);
    }
    return $webformSubmission;
  }

  /**
   *
   */
  private function findPaymentElement(WebformSubmissionInterface $submission) {
    $webformElements = $submission->getWebform()->getElementsDecodedAndFlattened();

    foreach ($webformElements as $elementKey => $webformElement) {
      if ('os2forms_payment' === ($webformElement['#type'] ?? NULL)) {
        return $elementKey;
      }
    }

  }

  /**
   * Retrieves webform element names from a webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   *
   * @return array
   *   An array containing the payment element and its machine name.
   */
  private function getWebformElementNames(WebformSubmissionInterface $submission): array {
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

    return [
      'paymentElement' => $paymentElement,
      'paymentElementMachineName' => $paymentElementMachineName,
    ];
  }

  /**
   * Hook on webform submission presave, modifying data before submission.
   *
   * @return void
   *   Return
   */
  public function webformSubmissionInsert(WebFormSubmissionInterface $submission): void {
    $logger_context = [
      'handler_id' => 'os2forms_payment',
      'channel' => 'webform_submission',
      'webform_submission' => $submission,
      'operation' => 'submission queued',
    ];

    /*
     * The paymentReferenceField is not a part of the form submission,
     * so we get it from the POST payload.
     */
    $request = $this->requestStack->getCurrentRequest();
    $paymentId = $request->request->get('os2forms_payment_reference_field');

    /** @var \Drupal\advancedqueue\Entity\Queue $queue */
    $queue = $this->getQueue();
    if (!$queue) {
      throw new \RuntimeException(sprintf('Queue with ID %s does not exist.', $this->queueId));
    }
    $job = Job::create(NetsEasyPaymentHandler::class, [
      'submissionId' => $submission->id(),
      'paymentId' => $paymentId,
    ]);
    $queue->enqueueJob($job);

    $this->submissionLogger->notice($this->t('Added submission #@serial to queue for processing', ['@serial' => $submission->serial()]), $logger_context);
  }

  /**
   * Get queue.
   */
  private function getQueue(): ?Queue {
    $queueStorage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var ?Queue $queue */
    $queue = $queueStorage->load($this->queueId);

    return $queue;
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

    $paymentEndpoint = $this->getPaymentEndpoint($paymentId);

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
    $result = $this->getResponseObject($response);

    $amountToPay = floatval($this->getAmountToPayTemp() * 100);
    $reservedAmount = floatval($result->payment->summary->reservedAmount ?? 0);
    // $chargedAmount = floatval($result->payment->summary->chargedAmount ?? 0);
    if ($amountToPay !== $reservedAmount) {
      // Reserved amount mismatch.
      $formState->setError(
        $element,
        $this->t('Reserved amount mismatch')
      );
    }
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
   * Returns the Nets API update endpoint.
   *
   * @return string
   *   The endpoint URL.
   */
  public function getUpdateReferenceInformationEndpoint(string $paymentId): string {
    return $this->getTestMode()
      ? 'https://test.api.dibspayment.eu/v1/payments/' . $paymentId . '/referenceinformation'
      : 'https://api.dibspayment.eu/v1/payments/' . $paymentId . '/referenceinformation';
  }

  /**
   * Returns the Nets API create payment endpoint.
   *
   * @return string
   *   The endpoint URL.
   */
  public function getCreatePaymentEndpoint(): string {
    return $this->getTestMode()
      ? 'https://test.api.dibspayment.eu/v1/payments/'
      : 'https://api.dibspayment.eu/v1/payments/';
  }

  /**
   * Returns the Nets API retrieve payment endpoint.
   *
   * @return string
   *   The endpoint URL.
   */
  public function getPaymentEndpoint(string $paymentId): string {
    return $this->getTestMode()
      ? 'https://test.api.dibspayment.eu/v1/payments/' . $paymentId
      : 'https://api.dibspayment.eu/v1/payments/' . $paymentId;
  }

  /**
   * Returns the Nets API payment charge endpoint.
   *
   * @return string
   *   The endpoint URL.
   */
  public function getChargePaymentEndpoint(string $paymentId): string {
    return $this->getTestMode()
      ? 'https://test.api.dibspayment.eu/v1/payments/' . $paymentId . '/charges'
      : 'https://api.dibspayment.eu/v1/payments/' . $paymentId . '/charges';
  }

  /**
   * Converts a JSON response to an object.
   *
   * @return object|null
   *   The response object.
   */
  public function getResponseObject(ResponseInterface $response): ?object {
    $contents = $response->getBody()->getContents();

    if (empty($contents)) {
      return NULL;
    }

    return json_decode($contents);
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

  /**
   * Handles an API request.
   *
   * @param string $method
   *   The HTTP method for the request.
   * @param string $endpoint
   *   The endpoint URL.
   * @param array $params
   *   Optional. An associative array of parameters
   *   to be sent in the request body.
   *
   * @return object|null
   *   The response object returned from the API endpoint if present.
   */
  public function handleApiRequest(string $method, string $endpoint, array $params = []): ?object {
    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'Authorization' => $this->getSecretKey(),
    ];

    $response = $this->httpClient->request(
      $method,
      $endpoint,
      ['headers' => $headers, 'json' => $params]
    );

    return $this->getResponseObject($response);
  }

}
