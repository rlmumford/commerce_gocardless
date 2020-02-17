<?php

namespace Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_gocardless\Event\CheckoutPaymentsEvent;
use Drupal\commerce_gocardless\Event\CommerceGoCardlessEvents;
use Drupal\commerce_gocardless\GoCardlessPaymentCreationTrait;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use GoCardlessPro\Client;
use GoCardlessPro\Core\Exception\GoCardlessProException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * GoCardless payment gateway.
 *
 * This is an on-site gateway as it operates on the basis of there already
 * being a mandate.
 *
 * @CommercePaymentGateway(
 *   id = "gocardless",
 *   label = "GoCardless",
 *   display_label = "GoCardless",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_gocardless\PluginForm\GoCardlessPaymentMethodAddForm",
 *   },
 *   modes = {
 *     "sandbox" = "Sandbox",
 *     "live" = "Live",
 *   },
 *   payment_method_types = {"commerce_gocardless_oneoff"},
 * )
 */
class GoCardlessPaymentGateway extends OnsitePaymentGatewayBase {

  /**
   * Configuration.
   *
   * @var array
   */
  protected $configuration;

  /**
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

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
      $container->get('event_dispatcher'),
      $container->get('queue')
    );
  }

  /**
   * GoCardlessPaymentGateway constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   * @param \Drupal\Component\Datetime\TimeInterface $time
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    EventDispatcherInterface $event_dispatcher,
    QueueFactory $queue_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->eventDispatcher = $event_dispatcher;
    $this->queueFactory = $queue_factory;
  }

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

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    // The stored payment method will have the mandate ID, which is
    // what we pass to GoCardless to identify the buyer.
    $mandate_id = $payment_method->getRemoteId();
    if (!$mandate_id) {
      throw new HardDeclineException('No direct debit mandate was set up with GoCardless.');
    }

    // Perform the create payment request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Remember to take into account $capture when performing the request.
    //    $amount = $payment->getAmount();

    // Create a payment on GoCardless.
    // The payment won't be approved immediately, so
    try {
      $this->createGoCardlessPayments($payment->getPaymentGateway(), $payment->getOrder(), $mandate_id);
    }
    catch (GoCardlessProException $e) {
      throw new PaymentGatewayException('GoGardless exception: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // Note that we don't have a mandate ID at this stage, but we do still
    // want to have a saved payment method in order to progress through
    // checkout.
    // The payment method is updated with the mandate ID in
    // MandateConfirmationController.
    $payment_method->setReusable(TRUE);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Delete the local entity.
    $payment_method->delete();
  }
  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return isset($this->configuration['description']) ? $this->configuration['description'] : '';
  }

  /**
   * {@inheritdoc}
   */
  public function getWebhookSecret() {
    return isset($this->configuration['webhook_secret']) ? $this->configuration['webhook_secret'] : '';
  }

  /**
   * {@inheritdoc}
   */
  public function getMandateDescription(PaymentMethodInterface $payment_method) {
    if ($payment_method->getRemoteId()) {
      $client = $this->createGoCardlessClient();

      try {
        $mandate = $client->mandates()->get($payment_method->getRemoteId());
      }
      catch (GoCardlessProException $e) {
        return $this->t('Invalid debit mandate');
      }

      $bank_account_ref = $mandate->links->customer_bank_account;
      if ($bank_account_ref) {
        $bank_account = $client->customerBankAccounts()->get($bank_account_ref);
        return $this->t('@account_holder_name, @bank_name, account number ending @account_number_ending', [
          '@account_holder_name' => $bank_account->account_holder_name,
          '@bank_name' => $bank_account->bank_name,
          '@account_number_ending' => $bank_account->account_number_ending,
        ]);
      }
    }
    return '';
  }

  /**
   * Asserts that the payment amount currency is GBP.
   *
   * @param \Drupal\commerce_price\Price $price
   *   The price.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the price is not in GBP.
   */
  protected function assertCurrencyGBP(Price $price) {
    if ($price->getCurrencyCode() !== 'GBP') {
      throw new \InvalidArgumentException('The payment amount must be in GBP.');
    }
  }

  /**
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   * @param \Drupal\commerce_order\Entity\Order $order
   * @param $mandate_id
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \GoCardlessPro\Core\Exception\InvalidStateException
   */
  public function createGoCardlessPayments(PaymentGatewayInterface $gateway, Order $order, $mandate_id) {
    try {
      if (!$mandate_id) {
        throw new \Exception('Missing mandate id');
      }
    }
    catch (\Exception $e) {}

    /** @var \Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway\GoCardlessPaymentGateway $gateway_plugin */
    $gateway_plugin = $gateway->getPlugin();
    $client = $gateway_plugin->createGoCardlessClient();

    $required_payments = [
      [
        'type' => 'payment',
        'price' => $order->getTotalPrice(),
        'description' => "Payment for ".$order->getOrderNumber(),
        'idempotency_key' => 'payment-for-order-'.$order->id(),
      ]
    ];

    $event = new CheckoutPaymentsEvent($order, $gateway_plugin, $required_payments);
    $this->eventDispatcher->dispatch(CommerceGoCardlessEvents::CHECKOUT_PAYMENTS, $event);

    foreach ($event->getPayments() as $required_payment) {
      $required_payment += [
        'type' => 'payment',
        'metadata' => (object)[],
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
          if ((int)$gateway_plugin->toMinorUnits($price) <= 0) {
            continue;
          }

          $gc_payment = $client->payments()->create([
            'params' => [
              'amount' => $gateway_plugin->toMinorUnits($price),
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
            'payment_gateway' => $gateway->id(),
            'order_id' => $order->id(),
            'remote_id' => $gc_payment->id,
            'remote_state' => $gc_payment->status,
          ])->save();
          break;
        case 'instalment_schedule':
          $required_payment['metadata']->order = $order->id();

          $ini_set = ini_get('serialize_precision');
          ini_set('serialize_precision', -1);
          $gc_schedule = $client->instalmentSchedules()->create([
            'params' => [
              'total_amount' => (int)$gateway_plugin->toMinorUnits($price),
              'currency' => $price->getCurrencyCode(),
              'name' => $required_payment['name'],
              'instalments' => $required_payment['schedule'],
              'metadata' => $required_payment['metadata'],
              'links' => [
                'mandate' => $mandate_id,
              ],
            ],
            'headers' => $headers,
          ]);
          ini_set('serialize_precision', $ini_set);

          $gc_schedule = $client->instalmentSchedules()->get($gc_schedule->id);
          if ($gc_schedule->status === 'active') {
            foreach ($gc_schedule->links->payments as $payment_id) {
              $gc_payment = $client->payments()->get($payment_id);

              $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
              $payment = $payment_storage->create([
                'state' => 'pending_capture',
                'amount' => new Price((string)($gc_payment->amount / 100), $price->getCurrencyCode()),
                'payment_gateway' => $gateway->id(),
                'order_id' => $order->id(),
                'remote_id' => $gc_payment->id,
                'remote_state' => $gc_payment->status,
              ]);
              $payment->save();
            }
          }
          else {
            // Queue up the creation of scheduled payments.
            $this->queueFactory->get('commerce_gocardless_instalment_schedule')->createItem([
              'payment_gateway' => $gateway,
              'order' => $order,
              'schedule' => $gc_schedule,
            ]);
          }

          break;
      }
    }
  }

}
