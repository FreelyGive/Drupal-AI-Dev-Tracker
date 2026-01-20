/**
 * @file
 * Main editor initialization for Meta-Issue-Editor.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Meta-Issue-Editor main functionality.
   */
  Drupal.metaIssueEditor = {

    /**
     * The TipTap editor instance.
     */
    editor: null,

    /**
     * Current source issue number.
     */
    sourceIssue: null,

    /**
     * Settings from Drupal.
     */
    settings: null,

    /**
     * Initialize the editor.
     */
    init: function (context) {
      const wrapper = once('meta-issue-editor', '.meta-issue-editor-wrapper', context);
      if (!wrapper.length) return;

      this.settings = drupalSettings.metaIssueEditor || {};
      this.sourceIssue = this.settings.issueNumber || null;

      // Initialize editor content area
      this.initEditorArea();

      // Set up event listeners
      this.setupEventListeners();

      // Load existing draft if available
      if (this.settings.draft) {
        this.loadDraftContent(this.settings.draft);
      }
    },

    /**
     * Initialize the editor content area.
     */
    initEditorArea: function () {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      // For now, use a contenteditable div since TipTap CDN loading is complex
      // In production, this would be replaced with proper TipTap initialization
      editorEl.setAttribute('contenteditable', 'true');
      editorEl.classList.add('ProseMirror');

      // Handle paste to detect issue references
      editorEl.addEventListener('paste', (e) => {
        // Allow default paste, then process for issues
        setTimeout(() => this.processContentForIssues(), 100);
      });

      // Handle input to detect issue references
      editorEl.addEventListener('input', Drupal.debounce(() => {
        this.checkForIssuePatterns();
      }, 500));
    },

    /**
     * Set up event listeners for toolbar buttons.
     */
    setupEventListeners: function () {
      // Load button
      const loadBtn = document.getElementById('load-issue-btn');
      if (loadBtn) {
        loadBtn.addEventListener('click', () => this.loadMetaIssue());
      }

      // Save draft button
      const saveBtn = document.getElementById('save-draft-btn');
      if (saveBtn) {
        saveBtn.addEventListener('click', () => this.saveDraft());
      }

      // Export buttons
      const exportHtmlBtn = document.getElementById('export-html-btn');
      if (exportHtmlBtn) {
        exportHtmlBtn.addEventListener('click', () => this.exportContent('html'));
      }

      const exportMdBtn = document.getElementById('export-md-btn');
      if (exportMdBtn) {
        exportMdBtn.addEventListener('click', () => this.exportContent('markdown'));
      }

      // Fetch unknown issues button
      const fetchBtn = document.getElementById('fetch-unknown-btn');
      if (fetchBtn) {
        fetchBtn.addEventListener('click', () => this.fetchUnknownIssues());
      }

      // Import markdown button
      const importBtn = document.getElementById('import-md-btn');
      if (importBtn) {
        importBtn.addEventListener('click', () => this.showImportDialog());
      }

      // Format buttons
      this.setupFormatButtons();
    },

    /**
     * Setup formatting buttons.
     */
    setupFormatButtons: function () {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      document.querySelectorAll('[data-format]').forEach(btn => {
        btn.addEventListener('click', () => {
          const format = btn.dataset.format;
          document.execCommand(format, false, null);
          editorEl.focus();
        });
      });
    },

    /**
     * Load a meta-issue from drupal.org.
     */
    loadMetaIssue: function () {
      const input = document.getElementById('issue-number-input');
      const issueNumber = parseInt(input?.value, 10);

      if (!issueNumber || issueNumber < 10000) {
        alert('Please enter a valid issue number');
        return;
      }

      this.sourceIssue = issueNumber;

      // First check if we have a local draft
      Drupal.metaIssueEditorApi.loadDraft(issueNumber)
        .then(result => {
          if (result.success && result.draft) {
            this.loadDraftContent(result.draft);
          } else {
            // No draft, fetch from drupal.org
            this.fetchAndLoadMetaIssue(issueNumber);
          }
        })
        .catch(() => {
          this.fetchAndLoadMetaIssue(issueNumber);
        });
    },

    /**
     * Fetch meta-issue from drupal.org and load.
     */
    fetchAndLoadMetaIssue: function (issueNumber) {
      const statusEl = document.getElementById('editor-status');
      if (statusEl) statusEl.textContent = 'Loading issue from drupal.org...';

      Drupal.metaIssueEditorApi.fetchMetaIssueBody(issueNumber)
        .then(body => {
          const editorEl = document.getElementById('meta-issue-editor-content');
          if (editorEl) {
            editorEl.innerHTML = body;
            this.processContentForIssues();
          }
          if (statusEl) statusEl.textContent = 'Loaded issue #' + issueNumber;
        })
        .catch(err => {
          if (statusEl) statusEl.textContent = 'Failed to load issue: ' + err.message;
        });
    },

    /**
     * Load draft content into editor.
     */
    loadDraftContent: function (draft) {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      try {
        const content = JSON.parse(draft.editor_content || draft.content);
        editorEl.innerHTML = content.html || '';

        // Restore issue cache
        if (draft.issue_cache) {
          const cache = JSON.parse(draft.issue_cache);
          Object.assign(Drupal.metaIssueBlock.issueCache, cache);
        }

        // Process to render issue blocks
        this.processContentForIssues();

        const statusEl = document.getElementById('editor-status');
        if (statusEl) statusEl.textContent = 'Draft loaded';
      } catch (e) {
        console.error('Failed to load draft:', e);
      }
    },

    /**
     * Process content to find and render issue blocks.
     */
    processContentForIssues: function () {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      // Find all issue references in the content
      const issuePattern = /\[#(\d{5,8})\]/g;
      const issues = [];
      let match;

      const html = editorEl.innerHTML;
      while ((match = issuePattern.exec(html)) !== null) {
        issues.push(parseInt(match[1], 10));
      }

      if (issues.length === 0) {
        this.updateUnknownCount();
        return;
      }

      const uniqueIssues = [...new Set(issues)];

      // Fetch local data first
      Drupal.metaIssueEditorApi.fetchLocalIssues(uniqueIssues)
        .then(result => {
          // Update cache with local data
          if (result.issues) {
            Object.assign(Drupal.metaIssueBlock.issueCache, result.issues);
          }

          // Replace issue references with blocks
          this.replaceIssueReferencesWithBlocks(editorEl);
          this.updateUnknownCount();
        })
        .catch(err => {
          console.error('Failed to fetch local issues:', err);
          this.replaceIssueReferencesWithBlocks(editorEl);
          this.updateUnknownCount();
        });
    },

    /**
     * Replace issue reference text with rendered blocks.
     */
    replaceIssueReferencesWithBlocks: function (editorEl) {
      const html = editorEl.innerHTML;

      // Replace [#XXXXXX] with issue blocks
      const newHtml = html.replace(/\[#(\d{5,8})\]/g, (match, issueNum) => {
        const num = parseInt(issueNum, 10);
        const data = Drupal.metaIssueBlock.issueCache[num];
        const block = Drupal.metaIssueBlock.createBlock(num, data);
        return block.outerHTML;
      });

      editorEl.innerHTML = newHtml;
    },

    /**
     * Check for issue patterns being typed.
     */
    checkForIssuePatterns: function () {
      // This would handle live typing of #1234567
      // For now, just update unknown count
      this.updateUnknownCount();
    },

    /**
     * Update the unknown issues counter.
     */
    updateUnknownCount: function () {
      const unknownIssues = Drupal.metaIssueBlock.getUnknownIssues();
      const countEl = document.getElementById('unknown-issues-count');
      const fetchBtn = document.getElementById('fetch-unknown-btn');

      if (countEl) {
        countEl.textContent = unknownIssues.length;
      }

      if (fetchBtn) {
        fetchBtn.disabled = unknownIssues.length === 0 || !this.settings.canFetch;
        fetchBtn.textContent = `Pull ${unknownIssues.length} Unknown Issue${unknownIssues.length !== 1 ? 's' : ''} from Drupal.org`;
      }
    },

    /**
     * Fetch unknown issues from drupal.org.
     */
    fetchUnknownIssues: function () {
      const unknownIssues = Drupal.metaIssueBlock.getUnknownIssues();
      if (unknownIssues.length === 0) return;

      const statusEl = document.getElementById('editor-status');
      if (statusEl) statusEl.textContent = 'Fetching ' + unknownIssues.length + ' issues from drupal.org...';

      Drupal.metaIssueEditorApi.fetchFromDrupalOrg(unknownIssues)
        .then(result => {
          if (result.issues) {
            Object.keys(result.issues).forEach(num => {
              Drupal.metaIssueBlock.updateBlock(num, result.issues[num]);
            });
          }

          this.updateUnknownCount();

          if (statusEl) {
            const fetched = Object.keys(result.issues || {}).length;
            const errors = Object.keys(result.errors || {}).length;
            statusEl.textContent = `Fetched ${fetched} issues` + (errors ? `, ${errors} failed` : '');
          }
        })
        .catch(err => {
          if (statusEl) statusEl.textContent = 'Failed to fetch: ' + err.message;
        });
    },

    /**
     * Save the current draft.
     */
    saveDraft: function () {
      if (!this.sourceIssue) {
        alert('Please load or specify an issue number first');
        return;
      }

      if (!this.settings.canSave) {
        alert('You do not have permission to save drafts');
        return;
      }

      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      const statusEl = document.getElementById('editor-status');
      if (statusEl) statusEl.textContent = 'Saving...';

      const content = {
        html: editorEl.innerHTML,
        notes: Drupal.metaIssueBlock.getAllNotes(),
      };

      Drupal.metaIssueEditorApi.saveDraft(
        this.sourceIssue,
        JSON.stringify(content),
        JSON.stringify(Drupal.metaIssueBlock.issueCache)
      )
        .then(result => {
          if (result.success) {
            if (statusEl) statusEl.textContent = 'Draft saved';
          } else {
            if (statusEl) statusEl.textContent = 'Save failed: ' + (result.error || 'Unknown error');
          }
        })
        .catch(err => {
          if (statusEl) statusEl.textContent = 'Save failed: ' + err.message;
        });
    },

    /**
     * Export content to specified format.
     */
    exportContent: function (format) {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      let content;
      if (format === 'html') {
        content = this.generateHtmlExport(editorEl);
      } else {
        content = this.generateMarkdownExport(editorEl);
      }

      // Open export page
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/ai-dashboard/meta-issue-editor/export/' + format;
      form.target = '_blank';

      const contentInput = document.createElement('input');
      contentInput.type = 'hidden';
      contentInput.name = 'content';
      contentInput.value = content;
      form.appendChild(contentInput);

      const issueInput = document.createElement('input');
      issueInput.type = 'hidden';
      issueInput.name = 'issue_number';
      issueInput.value = this.sourceIssue || '';
      form.appendChild(issueInput);

      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    },

    /**
     * Generate HTML export (for drupal.org).
     */
    generateHtmlExport: function (editorEl) {
      // Clone content
      const clone = editorEl.cloneNode(true);

      // Replace issue blocks with [#XXXXXX] references
      clone.querySelectorAll('.issue-block').forEach(block => {
        const issueNum = block.dataset.issueNumber;
        const text = document.createTextNode('[#' + issueNum + ']');
        block.replaceWith(text);
      });

      return clone.innerHTML;
    },

    /**
     * Generate Markdown export (with notes).
     */
    generateMarkdownExport: function (editorEl) {
      const notes = Drupal.metaIssueBlock.getAllNotes();
      let md = '';

      // Simple HTML to Markdown conversion
      const clone = editorEl.cloneNode(true);

      // Convert headings
      clone.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(h => {
        const level = parseInt(h.tagName[1], 10);
        const prefix = '#'.repeat(level) + ' ';
        h.outerHTML = '\n' + prefix + h.textContent + '\n';
      });

      // Convert issue blocks to markdown with metadata
      clone.querySelectorAll('.issue-block').forEach(block => {
        const issueNum = block.dataset.issueNumber;
        const data = Drupal.metaIssueBlock.issueCache[issueNum] || {};
        const note = notes[issueNum] || '';

        let issueText = '[#' + issueNum + ']';
        if (data.title) {
          issueText += ' ' + data.title;
        }

        let metadata = [];
        if (data.status) metadata.push('status=' + data.status);
        if (data.assigned) metadata.push('assigned=@' + data.assigned);
        if (data.update_summary) metadata.push('update_summary=' + data.update_summary);

        if (metadata.length) {
          issueText += '\n  <!-- meta: ' + metadata.join(', ') + ' -->';
        }
        if (note) {
          issueText += '\n  <!-- note: ' + note + ' -->';
        }

        block.outerHTML = issueText;
      });

      // Convert lists
      clone.querySelectorAll('ul, ol').forEach(list => {
        const items = list.querySelectorAll('li');
        items.forEach(li => {
          li.outerHTML = '- ' + li.innerHTML + '\n';
        });
        list.outerHTML = '\n' + list.innerHTML;
      });

      // Get text content
      md = clone.innerHTML
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>/gi, '\n\n')
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&')
        .replace(/\n{3,}/g, '\n\n')
        .trim();

      return md;
    },

    /**
     * Show import markdown dialog.
     */
    showImportDialog: function () {
      const md = prompt('Paste Markdown content to import:');
      if (!md) return;

      this.importMarkdown(md);
    },

    /**
     * Import markdown content.
     */
    importMarkdown: function (md) {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) return;

      // Simple Markdown to HTML conversion
      let html = md
        // Headings
        .replace(/^######\s+(.+)$/gm, '<h6>$1</h6>')
        .replace(/^#####\s+(.+)$/gm, '<h5>$1</h5>')
        .replace(/^####\s+(.+)$/gm, '<h4>$1</h4>')
        .replace(/^###\s+(.+)$/gm, '<h3>$1</h3>')
        .replace(/^##\s+(.+)$/gm, '<h2>$1</h2>')
        .replace(/^#\s+(.+)$/gm, '<h1>$1</h1>')
        // Bold
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        // Italic
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        // Lists
        .replace(/^-\s+(.+)$/gm, '<li>$1</li>')
        // Paragraphs
        .replace(/\n\n/g, '</p><p>')
        // Line breaks
        .replace(/\n/g, '<br>');

      html = '<p>' + html + '</p>';
      html = html.replace(/<li>/g, '</p><ul><li>').replace(/<\/li>(?![\s\S]*<li>)/g, '</li></ul><p>');

      editorEl.innerHTML = html;
      this.processContentForIssues();

      const statusEl = document.getElementById('editor-status');
      if (statusEl) statusEl.textContent = 'Markdown imported';
    },

  };

  /**
   * Drupal behavior.
   */
  Drupal.behaviors.metaIssueEditor = {
    attach: function (context) {
      Drupal.metaIssueEditor.init(context);
    }
  };

})(Drupal, drupalSettings, once);
