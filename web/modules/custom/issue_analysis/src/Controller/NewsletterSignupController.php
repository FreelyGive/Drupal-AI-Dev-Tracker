<?php

namespace Drupal\issue_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the Mailchimp embedded signup form.
 */
class NewsletterSignupController extends ControllerBase {

  public function page(): array {
    $embed = $this->config('issue_analysis.settings')->get('mailchimp_embed_code') ?? '';

    return [
      '#theme' => 'newsletter_signup',
      '#embed_code' => $embed,
    ];
  }

}