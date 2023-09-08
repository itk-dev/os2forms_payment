<?php

namespace Drupal\webform_create_event\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Create a new node entity from a webform submission.
 *
 * @WebformHandler(
 *   id = "Create a node",
 *   label = @Translation("Create a node"),
 *   category = @Translation("Entity Creation"),
 *   description = @Translation("Creates a new node from Webform Submissions."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class CreateNodeWebformHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {

  }

}
