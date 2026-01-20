/**
 * @file
 * API utilities for Meta-Issue-Editor.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * API helper for Meta-Issue-Editor.
   */
  Drupal.metaIssueEditorApi = {

    /**
     * Fetch local issues from the AI Dashboard.
     *
     * @param {Array} issueNumbers - Array of issue numbers.
     * @returns {Promise} - Promise resolving to issue data.
     */
    fetchLocalIssues: function (issueNumbers) {
      const url = '/api/meta-issue-editor/local-issues?issue_numbers=' + issueNumbers.join(',');

      return fetch(url, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
        },
      })
      .then(response => response.json());
    },

    /**
     * Fetch issues from drupal.org.
     *
     * @param {Array} issueNumbers - Array of issue numbers.
     * @returns {Promise} - Promise resolving to issue data.
     */
    fetchFromDrupalOrg: function (issueNumbers) {
      return fetch('/api/meta-issue-editor/fetch-issues', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          issue_numbers: issueNumbers,
        }),
      })
      .then(response => response.json());
    },

    /**
     * Save draft to the server.
     *
     * @param {number} sourceIssue - The source issue number.
     * @param {string} editorContent - JSON string of editor content.
     * @param {string} issueCache - JSON string of cached issue data.
     * @returns {Promise} - Promise resolving to save result.
     */
    saveDraft: function (sourceIssue, editorContent, issueCache) {
      return fetch('/api/meta-issue-editor/save-draft', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          source_issue: sourceIssue,
          editor_content: editorContent,
          issue_cache: issueCache,
        }),
      })
      .then(response => response.json());
    },

    /**
     * Load draft from server.
     *
     * @param {number} sourceIssue - The source issue number.
     * @returns {Promise} - Promise resolving to draft data.
     */
    loadDraft: function (sourceIssue) {
      return fetch('/api/meta-issue-editor/load-draft/' + sourceIssue, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
        },
      })
      .then(response => response.json());
    },

    /**
     * Fetch meta-issue body from drupal.org.
     *
     * @param {number} issueNumber - The meta-issue number.
     * @returns {Promise} - Promise resolving to issue body.
     */
    fetchMetaIssueBody: function (issueNumber) {
      // This goes through our fetch endpoint for a single issue
      return this.fetchFromDrupalOrg([issueNumber])
        .then(result => {
          if (result.issues && result.issues[issueNumber]) {
            return result.issues[issueNumber].body;
          }
          throw new Error('Issue not found');
        });
    },

  };

})(Drupal, drupalSettings);
