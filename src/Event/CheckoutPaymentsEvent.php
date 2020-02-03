<?php

namespace Drupal\commerce_gocardless\Event;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Symfony\Component\EventDispatcher\Event;

class CheckoutPaymentsEvent extends Event {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\Order
   */
  protected $order;

  /**
   * The payment gateway interface.
   *
   * @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface
   */
  protected $payment_gateway_plugin;

  /**
   * An array of payments to submit to go cardless.
   *
   * @var array
   */
  protected $payments;

  /**
   * CheckoutPaymentsEvent constructor.
   *
   * @param \Drupal\commerce_order\Entity\Order $order
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $plugin
   * @param array $payments
   */
  public function __construct(Order $order, PaymentGatewayInterface $plugin, array $payments) {
    $this->order = $order;
    $this->plugin = $plugin;
    $this->payments = $payments;
  }

  /**
   * Get the commerce order
   *
   * @return \Drupal\commerce_order\Entity\Order
   */
  public function getOrder() {
    return $this->order;
  }

  /**
   * Get the payment gateway plugin.
   *
   * @return \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface
   */
  public function getPaymentGatewayPlugin() {
    return $this->payment_gateway_plugin;
  }

  /**
   * Get the payments
   *
   * @return array
   */
  public function getPayments() {
    return $this->payments;
  }

  /**
   * Set the payments
   *
   * @param array $payments
   */
  public function setPayments(array $payments) {
    $this->payments = $payments;
  }
}
