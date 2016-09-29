<?php

namespace Drupal\moderation_sidebar\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\content_moderation\Entity\ModerationStateTransition;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The QuickDraftForm provides a quick button for creating a new Draft.
 */
class QuickDraftForm extends FormBase {

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInfo;

  /**
   * The moderation state transition validation service.
   *
   * @var \Drupal\content_moderation\StateTransitionValidation
   */
  protected $validation;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * QuickDraftForm constructor.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderation_info
   *   The moderation information service.
   * @param \Drupal\content_moderation\StateTransitionValidation $validation
   *   The moderation state transition validation service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ModerationInformationInterface $moderation_info, StateTransitionValidation $validation, EntityTypeManagerInterface $entity_type_manager) {
    $this->moderationInfo = $moderation_info;
    $this->validation = $validation;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_moderation.moderation_information'),
      $container->get('content_moderation.state_transition_validation'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moderation_sidebar_quick_draft_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ContentEntityInterface $entity = NULL) {
    // Persist the entity so we can access it in the submit handler.
    $form_state->set('entity', $entity);

    $form['create_draft'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create New Draft'),
      '#attributes' => [
        'class' => ['moderation-sidebar-link'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var ContentEntityInterface $entity */
    $entity = $form_state->get('entity');

    /** @var \Drupal\content_moderation\Entity\ModerationState $current_state */
    $current_state = $entity->moderation_state->entity;

    $transitions = $this->validation->getValidTransitions($entity, $this->currentUser());

    // Exclude self-transitions.
    $transitions = array_filter($transitions, function(ModerationStateTransition $transition) use ($current_state) {
      return $transition->getToState() != $current_state->id();
    });

    $new_transition = reset($transitions);
    $new_state = $new_transition->getToState();

    // @todo should we just just be updating the content moderation state
    //   entity? That would prevent setting the revision log.
    $entity->moderation_state->target_id = $new_state;
    $entity->revision_log = '';

    $entity->save();

    drupal_set_message($this->t('The moderation state has been updated.'));

    $entity_type_id = $entity->getEntityTypeId();
    $params = [$entity_type_id => $entity->id()];
    $form_state->setRedirect("entity.{$entity_type_id}.latest_version", $params);
  }

}
