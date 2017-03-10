<?php

namespace Drupal\sms_clickatell\Plugin\SmsGateway;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms\Message\SmsMessageReportStatus;
use Drupal\sms\Message\SmsMessageResultStatus;
use Clickatell\Api\ClickatellRest;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Entity\SmsMessageInterface as SmsMessageEntityInterface;

/**
 * @SmsGateway(
 *   id = "clickatell",
 *   label = @Translation("Clickatell"),
 *   outgoing_message_max_recipients = 600,
 *   reports_pull = TRUE,
 *   reports_push = TRUE,
 *   schedule_aware = TRUE,
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
        // Don't schedule time if now or soon (in case of time sync issues).
        // This is because the Clickatell API cannot handle times in the past,
        // throws invalid argument error 101.
        // Soon = scheduled_time + 30 mins.
        $date = DrupalDateTime::createFromTimestamp($time);
        $limit = (new DrupalDateTime())
          ->add(new \DateInterval('PT30M'));
        if ($date > $limit) {
          $extra['scheduledDeliveryTime'] = $date->format('Y-m-d\TH:i:s\Z');
        }
      }
    }

    $recipients = $sms_message->getRecipients();
    $message = $sms_message->getMessage();
    $response = $api->sendMessage($recipients, $message, $extra);

    // Unfortunately the Clickatell library (arcturial/clickatell) does not have
    // very good error handling. An empty response will be given if the request
    // fails.
    // See https://github.com/arcturial/clickatell/issues/25
    if (empty($response)) {
      return $result
        ->setError(SmsMessageResultStatus::ERROR)
        ->setErrorMessage('The request failed for some reason.');
    }

    // Response documentation.
    // https://www.clickatell.com/developers/api-docs/http-status-codes-rest/
    // https://www.clickatell.com/developers/api-docs/send-message-rest/

    $reports = [];
    foreach ($response as $message_result) {
      // If there is `id` and `destination`, then the request was received.
      $report = new SmsDeliveryReport();

      // Use empty here since non-error will be a FALSE. But the keys may also
      // not be set.
      $message_id = !empty($message_result->id)         ? $message_result->id : NULL;
      $recipient = !empty($message_result->destination) ? $message_result->destination : NULL;
//      $error_code = !empty($message_result->errorCode)  ? $message_result->errorCode : NULL;
      $error_message = !empty($message_result->error)   ? $message_result->error : NULL;

      // @fixme: Error code is not working due to a failure with the Clickatell
      // library (arcturial/clickatell).
      // See https://github.com/arcturial/clickatell/issues/25
      // We define a new '-1' error code just for our temporary usage.
      $error_code = !empty($error_message) ? -1 : NULL;

      if ($recipient) {
        $report->setRecipient($recipient);
      }
      if ($message_id) {
        $report->setMessageId($message_id);
      }

      // If $error_code is FALSE or NULL then there was an no error.
      if (!$error_code) {
        // Success!
        $report->setStatus(SmsMessageReportStatus::QUEUED);
      }
      else {
        if ($error_message) {
          $report->setStatusMessage(sprintf('Error: %s', $error_message));
        }

        // @todo implement conditionals for all Clickatell error codes when
        // the Clickatell library supports it.
        if ($error_code == 'wontbetrue') {

        }
        else {
          $report->setStatus(SmsMessageReportStatus::ERROR);
        }
      }

      $result->addReport($report);
    }

    return $result;
  }

}
