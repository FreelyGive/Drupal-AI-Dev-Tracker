/**
 * @file
 * Export functionality for Meta-Issue-Editor.
 */

(function (Drupal) {
  'use strict';

  /**
   * Export helper for Meta-Issue-Editor.
   */
  Drupal.metaIssueExport = {

    /**
     * Copy export content to clipboard.
     */
    copyToClipboard: function () {
      const textarea = document.getElementById('export-content');
      if (!textarea) {
        return;
      }

      textarea.select();
      textarea.setSelectionRange(0, 99999); // For mobile devices.

      // Try modern clipboard API first, fall back to execCommand.
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(textarea.value).then(function () {
          Drupal.metaIssueExport.showCopiedFeedback();
        }).catch(function () {
          // Fall back to execCommand.
          document.execCommand('copy');
          Drupal.metaIssueExport.showCopiedFeedback();
        });
      }
      else {
        document.execCommand('copy');
        Drupal.metaIssueExport.showCopiedFeedback();
      }
    },

    /**
     * Show copied feedback on the button.
     */
    showCopiedFeedback: function () {
      const btn = document.querySelector('.copy-button');
      if (!btn) {
        return;
      }

      btn.textContent = 'Copied!';
      btn.classList.add('copied');

      setTimeout(function () {
        btn.textContent = 'Copy to Clipboard';
        btn.classList.remove('copied');
      }, 2000);
    }

  };

})(Drupal);
