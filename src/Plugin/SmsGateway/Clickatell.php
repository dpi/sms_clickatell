<?php

namespace Drupal\clickatell\Plugin\SmsGateway;

use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Clickatell\Api\ClickatellRest;

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
      // HTTP/S.
      'username' => '',
      'password' => '',
      'api_id' => '',
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
  public function buildConfigurationForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
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
  public function submitConfigurationForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $this->configuration['account']['auth_token'] = $form_state->getValue('auth_token');
    $this->configuration['settings']['insecure'] = (boolean)$form_state->getValue('insecure');
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message, array $options) {
    $api = new ClickatellRest(
      $this->configuration['account']['auth_token']
    );
    $api->secure(empty($this->configuration['settings']['insecure']));

    $response = $api->sendMessage(
      $sms_message->getRecipients(),
      $sms_message->getMessage()
    );

    return new SmsMessageResult(['status' => TRUE]);
  }

}
