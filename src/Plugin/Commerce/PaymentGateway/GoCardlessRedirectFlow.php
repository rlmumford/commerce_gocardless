<?php

namespace Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_gocardless\Event\CheckoutPaymentsEvent;
use Drupal\commerce_gocardless\Event\CommerceGoCardlessEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GoCardlessPro\Core\Exception\InvalidStateException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class GoCardlessRedirect
 *
 * This is an on-site gateway as it operates on the basis of there already
 * being a mandate.
 *
 * @CommercePaymentGateway(
 *   id = "gocardless_redirect",
 *   label = "GoCardless Redirect Flow",
 *   display_label = "GoCardless",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_gocardless\PluginForm\GoCardlessOffsitePaymentForm",
 *   },
 *   modes = {
 *     "sandbox" = "Sandbox",
 *     "live" = "Live",
 *   },
 * )
 *
 * @package Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway
 */
class GoCardlessRedirectFlow extends OffsitePaymentGatewayBase {
  use GoCardlessPaymentGatewayTrait;

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * GoCardlessRedirectFlow constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   * @param \Drupal\Component\Datetime\TimeInterface $time
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    EventDispatcherInterface $event_dispatcher
  ) {
    parent::__construct(
      $configuration, $plugin_id, $plugin_definition, $entity_type_manager,
      $payment_type_manager, $payment_method_type_manager, $time
    );

    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // Complete the redirect flow.
    $flow_id = $request->query->get('redirect_flow_id');
    $client = $this->createGoCardlessClient();
    try {
      $flow = $client->redirectFlows()->complete($flow_id, [
        'params' => [
          'session_token' => \Drupal::service('session')->getId(),
        ],
      ]);
    }
    catch (InvalidStateException $exception) {
      // If we've already completed the flow just try and load it.
      $flow = $client->redirectFlows()->get($flow_id);
    }

    $mandate_id = $flow->links->mandate;
    $mandate_info = $client->mandates()->get($mandate_id);

    $mandate_storage = $this->entityTypeManager->getStorage('gocardless_mandate');
    $mandate = $mandate_storage->create([
      'owner' => \Drupal::currentUser()->id(),
      'init_order' => $order,
      'gc_mandate_id' => $mandate_id,
      'gc_mandate_scheme' => $mandate_info->scheme,
      'gc_mandate_status' => $mandate_info->status,
      'gc_customer_id' => $flow->links->customer,
      'sandbox' => $this->getMode() === 'sandbox',
    ]);
    $mandate->save();

    $required_payments = [
      [
        'type' => 'payments',
        'price' => $order->getTotalPrice(),
        'description' => "Payment for ".$order->getOrderNumber(),
        'idempotency_key' => 'payment-for-order-'.$order->id(),
      ]
    ];

    $event = new CheckoutPaymentsEvent($order, $this, $required_payments);
    $this->eventDispatcher->dispatch(CommerceGoCardlessEvents::CHECKOUT_PAYMENTS, $event);

    foreach ($event->getPayments() as $required_payment) {
      $required_payment += [
        'type' => 'payment',
        'metadata' => (object) [],
        'idempotency_key' => NULL,
      ];

      $headers = [];
      if (!empty($required_payment['idempotency_key'])) {
        $headers['Idempotency-Key'] = $required_payment['idempotency_key'];
      }

      /** @var \Drupal\commerce_price\Price $price */
      $price = $required_payment['price'];

      switch ($required_payment['type']) {
        case 'payment':
          $gc_payment = $client->payments()->create([
            'params' => [
              'amount' => $this->toMinorUnits($price),
              'currency' => $price->getCurrencyCode(),
              'description' => $required_payment['description'],
              'metadata' => $required_payment['metadata'],
              'links' => [
                'mandate' => $mandate_id,
              ],
            ],
            'headers' => $headers,
          ]);

          $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
          $payment_storage->create([
            'state' => 'pending_capture',
            'amount' => $price,
            'payment_gateway' => $this->parentEntity->id(),
            'order_id' => $order->id(),
            'remote_id' => $gc_payment->id,
            'remote_state' => $gc_payment->status,
          ])->save();
          break;
        case 'instalment_schedule':
          $required_payment['metadata']->order = $order->id();

          $gc_schedule = $client->instalmentSchedules()->create([
            'params' => [
              'amount' => $this->toMinorUnits($price),
              'currency_code' => $price->getCurrencyCode(),
              'name' => $required_payment['name'],
              'schedule' => $required_payment['schedule'],
              'metadata' => $required_payment['metadata'],
              'links' => [
                'mandate' => $mandate_id,
              ],
            ],
            'headers' => $headers,
          ]);

          $gc_schedule = $client->instalmentSchedules()->get($gc_schedule->id);
          if ($gc_schedule->status === 'active') {
            foreach ($gc_schedule->links->payments as $payment_id) {
              $gc_payment = $client->payments()->get($payment_id);

              $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
              $payment_storage->create([
                'state' => 'pending_capture',
                'amount' => new Price((string) ($gc_payment/100), $price->getCurrencyCode()),
                'payment_gateway' => $this->parentEntity->id(),
                'order_id' => $order->id(),
                'remote_id' => $gc_payment->id,
                'remote_state' => $gc_payment->status,
              ])->save();
            }
          }
          else {
            // Queue up the creation of scheduled payments.
            \Drupal::queue('commerce_gocardless_installment_schedule')->createItem([
              'payment_gateway' => $this->parentEntity,
              'order' => $order,
              'schedule' => $gc_schedule,
            ]);
          }

          break;
      }

    }
  }
}
