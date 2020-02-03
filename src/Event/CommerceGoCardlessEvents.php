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

  /**
   * Payment events.
   */
  const _PAYMENT_PREFIX = 'commerce_gocardless.payment.';
  const PAYMENT_CONFIRMED = self::_PAYMENT_PREFIX.'confirmed';
  const PAYMENT_CHARGED_BACK = self::_PAYMENT_PREFIX.'confirmed';
  const PAYMENT_FAILED = self::_PAYMENT_PREFIX.'confirmed';
  const PAYMENT_CANCELLED = self::_PAYMENT_PREFIX.'confirmed';
  const PAYMENT_CREATED = self::_PAYMENT_PREFIX.'created';
  const PAYMENT_SUBMITTED = self::_PAYMENT_PREFIX.'submitted';
  const PAYMENT_PAID_OUT = self::_PAYMENT_PREFIX.'paid_out';
  const PAYMENT_APPROVAL_GRANTED = self::_PAYMENT_PREFIX.'customer_approval_granted';
  const PAYMENT_APPROVAL_DENIED = self::_PAYMENT_PREFIX.'customer_approval_denied';
  const PAYMENT_CHARGEBACK_CANCELLED = self::_PAYMENT_PREFIX.'chargeback_cancelled';
  const PAYMENT_LATE_FAILURE_SETTLED = self::_PAYMENT_PREFIX.'late_failure_settled';
  const PAYMENT_CHARGEBACK_SETTLED = self::_PAYMENT_PREFIX.'chargeback_settled';
  const PAYMENT_RESUBMISSION_REQUESTED = self::_PAYMENT_PREFIX.'resubmission_requested';

}
