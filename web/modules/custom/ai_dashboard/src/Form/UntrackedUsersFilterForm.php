<?php

namespace Drupal\ai_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides filter form for untracked users report.
 */
class UntrackedUsersFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_dashboard_untracked_users_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Request $request = NULL) {
    // Get current filter values from request
    $date_filter = $request ? $request->query->get('date_filter', 'all') : 'all';
    $start_date = $request ? $request->query->get('start_date', '') : '';
    $end_date = $request ? $request->query->get('end_date', '') : '';

    // Use Drupal Form API but handle the submission via JavaScript
    $form['#attributes']['class'][] = 'untracked-users-filters';
    $form['#attributes']['id'] = 'untracked-users-filter-form';

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['filter-row']],
    ];

    $form['filters']['date_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Date Range'),
      '#options' => [
        'all' => $this->t('All Time'),
        'this_week' => $this->t('This Week'),
        'last_week' => $this->t('Last Week'),
        'this_month' => $this->t('This Month'),
        'last_month' => $this->t('Last Month'),
        'custom' => $this->t('Custom Range'),
      ],
      '#default_value' => $date_filter,
      '#attributes' => [
        'id' => 'date-filter',
        'class' => ['filter-select'],
        'onchange' => 'toggleCustomDates(this)',
      ],
    ];

    $form['filters']['custom_dates'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'custom-dates',
        'style' => $date_filter === 'custom' ? 'display: inline-flex;' : 'display: none;',
        'class' => ['custom-date-fields'],
      ],
    ];

    $form['filters']['custom_dates']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start'),
      '#default_value' => $start_date,
      '#attributes' => [
        'id' => 'start-date',
        'class' => ['filter-date'],
      ],
    ];

    $form['filters']['custom_dates']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End'),
      '#default_value' => $end_date,
      '#attributes' => [
        'id' => 'end-date',
        'class' => ['filter-date'],
      ],
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'button',
      '#value' => $this->t('Apply Filter'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
        'id' => 'apply-filter-btn',
      ],
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => \Drupal\Core\Url::fromRoute('ai_dashboard.reports.untracked_users'),
      '#attributes' => [
        'class' => ['button', 'button--secondary'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Not used - form submission handled via JavaScript
  }

}