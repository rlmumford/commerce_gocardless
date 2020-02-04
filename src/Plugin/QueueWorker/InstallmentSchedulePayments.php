<?php

namespace Drupal\commerce_gocardless\Plugin\QueueWorker;

use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class InstallmentSchedulePayments
 *
 * @QueueWorker(
 *  id = "commerce_gocardless_instalment_schedule",
 *  title = @Translation("Instalment Schedule Payments"),
 *  cron = {"time" = 10}
 * )
 *
 * @package Drupal\commerce_gocardless\Plugin\QueueWorker
 */
class InstallmentSchedulePayments extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * InstallmentSchedulePayments constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Works on a single queue item.
   *
   * @param mixed $data
   *   The data that was passed to
   *   \Drupal\Core\Queue\QueueInterface::createItem() when the item was queued.
   *
   * @throws \Drupal\Core\Queue\RequeueException
   *   Processing is not yet finished. This will allow another process to claim
   *   the item immediately.
   * @throws \Exception
   *   A QueueWorker plugin may throw an exception to indicate there was a
   *   problem. The cron process will log the exception, and leave the item in
   *   the queue to be processed again later.
   * @throws \Drupal\Core\Queue\SuspendQueueException
   *   More specifically, a SuspendQueueException should be thrown when a
   *   QueueWorker plugin is aware that the problem will affect all subsequent
   *   workers of its queue. For example, a callback that makes HTTP requests
   *   may find that the remote server is not responding. The cron process will
   *   behave as with a normal Exception, and in addition will not attempt to
   *   process further items from the current item's queue during the current
   *   cron run.
   *
   * @see \Drupal\Core\Cron::processQueues()
   */
  public function processItem($data) {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $data['payment_gateway'];
    /** @var \Drupal\commerce_gocardless\Plugin\Commerce\PaymentGateway\GoCardlessRedirectFlow $payment_gateway_plugin */
    $payment_gateway_plugin  = $payment_gateway->getPlugin();
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $data['order'];
    /** @var \GoCardlessPro\Resources\InstalmentSchedule $schedule */
    $schedule = $data['schedule'];

    $client = $payment_gateway_plugin->createGoCardlessClient();
    $schedule = $client->instalmentSchedules()->get($schedule->id);

    if ($schedule->status !== 'active') {
      foreach ($schedule->links->payments as $payment_id) {
        $gc_payment = $client->payments()->get($payment_id);

        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment_storage->create([
          'state' => 'pending_capture',
          'amount' => new Price((string) ($gc_payment/100), $order->getTotalPrice()->getCurrencyCode()),
          'payment_gateway' => $payment_gateway->id(),
          'order_id' => $order->id(),
          'remote_id' => $gc_payment->id,
          'remote_state' => $gc_payment->status,
        ])->save();
      }
    }
    else {
      throw new RequeueException('The instalment schedule is not yet fully created.');
    }
  }
}
