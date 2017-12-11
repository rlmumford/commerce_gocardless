<?php

namespace Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;

interface GoCardlessPaymentGatewayInterface extends OnsitePaymentGatewayInterface {

  /**
   * Create a new GoCardless client based on this plugin's configuration.
   *
   * @return \GoCardlessPro\Client
   */
  public function createGoCardlessClient();

  /**
   * A description, shown on the GoCardless site to identify the organisation.
   *
   * @return string
   */
  public function getDescription();

  /**
   * The secret used to validate requests to the webhook controller.
   *
   * @return string
   */
  public function getWebhookSecret();

  /**
   * Try to get a nice description of a payment method by looking up bank
   * details from GoCardless.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *
   * @return string
   *   Bank account holder, bank etc, or '' if this could not be obtained.
   */
  public function getMandateDescription(PaymentMethodInterface $payment_method);

}
