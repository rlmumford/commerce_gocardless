<?php

namespace Drupal\commerce_gocardless\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Class Mandate
 *
 * @ContentEntityType(
 *   id = "gocardless_mandate",
 *   label = @Translation("Mandate"),
 *   base_table = "gocardless_mandate",
 *   revision_table = "gocardless_mandate_revision",
 *   admin_permission = "administer mandates",
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\commerce_gocardless\Entity\MandateAccessControlHandler",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "uuid" = "uuid",
 *     "owner" = "owner",
 *   }
 * )
 *
 * @package Drupal\commerce_gocardless\Entity
 */
class Mandate extends ContentEntityBase implements EntityOwnerInterface {
  use EntityOwnerTrait;

  /**
   * Status constants.
   */
  const S_PENDING_SUBMISSION = 'pending_submission';
  const S_PENDING_CUSTOMER_APP = 'pending_customer_approval';
  const S_SUBMITTED = 'submitted';
  const S_ACTIVE = 'active';
  const S_FAILED = 'failed';
  const S_CANCELLED = 'cancelled';
  const S_EXPIRED = 'expired';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['init_order'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'commerce_order')
      ->setLabel(new TranslatableMarkup('Inital Order'))
      ->setDescription(new TranslatableMarkup('The order this mandate was set up for'))
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['gc_mandate_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('GoCardless Mandate ID'));
    $fields['gc_mandate_scheme'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('GoCardless Mandate Scheme'));
    $fields['gc_mandate_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('GoCardless Mandate Status'))
      ->setSetting('allowed_values', [
        static::S_PENDING_SUBMISSION => new TranslatableMarkup('Pending Submission'),
        static::S_PENDING_CUSTOMER_APP => new TranslatableMarkup('Pending Customer Approval'),
        static::S_SUBMITTED => new TranslatableMarkup('Submitted'),
        static::S_ACTIVE => new TranslatableMarkup('Active'),
        static::S_FAILED => new TranslatableMarkup('Failed'),
        static::S_CANCELLED => new TranslatableMarkup('Cancelled'),
        static::S_EXPIRED => new TranslatableMarkup('Expired'),
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['gc_customer_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('GoCardless Customer Id'));

    $fields['sandbox'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Is Sandbox'))
      ->setDefaultValue([
        'value' => FALSE,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('view', TRUE);
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
