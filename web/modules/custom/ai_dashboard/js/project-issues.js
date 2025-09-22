/**
 * Project Issues Management - Drag & Drop with Indentation
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.aiProjectIssues = {
    attach: function (context, settings) {
      // Use once() properly for Drupal
      const container = document.querySelector('#issues-table-body');
      if (!container || container.dataset.processed) return;
      container.dataset.processed = 'true';

      // Check if we have settings
      if (!drupalSettings.aiDashboard) return;
      
      const projectId = drupalSettings.aiDashboard.projectId;
      const saveUrl = drupalSettings.aiDashboard.saveOrderUrl;
      
      let draggedElement = null;

      // Initialize everything
      initDragDrop();
      initIndentButtons();
      initFilters();
      initFixedToggle();
      initSaveButton();
      initCollapsible();
      updateEpicStyles();

      /**
       * Initialize drag and drop functionality
       */
      function initDragDrop() {
        const rows = container.querySelectorAll('.issue-row[draggable="true"]');
        
        rows.forEach(row => {
          row.addEventListener('dragstart', handleDragStart);
          row.addEventListener('dragover', handleDragOver);
          row.addEventListener('drop', handleDrop);
          row.addEventListener('dragend', handleDragEnd);
          row.addEventListener('dragenter', handleDragEnter);
          row.addEventListener('dragleave', handleDragLeave);
        });
      }

      function handleDragStart(e) {
        draggedElement = this;
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Store the HTML to help with Firefox compatibility
        e.dataTransfer.setData('text/html', this.innerHTML);
      }

      function handleDragOver(e) {
        if (e.preventDefault) {
          e.preventDefault(); // Allows us to drop
        }
        e.dataTransfer.dropEffect = 'move';
        
        // Calculate where to insert
        const afterElement = getDragAfterElement(container, e.clientY);
        if (afterElement == null) {
          container.appendChild(draggedElement);
        } else {
          container.insertBefore(draggedElement, afterElement);
        }
        
        return false;
      }

      function handleDrop(e) {
        if (e.stopPropagation) {
          e.stopPropagation();
        }
        
        markUnsaved();
        return false;
      }

      function handleDragEnd(e) {
        // Clean up
        const rows = container.querySelectorAll('.issue-row');
        rows.forEach(row => {
          row.classList.remove('dragging', 'drag-over');
        });
        draggedElement = null;
      }

      function handleDragEnter(e) {
        this.classList.add('drag-over');
      }

      function handleDragLeave(e) {
        this.classList.remove('drag-over');
      }

      function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.issue-row:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
          const box = child.getBoundingClientRect();
          const offset = y - box.top - box.height / 2;
          
          if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
          } else {
            return closest;
          }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
      }

      /**
       * Initialize indent/outdent buttons
       */
      function initIndentButtons() {
        // Use event delegation for dynamically added content
        container.addEventListener('click', function(e) {
          if (e.target.classList.contains('indent')) {
            const row = e.target.closest('.issue-row');
            if (!row) return;
            
            const currentIndent = parseInt(row.dataset.indent || '0');
            const newIndent = currentIndent + 1;
            
            row.dataset.indent = newIndent;
            const titleCol = row.querySelector('.col-title');
            if (titleCol) {
              titleCol.style.paddingLeft = (newIndent * 20) + 'px';
            }
            
            markUnsaved();
            updateEpicStyles();
          }
          
          if (e.target.classList.contains('outdent')) {
            const row = e.target.closest('.issue-row');
            if (!row) return;
            
            const currentIndent = parseInt(row.dataset.indent || '0');
            const newIndent = Math.max(0, currentIndent - 1);
            
            row.dataset.indent = newIndent;
            const titleCol = row.querySelector('.col-title');
            if (titleCol) {
              titleCol.style.paddingLeft = (newIndent * 20) + 'px';
            }
            
            markUnsaved();
            updateEpicStyles();
          }
        });

        // Keyboard shortcuts
        container.addEventListener('keydown', function(e) {
          if (e.key === 'Tab' && e.target.closest('.issue-row')) {
            e.preventDefault();
            const row = e.target.closest('.issue-row');
            const button = e.shiftKey ? 
              row.querySelector('.indent-btn.outdent') : 
              row.querySelector('.indent-btn.indent');
            if (button) button.click();
          }
        });
      }

      /**
       * Initialize filters
       */
      function initFilters() {
        $('.filter-select').on('change', function() {
          const filters = {};
          $('.filter-select').each(function() {
            const filter = $(this).data('filter');
            const value = $(this).val();
            if (value) {
              filters[filter] = value;
            }
          });

          // Build query string
          const params = new URLSearchParams(filters);
          const url = window.location.pathname + (params.toString() ? '?' + params.toString() : '');

          // Navigate to filtered URL
          window.location.href = url;
        });

        // Clear filters button
        $('#clear-filters-btn').on('click', function() {
          window.location.href = window.location.pathname;
        });
      }

      /**
       * Initialize show/hide fixed issues toggle
       */
      function initFixedToggle() {
        const toggle = $('#show-fixed');

        // Restore saved state from localStorage
        const savedState = localStorage.getItem('showFixedIssues');
        if (savedState !== null) {
          toggle.prop('checked', savedState === 'true');
          toggleFixedIssues(savedState === 'true');
        }

        // Handle toggle change
        toggle.on('change', function() {
          const show = $(this).is(':checked');
          localStorage.setItem('showFixedIssues', show);
          toggleFixedIssues(show);
        });

        function toggleFixedIssues(show) {
          const fixedRows = $('.issue-row.status-fixed, .issue-row.status-closed-fixed');
          if (show) {
            fixedRows.show();
          } else {
            fixedRows.hide();
          }

          // Update issue counts
          updateIssueCounts();
          // Update collapsible states after hiding/showing
          updateCollapsibleStates();
        }

        function updateIssueCounts() {
          const visibleIssues = $('.issue-row:visible');
          const totalVisible = visibleIssues.length;
          const activeVisible = visibleIssues.filter('.status-active').length;
          const needsWorkVisible = visibleIssues.filter('.status-needs-work').length;
          const needsReviewVisible = visibleIssues.filter('.status-needs-review').length;
          const rtbcVisible = visibleIssues.filter('.status-rtbc').length;
          const fixedVisible = visibleIssues.filter('.status-fixed, .status-closed-fixed').length;

          // Update the counts display if it exists
          const countsContainer = $('.issue-counts');
          if (countsContainer.length) {
            countsContainer.find('.count-item').each(function() {
              const text = $(this).text();
              if (text.startsWith('Total:')) {
                $(this).text('Total: ' + totalVisible);
              } else if (text.startsWith('Active:')) {
                $(this).text('Active: ' + activeVisible);
              } else if (text.startsWith('Needs Work:')) {
                $(this).text('Needs Work: ' + needsWorkVisible);
              } else if (text.startsWith('Needs Review:')) {
                $(this).text('Needs Review: ' + needsReviewVisible);
              } else if (text.startsWith('RTBC:')) {
                $(this).text('RTBC: ' + rtbcVisible);
              } else if (text.startsWith('Fixed:')) {
                $(this).text('Fixed: ' + fixedVisible);
              }
            });
          }
        }
      }

      /**
       * Initialize save button
       */
      function initSaveButton() {
        const saveBtn = document.getElementById('save-order-btn');
        if (saveBtn) {
          saveBtn.addEventListener('click', saveOrder);
        }
      }

      /**
       * Mark as unsaved
       */
      function markUnsaved() {
        const saveBtn = document.getElementById('save-order-btn');
        if (saveBtn) {
          saveBtn.classList.add('unsaved');
          saveBtn.textContent = 'Save Order*';
        }
      }

      /**
       * Save the order to the server
       */
      function saveOrder() {
        const saveBtn = document.getElementById('save-order-btn');
        if (!saveBtn) return;
        
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
        
        // Collect order data
        const items = [];
        const rows = container.querySelectorAll('.issue-row');
        
        rows.forEach((row, index) => {
          const nid = row.dataset.nid;
          const indent = parseInt(row.dataset.indent || '0');
          
          // Find parent (nearest above row with smaller indent)
          let parent = null;
          if (indent > 0) {
            for (let i = index - 1; i >= 0; i--) {
              const prevRow = rows[i];
              const prevIndent = parseInt(prevRow.dataset.indent || '0');
              if (prevIndent < indent) {
                parent = prevRow.dataset.nid;
                break;
              }
            }
          }
          
          items.push({
            nid: parseInt(nid),
            weight: index,
            indent: indent,
            parent: parent ? parseInt(parent) : null
          });
        });

        // Get CSRF token first
        fetch('/session/token')
          .then(response => response.text())
          .then(csrfToken => {
            // Send to server with CSRF token
            return fetch(saveUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
              },
              body: JSON.stringify({ items: items })
            });
          })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.error) {
            throw new Error(data.error);
          }
          saveBtn.disabled = false;
          saveBtn.classList.remove('unsaved');
          saveBtn.textContent = 'Save Order';
          
          showFeedback('Order saved successfully!', 'success');
          
          // Reload page after a short delay to get fresh data
          setTimeout(function() {
            window.location.reload();
          }, 1000);
        })
        .catch(error => {
          console.error('Save error:', error);
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save Order*';
          showFeedback('Failed to save order. Please try again.', 'error');
        });
      }

      /**
       * Show feedback message
       */
      function showFeedback(message, type) {
        const feedback = document.getElementById('save-feedback');
        if (!feedback) return;
        
        feedback.className = 'save-feedback ' + type;
        feedback.textContent = message;
        feedback.style.display = 'block';
        
        setTimeout(function() {
          feedback.style.display = 'none';
        }, 3000);
      }

      /**
       * Initialize collapsible functionality
       */
      function initCollapsible() {
        const rows = container.querySelectorAll('.issue-row');
        const collapsedState = JSON.parse(localStorage.getItem('project_' + projectId + '_collapsed') || '{}');
        
        // First pass: identify parents and show their collapse toggles
        rows.forEach((row, index) => {
          const currentIndent = parseInt(row.dataset.indent || '0');
          const nid = row.dataset.nid;
          
          // Check if this row has children (next row has greater indent)
          if (index < rows.length - 1) {
            const nextRow = rows[index + 1];
            const nextIndent = parseInt(nextRow.dataset.indent || '0');
            
            if (nextIndent > currentIndent) {
              // This is a parent - show collapse toggle
              const toggle = row.querySelector('.collapse-toggle');
              if (toggle) {
                toggle.style.display = 'inline-block';
                row.classList.add('is-parent');
                
                // Count all descendants (children, grandchildren, etc.)
                let childCount = 0;
                for (let j = index + 1; j < rows.length; j++) {
                  const checkIndent = parseInt(rows[j].dataset.indent || '0');
                  if (checkIndent <= currentIndent) break;
                  // Count all issues with indent greater than current (all descendants)
                  if (checkIndent > currentIndent) childCount++;
                }
                
                // Show child count when collapsed
                const countSpan = toggle.querySelector('.child-count');
                if (countSpan) {
                  countSpan.textContent = `(${childCount})`;
                }
                
                // Restore collapsed state
                if (collapsedState[nid]) {
                  toggle.querySelector('.collapse-icon').classList.remove('expanded');
                  toggle.querySelector('.collapse-icon').classList.add('collapsed');
                  toggle.querySelector('.collapse-icon').textContent = '▶';
                  if (countSpan) countSpan.style.display = 'inline';
                }
              }
            }
          }
        });
        
        // Second pass: hide children of collapsed parents
        rows.forEach((row, index) => {
          const currentIndent = parseInt(row.dataset.indent || '0');
          
          if (currentIndent > 0) {
            // Find parent
            for (let i = index - 1; i >= 0; i--) {
              const prevRow = rows[i];
              const prevIndent = parseInt(prevRow.dataset.indent || '0');
              
              if (prevIndent < currentIndent) {
                // This is a parent
                const parentNid = prevRow.dataset.nid;
                if (collapsedState[parentNid]) {
                  row.style.display = 'none';
                  row.classList.add('collapsed-child');
                }
                break;
              }
            }
          }
        });
        
        // Add click handlers for collapse toggles
        container.addEventListener('click', function(e) {
          if (e.target.closest('.collapse-toggle')) {
            const toggle = e.target.closest('.collapse-toggle');
            const parentNid = toggle.dataset.nid;
            const parentRow = toggle.closest('.issue-row');
            const parentIndent = parseInt(parentRow.dataset.indent || '0');
            const icon = toggle.querySelector('.collapse-icon');
            
            const isCollapsed = icon.classList.contains('collapsed');
            
            const countSpan = toggle.querySelector('.child-count');
            
            if (isCollapsed) {
              // Expand
              icon.classList.remove('collapsed');
              icon.classList.add('expanded');
              icon.textContent = '▼';
              if (countSpan) countSpan.style.display = 'none';
              delete collapsedState[parentNid];
            } else {
              // Collapse
              icon.classList.remove('expanded');
              icon.classList.add('collapsed');
              icon.textContent = '▶';
              if (countSpan) countSpan.style.display = 'inline';
              collapsedState[parentNid] = true;
            }
            
            // Save state
            localStorage.setItem('project_' + projectId + '_collapsed', JSON.stringify(collapsedState));
            
            // Show/hide children
            const allRows = container.querySelectorAll('.issue-row');
            let foundParent = false;
            let hiding = !isCollapsed;
            
            allRows.forEach(row => {
              if (row === parentRow) {
                foundParent = true;
                return;
              }
              
              if (foundParent) {
                const rowIndent = parseInt(row.dataset.indent || '0');
                
                // Stop when we reach a row at same or lower indent level
                if (rowIndent <= parentIndent) {
                  return;
                }
                
                // This is a child
                if (hiding) {
                  row.style.display = 'none';
                  row.classList.add('collapsed-child');
                } else {
                  // Only show if not hidden by another collapsed parent
                  let shouldShow = true;
                  
                  // Check if any parent above is collapsed
                  for (let i = Array.from(allRows).indexOf(row) - 1; i >= 0; i--) {
                    const checkRow = allRows[i];
                    const checkIndent = parseInt(checkRow.dataset.indent || '0');
                    
                    if (checkIndent < rowIndent) {
                      // This is a parent of our row
                      if (collapsedState[checkRow.dataset.nid] && checkRow !== parentRow) {
                        shouldShow = false;
                        break;
                      }
                      
                      if (checkIndent === parentIndent) {
                        // We've reached the same level as our toggle parent
                        break;
                      }
                    }
                  }
                  
                  if (shouldShow) {
                    row.style.display = '';
                    row.classList.remove('collapsed-child');
                  }
                }
              }
            });
          }
        });
      }
      
      /**
       * Update epic styles - only mark items as epics if they have children
       */
      function updateEpicStyles() {
        const rows = container.querySelectorAll('.issue-row');
        
        // First, remove all epic classes
        rows.forEach(row => {
          row.classList.remove('is-epic', 'is-sub-epic');
        });
        
        // Then, identify epics (items that have children)
        rows.forEach((row, index) => {
          const currentIndent = parseInt(row.dataset.indent || '0');
          
          // Check if this row has children (next row has higher indent)
          let hasChildren = false;
          if (index < rows.length - 1) {
            const nextRow = rows[index + 1];
            const nextIndent = parseInt(nextRow.dataset.indent || '0');
            if (nextIndent > currentIndent) {
              hasChildren = true;
            }
          }
          
          // Mark as epic if it has children and is at indent level 0
          if (hasChildren && currentIndent === 0) {
            row.classList.add('is-epic');
          }
          // Mark as sub-epic if it has children and is at indent level 1+
          else if (hasChildren && currentIndent > 0) {
            row.classList.add('is-sub-epic');
          }
        });
      }
    }
  };

})(jQuery, Drupal, drupalSettings);