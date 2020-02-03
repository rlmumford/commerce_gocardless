<?php

namespace Drupal\commerce_gocardless\Event;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class PaymentWebhookEvent
 *
 * Event when a webhook event happens.
 *
 * @package Drupal\commerce_gocardless\Event
 */
class PaymentWebhookEvent extends Event {

  /**
   * @var \Drupal\commerce_payment\Entity\PaymentInterface
   */
  protected $payment;

  /**
   * @var array
   */
  protected $eventInfo;

  /**
   * PaymentWebhookEvent constructor.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   * @param array $event
   */
  public function __construct(PaymentInterface $payment, array $event_info) {
    $this->payment = $payment;
    $this->eventInfo = $event_info;
  }

  /**
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   */
  public function getPayment() {
    return $this->payment;
  }

  /**
   * @return array
   */
  public function getEventInfo() {
    return $this->eventInfo;
  }

}
