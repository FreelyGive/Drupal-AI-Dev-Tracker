<?php

namespace Drupal\issue_analysis\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\issue_analysis\Service\DailyDigestService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to trigger daily newsletter digest generation via the Batch API.
 */
class DailyDigestForm extends FormBase {

  public function __construct(
    protected DailyDigestService $digestService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('issue_analysis.daily_digest'));
  }

  public function getFormId(): string {
    return 'issue_analysis_daily_digest';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $lastRun = $this->digestService->lastRunFormatted();

    $form['status'] = [
      '#type' => 'container',
    ];

    $form['status']['last_run'] = [
      '#markup' => $lastRun
        ? $this->t('Last generated: <strong>@time</strong>', ['@time' => $lastRun])
        : $this->t('The digest has not been generated yet.'),
    ];

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Clicking the button will fetch the last 24 hours of GitLab activity for all AI modules and generate the developer and executive newsletters. Progress is shown in real time.') . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate daily digest now'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = $this->digestService->buildBatch();
    batch_set($batch);
  }

}
