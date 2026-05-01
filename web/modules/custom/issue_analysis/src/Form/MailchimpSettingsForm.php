<?php

namespace Drupal\issue_analysis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Mailchimp campaign settings for the daily digest.
 */
class MailchimpSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'issue_analysis_mailchimp_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['issue_analysis.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('issue_analysis.settings');

    $form['audience'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Audience'),
    ];

    $form['audience']['mailchimp_list_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Audience / List ID'),
      '#description' => $this->t('The Mailchimp audience (list) ID shared by both campaigns.'),
      '#default_value' => $config->get('mailchimp_list_id') ?? '',
    ];

    $form['audience']['mailchimp_from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#default_value' => $config->get('mailchimp_from_name') ?? 'Drupal AI Initiative',
    ];

    $form['audience']['mailchimp_reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Reply-to email'),
      '#default_value' => $config->get('mailchimp_reply_to') ?? '',
    ];

    $form['interests'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Interest group segmentation'),
      '#description' => $this->t('Used to target subscribers based on the "What best describes you?" group in Mailchimp. Obtain IDs via <code>GET /lists/{id}/interest-categories/{cat_id}/interests</code>.'),
    ];

    $form['interests']['mailchimp_interest_category_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Interest category ID'),
      '#description' => $this->t('The ID of the "What best describes you?" interest category (e.g. <code>c5212aadb6</code>).'),
      '#default_value' => $config->get('mailchimp_interest_category_id') ?? '',
    ];

    $form['interests']['mailchimp_interest_executive'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Executive interest ID'),
      '#description' => $this->t('Interest ID for the "Executive" option (e.g. <code>a4dd4a1c6b</code>).'),
      '#default_value' => $config->get('mailchimp_interest_executive') ?? '',
    ];

    $form['interests']['mailchimp_interest_developer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Developer interest ID'),
      '#description' => $this->t('Interest ID for the "Developer" option (e.g. <code>84b895f5bc</code>).'),
      '#default_value' => $config->get('mailchimp_interest_developer') ?? '',
    ];

    $form['testing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Testing'),
    ];

    $form['testing']['mailchimp_list_id_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test audience ID'),
      '#description' => $this->t('When set, both campaigns target this audience with no segmentation. Clear when done testing.'),
      '#default_value' => $config->get('mailchimp_list_id_test') ?? '',
    ];

    $form['signup_form'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Signup form'),
    ];

    $form['signup_form']['mailchimp_embed_code'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Embed code'),
      '#description' => $this->t('Paste the HTML embed code from Mailchimp (Audience → Signup forms → Embedded forms). This is rendered at <code>/newsletter/signup</code>.'),
      '#default_value' => $config->get('mailchimp_embed_code') ?? '',
      '#rows' => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('issue_analysis.settings')
      ->set('mailchimp_list_id', trim($form_state->getValue('mailchimp_list_id')))
      ->set('mailchimp_from_name', trim($form_state->getValue('mailchimp_from_name')))
      ->set('mailchimp_reply_to', trim($form_state->getValue('mailchimp_reply_to')))
      ->set('mailchimp_interest_category_id', trim($form_state->getValue('mailchimp_interest_category_id')))
      ->set('mailchimp_interest_executive', trim($form_state->getValue('mailchimp_interest_executive')))
      ->set('mailchimp_interest_developer', trim($form_state->getValue('mailchimp_interest_developer')))
      ->set('mailchimp_list_id_test', trim($form_state->getValue('mailchimp_list_id_test')))
      ->set('mailchimp_embed_code', $form_state->getValue('mailchimp_embed_code'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
