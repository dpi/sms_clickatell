<?php

namespace Drupal\sms_clickatell\Plugin\SmsGateway;

use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Message\SmsMessageStatus;
use Clickatell\Api\ClickatellRest;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Entity\SmsMessageInterface as SmsMessageEntityInterface;

/**
 * @SmsGateway(
 *   id = "clickatell",
 *   label = @Translation("Clickatell"),
 *   outgoing_message_max_recipients = 600,
 * )
 */
class Clickatell extends SmsGatewayPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $defaults = [];
    $defaults['account'] = [
      // REST.
      'auth_token' => '',
    ];
    $defaults['settings'] = [
      'insecure' => FALSE,
    ];
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['clickatell'] = [
      '#type' => 'details',
      '#title' => $this->t('Clickatell'),
      '#open' => TRUE,
    ];

    $form['clickatell']['auth_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authorization token'),
      '#default_value' => $config['account']['auth_token'],
    ];

    $form['clickatell']['insecure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use insecure requests'),
      '#description' => $this->t('Changes default behaviour from https to http.'),
      '#default_value' => $config['settings']['insecure'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['account']['auth_token'] = trim($form_state->getValue('auth_token'));
    $this->configuration['settings']['insecure'] = (boolean)$form_state->getValue('insecure');
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message) {
    $result = new SmsMessageResult();

    $api = new ClickatellRest(
      $this->configuration['account']['auth_token']
    );
    $api->secure(empty($this->configuration['settings']['insecure']));

    $extra = [];
    if ($sms_message instanceof SmsMessageEntityInterface) {
      // See: https://www.clickatell.com/developers/api-docs/scheduled-delivery-advanced-message-send/
      if ($time = $sms_message->getSendTime()) {
        // Disabling schedule_aware status temporarily.
        // If the time of the scheduled delivery is now or in the past then
        // an error will be thrown by the endpoint. Unfortunately the Clickatell
        // library (arcturial/clickatell) does not have very good error
        // handling.
        // See https://github.com/arcturial/clickatell/issues/25
        //// $date = DrupalDateTime::createFromTimestamp($time);
        //// $extra['scheduledDeliveryTime'] = $date->format('Y-m-d\TH:i:s\Z');
      }
    }

    $response = $api->sendMessage(
      $sms_message->getRecipients(),
      $sms_message->getMessage(),
      $extra
    );

    // Response documentation.
    // https://www.clickatell.com/developers/api-docs/http-status-codes-rest/
    // https://www.clickatell.com/developers/api-docs/send-message-rest/

    $reports = [];
    $error_message = '';
    $error_count = 0;
    foreach ($response as $message_result) {
      // If there is `id` and `destination`, then the request was received.
      $report = new SmsDeliveryReport();

      // Use empty here since non-error will be a FALSE. But the keys may also
      // not be set.
      $message_id = !empty($message_result->id)         ? $message_result->id : NULL;
      $recipient = !empty($message_result->destination) ? $message_result->destination : NULL;
      $error_code = !empty($message_result->errorCode)  ? $message_result->errorCode : NULL;
      $error_message = !empty($message_result->error)   ? $message_result->error : NULL;

      if ($error_code) {
        $error_count++;
      }
      else {
        $report->setStatus(SmsMessageStatus::QUEUED);
      }

      if ($recipient) {
        $report->setRecipients([$recipient]);
      }
      else {
        // This is bad.
        continue;
      }

      if ($message_id) {
        $report->setMessageId($message_id);
      }

      if ($error_message) {
        $report->setStatus(SmsMessageStatus::ERROR);
        $report->setStatusMessage(sprintf('Error: %s', $error_message));
      }

      $reports[$recipient] = $report;
    }

    if (count($reports)) {
      $result->setReports($reports);
      if (0 == $error_count) {
        $result->setStatus(SmsMessageStatus::QUEUED);
      }
      else {
        $result->setStatus(SmsMessageStatus::ERROR);
      }
    }
    else if (isset($error_code)) {
      $result->setStatus(SmsMessageStatus::ERROR);
      $result->setStatusMessage($error_message);
    }

    return $result;
  }

}
