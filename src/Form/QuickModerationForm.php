<?php

namespace Drupal\moderation_sidebar\Form;

use Drupal\content_moderation\Form\EntityModerationForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * The QuickModerationForm provides a minimal interface for changing states.
 */
class QuickModerationForm extends EntityModerationForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ContentEntityInterface $entity = NULL) {
    $form = parent::buildForm($form, $form_state, $entity);
    $form['current']['#access'] = FALSE;
    unset($form['new_state']['#title']);
    unset($form['#theme']);
    $form['#attributes']['class'][] = 'quick-moderation-form';
    return $form;
  }

}
