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
      '#description' => $this->t('Mailchimp audience (list) ID to use instead of the live one — must be the same alphanumeric format as the main audience ID (e.g. <code>6c08b866c9</code>). Leave blank to use the live audience.'),
      '#default_value' => $config->get('mailchimp_list_id_test') ?? '',
      '#maxlength' => 32,
    ];

    $form['testing']['mailchimp_tag_id_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test tag ID'),
      '#description' => $this->t('Numeric Mailchimp tag ID (segment ID) to restrict sending to tagged subscribers only. When set, campaigns target the main audience filtered by this tag — no interest-group segmentation is applied. Obtain the ID via <code>GET /lists/{id}/segments</code> (type: static). Example: <code>1519891</code>.'),
      '#default_value' => $config->get('mailchimp_tag_id_test') ?? '',
      '#maxlength' => 16,
    ];

    $form['testing']['disable_sending'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable sending'),
      '#description' => $this->t('When checked, campaign drafts are created in Mailchimp but not sent. Useful for reviewing output before going live.'),
      '#default_value' => $config->get('disable_sending') ?? FALSE,
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

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Mailchimp audience IDs are alphanumeric (typically 10 hex chars).
    // Reject anything that looks like a plain integer — those are segment IDs,
    // not list IDs, and will cause a 400 from the Mailchimp API.
    foreach (['mailchimp_list_id', 'mailchimp_list_id_test'] as $field) {
      $value = trim($form_state->getValue($field) ?? '');
      if ($value !== '' && ctype_digit($value)) {
        $form_state->setErrorByName($field, $this->t('This looks like a numeric segment ID, not a Mailchimp audience ID. Audience IDs are alphanumeric (e.g. <code>6c08b866c9</code>). Leave blank if unsure.'));
      }
    }

    // Tag IDs must be numeric.
    $tag_id = trim($form_state->getValue('mailchimp_tag_id_test') ?? '');
    if ($tag_id !== '' && !ctype_digit($tag_id)) {
      $form_state->setErrorByName('mailchimp_tag_id_test', $this->t('Tag ID must be a numeric value (e.g. <code>1519891</code>).'));
    }
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
      ->set('mailchimp_tag_id_test', trim($form_state->getValue('mailchimp_tag_id_test')))
      ->set('disable_sending', (bool) $form_state->getValue('disable_sending'))
      ->set('mailchimp_embed_code', $form_state->getValue('mailchimp_embed_code'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
