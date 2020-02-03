<?php

namespace Drupal\commerce_gocardless\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use GoCardlessPro\Client;

class GoCardlessOffsitePaymentForm extends PaymentOffsiteForm {

  /**
   * @var \Drupal\commerce_payment\Entity\Payment
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $order = $this->entity->getOrder();
    $profile = $order->getBillingProfile();
    /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
    $session = \Drupal::service('session');

    $client = $this->goCardlessClient();
    $redirect_flow = $client->redirectFlows()->create([
      'params' => [
        'description' => "Order {$order->getOrderNumber()}",
        'session_token' => $session->getId(),
        'session_redirect_url' => $form['#return_url'],
        'prefilled_customer' => [
          'given_name' => $profile->address->given_name,
          'family_name' => $profile->address->family_name,
          'address_line1' => $profile->address->address_line1,
          'address_line2' => $profile->address->address_line2,
          'city' => $profile->address->locality,
          'company_name' => $profile->address->organization,
          'email' => $order->getEmail(),
          'postal_code' => $profile->address->postal_code,
          'region' => $profile->address->administrative_area,
        ],
      ],
    ]);

    $session->set('gc_flow', $redirect_flow->id);
    $form = $this->buildRedirectForm($form, $form_state, $redirect_flow->redirect_url, [], static::REDIRECT_GET);

    return $form;
  }

  /**
   * Get the GoCardless client.
   *
   * @return \GoCardlessPro\Client
   */
  protected function goCardlessClient() {
    $gateway = $this->entity->getPaymentGateway();
    $conf = $gateway->getPlugin()->getConfiguration();

    return new Client([
      'access_token' => $conf['access_token'],
      'environment' => $conf['mode'],
    ]);
  }
}
