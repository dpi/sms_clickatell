<?php

namespace Drupal\sms_clickatell\Plugin\SmsGateway;

use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Clickatell\Api\ClickatellRest;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Entity\SmsMessageInterface as SmsMessageEntityInterface;

/**
 * @SmsGateway(
 *   id = "clickatell",
 *   label = @Translation("Clickatell"),
 *   outgoing_message_max_recipients = 600,
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
    $this->configuration['account']['auth_token'] = $form_state->getValue('auth_token');
    $this->configuration['settings']['insecure'] = (boolean)$form_state->getValue('insecure');
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message) {
    $api = new ClickatellRest(
      $this->configuration['account']['auth_token']
    );
    $api->secure(empty($this->configuration['settings']['insecure']));

    $extra = [];
    if ($sms_message instanceof SmsMessageEntityInterface) {
      // See: https://www.clickatell.com/developers/api-docs/scheduled-delivery-advanced-message-send/
      if ($time = $sms_message->getSendTime()) {
        $extra['scheduledDeliveryTime'] = $time;
      }
    }

    $response = $api->sendMessage(
      $sms_message->getRecipients(),
      $sms_message->getMessage(),
      $extra
    );

    return new SmsMessageResult(['status' => TRUE]);
  }

}
