<?php

namespace Drupal\commerce_gocardless\Event;

/**
 * Class CommerceGoCardlessEvents
 *
 * Events provided by commerce_gocardless
 *
 * @package Drupal\commerce_gocardless\Event
 */
final class CommerceGoCardlessEvents {

  /**
   * Event fired in the checkout process to collect the payments required.
   */
  const CHECKOUT_PAYMENTS = 'commerce_gocardless.checkout_payments';
}
