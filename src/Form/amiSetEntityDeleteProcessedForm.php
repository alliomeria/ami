<?php
namespace Drupal\ami\Form;

use Drupal\ami\AmiUtilityService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the MetadataDisplayEntity entity delete form.
 *
 * @ingroup ami
 */
class amiSetEntityDeleteProcessedForm extends ContentEntityConfirmFormBase {

  /**
   * @var \Drupal\ami\AmiUtilityService
   */
  protected $AmiUtilityService;

  /**
   * Constructs a ContentEntityForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface|null $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The time service.
   * @param \Drupal\ami\AmiUtilityService $ami_utility
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info = NULL, TimeInterface $time = NULL, AmiUtilityService $ami_utility) {

    parent::__construct($entity_repository,$entity_type_bundle_info, $time);
    $this->AmiUtilityService = $ami_utility;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('ami.utility')
    );
  }



  public function getQuestion() {
    return $this->t('Are you sure you want to delete ADOs generated by %name?', ['%name' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.ami_set_entity.collection');
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // @TODO We should here make sure we get rid of any files
    // But if the queue has elements from this Set we should not be able to delete?
   // $this->entity->delete();
    $csv_file_reference = $this->entity->get('source_data')->getValue();
    if (isset($csv_file_reference[0]['target_id'])) {
      $file = $this->entityTypeManager->getStorage('file')->load($csv_file_reference[0]['target_id']);
    }
    $data = new \stdClass();
    foreach($this->entity->get('set') as $item) {
      /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $item */
      $data = $item->provideDecoded(FALSE);
    }
    if ($file && $data!== new \stdClass()) {
      $uuids = $this->AmiUtilityService->getProcessedAmiSetNodeUUids($file, $data);
      $operations = [];
      foreach (array_chunk($uuids, 10) as $batch_data_uuid) {
        $operations[] = ['\Drupal\ami\Form\amiSetEntityDeleteProcessedForm::batchDelete'
          , [$batch_data_uuid]];
      }
      // Setup and define batch informations.
      $batch = array(
        'title' => t('Deleting ADOs in batch...'),
        'operations' => $operations,
        'finished' => '\Drupal\ami\Form\amiSetEntityDeleteProcessedForm::batchFinished',
      );
      batch_set($batch);
    } else {
      $this->messenger()->addError(
        $this->t('So Sorry. This Ami Set has incorrect Metadata and/or has its CSV file missing. We need it to know which ADOs where generated via this Set. Please correct or manually delete your ADOs.',
          [
            '@label' => $this->entity->label(),
          ]
        )
      );
    }
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['delete_enqueued'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Delete Ingested ADOs via this Set'),
      '#description' => $this->t('Confirming will trigger a Batch Delete for already ingested ADOs.'),
    ];
    return $form + parent::buildForm($form, $form_state);
  }


  public static function batchDelete($batch_data_uuid, &$context) {
    // Deleting nodes.
    $storage_handler = \Drupal::entityTypeManager()->getStorage('node');
    $entities = $storage_handler->loadByProperties(['uuid' => $batch_data_uuid]);
    $storage_handler->delete($entities);

    // Display data while running batch.
    $batch_size=sizeof($batch_data_uuid);
    $batch_number=sizeof($context['results'])+1;
    $context['message'] = sprintf("Deleting %s ADOs per batch. Batch #%s"
      , $batch_size, $batch_number);
    $context['results'][] = sizeof($batch_data_uuid);
  }

  // What to do after batch ran. Display success or error message.
  public static function batchFinished($success, $results, $operations) {
    if ($success)
      $message = count($results). ' batches processed.';
    else
      $message = 'Finished with an error.';

    $messenger = \Drupal::messenger();
    if (isset($message)) {
      $messenger->addMessage($message);
    }
  }
}

