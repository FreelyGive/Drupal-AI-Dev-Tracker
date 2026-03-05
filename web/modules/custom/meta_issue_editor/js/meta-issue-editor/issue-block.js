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
     * @param {object} options - Render options (optional).
     * @returns {HTMLElement} - The issue block element.
     */
    createBlock: function (issueNumber, issueData, note, options = {}) {
      const block = document.createElement('div');
      block.className = 'issue-block';
      block.dataset.issueNumber = issueNumber;
      const inlineAssignee = (options.inlineAssignee || '').trim();
      if (inlineAssignee) {
        block.dataset.inlineAssignee = inlineAssignee;
      }
      else {
        delete block.dataset.inlineAssignee;
      }
      // Keep issue rows non-editable inside the contenteditable editor surface.
      block.setAttribute('contenteditable', 'false');

      if (issueData) {
        this.issueCache[issueNumber] = issueData;
        this.renderBlockWithData(block, issueNumber, issueData, note, inlineAssignee);
      } else if (this.issueCache[issueNumber]) {
        this.renderBlockWithData(block, issueNumber, this.issueCache[issueNumber], note, inlineAssignee);
      } else {
        this.renderUnknownBlock(block, issueNumber, inlineAssignee);
      }

      return block;
    },

    /**
     * Render a block with issue data.
     */
    renderBlockWithData: function (block, issueNumber, data, note, inlineAssignee = '') {
      const status = (data.status || '').toLowerCase();
      const statusClass = this.statusClasses[status] || 'status-unknown';
      const isClosed = this.closedStatuses.includes(status);

      if (isClosed) {
        block.classList.add('closed');
      }

      block.innerHTML = `
        <div class="issue-block-header">
          <span class="issue-block-drag-handle"
                draggable="true"
                contenteditable="false"
                title="Drag to reorder"
                aria-label="Drag issue">⋮⋮</span>
          <a href="${data.url || 'https://www.drupal.org/node/' + issueNumber}"
             target="_blank"
             class="issue-block-number">#${issueNumber}</a>
          <span class="issue-block-title">${this.escapeHtml(data.title || 'Untitled')}</span>
          ${inlineAssignee ? `<span class="issue-inline-assignee">@${this.escapeHtml(inlineAssignee)}</span>` : ''}
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
    renderUnknownBlock: function (block, issueNumber, inlineAssignee = '') {
      block.classList.add('unknown');
      block.innerHTML = `
        <div class="issue-block-header">
          <span class="issue-block-drag-handle"
                draggable="true"
                contenteditable="false"
                title="Drag to reorder"
                aria-label="Drag issue">⋮⋮</span>
          <span class="issue-block-number">#${issueNumber}</span>
          <span class="issue-block-title">(Unknown issue)</span>
          ${inlineAssignee ? `<span class="issue-inline-assignee">@${this.escapeHtml(inlineAssignee)}</span>` : ''}
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
        const inlineAssignee = (block.dataset.inlineAssignee || '').trim();
        block.classList.remove('unknown');
        this.renderBlockWithData(block, issueNumber, data, note, inlineAssignee);
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

    /**
     * Currently dragged element.
     */
    draggedElement: null,

    /**
     * Initialize drag-and-drop for issue blocks.
     */
    initDragDrop: function () {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      // Use event delegation on the editor container
      editorEl.addEventListener('dragstart', (e) => this.handleDragStart(e));
      editorEl.addEventListener('dragend', (e) => this.handleDragEnd(e));
      editorEl.addEventListener('dragover', (e) => this.handleDragOver(e));
      editorEl.addEventListener('dragenter', (e) => this.handleDragEnter(e));
      editorEl.addEventListener('dragleave', (e) => this.handleDragLeave(e));
      editorEl.addEventListener('drop', (e) => this.handleDrop(e));
    },

    /**
     * Handle drag start.
     */
    handleDragStart: function (e) {
      const handle = e.target.closest('.issue-block-drag-handle, .list-item-drag-handle');
      if (!handle) return;
      e.stopPropagation();

      const draggableUnit = this.getDraggableUnitFromHandle(handle);
      if (!draggableUnit) return;

      this.draggedElement = draggableUnit;
      draggableUnit.classList.add('dragging');

      // Set drag data
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', draggableUnit.dataset.issueNumber || 'list-item');

      // Use the dragged unit as drag image.
      e.dataTransfer.setDragImage(draggableUnit, 20, 20);
    },

    /**
     * Handle drag end.
     */
    handleDragEnd: function (e) {
      if (this.draggedElement) {
        this.draggedElement.classList.remove('dragging');
        this.draggedElement = null;
      }

      // Remove all drop indicators
      document.querySelectorAll('.drop-above, .drop-below').forEach(el => {
        el.classList.remove('drop-above', 'drop-below');
      });
    },

    /**
     * Handle drag over.
     */
    handleDragOver: function (e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      const targetUnit = this.getDropTargetUnit(e.target);
      if (!targetUnit || targetUnit === this.draggedElement || this.draggedElement?.contains(targetUnit)) return;

      // Determine if dropping above or below
      const rect = targetUnit.getBoundingClientRect();
      const midY = rect.top + rect.height / 2;

      targetUnit.classList.remove('drop-above', 'drop-below');
      if (e.clientY < midY) {
        targetUnit.classList.add('drop-above');
      } else {
        targetUnit.classList.add('drop-below');
      }
    },

    /**
     * Handle drag enter.
     */
    handleDragEnter: function (e) {
      e.preventDefault();
    },

    /**
     * Handle drag leave.
     */
    handleDragLeave: function (e) {
      const targetUnit = this.getDropTargetUnit(e.target);
      if (targetUnit && !targetUnit.contains(e.relatedTarget)) {
        targetUnit.classList.remove('drop-above', 'drop-below');
      }
    },

    /**
     * Handle drop.
     */
    handleDrop: function (e) {
      e.preventDefault();

      const targetUnit = this.getDropTargetUnit(e.target);
      if (!targetUnit || !this.draggedElement || targetUnit === this.draggedElement || this.draggedElement.contains(targetUnit)) {
        return;
      }

      // Determine insertion point
      const rect = targetUnit.getBoundingClientRect();
      const midY = rect.top + rect.height / 2;
      const insertBefore = e.clientY < midY;

      // Perform the move
      if (insertBefore) {
        targetUnit.parentNode.insertBefore(this.draggedElement, targetUnit);
      } else {
        targetUnit.parentNode.insertBefore(this.draggedElement, targetUnit.nextSibling);
      }

      // Clean up
      targetUnit.classList.remove('drop-above', 'drop-below');
      this.removeEmptyLists();

      // Notify that content changed
      const statusEl = document.getElementById('editor-status');
      if (statusEl) {
        statusEl.textContent = 'Issues reordered (remember to save)';
      }
    },

    /**
     * Resolve draggable unit from a handle.
     *
     * Prefers list item movement so rows stay valid list HTML.
     */
    getDraggableUnitFromHandle: function (handle) {
      return handle.closest('li') || handle.closest('.issue-block');
    },

    /**
     * Resolve target unit for drop calculations.
     */
    getDropTargetUnit: function (node) {
      if (!node) {
        return null;
      }
      return node.closest('li') || node.closest('.issue-block');
    },

    /**
     * Remove empty lists after cross-list drag and drop.
     */
    removeEmptyLists: function () {
      document.querySelectorAll('#meta-issue-editor-content ul, #meta-issue-editor-content ol').forEach(list => {
        const hasListItems = Array.from(list.children).some(child => child.tagName === 'LI');
        if (!hasListItems) {
          list.remove();
        }
      });
    },

  };

})(Drupal, drupalSettings);
