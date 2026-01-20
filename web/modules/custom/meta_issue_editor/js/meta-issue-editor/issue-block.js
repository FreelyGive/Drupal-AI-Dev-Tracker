/**
 * @file
 * Issue block rendering for Meta-Issue-Editor.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Issue block utilities.
   */
  Drupal.metaIssueBlock = {

    /**
     * Issue data cache.
     */
    issueCache: {},

    /**
     * Status class mapping.
     */
    statusClasses: {
      'active': 'status-active',
      'fixed': 'status-fixed',
      'closed (fixed)': 'status-closed-fixed',
      'needs work': 'status-needs-work',
      'needs review': 'status-needs-review',
      'reviewed & tested by the community': 'status-rtbc',
      'rtbc': 'status-rtbc',
      'postponed': 'status-postponed',
      'closed': 'status-closed',
    },

    /**
     * Closed status list.
     */
    closedStatuses: [
      'fixed', 'closed', 'closed (fixed)', 'closed (duplicate)',
      'closed (won\'t fix)', 'closed (works as designed)', 'closed (cannot reproduce)'
    ],

    /**
     * Create an issue block element.
     *
     * @param {number} issueNumber - The issue number.
     * @param {object} issueData - Issue data (optional).
     * @param {string} note - Internal note (optional).
     * @returns {HTMLElement} - The issue block element.
     */
    createBlock: function (issueNumber, issueData, note) {
      const block = document.createElement('div');
      block.className = 'issue-block';
      block.dataset.issueNumber = issueNumber;

      if (issueData) {
        this.issueCache[issueNumber] = issueData;
        this.renderBlockWithData(block, issueNumber, issueData, note);
      } else if (this.issueCache[issueNumber]) {
        this.renderBlockWithData(block, issueNumber, this.issueCache[issueNumber], note);
      } else {
        this.renderUnknownBlock(block, issueNumber);
      }

      return block;
    },

    /**
     * Render a block with issue data.
     */
    renderBlockWithData: function (block, issueNumber, data, note) {
      const status = (data.status || '').toLowerCase();
      const statusClass = this.statusClasses[status] || 'status-unknown';
      const isClosed = this.closedStatuses.includes(status);

      if (isClosed) {
        block.classList.add('closed');
      }

      block.innerHTML = `
        <div class="issue-block-header">
          <span class="issue-block-drag-handle" draggable="true">⋮⋮</span>
          <a href="${data.url || 'https://www.drupal.org/node/' + issueNumber}"
             target="_blank"
             class="issue-block-number">#${issueNumber}</a>
          <span class="issue-block-title">${this.escapeHtml(data.title || 'Untitled')}</span>
          <span class="issue-status-badge ${statusClass}">${this.escapeHtml(data.status || 'Unknown')}</span>
          ${data.priority ? `<span class="issue-priority-badge">${this.escapeHtml(data.priority)}</span>` : ''}
          <button type="button" class="issue-block-expand" onclick="Drupal.metaIssueBlock.toggleExpand(this)">▶ Expand</button>
        </div>
        <div class="issue-block-metadata">
          <div class="issue-metadata-grid">
            ${data.component ? `<div class="issue-metadata-item"><strong>Component:</strong> ${this.escapeHtml(data.component)}</div>` : ''}
            ${data.module ? `<div class="issue-metadata-item"><strong>Module:</strong> ${this.escapeHtml(data.module)}</div>` : ''}
            ${data.assigned ? `<div class="issue-metadata-item"><strong>Assigned:</strong> @${this.escapeHtml(data.assigned)}</div>` : ''}
            ${data.tags && data.tags.length ? `<div class="issue-metadata-item"><strong>Tags:</strong> ${data.tags.map(t => this.escapeHtml(t)).join(', ')}</div>` : ''}
            ${data.update_summary ? `<div class="issue-metadata-item"><strong>Update Summary:</strong> ${this.escapeHtml(data.update_summary)}</div>` : ''}
          </div>
          <div class="issue-block-notes">
            <div class="issue-block-notes-label">📝 Notes (internal, not exported):</div>
            <textarea class="issue-note" placeholder="Add internal notes here...">${this.escapeHtml(note || '')}</textarea>
          </div>
        </div>
      `;
    },

    /**
     * Render an unknown issue block.
     */
    renderUnknownBlock: function (block, issueNumber) {
      block.classList.add('unknown');
      block.innerHTML = `
        <div class="issue-block-header">
          <span class="issue-block-drag-handle" draggable="true">⋮⋮</span>
          <span class="issue-block-number">#${issueNumber}</span>
          <span class="issue-block-title">(Unknown issue)</span>
          <span class="issue-status-badge status-unknown">⚠ Unknown</span>
        </div>
      `;
    },

    /**
     * Toggle expanded state of an issue block.
     */
    toggleExpand: function (button) {
      const block = button.closest('.issue-block');
      const isExpanded = block.classList.toggle('expanded');
      button.textContent = isExpanded ? '▼ Collapse' : '▶ Expand';
    },

    /**
     * Update block with new data.
     */
    updateBlock: function (issueNumber, data) {
      this.issueCache[issueNumber] = data;
      const blocks = document.querySelectorAll(`.issue-block[data-issue-number="${issueNumber}"]`);
      blocks.forEach(block => {
        const note = block.querySelector('.issue-note')?.value || '';
        block.classList.remove('unknown');
        this.renderBlockWithData(block, issueNumber, data, note);
      });
    },

    /**
     * Get notes from all issue blocks.
     *
     * @returns {object} - Map of issue numbers to notes.
     */
    getAllNotes: function () {
      const notes = {};
      document.querySelectorAll('.issue-block').forEach(block => {
        const issueNumber = block.dataset.issueNumber;
        const noteEl = block.querySelector('.issue-note');
        if (noteEl && noteEl.value.trim()) {
          notes[issueNumber] = noteEl.value.trim();
        }
      });
      return notes;
    },

    /**
     * Get all unknown issue numbers.
     *
     * @returns {Array} - Array of unknown issue numbers.
     */
    getUnknownIssues: function () {
      const unknown = [];
      document.querySelectorAll('.issue-block.unknown').forEach(block => {
        unknown.push(parseInt(block.dataset.issueNumber, 10));
      });
      return unknown;
    },

    /**
     * Escape HTML entities.
     */
    escapeHtml: function (text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },

  };

})(Drupal, drupalSettings);
