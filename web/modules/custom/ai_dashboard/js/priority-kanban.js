/**
 * @file
 * JavaScript for the AI Priority Kanban board.
 * 
 * Handles:
 * - Project and tag filter persistence and submission
 * - Column visibility toggles
 * - Clear filters functionality
 * - Respects server-side default project selection
 */

(function ($, Drupal, once) {
  Drupal.behaviors.aiPriorityKanban = {
    attach: function (context, settings) {
      const kanbanBoard = once('ai-priority-kanban', '.ai-priority-kanban', context);

      if (kanbanBoard.length) {
        // Handle advanced filters toggle with localStorage persistence
        const advancedBtn = document.getElementById('advanced-filters-btn');
        const advancedSection = document.getElementById('kanban-advanced-filters');

        if (advancedBtn && advancedSection) {
          // Load saved state from localStorage
          const savedState = localStorage.getItem('aiDashboard.kanban.advancedVisible');
          const isVisible = savedState === 'true'; // Default to hidden

          // Apply saved state
          advancedSection.style.display = isVisible ? 'flex' : 'none';
          advancedBtn.classList.toggle('active', isVisible);

          // Handle toggle click
          advancedBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isCurrentlyVisible = advancedSection.style.display !== 'none';
            const newState = !isCurrentlyVisible;

            advancedSection.style.display = newState ? 'flex' : 'none';
            advancedBtn.classList.toggle('active', newState);

            // Save state to localStorage
            localStorage.setItem('aiDashboard.kanban.advancedVisible', newState);
          });
        }
        // Auto-apply tag filter on change
        const tagForm = document.querySelector('.tag-filter-form');
        const tagSelect = document.getElementById('kanban-tag-filter');
        const projectSelect = document.getElementById('kanban-project-filter');
        
        if (tagForm && tagSelect) {
          const tagStorageKey = 'aiDashboard.kanban.selectedTag';
          const projectStorageKey = 'aiDashboard.kanban.selectedProject';

          // Ensure the selects reflect the current URL params reliably
          try {
            const params = new URLSearchParams(window.location.search);
            const urlTag = params.get('tag');
            const urlProject = params.get('project');
            
            if (urlTag) {
              // Set value if option exists; fallback handled by server
              const opt = Array.from(tagSelect.options).find(o => o.value === urlTag);
              if (opt) tagSelect.value = urlTag;
              // Persist selected tag in session storage
              try { localStorage.setItem(tagStorageKey, urlTag); } catch (e2) {}
            } else {
              // No tag in URL: try to restore last used tag
              try {
                const saved = localStorage.getItem(tagStorageKey);
                if (saved) {
                  const opt2 = Array.from(tagSelect.options).find(o => o.value === saved);
                  if (opt2) {
                    tagSelect.value = saved;
                    tagForm.submit();
                  }
                }
              } catch (e3) {}
            }
            
            // Handle project parameter - respect server's selection
            if (projectSelect) {
              if (urlProject !== null) {
                // URL has explicit project parameter (could be empty string for "All Projects")
                const opt = Array.from(projectSelect.options).find(o => o.value === urlProject);
                if (opt) projectSelect.value = urlProject;
                try { localStorage.setItem(projectStorageKey, urlProject); } catch (e2) {}
              } else {
                // No project in URL - the server may have selected a default
                // The dropdown should already have the correct selection from server-side rendering
                // Save the current selection to localStorage for consistency
                const currentValue = projectSelect.value;
                if (currentValue) {
                  try { localStorage.setItem(projectStorageKey, currentValue); } catch (e2) {}
                }
              }
            }
          } catch (e) {}

          tagSelect.addEventListener('change', () => {
            const val = tagSelect.value;
            // Persist selection except when choosing All Tags (we still save '__all' for UX consistency)
            try { localStorage.setItem(tagStorageKey, val); } catch (e4) {}
            tagForm.submit();
          });
          
          // Auto-apply project filter on change
          if (projectSelect) {
            projectSelect.addEventListener('change', () => {
              const val = projectSelect.value;
              try { localStorage.setItem(projectStorageKey, val); } catch (e4) {}
              tagForm.submit();
            });
          }
        }

        // Clear filters: reset to defaults (server will use default project)
        const clearBtn = document.getElementById('clear-kanban-filters');
        if (clearBtn) {
          clearBtn.addEventListener('click', (e) => {
            // Allow other UI resets, but ensure navigation resets to defaults
            e.preventDefault();
            try {
              localStorage.removeItem('aiDashboard.kanban.optionalColumns');
              localStorage.removeItem('aiDashboard.kanban.mainColumnsHidden');
              localStorage.removeItem('aiDashboard.kanban.selectedTag');
              localStorage.removeItem('aiDashboard.kanban.selectedProject');
              localStorage.removeItem('aiDashboard.kanban.showMetaIssues');
              // Clear multi-select filters
              localStorage.removeItem('aiDashboard.kanban.tagsMulti');
              localStorage.removeItem('aiDashboard.kanban.priority');
              localStorage.removeItem('aiDashboard.kanban.status');
              localStorage.removeItem('aiDashboard.kanban.track');
              localStorage.removeItem('aiDashboard.kanban.workstream');
            } catch (err) {}
            // Navigate without parameters - server will apply defaults
            window.location.href = '/ai-dashboard/priority-kanban';
          });
        }
        // Tags filter handled server-side via GET params.

        // Initialize advanced multi-select filters
        function initAdvancedFilters() {
          const tagsMulti = document.getElementById('kanban-tags-multi');
          const priorityFilter = document.getElementById('kanban-priority-filter');
          const statusFilter = document.getElementById('kanban-status-filter');
          const trackFilter = document.getElementById('kanban-track-filter');
          const workstreamFilter = document.getElementById('kanban-workstream-filter');

          // Load saved selections from localStorage
          function loadFilterState(filterId) {
            try {
              const saved = localStorage.getItem(`aiDashboard.kanban.${filterId}`);
              return saved ? JSON.parse(saved) : [];
            } catch (e) {
              return [];
            }
          }

          // Save selections to localStorage
          function saveFilterState(filterId, values) {
            try {
              localStorage.setItem(`aiDashboard.kanban.${filterId}`, JSON.stringify(values));
            } catch (e) {}
          }

          // Apply saved selections
          if (tagsMulti) {
            const saved = loadFilterState('tagsMulti');
            Array.from(tagsMulti.options).forEach(opt => {
              opt.selected = saved.includes(opt.value);
            });
          }
          if (priorityFilter) {
            const saved = loadFilterState('priority');
            Array.from(priorityFilter.options).forEach(opt => {
              opt.selected = saved.includes(opt.value);
            });
          }
          if (statusFilter) {
            const saved = loadFilterState('status');
            Array.from(statusFilter.options).forEach(opt => {
              opt.selected = saved.includes(opt.value);
            });
          }
          if (trackFilter) {
            const saved = loadFilterState('track');
            Array.from(trackFilter.options).forEach(opt => {
              opt.selected = saved.includes(opt.value);
            });
          }
          if (workstreamFilter) {
            const saved = loadFilterState('workstream');
            Array.from(workstreamFilter.options).forEach(opt => {
              opt.selected = saved.includes(opt.value);
            });
          }

          // Apply all filters
          function applyFilters() {
            const selectedTags = tagsMulti ? Array.from(tagsMulti.selectedOptions).map(o => o.value) : [];
            const selectedPriorities = priorityFilter ? Array.from(priorityFilter.selectedOptions).map(o => o.value) : [];
            const selectedStatuses = statusFilter ? Array.from(statusFilter.selectedOptions).map(o => o.value) : [];
            const selectedTracks = trackFilter ? Array.from(trackFilter.selectedOptions).map(o => o.value) : [];
            const selectedWorkstreams = workstreamFilter ? Array.from(workstreamFilter.selectedOptions).map(o => o.value) : [];
            const showMeta = document.getElementById('show-meta-issues').checked;

            const issueCards = document.querySelectorAll('.issue-card');

            issueCards.forEach(card => {
              let shouldShow = true;

              // Meta filter
              const isMeta = card.dataset.isMeta === '1';
              if (!showMeta && isMeta) {
                shouldShow = false;
              }

              // Tags filter (match ANY selected tag)
              if (shouldShow && selectedTags.length > 0) {
                // Extract tags from the card - look for tag elements or data attributes
                const cardTags = [];
                // Check if card has tags stored in dataset or find tag elements
                const tagElements = card.querySelectorAll('.issue-tag, .tag');
                tagElements.forEach(tagEl => {
                  cardTags.push(tagEl.textContent.trim());
                });
                // Also check data attributes if present
                if (card.dataset.tags) {
                  cardTags.push(...card.dataset.tags.split(',').map(t => t.trim()));
                }

                if (!selectedTags.some(tag => cardTags.includes(tag))) {
                  shouldShow = false;
                }
              }

              // Priority filter
              if (shouldShow && selectedPriorities.length > 0) {
                const cardPriority = card.querySelector('.issue-priority')?.textContent.toLowerCase();
                if (!selectedPriorities.includes(cardPriority)) {
                  shouldShow = false;
                }
              }

              // Status filter
              if (shouldShow && selectedStatuses.length > 0) {
                const cardStatus = card.querySelector('.issue-status')?.textContent.toLowerCase().replace(/ /g, '-');
                if (!selectedStatuses.includes(cardStatus)) {
                  shouldShow = false;
                }
              }

              // Track filter
              if (shouldShow && selectedTracks.length > 0) {
                const trackEl = card.querySelector('.issue-track');
                const cardTrack = trackEl ? trackEl.dataset.track : null;
                if (!cardTrack || !selectedTracks.includes(cardTrack)) {
                  shouldShow = false;
                }
              }

              // Workstream filter
              if (shouldShow && selectedWorkstreams.length > 0) {
                const workstreamEl = card.querySelector('.issue-workstream');
                const cardWorkstream = workstreamEl ? workstreamEl.dataset.workstream : null;
                if (!cardWorkstream || !selectedWorkstreams.includes(cardWorkstream)) {
                  shouldShow = false;
                }
              }

              card.style.display = shouldShow ? '' : 'none';
            });

            // Update column counts
            document.querySelectorAll('.kanban-column').forEach(column => {
              const visibleIssues = column.querySelectorAll('.issue-card:not([style*="display: none"])').length;
              const countSpan = column.querySelector('.column-count');
              if (countSpan) {
                countSpan.textContent = `(${visibleIssues})`;
              }
            });
          }

          // Add change listeners
          if (tagsMulti) {
            tagsMulti.addEventListener('change', () => {
              const selected = Array.from(tagsMulti.selectedOptions).map(o => o.value);
              saveFilterState('tagsMulti', selected);
              applyFilters();
            });
          }
          if (priorityFilter) {
            priorityFilter.addEventListener('change', () => {
              const selected = Array.from(priorityFilter.selectedOptions).map(o => o.value);
              saveFilterState('priority', selected);
              applyFilters();
            });
          }
          if (statusFilter) {
            statusFilter.addEventListener('change', () => {
              const selected = Array.from(statusFilter.selectedOptions).map(o => o.value);
              saveFilterState('status', selected);
              applyFilters();
            });
          }
          if (trackFilter) {
            trackFilter.addEventListener('change', () => {
              const selected = Array.from(trackFilter.selectedOptions).map(o => o.value);
              saveFilterState('track', selected);
              applyFilters();
            });
          }
          if (workstreamFilter) {
            workstreamFilter.addEventListener('change', () => {
              const selected = Array.from(workstreamFilter.selectedOptions).map(o => o.value);
              saveFilterState('workstream', selected);
              applyFilters();
            });
          }

          // Initial filter application
          applyFilters();
        }

        // Initialize meta issues filter
        const showMetaCheckbox = document.getElementById('show-meta-issues');
        if (showMetaCheckbox) {
          // Load saved preference
          const savedState = localStorage.getItem('aiDashboard.kanban.showMetaIssues');
          if (savedState !== null) {
            showMetaCheckbox.checked = savedState === 'true';
          }

          // Handle checkbox change
          showMetaCheckbox.addEventListener('change', () => {
            localStorage.setItem('aiDashboard.kanban.showMetaIssues', showMetaCheckbox.checked);
            // Trigger the advanced filters which now handle meta filtering
            if (document.getElementById('kanban-advanced-filters')) {
              initAdvancedFilters();
            }
          });
        }

        // Initialize advanced filters
        initAdvancedFilters();

        // Initialize a toggle menu for columns (optional/main)
        function initColumnMenu(menuId, storageKey, mode) {
          const menu = document.getElementById(menuId);
          if (!menu) return;
          const loadState = () => {
            try { const raw = localStorage.getItem(storageKey); return raw ? JSON.parse(raw) : []; } catch (e) { return []; }
          };
          const saveState = (ids) => { try { localStorage.setItem(storageKey, JSON.stringify(ids)); } catch (e) {} };
          const buttons = menu.querySelectorAll('.column-toggle-button');
          const state = new Set(loadState());

          // Apply saved state on load.
          buttons.forEach((btn) => {
            const target = btn.getAttribute('data-target');
            const col = document.querySelector(`.kanban-column[data-column-id="${target}"]`);
            if (!col) return;
            if (mode === 'optional') {
              if (state.has(target)) {
                col.classList.add('expanded');
                btn.classList.add('active');
                btn.setAttribute('aria-pressed', 'true');
              }
            } else if (mode === 'main') {
              if (state.has(target)) {
                col.classList.add('hidden');
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
              }
            }
          });

          // Toggle and persist state.
          buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
              const target = btn.getAttribute('data-target');
              const col = document.querySelector(`.kanban-column[data-column-id="${target}"]`);
              if (!col) return;
              if (mode === 'optional') {
                const nowShown = col.classList.toggle('expanded');
                btn.classList.toggle('active', nowShown);
                btn.setAttribute('aria-pressed', nowShown ? 'true' : 'false');
                if (nowShown) state.add(target); else state.delete(target);
              } else if (mode === 'main') {
                const wasHidden = col.classList.toggle('hidden');
                const nowShown = !wasHidden;
                btn.classList.toggle('active', nowShown);
                btn.setAttribute('aria-pressed', nowShown ? 'true' : 'false');
                if (wasHidden) state.add(target); else state.delete(target);
              }
              saveState(Array.from(state));
            });
          });
        }

        // Optional columns menu (persist shown list)
        initColumnMenu('optional-columns-menu', 'aiDashboard.kanban.optionalColumns', 'optional');
        // Main columns menu (persist hidden list)
        initColumnMenu('main-columns-menu', 'aiDashboard.kanban.mainColumnsHidden', 'main');

        // Removed prototype "add column" helper (not part of v1)
      }
    }
  };
})(jQuery, Drupal, once);
