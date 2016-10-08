<?php

namespace Drupal\moderation_sidebar\Controller;

use Drupal\content_moderation\ModerationInformation;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Url;
use Drupal\moderation_sidebar\Form\QuickTransitionForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Endpoints for the Moderation Sidebar module.
 */
class ModerationSidebarController extends ControllerBase {

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformation
   */
  protected $moderationInformation;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Creates a ModerationSidebarController instance.
   *
   * @param \Drupal\content_moderation\ModerationInformation $moderation_information
   *   The moderation information service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(ModerationInformation $moderation_information, RequestStack $request_stack) {
    $this->moderationInformation = $moderation_information;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_moderation.moderation_information'),
      $container->get('request_stack')
    );
  }

  /**
   * Displays information relevant to moderating an entity in-line.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return array
   *   The render array for the sidebar.
   */
  public function sideBar(ContentEntityInterface $entity) {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moderation-sidebar-container'],
      ],
    ];

    // Add information about this Entity to the top of the bar.
    $state = $this->getState($entity);
    $build['info'] = [
      '#theme' => 'moderation_sidebar_info',
      '#title' => $entity->label(),
      '#state' => $state->label(),
    ];

    if ($entity instanceof EntityChangedInterface) {
      $build['info']['#updated_date'] = $entity->getChangedTime();
    }

    if ($entity instanceof RevisionLogInterface) {
      $user = $entity->getRevisionUser();
      $user_link = $user->toLink()->toRenderable();
      $user_link['#attributes']['target'] = '_blank';
      $build['info']['#revision_author'] = $user->label();
      $build['info']['#revision_author_link'] = $user_link;
      $build['info']['#revision_time'] = $entity->getRevisionCreationTime();
      $build['info']['#revision_id'] = $entity->getRevisionId();
    }

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moderation-sidebar-actions'],
      ],
    ];

    $entity_type_id = $entity->getEntityTypeId();
    $is_latest = $this->moderationInformation->isLatestRevision($entity);

    // If this revision is not the latest, provide a link to the latest entity.
    if (!$is_latest) {
      $build['actions']['view_latest'] = [
        '#title' => $this->t('View existing draft'),
        '#type' => 'link',
        '#url' => Url::fromRoute("entity.{$entity_type_id}.latest_version", [
          $entity_type_id => $entity->id(),
        ]),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button'],
        ],
      ];
    }

    // Provide a link to the default display of the entity.
    if (!$entity->isDefaultRevision()) {
      $build['actions']['view_default'] = [
        '#title' => $this->t('View live content'),
        '#type' => 'link',
        '#url' => $entity->toLink()->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button'],
        ],
      ];
    }

    // Show an edit link if this is the latest revision.
    if ($is_latest && !$this->moderationInformation->isLiveRevision($entity)) {
      $build['actions']['edit_draft'] = [
        '#title' => $this->t('Edit draft'),
        '#type' => 'link',
        '#url' => $entity->toLink(NULL, 'edit-form')->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button'],
        ],
      ];
    }

    // Provide a list of actions representing transitions for this revision.
    $build['actions']['quick_draft_form'] = $this->formBuilder()->getForm(QuickTransitionForm::class, $entity);

    // Only show the entity delete action on the default revision.
    if ($entity->isDefaultRevision()) {
      $build['actions']['delete'] = [
        '#title' => $this->t('Delete content'),
        '#type' => 'link',
        '#url' => $entity->toLink(NULL, 'delete-form')->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button', 'button--danger'],
        ],
      ];
    }

    return $build;
  }

  /**
   * Displays the moderation sidebar for the latest revision of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return array
   *   The render array for the sidebar.
   */
  public function sideBarLatest(ContentEntityInterface $entity) {
    $entity = $this->moderationInformation->getLatestRevision($entity->getEntityTypeId(), $entity->id());
    return $this->sideBar($entity);
  }

  /**
   * Renders the sidebar title for moderating this Entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return string
   *   The title of the sidebar.
   */
  public function title(ContentEntityInterface $entity) {
    // @todo Is there a way to generically get the Bundle Entity Type for a
    // given Entity?
    if ($entity->getEntityTypeId() == 'node') {
      $type = $this->moderationInformation->loadBundleEntity('node_type', $entity->getType());
      $label = $type->label();
    }
    else {
      $label = $entity->getEntityType()->getLabel();
    }
    return $this->t('Moderate @label', ['@label' => $label]);
  }

  /**
   * Performs custom access checks for Moderation Sidebar routes.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *    A moderated entity.
   *
   * @return bool
   *   Whether or not the route can be accessed.
   */
  public function access(ContentEntityInterface $entity) {
    $is_moderated = $this->moderationInformation->isModeratedEntity($entity);
    return AccessResultAllowed::allowedIf($is_moderated);
  }

  /**
   * Gets the moderation state for the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return \Drupal\workbench_moderation\Entity\ModerationState
   *   The moderation state.
   */
  protected function getState(ContentEntityInterface $entity) {
    return $entity->moderation_state->entity;
  }

}
