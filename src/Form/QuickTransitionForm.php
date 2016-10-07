<?php

namespace Drupal\moderation_sidebar\Form;

use Drupal\content_moderation\ModerationStateInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\content_moderation\Entity\ModerationStateTransition;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidation;
use Drupal\content_moderation\ModerationStateTransitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The QuickTransitionForm provides quick buttons for changing transitions.
 */
class QuickTransitionForm extends FormBase {

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
    return 'moderation_sidebar_quick_transition_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ContentEntityInterface $entity = NULL) {
    // Persist the entity so we can access it in the submit handler.
    $form_state->set('entity', $entity);

    $transitions = $this->validation->getValidTransitions($entity, $this->currentUser());

    // Exclude self-transitions.
    /** @var \Drupal\content_moderation\Entity\ModerationState $current_state */
    $current_state = $entity->moderation_state->entity;

    /** @var ModerationStateTransitionInterface[] $transitions */
    $transitions = array_filter($transitions, function(ModerationStateTransition $transition) use ($current_state) {
      return $transition->getToState() != $current_state->id();
    });

    foreach ($transitions as $transition) {
      $form[$transition->id()] = [
        '#type' => 'submit',
        '#id' => $transition->id(),
        '#value' => $this->t($transition->label()),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button--primary'],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var ContentEntityInterface $entity */
    $entity = $form_state->get('entity');

    /** @var ModerationStateTransitionInterface[] $transitions */
    $transitions = $this->validation->getValidTransitions($entity, $this->currentUser());

    $element = $form_state->getTriggeringElement();

    if (!isset($transitions[$element['#id']])) {
      $form_state->setError($element, $this->t('Invalid transition selected.'));
      return;
    }

    $state_id = $transitions[$element['#id']]->getToState();
    /** @var ModerationStateInterface $state */
    $state = $this->entityTypeManager->getStorage('moderation_state')->load($state_id);

    $entity->moderation_state->target_id = $state_id;
    $entity->revision_log = $this->t('Used the Moderation Sidebar to change the state to "@state".', ['@state' => $state->label()]);

    $entity->save();

    drupal_set_message($this->t('The moderation state has been updated.'));

    if ($state->isPublishedState()) {
      $form_state->setRedirectUrl($entity->toLink()->getUrl());
    }
    else {
      $entity_type_id = $entity->getEntityTypeId();
      $params = [$entity_type_id => $entity->id()];
      $form_state->setRedirect("entity.{$entity_type_id}.latest_version", $params);
    }
  }

}