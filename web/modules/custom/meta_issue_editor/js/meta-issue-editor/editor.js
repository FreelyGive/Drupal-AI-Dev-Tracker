/**
 * @file
 * Main editor initialization for Meta-Issue-Editor.
 *
 * This editor intentionally uses a contenteditable-based implementation
 * with issue-block enhancements. TipTap is not loaded in production for
 * this feature to avoid runtime CDN/module compatibility failures.
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
     * Current draft node id (if loaded/saved).
     */
    currentDraftNid: null,

    /**
     * Local storage key for the last loaded issue number.
     */
    lastIssueStorageKey: 'metaIssueEditor.lastLoadedIssue',

    /**
     * Initialize the editor.
     */
    init: function (context) {
      const wrapper = once('meta-issue-editor', '.meta-issue-editor-wrapper', context);
      if (!wrapper.length) return;

      this.settings = drupalSettings.metaIssueEditor || {};
      this.sourceIssue = parseInt(this.settings.issueNumber, 10) || null;
      this.currentDraftNid = parseInt(this.settings.draft?.nid, 10) || null;

      // Initialize editor content area
      this.initEditorArea();

      // Set up event listeners
      this.setupEventListeners();
      this.updateDraftViewLink(
        this.settings.draft?.published && this.currentDraftNid
          ? this.getDraftViewUrl(this.currentDraftNid)
          : null
      );

      // Initialize drag-and-drop for issue blocks
      Drupal.metaIssueBlock.initDragDrop();

      const rememberedIssue = this.getRememberedIssueNumber();
      const forceFresh = new URLSearchParams(window.location.search).get('fresh') === '1';

      // Path issue number takes precedence over remembered issue number.
      if (this.sourceIssue) {
        this.syncLoadedIssue(this.sourceIssue);
        if (this.settings.draft && !forceFresh) {
          this.loadDraftContent(this.settings.draft);
        }
        else {
          this.loadMetaIssueByNumber(this.sourceIssue, false);
        }
      }
      else if (rememberedIssue) {
        this.loadMetaIssueByNumber(rememberedIssue, true);
      }
    },

    /**
     * Keep issue state in sync across input field, URL, and local storage.
     *
     * @param {number} issueNumber - Issue number being loaded.
     * @param {boolean} updateUrl - Whether to update the browser URL.
     */
    syncLoadedIssue: function (issueNumber, updateUrl = true) {
      const changedIssue = this.sourceIssue !== issueNumber;
      this.sourceIssue = issueNumber;
      if (changedIssue) {
        this.currentDraftNid = null;
        this.updateDraftViewLink(null);
      }

      const input = document.getElementById('issue-number-input');
      if (input) {
        input.value = issueNumber;
      }

      this.rememberIssueNumber(issueNumber);

      if (updateUrl && window.history && window.history.replaceState) {
        const targetPath = '/ai-dashboard/meta-issue-editor/' + issueNumber;
        if (window.location.pathname !== targetPath) {
          window.history.replaceState({}, '', targetPath);
        }
      }
    },

    /**
     * Save the issue number in local storage.
     *
     * @param {number} issueNumber - Issue number to remember.
     */
    rememberIssueNumber: function (issueNumber) {
      try {
        window.localStorage.setItem(this.lastIssueStorageKey, String(issueNumber));
      }
      catch (e) {
        // Ignore storage errors in private browsing or restricted contexts.
      }
    },

    /**
     * Read the last issue number from local storage.
     *
     * @returns {number|null} - Last remembered issue number.
     */
    getRememberedIssueNumber: function () {
      try {
        const stored = parseInt(window.localStorage.getItem(this.lastIssueStorageKey), 10);
        if (stored >= 10000) {
          return stored;
        }
      }
      catch (e) {
        // Ignore storage errors in private browsing or restricted contexts.
      }
      return null;
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

      // List row controls are the primary UX for indentation and new bullets.
      editorEl.addEventListener('click', (e) => {
        const addBulletBtn = e.target.closest('.list-add-bullet-btn');
        if (addBulletBtn) {
          const listItem = addBulletBtn.closest('li');
          if (!listItem) {
            return;
          }

          e.preventDefault();
          e.stopPropagation();
          this.insertEmptyBulletAfter(listItem);
          return;
        }

        const indentBtn = e.target.closest('.list-indent-btn');
        if (!indentBtn) {
          return;
        }

        const listItem = indentBtn.closest('li');
        if (!listItem) {
          return;
        }

        e.preventDefault();
        e.stopPropagation();
        this.applyIndentForListItem(listItem, indentBtn.classList.contains('outdent'));
      });

      // Enter on an issue row should create a new editable bullet below.
      editorEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') {
          return;
        }

        const selection = window.getSelection();
        let node = selection?.anchorNode;
        if (!node) {
          return;
        }

        if (node.nodeType === Node.TEXT_NODE) {
          node = node.parentElement;
        }

        const issueBlock = node?.closest('.issue-block');
        const listItem = issueBlock?.closest('li');
        if (!listItem) {
          return;
        }

        e.preventDefault();
        this.insertEmptyBulletAfter(listItem);
      });

      // Native list merge/split operations can displace editor controls.
      // Re-normalize quickly after key-driven mutations.
      editorEl.addEventListener('keyup', (e) => {
        if (!['Backspace', 'Delete', 'Enter'].includes(e.key)) {
          return;
        }
        this.normalizeIssueListItems(editorEl);
        this.ensureListItemDragHandles(editorEl);
      });
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

      const publishBtn = document.getElementById('publish-draft-btn');
      if (publishBtn) {
        publishBtn.addEventListener('click', () => this.publishDraft());
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

      const insertIssueBtn = document.getElementById('insert-issue-btn');
      if (insertIssueBtn) {
        insertIssueBtn.addEventListener('click', () => this.insertIssueFromToolbar());
      }

      const insertIssueInput = document.getElementById('insert-issue-number-input');
      if (insertIssueInput) {
        insertIssueInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            this.insertIssueFromToolbar();
          }
        });
      }

      const insertBulletBtn = document.getElementById('insert-bullet-btn');
      if (insertBulletBtn) {
        insertBulletBtn.addEventListener('click', () => this.insertBulletFromToolbar());
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

      document.querySelectorAll('[data-format], [data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
          const action = btn.dataset.action;
          if (action === 'create-link') {
            const url = prompt('Enter link URL:');
            if (!url) {
              return;
            }
            document.execCommand('createLink', false, url);
            editorEl.focus();
            return;
          }

          const format = btn.dataset.format;
          if (!format) {
            return;
          }
          let value = btn.dataset.value || null;

          // formatBlock requires the tag wrapped in angle brackets.
          if (format === 'formatBlock' && value) {
            value = '<' + value + '>';
          }

          document.execCommand(format, false, value);
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
      this.loadMetaIssueByNumber(issueNumber, true);
    },

    /**
     * Load a meta-issue by issue number.
     *
     * @param {number} issueNumber - Issue number to load.
     * @param {boolean} updateUrl - Whether to update browser URL.
     */
    loadMetaIssueByNumber: function (issueNumber, updateUrl = true) {
      if (!issueNumber || issueNumber < 10000) {
        alert('Please enter a valid issue number');
        return;
      }

      this.syncLoadedIssue(issueNumber, updateUrl);
      const forceFresh = new URLSearchParams(window.location.search).get('fresh') === '1';

      // First check if we have a local draft (for authorized users).
      if (this.settings.canLoadDraft && !forceFresh) {
        Drupal.metaIssueEditorApi.loadDraft(issueNumber)
          .then(result => {
            if (result.success && result.draft) {
              this.loadDraftContent(result.draft);
            } else {
              // No draft, fetch from drupal.org.
              this.fetchAndLoadMetaIssue(issueNumber);
            }
          })
          .catch(() => {
            this.fetchAndLoadMetaIssue(issueNumber);
          });
        return;
      }

      this.fetchAndLoadMetaIssue(issueNumber);
    },

    /**
     * Insert a specific issue into the current list position.
     */
    insertIssueFromToolbar: function () {
      const input = document.getElementById('insert-issue-number-input');
      const includeAssigned = !!document.getElementById('insert-include-assigned')?.checked;
      const issueNumber = parseInt(input?.value, 10);
      if (!issueNumber || issueNumber < 10000) {
        alert('Please enter a valid issue number to insert');
        return;
      }

      const statusEl = document.getElementById('editor-status');
      if (statusEl) {
        statusEl.textContent = 'Loading issue #' + issueNumber + '...';
      }

      this.ensureIssueData(issueNumber)
        .then(issueData => {
          const inlineAssignee = includeAssigned && issueData?.assigned ? issueData.assigned : '';
          this.insertIssueAtCursor(issueNumber, issueData, inlineAssignee);

          if (statusEl) {
            statusEl.textContent = 'Inserted issue #' + issueNumber;
          }

          if (input) {
            input.value = '';
            input.focus();
          }
        })
        .catch(err => {
          // Keep editing flow unblocked: insert as unknown if lookup fails.
          this.insertIssueAtCursor(issueNumber, null, '');
          if (statusEl) {
            statusEl.textContent = 'Inserted issue #' + issueNumber + ' (lookup failed: ' + err.message + ')';
          }
        });
    },

    /**
     * Ensure issue data is available before insertion.
     *
     * @param {number} issueNumber - Issue number.
     * @returns {Promise<object|null>} - Issue data if found.
     */
    ensureIssueData: function (issueNumber) {
      if (Drupal.metaIssueBlock.issueCache[issueNumber]) {
        return Promise.resolve(Drupal.metaIssueBlock.issueCache[issueNumber]);
      }

      return Drupal.metaIssueEditorApi.fetchLocalIssues([issueNumber])
        .then(result => {
          const localIssue = result?.issues?.[issueNumber] || result?.issues?.[String(issueNumber)] || null;
          if (localIssue) {
            Drupal.metaIssueBlock.issueCache[issueNumber] = localIssue;
            return localIssue;
          }

          if (!this.settings.canFetch) {
            return null;
          }

          return Drupal.metaIssueEditorApi.fetchFromDrupalOrg([issueNumber]).then(fetchResult => {
            const fetchedIssue = fetchResult?.issues?.[issueNumber] || fetchResult?.issues?.[String(issueNumber)] || null;
            if (fetchedIssue) {
              Drupal.metaIssueBlock.issueCache[issueNumber] = fetchedIssue;
            }
            return fetchedIssue;
          });
        });
    },

    /**
     * Insert an issue block as a new bullet near the current selection.
     *
     * @param {number} issueNumber - Issue number.
     * @param {object|null} issueData - Issue data (optional).
     * @param {string} inlineAssignee - Optional inline assignee username.
     */
    insertIssueAtCursor: function (issueNumber, issueData, inlineAssignee = '') {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) {
        return;
      }

      const targetListItem = this.getClosestListItemFromSelection(editorEl);
      const targetList = targetListItem ? this.getParentListElement(targetListItem) : this.getClosestListFromSelection(editorEl);

      const listItem = document.createElement('li');
      const block = Drupal.metaIssueBlock.createBlock(issueNumber, issueData, '', {
        inlineAssignee,
      });
      listItem.appendChild(block);

      if (targetListItem && targetList) {
        if (targetListItem.nextSibling) {
          targetList.insertBefore(listItem, targetListItem.nextSibling);
        }
        else {
          targetList.appendChild(listItem);
        }
      }
      else if (targetList) {
        targetList.appendChild(listItem);
      }
      else {
        const newList = document.createElement('ul');
        newList.appendChild(listItem);
        editorEl.appendChild(newList);
      }

      this.normalizeIssueListItems(editorEl);
      this.ensureListItemDragHandles(editorEl);
      editorEl.dispatchEvent(new Event('input', { bubbles: true }));
    },

    /**
     * Fetch meta-issue from drupal.org and load.
     */
    fetchAndLoadMetaIssue: function (issueNumber) {
      const statusEl = document.getElementById('editor-status');

      // Check if user can fetch from drupal.org
      if (!this.settings.canFetch) {
        if (statusEl) {
          statusEl.textContent = 'Loading from drupal.org is disabled for this context.';
        }
        return;
      }

      if (statusEl) statusEl.textContent = 'Loading issue from drupal.org...';

      Drupal.metaIssueEditorApi.fetchMetaIssueBody(issueNumber)
        .then(body => {
          const editorEl = document.getElementById('meta-issue-editor-content');
          if (editorEl) {
            // Clean the imported HTML to match drupal.org edit format
            const cleanedHtml = this.cleanImportedHtml(body);
            editorEl.innerHTML = cleanedHtml;
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
      const statusEl = document.getElementById('editor-status');
      if (!editorEl) return;
      this.currentDraftNid = parseInt(draft.nid, 10) || this.currentDraftNid;
      this.updateDraftViewLink(draft.published ? this.getDraftViewUrl(this.currentDraftNid) : null);

      // Parse editor content (handle both key names for compatibility)
      const editorContentRaw = draft.editor_content || draft.content;
      if (!editorContentRaw) {
        if (statusEl) statusEl.textContent = 'Draft is empty';
        return;
      }

      try {
        const content = JSON.parse(editorContentRaw);
        editorEl.innerHTML = content.html || '';
      } catch (e) {
        console.error('Failed to parse draft content:', e);
        if (statusEl) statusEl.textContent = 'Failed to load draft: invalid content format';
        return;
      }

      // Restore issue cache (handle both key names for compatibility)
      const issueCacheRaw = draft.issueCache || draft.issue_cache;
      if (issueCacheRaw) {
        try {
          const cache = JSON.parse(issueCacheRaw);
          Object.assign(Drupal.metaIssueBlock.issueCache, cache);
        } catch (e) {
          console.error('Failed to parse issue cache:', e);
          // Non-fatal - continue without cache
        }
      }

      // Process to render issue blocks
      this.processContentForIssues();

      if (statusEl) statusEl.textContent = 'Draft loaded';
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
        this.normalizeIssueListItems(editorEl);
        this.ensureListItemDragHandles(editorEl);
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
      this.normalizeIssueListItems(editorEl);
      this.ensureListItemDragHandles(editorEl);
    },

    /**
     * Normalize list items that contain only issue blocks.
     *
     * This removes default bullets/padding from issue-only lists so imported
     * meta-issues stay compact and closer to drupal.org's visual rhythm.
     *
     * @param {HTMLElement} editorEl - Editor element.
     */
    normalizeIssueListItems: function (editorEl) {
      editorEl.querySelectorAll('ul, ol').forEach(list => {
        const listItems = Array.from(list.children).filter(el => el.tagName === 'LI');
        if (!listItems.length) {
          list.classList.remove('issue-list-blocks');
          return;
        }

        let issueRowCount = 0;
        listItems.forEach(li => {
          const significantNodes = Array.from(li.childNodes).filter(node => {
            if (node.nodeType === Node.TEXT_NODE) {
              return node.textContent.replace(/\u00a0/g, ' ').trim() !== '';
            }
            if (node.nodeType === Node.ELEMENT_NODE) {
              if (node.tagName === 'BR') {
                return false;
              }
              if (node.classList?.contains('list-item-drag-handle')) {
                return false;
              }
              if (node.classList?.contains('list-indent-controls')) {
                return false;
              }
            }
            return true;
          });

          const isIssueOnlyRow = significantNodes.length === 1 &&
            significantNodes[0].nodeType === Node.ELEMENT_NODE &&
            significantNodes[0].classList.contains('issue-block');

          if (isIssueOnlyRow) {
            li.classList.add('issue-list-item');
            issueRowCount++;
          }
          else {
            li.classList.remove('issue-list-item');
          }
        });

        if (issueRowCount > 0 && issueRowCount === listItems.length) {
          list.classList.add('issue-list-blocks');
        }
        else {
          list.classList.remove('issue-list-blocks');
        }
      });
    },

    /**
     * Add drag handles to regular list items (non-issue rows).
     *
     * @param {HTMLElement} editorEl - Editor element.
     */
    ensureListItemDragHandles: function (editorEl) {
      editorEl.querySelectorAll('li').forEach(li => {
        const hasIssueHandle = !!li.querySelector('.issue-block-drag-handle');
        const directHandles = Array.from(li.children).filter(child => child.classList?.contains('list-item-drag-handle'));
        const allHandles = Array.from(li.querySelectorAll('.list-item-drag-handle'));
        const directControls = Array.from(li.children).filter(child => child.classList?.contains('list-indent-controls'));
        const allControls = Array.from(li.querySelectorAll('.list-indent-controls'));
        const header = li.querySelector('.issue-block-header');

        if (hasIssueHandle) {
          // Issue rows use issue-block drag handle only.
          allHandles.forEach(handle => handle.remove());

          // Issue rows should only keep controls in the issue header.
          allControls.forEach(control => {
            if (!header || control.parentElement !== header) {
              control.remove();
            }
          });

          const issueHandle = header?.querySelector('.issue-block-drag-handle');
          const headerControls = header
            ? Array.from(header.children).filter(child => child.classList?.contains('list-indent-controls'))
            : [];

          let primaryHeaderControls = headerControls[0] || null;
          if (!primaryHeaderControls && header && issueHandle) {
            primaryHeaderControls = this.createIndentControls();
            header.insertBefore(primaryHeaderControls, issueHandle.nextSibling);
          }

          if (headerControls.length > 1) {
            headerControls.slice(1).forEach(control => control.remove());
          }

          if (header && issueHandle && primaryHeaderControls && primaryHeaderControls.previousElementSibling !== issueHandle) {
            header.insertBefore(primaryHeaderControls, issueHandle.nextSibling);
          }

          return;
        }

        // Non-issue rows should not keep controls nested in prior header markup.
        li.querySelectorAll('.issue-block-header > .list-indent-controls').forEach(control => control.remove());

        // Keep controls/handle only as direct li children.
        allHandles.forEach(handle => {
          if (handle.parentElement !== li) {
            handle.remove();
          }
        });
        allControls.forEach(control => {
          if (control.parentElement !== li) {
            control.remove();
          }
        });

        let primaryControls = directControls[0] || null;
        if (!primaryControls) {
          primaryControls = this.createIndentControls();
          li.insertBefore(primaryControls, li.firstChild);
        }
        if (directControls.length > 1) {
          directControls.slice(1).forEach(control => control.remove());
        }

        let primaryHandle = directHandles[0] || null;
        if (!primaryHandle) {
          primaryHandle = this.createListItemDragHandle();
          li.insertBefore(primaryHandle, primaryControls.nextSibling);
        }
        if (directHandles.length > 1) {
          directHandles.slice(1).forEach(handle => handle.remove());
        }

        // Keep deterministic order so controls don't drift into text content.
        if (li.firstChild !== primaryControls) {
          li.insertBefore(primaryControls, li.firstChild);
        }
        if (primaryControls.nextSibling !== primaryHandle) {
          li.insertBefore(primaryHandle, primaryControls.nextSibling);
        }
      });
    },

    /**
     * Create a drag handle for normal list items.
     *
     * @returns {HTMLElement} - Drag handle element.
     */
    createListItemDragHandle: function () {
      const handle = document.createElement('span');
      handle.className = 'list-item-drag-handle';
      handle.setAttribute('draggable', 'true');
      handle.setAttribute('contenteditable', 'false');
      handle.setAttribute('title', 'Drag bullet to reorder');
      handle.setAttribute('aria-label', 'Drag bullet');
      handle.textContent = '⋮⋮';
      return handle;
    },

    /**
     * Create reusable indent control buttons.
     *
     * @returns {HTMLElement} - Controls container.
     */
    createIndentControls: function () {
      const controls = document.createElement('span');
      controls.className = 'list-indent-controls';
      controls.setAttribute('contenteditable', 'false');

      const outdentBtn = document.createElement('button');
      outdentBtn.type = 'button';
      outdentBtn.className = 'list-indent-btn outdent';
      outdentBtn.title = 'Outdent';
      outdentBtn.setAttribute('aria-label', 'Outdent bullet');
      outdentBtn.textContent = '◀';

      const indentBtn = document.createElement('button');
      indentBtn.type = 'button';
      indentBtn.className = 'list-indent-btn indent';
      indentBtn.title = 'Indent';
      indentBtn.setAttribute('aria-label', 'Indent bullet');
      indentBtn.textContent = '▶';

      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'list-add-bullet-btn';
      addBtn.title = 'Add bullet below';
      addBtn.setAttribute('aria-label', 'Add bullet below');
      addBtn.textContent = '+';

      controls.appendChild(outdentBtn);
      controls.appendChild(indentBtn);
      controls.appendChild(addBtn);
      return controls;
    },

    /**
     * Insert a new empty bullet from toolbar context.
     */
    insertBulletFromToolbar: function () {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) {
        return;
      }

      const listItem = this.getClosestListItemFromSelection(editorEl);
      if (listItem) {
        this.insertEmptyBulletAfter(listItem);
        return;
      }

      const list = this.getClosestListFromSelection(editorEl);
      if (list) {
        const newItem = document.createElement('li');
        newItem.appendChild(document.createElement('br'));
        list.appendChild(newItem);
        this.normalizeIssueListItems(editorEl);
        this.ensureListItemDragHandles(editorEl);
        this.placeCaretInListItem(newItem);
        editorEl.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }

      const newList = document.createElement('ul');
      const newItem = document.createElement('li');
      newItem.appendChild(document.createElement('br'));
      newList.appendChild(newItem);
      editorEl.appendChild(newList);
      this.normalizeIssueListItems(editorEl);
      this.ensureListItemDragHandles(editorEl);
      this.placeCaretInListItem(newItem);
      editorEl.dispatchEvent(new Event('input', { bubbles: true }));
    },

    /**
     * Insert a new empty list item directly after a given item.
     *
     * @param {HTMLElement} listItem - Existing list item.
     */
    insertEmptyBulletAfter: function (listItem) {
      const editorEl = document.getElementById('meta-issue-editor-content');
      const parentList = this.getParentListElement(listItem);
      if (!editorEl || !parentList) {
        return;
      }

      const newItem = document.createElement('li');
      newItem.appendChild(document.createElement('br'));

      if (listItem.nextSibling) {
        parentList.insertBefore(newItem, listItem.nextSibling);
      }
      else {
        parentList.appendChild(newItem);
      }

      this.normalizeIssueListItems(editorEl);
      this.ensureListItemDragHandles(editorEl);
      this.placeCaretInListItem(newItem);
      editorEl.dispatchEvent(new Event('input', { bubbles: true }));
    },

    /**
     * Apply indent/outdent command for a specific list item.
     *
     * @param {HTMLElement} listItem - Target list item.
     * @param {boolean} outdent - TRUE to outdent, FALSE to indent.
     */
    applyIndentForListItem: function (listItem, outdent = false) {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl || !listItem || !editorEl.contains(listItem)) {
        return;
      }

      const moved = outdent
        ? this.outdentListItem(listItem)
        : this.indentListItem(listItem);

      if (!moved) {
        return;
      }

      this.normalizeIssueListItems(editorEl);
      this.ensureListItemDragHandles(editorEl);
      this.placeCaretInListItem(listItem);
      editorEl.dispatchEvent(new Event('input', { bubbles: true }));
    },

    /**
     * Indent a list item into the previous sibling list item.
     *
     * @param {HTMLElement} listItem - Target list item.
     * @returns {boolean} - TRUE if moved.
     */
    indentListItem: function (listItem) {
      const currentList = this.getParentListElement(listItem);
      if (!currentList) {
        return false;
      }

      const previousItem = listItem.previousElementSibling;
      if (!previousItem || previousItem.tagName !== 'LI') {
        return false;
      }

      let nestedList = null;
      for (let i = previousItem.children.length - 1; i >= 0; i--) {
        const child = previousItem.children[i];
        if (child.tagName === currentList.tagName) {
          nestedList = child;
          break;
        }
      }

      if (!nestedList) {
        nestedList = document.createElement(currentList.tagName.toLowerCase());
        previousItem.appendChild(nestedList);
      }

      nestedList.appendChild(listItem);
      this.removeListIfEmpty(currentList);
      return true;
    },

    /**
     * Outdent a list item one level up.
     *
     * @param {HTMLElement} listItem - Target list item.
     * @returns {boolean} - TRUE if moved.
     */
    outdentListItem: function (listItem) {
      const currentList = this.getParentListElement(listItem);
      if (!currentList) {
        return false;
      }

      const parentItem = currentList.parentElement;
      if (!parentItem || parentItem.tagName !== 'LI') {
        return false;
      }

      const parentList = this.getParentListElement(parentItem);
      if (!parentList) {
        return false;
      }

      if (parentItem.nextSibling) {
        parentList.insertBefore(listItem, parentItem.nextSibling);
      }
      else {
        parentList.appendChild(listItem);
      }

      this.removeListIfEmpty(currentList);
      return true;
    },

    /**
     * Get the parent list element for a list item.
     *
     * @param {HTMLElement} listItem - List item.
     * @returns {HTMLElement|null} - UL/OL element or null.
     */
    getParentListElement: function (listItem) {
      const parent = listItem?.parentElement;
      if (!parent) {
        return null;
      }

      if (parent.tagName === 'UL' || parent.tagName === 'OL') {
        return parent;
      }

      return null;
    },

    /**
     * Remove a list element when it no longer has list-item children.
     *
     * @param {HTMLElement} listEl - List element.
     */
    removeListIfEmpty: function (listEl) {
      if (!listEl) {
        return;
      }

      const hasListItems = Array.from(listEl.children).some(child => child.tagName === 'LI');
      if (!hasListItems) {
        listEl.remove();
      }
    },

    /**
     * Place caret at start of a list item after structural moves.
     *
     * @param {HTMLElement} listItem - Target list item.
     */
    placeCaretInListItem: function (listItem) {
      const selection = window.getSelection();
      if (!selection || !listItem) {
        return;
      }
      const isIssueOnlyRow = !!listItem.querySelector(':scope > .issue-block');

      const range = document.createRange();
      const firstEditableNode = Array.from(listItem.childNodes).find(node => {
        if (node.nodeType === Node.TEXT_NODE) {
          return true;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
          return false;
        }

        if (
          node.classList?.contains('list-indent-controls') ||
          node.classList?.contains('list-item-drag-handle') ||
          node.classList?.contains('issue-block')
        ) {
          return false;
        }

        return true;
      });

      if (firstEditableNode?.nodeType === Node.TEXT_NODE) {
        range.setStart(firstEditableNode, firstEditableNode.textContent.length);
      }
      else if (firstEditableNode?.nodeType === Node.ELEMENT_NODE && firstEditableNode.tagName === 'BR') {
        const textNode = document.createTextNode('');
        listItem.insertBefore(textNode, firstEditableNode);
        range.setStart(textNode, 0);
      }
      else if (firstEditableNode) {
        range.setStart(firstEditableNode, 0);
      }
      else {
        if (isIssueOnlyRow) {
          return;
        }
        const textNode = document.createTextNode('');
        listItem.appendChild(textNode);
        range.setStart(textNode, 0);
      }

      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    },

    /**
     * Find closest list item for current selection.
     *
     * @param {HTMLElement} editorEl - Editor element.
     * @returns {HTMLElement|null} - Closest LI or null.
     */
    getClosestListItemFromSelection: function (editorEl) {
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0) {
        return null;
      }

      let node = selection.anchorNode;
      if (!node) {
        return null;
      }

      if (node.nodeType === Node.TEXT_NODE) {
        node = node.parentElement;
      }

      if (!node || !editorEl.contains(node)) {
        return null;
      }

      return node.closest('li');
    },

    /**
     * Find closest list element for current selection.
     *
     * @param {HTMLElement} editorEl - Editor element.
     * @returns {HTMLElement|null} - Closest UL/OL element or null.
     */
    getClosestListFromSelection: function (editorEl) {
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0) {
        return null;
      }

      let node = selection.anchorNode;
      if (!node) {
        return null;
      }

      if (node.nodeType === Node.TEXT_NODE) {
        node = node.parentElement;
      }

      if (!node || !editorEl.contains(node)) {
        return null;
      }

      return node.closest('ul, ol');
    },

    /**
     * Check for issue patterns being typed.
     */
    checkForIssuePatterns: function () {
      const editorEl = document.getElementById('meta-issue-editor-content');
      if (editorEl) {
        this.normalizeIssueListItems(editorEl);
        this.ensureListItemDragHandles(editorEl);
      }
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
        return Promise.resolve(null);
      }

      if (!this.settings.canSave) {
        alert('You do not have permission to save drafts');
        return Promise.resolve(null);
      }

      const editorEl = document.getElementById('meta-issue-editor-content');
      if (!editorEl) {
        return Promise.resolve(null);
      }

      const statusEl = document.getElementById('editor-status');
      if (statusEl) statusEl.textContent = 'Saving...';

      const content = {
        html: editorEl.innerHTML,
        notes: Drupal.metaIssueBlock.getAllNotes(),
      };

      return Drupal.metaIssueEditorApi.saveDraft(
        this.sourceIssue,
        JSON.stringify(content),
        JSON.stringify(Drupal.metaIssueBlock.issueCache)
      )
        .then(result => {
          if (result.success) {
            this.currentDraftNid = parseInt(result.nid, 10) || this.currentDraftNid;
            if (statusEl) statusEl.textContent = 'Draft saved';
            this.updateDraftViewLink(result.share_url || null);
            return result;
          } else {
            if (statusEl) statusEl.textContent = 'Save failed: ' + (result.error || 'Unknown error');
            return null;
          }
        })
        .catch(err => {
          if (statusEl) statusEl.textContent = 'Save failed: ' + err.message;
          return null;
        });
    },

    /**
     * Publish current draft and expose a review/share URL.
     */
    publishDraft: function () {
      if (!this.settings.canSave) {
        return;
      }

      const statusEl = document.getElementById('editor-status');
      if (statusEl) {
        statusEl.textContent = 'Publishing draft...';
      }

      const ensureDraftPromise = this.currentDraftNid
        ? Promise.resolve({ nid: this.currentDraftNid })
        : this.saveDraft();

      ensureDraftPromise
        .then(result => {
          const draftNid = parseInt(result?.nid || this.currentDraftNid, 10);
          if (!draftNid) {
            throw new Error('Please save a draft first.');
          }
          this.currentDraftNid = draftNid;
          return Drupal.metaIssueEditorApi.publishDraft(draftNid);
        })
        .then(result => {
          if (!result?.success) {
            throw new Error(result?.error || 'Publish failed');
          }

          this.updateDraftViewLink(result.share_url || this.getDraftViewUrl(this.currentDraftNid));
          if (statusEl) {
            statusEl.textContent = 'Draft published. Share link ready.';
          }
        })
        .catch(err => {
          if (statusEl) {
            statusEl.textContent = 'Publish failed: ' + err.message;
          }
        });
    },

    /**
     * Build draft review URL for a given draft nid.
     *
     * @param {number|null} draftNid - Draft node id.
     * @returns {string|null} - Draft review URL.
     */
    getDraftViewUrl: function (draftNid) {
      const nid = parseInt(draftNid, 10);
      if (!nid) {
        return null;
      }

      const base = this.settings.draftViewBasePath || '/ai-dashboard/meta-issue-editor/draft/';
      return base + nid;
    },

    /**
     * Show or hide the "View Draft" link.
     *
     * @param {string|null} url - Draft URL.
     */
    updateDraftViewLink: function (url) {
      const viewLink = document.getElementById('view-draft-link');
      if (!viewLink) {
        return;
      }

      if (!url) {
        viewLink.classList.add('is-hidden');
        viewLink.removeAttribute('href');
        return;
      }

      viewLink.href = url;
      viewLink.classList.remove('is-hidden');
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
     *
     * Converts editor content back to drupal.org edit HTML format.
     * Issue blocks become [#XXXXXX] references.
     * Output is formatted for readability in drupal.org editor source.
     */
    generateHtmlExport: function (editorEl) {
      // Clone content
      const clone = editorEl.cloneNode(true);

      // Replace issue blocks with [#XXXXXX] references
      clone.querySelectorAll('.issue-block').forEach(block => {
        const issueNum = block.dataset.issueNumber;
        const inlineAssignee = (block.dataset.inlineAssignee || '').trim();
        const issueReference = inlineAssignee
          ? '[#' + issueNum + '] @' + inlineAssignee
          : '[#' + issueNum + ']';
        block.replaceWith(document.createTextNode(issueReference));
      });

      // Remove editor-specific elements (notes, metadata panels, drag handles)
      clone.querySelectorAll('.issue-block-notes, .issue-block-metadata, .issue-block-drag-handle, .list-item-drag-handle, .list-indent-controls').forEach(el => {
        el.remove();
      });

      // Serialize to clean, human-readable HTML:
      // - no class/style/editor attributes
      // - no <p> wrappers
      // - no <br> tags (line breaks are literal newlines)
      // - one <li> per line for readable list editing
      const blocks = [];
      Array.from(clone.childNodes).forEach(node => {
        const serialized = this.serializeExportNode(node);
        if (serialized !== '') {
          blocks.push(serialized);
        }
      });

      return blocks.join('\n').replace(/\n{3,}/g, '\n\n').trim();
    },

    /**
     * Serialize a node for HTML export.
     *
     * @param {Node} node - DOM node.
     * @returns {string} - Serialized output.
     */
    serializeExportNode: function (node) {
      if (!node) {
        return '';
      }

      if (node.nodeType === Node.TEXT_NODE) {
        const text = (node.textContent || '').replace(/\u00a0/g, ' ').trim();
        return text;
      }

      if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
      }

      const tag = node.tagName.toLowerCase();

      if (tag === 'p') {
        const content = this.serializeInlineContent(node).replace(/\n{2,}/g, '\n');
        return content ? (content + '\n') : '';
      }

      if (tag === 'br') {
        return '';
      }

      if (tag.match(/^h[1-6]$/)) {
        return '<' + tag + '>' + this.serializeInlineContent(node) + '</' + tag + '>';
      }

      if (tag === 'ul' || tag === 'ol') {
        return this.serializeListForExport(node, tag, 0);
      }

      if (tag === 'blockquote' || tag === 'pre') {
        return '<' + tag + '>' + this.serializeInlineContent(node, true) + '</' + tag + '>';
      }

      // Fallback: flatten container wrappers but keep contained structure.
      const chunks = [];
      Array.from(node.childNodes).forEach(child => {
        const serialized = this.serializeExportNode(child);
        if (serialized !== '') {
          chunks.push(serialized);
        }
      });
      return chunks.join('\n');
    },

    /**
     * Serialize inline content for export-safe HTML.
     *
     * @param {HTMLElement} element - Source element.
     * @param {boolean} preserveWhitespace - Keep consecutive whitespace.
     * @returns {string} - Inline serialized HTML.
     */
    serializeInlineContent: function (element, preserveWhitespace = false) {
      const chunks = [];

      Array.from(element.childNodes).forEach(node => {
        if (node.nodeType === Node.TEXT_NODE) {
          let text = (node.textContent || '').replace(/\u00a0/g, ' ');
          if (!preserveWhitespace) {
            text = text.replace(/\s+/g, ' ');
          }
          chunks.push(text);
          return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
          return;
        }

        const tag = node.tagName.toLowerCase();

        if (tag === 'br') {
          chunks.push('\n');
          return;
        }

        if (tag === 'strong' || tag === 'b') {
          chunks.push('<strong>' + this.serializeInlineContent(node, preserveWhitespace) + '</strong>');
          return;
        }

        if (tag === 'em' || tag === 'i') {
          chunks.push('<em>' + this.serializeInlineContent(node, preserveWhitespace) + '</em>');
          return;
        }

        if (tag === 's' || tag === 'strike') {
          chunks.push('<s>' + this.serializeInlineContent(node, preserveWhitespace) + '</s>');
          return;
        }

        if (tag === 'code') {
          chunks.push('<code>' + this.serializeInlineContent(node, true) + '</code>');
          return;
        }

        if (tag === 'a') {
          const href = node.getAttribute('href') || '';
          const hrefAttr = href ? ' href="' + href.replace(/"/g, '&quot;') + '"' : '';
          chunks.push('<a' + hrefAttr + '>' + this.serializeInlineContent(node, preserveWhitespace) + '</a>');
          return;
        }

        if (tag === 'ul' || tag === 'ol') {
          // List nesting is handled in block serializer.
          return;
        }

        chunks.push(this.serializeInlineContent(node, preserveWhitespace));
      });

      let result = chunks.join('');
      if (!preserveWhitespace) {
        result = result.replace(/[ \t]*\n[ \t]*/g, '\n');
      }
      return result.trim();
    },

    /**
     * Serialize UL/OL blocks with one LI per line.
     *
     * @param {HTMLElement} listEl - UL/OL element.
     * @param {string} listTag - ul or ol.
     * @param {number} depth - Nesting depth.
     * @returns {string} - Serialized list block.
     */
    serializeListForExport: function (listEl, listTag, depth) {
      const indent = '  '.repeat(depth);
      const lines = [indent + '<' + listTag + '>'];

      Array.from(listEl.children).forEach(child => {
        if (child.tagName.toLowerCase() !== 'li') {
          return;
        }

        const nestedLists = [];
        const liClone = child.cloneNode(true);
        Array.from(liClone.querySelectorAll(':scope > ul, :scope > ol')).forEach(nested => {
          nestedLists.push(nested.cloneNode(true));
          nested.remove();
        });

        const itemInline = this.serializeInlineContent(liClone).replace(/\n+/g, '\n').trim();

        if (!nestedLists.length) {
          lines.push(indent + '  <li>' + itemInline + '</li>');
          return;
        }

        lines.push(indent + '  <li>' + itemInline);
        nestedLists.forEach(nested => {
          lines.push(this.serializeListForExport(nested, nested.tagName.toLowerCase(), depth + 2));
        });
        lines.push(indent + '  </li>');
      });

      lines.push(indent + '</' + listTag + '>');
      return lines.join('\n');
    },

    /**
     * Clean imported HTML while preserving semantic structure.
     *
     * Keeps headings/lists/paragraphs intact, only normalizes wrappers and
     * converts drupal.org issue links into [#XXXXXX] placeholders that are
     * rendered as issue blocks in the editor.
     */
    cleanImportedHtml: function (html) {
      const temp = document.createElement('div');
      temp.innerHTML = html;

      // Strip drupal field wrapper if present.
      const fieldItem = temp.querySelector('.field-item');
      if (fieldItem) {
        temp.innerHTML = fieldItem.innerHTML;
      }

      // Convert drupal.org issue link spans to [#XXXXXX].
      temp.querySelectorAll('span.project-issue-issue-link').forEach(span => {
        const link = span.querySelector('a');
        if (link) {
          const href = link.getAttribute('href') || '';
          const match = href.match(/\/issues\/(\d+)/);
          if (match) {
            span.replaceWith(document.createTextNode('[#' + match[1] + ']'));
          }
        }
      });

      // Preserve intentional blank separator lines between content blocks.
      // Convert interior empty paragraphs to <p><br></p> so they render as a
      // visible blank line in the editor instead of being collapsed away.
      temp.querySelectorAll('p').forEach(p => {
        if (p.textContent.replace(/\u00a0/g, ' ').trim() !== '') {
          return;
        }

        const prev = p.previousElementSibling;
        const next = p.nextElementSibling;
        const isInteriorSpacer = !!prev && !!next;
        if (isInteriorSpacer) {
          p.innerHTML = '<br>';
        }
        else {
          p.remove();
        }
      });

      // Remove whitespace-only text nodes between block elements.
      const pruneWhitespaceTextNodes = node => {
        Array.from(node.childNodes).forEach(child => {
          if (child.nodeType === Node.TEXT_NODE) {
            if (child.textContent.replace(/\u00a0/g, ' ').trim() === '') {
              child.remove();
            }
            return;
          }
          pruneWhitespaceTextNodes(child);
        });
      };
      pruneWhitespaceTextNodes(temp);

      return temp.innerHTML.trim();
    },

    /**
     * Generate Markdown export (with notes).
     */
    generateMarkdownExport: function (editorEl) {
      const notes = Drupal.metaIssueBlock.getAllNotes();
      let md = '';

      // Simple HTML to Markdown conversion
      const clone = editorEl.cloneNode(true);

      // Remove editor-only drag handles.
      clone.querySelectorAll('.issue-block-drag-handle, .list-item-drag-handle, .list-indent-controls').forEach(el => {
        el.remove();
      });

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
        const inlineAssignee = (block.dataset.inlineAssignee || '').trim();

        let issueText = '[#' + issueNum + ']';
        if (inlineAssignee) {
          issueText += ' @' + inlineAssignee;
        }
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

      return this.normalizeMarkdownParagraphSpacing(md);
    },

    /**
     * Normalize markdown spacing so paragraph-internal lines are not double-spaced.
     *
     * Keeps blank lines around block boundaries (headings/lists), but removes
     * accidental blank lines between plain text lines in the same paragraph.
     *
     * @param {string} md - Raw markdown content.
     * @returns {string} - Normalized markdown.
     */
    normalizeMarkdownParagraphSpacing: function (md) {
      const lines = md.split('\n');
      const normalized = [];

      for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (line.trim() !== '') {
          normalized.push(line);
          continue;
        }

        const prevLine = normalized.length ? normalized[normalized.length - 1].trim() : '';
        let nextLine = '';
        for (let j = i + 1; j < lines.length; j++) {
          if (lines[j].trim() !== '') {
            nextLine = lines[j].trim();
            break;
          }
        }

        const isHeading = text => /^#{1,6}\s/.test(text);
        const isListItem = text => /^[-*]\s/.test(text) || /^\d+\.\s/.test(text);
        const isMetaComment = text => /^<!--/.test(text);

        const prevIsBlock = isHeading(prevLine) || isListItem(prevLine) || isMetaComment(prevLine);
        const nextIsBlock = isHeading(nextLine) || isListItem(nextLine) || isMetaComment(nextLine);

        // Remove empty lines between consecutive plain-text lines.
        if (prevLine && nextLine && !prevIsBlock && !nextIsBlock) {
          continue;
        }

        // Also collapse blank lines between consecutive list items.
        if (isListItem(prevLine) && isListItem(nextLine)) {
          continue;
        }

        if (normalized[normalized.length - 1] !== '') {
          normalized.push('');
        }
      }

      return normalized.join('\n').replace(/\n{3,}/g, '\n\n').trim();
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
