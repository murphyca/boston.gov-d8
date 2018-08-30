<?php

namespace Drupal\transaction\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the transaction entity edit forms.
 */
class TransactionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    $entity = $this->getEntity();
    $result = $entity->save();
    $link = $entity->toLink($this->t('View'))->toRenderable();

    $message_arguments = ['%label' => $this->entity->label()];
    $logger_arguments = $message_arguments + ['link' => render($link)];

    if ($result == SAVED_NEW) {
      drupal_set_message($this->t('New transaction %label has been created.', $message_arguments));
      $this->logger('transaction')->notice('Created new transaction %label', $logger_arguments);
    }
    else {
      drupal_set_message($this->t('The transaction %label has been updated.', $message_arguments));
      $this->logger('transaction')->notice('Created new transaction %label.', $logger_arguments);
    }

    $form_state->setRedirect('entity.transaction.canonical', ['transaction' => $entity->id()]);
  }

}
