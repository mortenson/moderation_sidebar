<?php

namespace Drupal\moderation_sidebar\Controller;

use Drupal\content_moderation\ModerationInformation;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Url;
use Drupal\moderation_sidebar\Form\QuickDraftForm;
use Drupal\moderation_sidebar\Form\QuickModerationForm;
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
    $build = [];

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
        '#title' => $this->t('View draft'),
        '#type' => 'link',
        '#url' => Url::fromRoute("entity.{$entity_type_id}.latest_version", [
          $entity_type_id => $entity->id(),
        ]),
        '#attributes' => [
          'class' => ['moderation-sidebar-link'],
        ],
      ];
    }
    // Provide a quick "Create Draft" button if this is the default revision.
    else if ($entity->isDefaultRevision()) {
      $build['actions']['create_draft'] = $this->formBuilder()->getForm(QuickDraftForm::class, $entity);
    }
    // Provide a link to the default display of the entity.
    else {
      $build['actions']['view_default'] = [
        '#title' => $this->t('View live content'),
        '#type' => 'link',
        '#url' => $entity->toLink()->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link'],
        ],
      ];
      // Show an edit link if this is the latest revision.
      if ($is_latest) {
        $build['actions']['edit_draft'] = [
          '#title' => $this->t('Edit this draft'),
          '#type' => 'link',
          '#url' => $entity->toLink(NULL, 'edit-form')->getUrl(),
          '#attributes' => [
            'class' => ['moderation-sidebar-link'],
          ],
        ];
      }
    }

    // Show our simplified version of the Moderation Form.
    if ($is_latest && !$entity->isDefaultRevision()) {
      $build['form_wrapper'] = [
        '#type' => 'details',
        '#title' => $this->t('Transition State'),
        '#open' => TRUE,
        '#attributes' => [
          'class' => ['moderation-sidebar-form'],
        ],
      ];
      $build['form_wrapper']['form'] = $this->formBuilder()->getForm(QuickModerationForm::class, $entity);
    }

    return $build;
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
