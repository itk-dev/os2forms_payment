<?php

namespace Drupal\os2forms_payment\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\os2forms_payment\Helper\PaymentHelper;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Archive document job.
 *
 * @AdvancedQueueJobType(
 *   id = "Drupal\os2forms_payment\Plugin\AdvancedQueue\JobType\NetsEasyPaymentHandler",
 *   label = @Translation("Charge nets payment."),
 * )
 */
final class NetsEasyPaymentHandler extends JobTypeBase implements ContainerFactoryPluginInterface {
  /**
   * The submission logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $submissionLogger;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
                                     $plugin_id,
                                     $plugin_definition,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly Client $httpClient,
    protected readonly PaymentHelper $paymentHelper,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->submissionLogger = $loggerFactory->get('webform_submission');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('Drupal\os2forms_payment\Helper\PaymentHelper'),
    );
  }

  /**
   * Process the job by handling payment stages.
   *
   * @param \Drupal\advancedqueue\Job $job
   *   The job object to process.
   *
   * @return \Drupal\advancedqueue\JobResult
   *   The result of processing the job.
   *
   * @throws \Exception|GuzzleException
   *   Throws Exception.
   */
  public function process(Job $job): JobResult {
    $payload = $job->getPayload();
    $stage = $payload['processing_stage'] ?? 0;

    /** @var \Drupal\webform\Entity\WebformSubmissionInterface $webformSubmission */
    $webformSubmission = WebformSubmission::load($payload['submissionId']);
    $logger_context = [
      'handler_id' => 'os2forms_payment',
      'channel' => 'webform_submission',
      'webform_submission' => $webformSubmission,
      'operation' => 'response from queue',
    ];

    try {

      if ($stage < 1) {
        $this->getPaymentAndSetRelevantValues($job, $webformSubmission, $logger_context);
      }

      if ($stage < 2) {
        $this->updatePaymentReference($job, $webformSubmission, $logger_context);
      }

      if ($stage < 3) {
        $this->chargePayment($job, $webformSubmission, $logger_context);
      }

      $this->submissionLogger->notice($this->t('The submission #@serial was successfully delivered', ['@serial' => $webformSubmission->serial()]), $logger_context);

      return JobResult::success();
    }
    catch (\Exception | GuzzleException $e) {
      $this->submissionLogger->error($this->t('The submission #@serial failed (@message)', [
        '@serial' => $webformSubmission->serial(),
        '@message' => $e->getMessage(),
      ]), $logger_context);

      return JobResult::failure($e->getMessage());
    }
  }

  /**
   * Get payment information and set relevant values in the job payload.
   *
   * @param \Drupal\advancedqueue\Job $job
   *   The job object containing payload information.
   * @param \Drupal\webform\Entity\WebformSubmissionInterface $webformSubmission
   *   The webform submission related to the payment.
   * @param array $logger_context
   *   Context for logging.
   *
   * @throws \Exception|GuzzleException
   *   Throws Exception.
   */
  private function getPaymentAndSetRelevantValues(Job $job, WebformSubmissionInterface $webformSubmission, array $logger_context): void {
    try {
      $payload = $job->getPayload();
      $paymentId = $payload['paymentId'];
      $submissionId = $webformSubmission->id();
      $webformId = $webformSubmission->getWebform()->id();

      // Retrieve payment, save reference field.
      $paymentEndpoint = $this->paymentHelper->getPaymentEndpoint($paymentId);
      $result = $this->paymentHelper->handleApiRequest('GET', $paymentEndpoint);

      $checkoutUrl = $result->payment->checkout->url;
      $reservedAmount = floatval($result->payment->summary->reservedAmount ?? 0);
      $chargedAmount = floatval($result->payment->summary->chargedAmount ?? 0);
      $paymentReferenceSuffix = $result->payment->orderDetails->reference;

      // Payment reference (field in NEXI backend)
      // is defined as {optional suffix}:{webform_id}:{submission_id}.
      $paymentReferenceValue = $webformId . ':' . $submissionId;

      if ("undefined" !== $paymentReferenceSuffix) {
        $paymentReferenceValue = $paymentReferenceValue . ':' . $paymentReferenceSuffix;
      }

      $payload['checkoutUrl'] = $checkoutUrl;
      $payload['reservedAmount'] = $reservedAmount;
      $payload['chargedAmount'] = $chargedAmount;
      $payload['paymentReferenceValue'] = $paymentReferenceValue;

      $payload['processing_stage'] = 1;

      $job->setPayload($payload);

    }
    catch (\Exception | GuzzleException $e) {
      $this->submissionLogger->error($this->t('The submission #@serial failed (@message)', [
        '@serial' => $webformSubmission->serial(),
        '@message' => $e->getMessage(),
      ]), $logger_context);

      throw $e;
    }
  }

  /**
   * Update the payment reference of the current job.
   *
   * @param \Drupal\advancedqueue\Job $job
   *   The job object containing payload information.
   * @param \Drupal\webform\Entity\WebformSubmissionInterface $webformSubmission
   *   The webform submission related to the payment.
   * @param array $logger_context
   *   Context for logging.
   *
   * @throws \Exception|GuzzleException
   *   Throws Exception.
   */
  private function updatePaymentReference(Job $job, WebformSubmissionInterface $webformSubmission, array $logger_context): void {
    try {
      $payload = $job->getPayload();
      $paymentId = $payload['paymentId'];
      $checkoutUrl = $payload['checkoutUrl'];
      $paymentReferenceValue = $payload['paymentReferenceValue'];

      $updatePaymentReferenceEndpoint = $this->paymentHelper->getUpdateReferenceInformationEndpoint($paymentId);

      $this->paymentHelper->handleApiRequest('PUT', $updatePaymentReferenceEndpoint, [
        'checkoutUrl' => $checkoutUrl,
        'reference' => $paymentReferenceValue,
      ]);

      $payload['processing_stage'] = 2;
      $job->setPayload($payload);

    }
    catch (\Exception | GuzzleException $e) {
      $this->submissionLogger->error($this->t('The submission #@serial failed (@message)', [
        '@serial' => $webformSubmission->serial(),
        '@message' => $e->getMessage(),
      ]), $logger_context);

      throw $e;
    }
  }

  /**
   * Charge a payment of the current job.
   *
   * @param \Drupal\advancedqueue\Job $job
   *   The job object containing payload information.
   * @param \Drupal\webform\Entity\WebformSubmissionInterface $webformSubmission
   *   The webform submission related to the payment.
   * @param array $logger_context
   *   Context for logging.
   *
   * @throws \Exception
   *   Throws Exception.
   */
  private function chargePayment(Job $job, WebformSubmissionInterface $webformSubmission, array $logger_context): void {
    try {
      $payload = $job->getPayload();
      $paymentId = $payload['paymentId'];
      $reservedAmount = $payload['reservedAmount'];

      // Validate charge.
      $this->validateCharge($payload);

      // Execute charge.
      $chargePaymentEndpoint = $this->paymentHelper->getChargePaymentEndpoint($paymentId);

      $result = $this->paymentHelper->handleApiRequest('POST', $chargePaymentEndpoint, [
        'amount' => $reservedAmount,
      ]);

      $paymentChargeId = $result->chargeId ?? NULL;

      if (!$paymentChargeId) {
        throw new \Exception('Payment could not be charged');
      }

      /*
       * When chargePayment return a charge id,
       * it can be assumed that the payment has been charged.
       *
       * Although there is a "retrieve charge" endpoint,
       * it is not documented to do anything else than
       * return the value we just received.
       */

      $payload['processing_stage'] = 3;
      $job->setPayload($payload);

    }
    catch (\Exception $e) {
      $this->submissionLogger->error($this->t('The submission #@serial failed (@message)', [
        '@serial' => $webformSubmission->serial(),
        '@message' => $e->getMessage(),
      ]), $logger_context);

      throw $e;
    }
  }

  /**
   * Validates the reservation and charge amounts.
   *
   * @param array $payload
   *   The payload data containing reserved and charged amounts.
   *
   * @throws \Exception
   *   Throws Exception.
   */
  public function validateCharge(array $payload): void {
    $reservedAmount = $payload['reservedAmount'];
    $chargedAmount = $payload['chargedAmount'];
    if ($reservedAmount <= 0) {
      throw new \Exception('Reserved amount is zero when validating reserved amount');
    }
    if ($chargedAmount != 0) {
      throw new \Exception('Charged amount is not zero before attempting to charge');
    }
  }

}
