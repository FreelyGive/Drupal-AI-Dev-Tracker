<?php

namespace Drupal\issue_analysis\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Mailchimp\MailchimpCampaigns;

/**
 * Creates Mailchimp campaign drafts from a daily_digest node.
 *
 * Two campaigns are created per digest targeting the same audience but with
 * inline interest-group conditions:
 *   - Executive: field_executive_summary → subscribers who chose "Executive"
 *   - Developer: field_developer_summary → subscribers who chose "Developer"
 *
 * When a test audience ID is configured, both campaigns target that list
 * with no segmentation (useful for smoke-testing before going live).
 */
class MailchimpDigestService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Creates two Mailchimp campaign drafts (executive + developer) for the node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A published daily_digest node.
   *
   * @return array
   *   Keyed ['executive' => string|null, 'developer' => string|null].
   */
  public function createCampaignDraft(NodeInterface $node): array {
    if (!\Drupal::moduleHandler()->moduleExists('mailchimp_campaign')) {
      return ['executive' => NULL, 'developer' => NULL];
    }

    $config    = $this->configFactory->get('issue_analysis.settings');
    $test_list = $config->get('mailchimp_list_id_test') ?: NULL;
    $list_id   = $test_list ?: ($config->get('mailchimp_list_id') ?: NULL);

    if (empty($list_id)) {
      $this->loggerFactory->get('issue_analysis')->notice(
        'Mailchimp campaigns not created: no audience ID configured.'
      );
      return ['executive' => NULL, 'developer' => NULL];
    }

    $from_name   = $config->get('mailchimp_from_name') ?: 'Drupal AI Initiative';
    $reply_to    = $config->get('mailchimp_reply_to') ?: '';
    $category_id = $config->get('mailchimp_interest_category_id') ?: NULL;
    $exec_id     = $config->get('mailchimp_interest_executive') ?: NULL;
    $dev_id      = $config->get('mailchimp_interest_developer') ?: NULL;

    $subject = $node->getTitle();

    $personas = [
      'executive' => [
        'label'       => 'Executive',
        'field'       => 'field_executive_summary',
        'interest_id' => $exec_id,
      ],
      'developer' => [
        'label'       => 'Developer',
        'field'       => 'field_developer_summary',
        'interest_id' => $dev_id,
      ],
    ];

    $results = [];
    foreach ($personas as $key => $persona) {
      $html = $this->renderFieldWithTldr($node, $persona['field']);
      if (empty($html)) {
        $this->loggerFactory->get('issue_analysis')->notice(
          'Skipping @label Mailchimp campaign: @field is empty on node @nid.',
          ['@label' => $persona['label'], '@field' => $persona['field'], '@nid' => $node->id()]
        );
        $results[$key] = NULL;
        continue;
      }

      // Build segment_opts only when not in test mode and IDs are configured.
      $segment_opts = NULL;
      if (!$test_list && $category_id && $persona['interest_id']) {
        $segment_opts = (object) [
          'match' => 'all',
          'conditions' => [
            (object) [
              'condition_type' => 'Interests',
              'field'          => 'interests-' . $category_id,
              'op'             => 'interestcontains',
              'value'          => [$persona['interest_id']],
            ],
          ],
        ];
      }

      $results[$key] = $this->createSingleCampaign(
        $list_id,
        $segment_opts,
        $subject . ' — ' . $persona['label'],
        $from_name,
        $reply_to,
        $html,
        $node->id()
      );
    }

    // Send each successfully created campaign immediately, unless sending is disabled.
    if ($config->get('disable_sending')) {
      $this->loggerFactory->get('issue_analysis')->notice(
        'Mailchimp sending is disabled — campaigns created as drafts only.'
      );
    }
    else {
      foreach ($results as $campaignId) {
        if (!$campaignId) {
          continue;
        }
        $entities = \Drupal::entityTypeManager()
          ->getStorage('mailchimp_campaign')
          ->loadByProperties(['mc_campaign_id' => $campaignId]);
        $entity = reset($entities);
        if ($entity) {
          mailchimp_campaign_send_campaign($entity);
        }
        else {
          $this->loggerFactory->get('issue_analysis')->error(
            'Could not load mailchimp_campaign entity for @id — campaign not sent.',
            ['@id' => $campaignId]
          );
        }
      }
    }

    return $results;
  }

  /**
   * Creates a single campaign draft and registers the Drupal entity.
   */
  private function createSingleCampaign(
    string $list_id,
    ?object $segment_opts,
    string $subject,
    string $from_name,
    string $reply_to,
    string $html,
    int $nid,
  ): ?string {
    try {
      /** @var \Mailchimp\MailchimpCampaigns $mc */
      $mc = \Drupal::service('mailchimp.api')->getApiObject('MailchimpCampaigns');
      if (!$mc) {
        throw new \Exception('Mailchimp API not available. Check API key.');
      }

      $recipients = (object) ['list_id' => $list_id];
      if ($segment_opts) {
        $recipients->segment_opts = $segment_opts;
      }

      $settings = (object) [
        'subject_line' => $subject,
        'title'        => $subject,
        'from_name'    => $from_name,
        'reply_to'     => $reply_to,
      ];

      $result = $mc->addCampaign(MailchimpCampaigns::CAMPAIGN_TYPE_REGULAR, $recipients, $settings);

      if (empty($result->id)) {
        throw new \Exception('Mailchimp API returned no campaign ID.');
      }

      $mc->setCampaignContent($result->id, ['html' => $html]);

      \Drupal::entityTypeManager()
        ->getStorage('mailchimp_campaign')
        ->create([
          'mc_campaign_id' => $result->id,
          'template' => serialize(['html' => ['value' => $html, 'format' => 'content_format']]),
        ])
        ->save();

      $this->loggerFactory->get('issue_analysis')->notice(
        'Mailchimp campaign draft created: @id (subject: @subject) for node @nid.',
        ['@id' => $result->id, '@subject' => $subject, '@nid' => $nid]
      );

      return $result->id;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('issue_analysis')->error(
        'Failed to create Mailchimp campaign draft "@subject": @msg',
        ['@subject' => $subject, '@msg' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Returns field HTML with the TL;DR summary prepended at the top.
   *
   * The summary subfield holds the TL;DR generated during digest assembly.
   * It is rendered first under an "In this edition" heading, followed by an
   * <hr> separator, then the full body value.
   */
  private function renderFieldWithTldr(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }

    $item = $node->get($field_name)->first();
    $tldr = trim($item->summary ?? '');
    $body = trim($item->value ?? '');

    if (empty($body)) {
      return '';
    }

    // Extract the footer meta line (Period | Generated) so it can be
    // re-appended after the disclaimer. assembleNewsletter() places it as
    // <p class="digest-meta">...</p> at the very end of the body.
    $metaLine = '';
    $body = preg_replace_callback(
      '/<hr[^>]*>\n<p class="digest-meta"[^>]*>(.*?)<\/p>\s*$/s',
      function (array $m) use (&$metaLine): string {
        $metaLine = $m[1];
        return '';
      },
      $body
    );
    $body = trim($body);

    $parts = [];

    if (!empty($tldr)) {
      $parts[] = '<div style="background:#f0f6ff;border-left:4px solid #0056b3;border-radius:4px;padding:1em 1.25em;margin-bottom:1.5em;">';
      $parts[] = '<h2 style="margin-top:0;color:#0056b3;font-size:1em;text-transform:uppercase;letter-spacing:0.04em;">In this edition</h2>';
      $parts[] = $tldr;
      $parts[] = '</div>';
    }

    $parts[] = $body;

    $parts[] = '<hr>';
    $parts[] = '<p style="font-size:0.85em;color:#666;"><em>'
      . 'This summary was generated by AI and may not be fully accurate. '
      . 'Always verify details against the linked sources.'
      . '</em></p>';
    $parts[] = '<p>AI provided by <a href="https://www.amazee.io/" title="Amazee.io">Amazee.io</a></p>';

    if ($metaLine) {
      $parts[] = '<p style="font-size:0.85em;color:#666;">' . $metaLine . '</p>';
    }

    return implode("\n", $parts);
  }

}
