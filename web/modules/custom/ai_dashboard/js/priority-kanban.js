/**
 * @file
 * JavaScript for the AI Priority Kanban board.
 */

(function ($, Drupal, once) {
  Drupal.behaviors.aiPriorityKanban = {
    attach: function (context, settings) {
      const kanbanBoard = once('ai-priority-kanban', '.ai-priority-kanban', context);

      if (kanbanBoard.length) {
        // Auto-apply tag filter on change
        const tagForm = document.querySelector('.tag-filter-form');
        const tagSelect = document.getElementById('kanban-tag-filter');
        if (tagForm && tagSelect) {
          const tagStorageKey = 'aiDashboard.kanban.selectedTag';

          // Ensure the select reflects the current URL param reliably
          try {
            const params = new URLSearchParams(window.location.search);
            const urlTag = params.get('tag');
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
          } catch (e) {}

          tagSelect.addEventListener('change', () => {
            tagForm.submit();
            // Persist selection
            try { localStorage.setItem(tagStorageKey, tagSelect.value); } catch (e4) {}
          });
        }

        // Clear filters: reset to default priority tag
        const clearBtn = document.getElementById('clear-kanban-filters');
        if (clearBtn) {
          clearBtn.addEventListener('click', (e) => {
            // Allow other UI resets, but ensure navigation resets tag to default
            e.preventDefault();
            try {
              localStorage.removeItem('aiDashboard.kanban.optionalColumns');
              localStorage.removeItem('aiDashboard.kanban.selectedTag');
            } catch (err) {}
            window.location.href = '/ai-dashboard/priority-kanban';
          });
        }
        // Tags filter handled server-side via GET params.

        // Individual optional column toggles
        const menu = document.getElementById('optional-columns-menu');
        if (menu) {
          const storageKey = 'aiDashboard.kanban.optionalColumns';
          const loadState = () => {
            try {
              const raw = localStorage.getItem(storageKey);
              return raw ? JSON.parse(raw) : [];
            } catch (e) { return []; }
          };
          const saveState = (ids) => {
            try { localStorage.setItem(storageKey, JSON.stringify(ids)); } catch (e) {}
          };

          const buttons = menu.querySelectorAll('.column-toggle-button');
          const current = new Set(loadState());

          // Apply saved state on load.
          buttons.forEach((btn) => {
            const target = btn.getAttribute('data-target');
            const column = document.querySelector(`.kanban-column[data-column-id="${target}"]`);
            if (!column) return;
            if (current.has(target)) {
              column.classList.add('expanded');
              btn.classList.add('active');
              btn.setAttribute('aria-pressed', 'true');
            }
          });

          // Toggle and persist state.
          buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
              const target = btn.getAttribute('data-target');
              const column = document.querySelector(`.kanban-column[data-column-id="${target}"]`);
              if (!column) return;
              const isNowShown = column.classList.toggle('expanded');
              btn.classList.toggle('active', isNowShown);
              btn.setAttribute('aria-pressed', isNowShown ? 'true' : 'false');
              if (isNowShown) {
                current.add(target);
              } else {
                current.delete(target);
              }
              saveState(Array.from(current));
            });
          });
        }

        // Removed prototype "add column" helper (not part of v1)
      }
    }
  };
})(jQuery, Drupal, once);
