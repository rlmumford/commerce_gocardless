<?php

namespace Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use GoCardlessPro\Client;

trait GoCardlessPaymentGatewayTrait {

  /**
   * Configuration.
   *
   * @var array
   */
  protected $configuration;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'description' => '',
        'access_token' => '',
        'webhook_secret' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#description' => $this->t('This will be visible on the GoCardless site and identifies your organisation.'),
      '#default_value' => $this->configuration['description'],
      '#required' => TRUE,
    ];
    $form['access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access token'),
      '#description' => $this->t("The API token supplied by GoCardless."),
      '#default_value' => $this->configuration['access_token'],
      '#required' => TRUE,
    ];
    $form['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook secret'),
      '#description' => $this->t("An arbitrary string which GoCardless will use to verify itself when making API requests to this site."),
      '#default_value' => $this->configuration['webhook_secret'],
      '#required' => FALSE,  // you need to get this from GC
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['description'] = $values['description'];
      $this->configuration['access_token'] = $values['access_token'];
      $this->configuration['webhook_secret'] = $values['webhook_secret'];
    }
  }


  /**
   * {@inheritdoc}
   */
  public function createGoCardlessClient() {
    if (!isset($this->configuration['mode']) || !isset($this->configuration['access_token'])) {
      throw new \Exception('Unable to create GoCardless client because the payment gateway configuration does not specify a mode (environment) and access token.');
    }

    return new Client([
      'environment' => $this->configuration['mode'],
      'access_token' => $this->configuration['access_token'],
    ]);
  }

}
