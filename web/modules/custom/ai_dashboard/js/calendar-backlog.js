(function ($, Drupal) {
  'use strict';

  /**
   * AI Calendar Dashboard Backlog functionality.
   */
  Drupal.behaviors.aiCalendarBacklog = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }

      var self = this;
      
      // Initialize backlog drawer
      self.initBacklogDrawer();
      
      // Initialize drag and drop
      self.initDragAndDrop();
      
      // Initialize filters
      self.initFilters();
      
      // Initialize week management
      self.initWeekManagement(settings);
      
      // Initialize remove buttons
      self.initRemoveButtons();
      
      // Initialize drupal.org sync button
      self.initSyncAllButton();
      
      // Initialize remove all issues button
      self.initRemoveAllButton();
      
    },

    /**
     * Initialize the backlog drawer functionality.
     */
    initBacklogDrawer: function() {
      var $drawer = $('#backlog-drawer');
      var $toggle = $('#show-backlog');
      var $close = $('#close-backlog');

      // Toggle drawer visibility
      $toggle.on('click', function() {
        $drawer.addClass('open');
        $('body').css('overflow', 'hidden'); // Prevent background scrolling
      });

      // Close drawer
      $close.on('click', function() {
        $drawer.removeClass('open');
        $('body').css('overflow', '');
      });

      // Close on escape key
      $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $drawer.hasClass('open')) {
          $drawer.removeClass('open');
          $('body').css('overflow', '');
        }
      });

      // Close when clicking outside drawer
      $(document).on('click', function(e) {
        if ($drawer.hasClass('open') && 
            !$drawer.is(e.target) && 
            $drawer.has(e.target).length === 0 &&
            !$toggle.is(e.target)) {
          $drawer.removeClass('open');
          $('body').css('overflow', '');
        }
      });
    },

    /**
     * Initialize drag and drop functionality.
     */
    initDragAndDrop: function() {
      var self = this;
      var $dropIndicator = $('#drop-indicator');
      var draggedIssue = null;
      var dragSource = null; // Track if dragging from backlog or calendar

      // Make backlog issues draggable
      $('.backlog-issue').attr('draggable', true).on('dragstart', function(e) {
        draggedIssue = $(this);
        dragSource = 'backlog';
        $(this).addClass('dragging');
        
        // Store issue data for transfer
        var issueData = {
          id: $(this).data('issue-id'),
          title: $(this).find('.issue-title').text(),
          priority: $(this).data('priority'),
          moduleId: $(this).data('module-id'),
          tags: $(this).data('tags'),
          source: 'backlog'
        };
        
        e.originalEvent.dataTransfer.setData('application/json', JSON.stringify(issueData));
        e.originalEvent.dataTransfer.effectAllowed = 'move';
      });

      // Make calendar issue cards draggable  
      $('.issue-card').attr('draggable', true).on('dragstart', function(e) {
        draggedIssue = $(this);
        dragSource = 'calendar';
        $(this).addClass('dragging');
        
        // Store issue data for transfer
        var issueData = {
          id: $(this).data('issue-id'),
          title: $(this).find('.issue-title').text(),
          source: 'calendar'
        };
        
        e.originalEvent.dataTransfer.setData('application/json', JSON.stringify(issueData));
        e.originalEvent.dataTransfer.effectAllowed = 'move';
      });

      $('.backlog-issue, .issue-card').on('dragend', function() {
        $(this).removeClass('dragging');
        draggedIssue = null;
        dragSource = null;
        $dropIndicator.removeClass('active');
        $('.developer-row').removeClass('drag-over');
        $('.backlog-drawer').removeClass('drag-over');
      });

      // Make developer rows drop targets
      $('.developer-row').on('dragover', function(e) {
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';
        
        $(this).addClass('drag-over');
        
        // Position drop indicator
        var rect = this.getBoundingClientRect();
        $dropIndicator.css({
          top: rect.top + window.scrollY,
          left: rect.left + window.scrollX,
          width: rect.width,
          height: rect.height
        }).addClass('active');
      });

      $('.developer-row').on('dragleave', function(e) {
        // Only remove if not moving to child element
        if (!$(this).has(e.relatedTarget).length) {
          $(this).removeClass('drag-over');
          $dropIndicator.removeClass('active');
        }
      });

      $('.developer-row').on('drop', function(e) {
        e.preventDefault();
        
        var $developerRow = $(this);
        var developerId = $developerRow.data('developer-id');
        var issueData = JSON.parse(e.originalEvent.dataTransfer.getData('application/json'));
        
        $(this).removeClass('drag-over');
        $dropIndicator.removeClass('active');

        // Assign issue to developer
        self.assignIssueToUser(issueData.id, developerId, function(success) {
          if (success) {
            // Remove from backlog
            if (draggedIssue) {
              draggedIssue.remove();
              self.updateBacklogCount();
            }
            
            // Show success message
            self.showMessage('Issue assigned successfully!', 'success');
            
            // Optionally reload the calendar view to show the new assignment
            setTimeout(function() {
              window.location.reload();
            }, 1000);
          } else {
            self.showMessage('Failed to assign issue. Please try again.', 'error');
          }
        });
      });

      // Make backlog drawer a drop target for unassigning issues
      $('.backlog-drawer').on('dragover', function(e) {
        // Only allow drops from calendar (assigned issues) - check drag source
        if (dragSource === 'calendar') {
          e.preventDefault();
          e.originalEvent.dataTransfer.dropEffect = 'move';
          $(this).addClass('drag-over');
        }
      });

      $('.backlog-drawer').on('dragleave', function(e) {
        // Only remove if not moving to child element
        if (!$(this).has(e.relatedTarget).length) {
          $(this).removeClass('drag-over');
        }
      });

      $('.backlog-drawer').on('drop', function(e) {
        e.preventDefault();
        
        var issueData = JSON.parse(e.originalEvent.dataTransfer.getData('application/json'));
        
        // Only handle drops from calendar
        if (issueData.source === 'calendar') {
          $(this).removeClass('drag-over');

          // Unassign the issue (move back to backlog)
          self.unassignIssue(issueData.id, function(success) {
            if (success) {
              // Show success message
              self.showMessage('Issue moved back to backlog!', 'success');
              
              // Reload to update both calendar and backlog
              setTimeout(function() {
                window.location.reload();
              }, 1000);
            } else {
              self.showMessage('Failed to move issue to backlog. Please try again.', 'error');
            }
          });
        }
      });
    },

    /**
     * Initialize filter functionality.
     */
    initFilters: function() {
      var self = this;
      var $moduleFilter = $('#module-filter');
      var $tagFilter = $('#tag-filter'); 
      var $priorityFilter = $('#priority-filter');
      var $clearFilters = $('#clear-filters');

      // Apply filters when changed
      function applyFilters() {
        var moduleFilter = $moduleFilter.val();
        var tagFilter = $tagFilter.val();
        var priorityFilter = $priorityFilter.val();
        
        var visibleCount = 0;
        
        $('.backlog-issue').each(function() {
          var $issue = $(this);
          var show = true;
          
          // Module filter
          if (moduleFilter && $issue.data('module-id') != moduleFilter) {
            show = false;
          }
          
          // Tag filter
          if (tagFilter && $issue.data('tags')) {
            var tags = $issue.data('tags').toString().split(',');
            if (tags.indexOf(tagFilter) === -1) {
              show = false;
            }
          }
          
          // Priority filter
          if (priorityFilter && $issue.data('priority') !== priorityFilter) {
            show = false;
          }
          
          $issue.toggleClass('hidden', !show);
          if (show) visibleCount++;
        });
        
        // Update count
        $('#showing-count').text(visibleCount);
      }

      $moduleFilter.on('change', applyFilters);
      $tagFilter.on('change', applyFilters);
      $priorityFilter.on('change', applyFilters);
      
      // Clear all filters
      $clearFilters.on('click', function() {
        $moduleFilter.val('');
        $tagFilter.val('');
        $priorityFilter.val('');
        applyFilters();
      });

      // Initialize calendar filters
      self.initCalendarFilters();
    },

    /**
     * Initialize calendar filtering functionality.
     */
    initCalendarFilters: function() {
      var $calendarPriorityFilter = $('#calendar-priority-filter');
      var $calendarStatusFilter = $('#calendar-status-filter');
      var $clearCalendarFilters = $('#clear-calendar-filters');
      
      // Apply calendar filters when changed
      function applyCalendarFilters() {
        var priorityFilter = $calendarPriorityFilter.val();
        var statusFilter = $calendarStatusFilter.val();
        
        $('.issue-card').each(function() {
          var $issue = $(this);
          var show = true;
          
          // Priority filter
          if (priorityFilter && !$issue.hasClass('priority-' + priorityFilter)) {
            show = false;
          }
          
          // Status filter
          if (statusFilter && !$issue.hasClass(statusFilter.replace('_', '-'))) {
            show = false;
          }
          
          $issue.toggleClass('calendar-filtered', !show);
        });
        
        // Hide developers with no visible issues
        $('.developer-row').each(function() {
          var $row = $(this);
          var hasVisibleIssues = $row.find('.issue-card:not(.calendar-filtered)').length > 0;
          var hasAvailable = $row.find('.no-issues').length > 0;
          
          $row.toggleClass('developer-filtered', !hasVisibleIssues && !hasAvailable);
        });
        
        // Hide companies with no visible developers
        $('.company-group').each(function() {
          var $group = $(this);
          var hasVisibleDevelopers = $group.find('.developer-row:not(.developer-filtered)').length > 0;
          
          $group.toggleClass('company-filtered', !hasVisibleDevelopers);
        });
      }
      
      $calendarPriorityFilter.on('change', applyCalendarFilters);
      $calendarStatusFilter.on('change', applyCalendarFilters);
      
      // Clear calendar filters
      $clearCalendarFilters.on('click', function() {
        $calendarPriorityFilter.val('');
        $calendarStatusFilter.val('');
        applyCalendarFilters();
      });
    },

    /**
     * Initialize week management functionality.
     */
    initWeekManagement: function(settings) {
      var self = this;
      var $copyPrevious = $('#copy-previous-week');
      
      // Copy previous week assignments
      $copyPrevious.on('click', function() {
        if (!confirm('Add all assignments from the previous week to this week? Issues already assigned to this week will not be duplicated.')) {
          return;
        }
        
        var currentWeekOffset = settings.aiDashboard ? settings.aiDashboard.weekOffset : 0;
        var previousWeekOffset = currentWeekOffset - 1;
        
        self.copyWeekAssignments(previousWeekOffset, currentWeekOffset, function(success) {
          if (success) {
            self.showMessage('Previous week assignments copied successfully!', 'success');
            setTimeout(function() {
              window.location.reload();
            }, 1000);
          } else {
            self.showMessage('Failed to copy assignments. Please try again.', 'error');
          }
        });
      });
    },

    /**
     * Assign issue to a user for the current week.
     */
    assignIssueToUser: function(issueId, developerId, callback) {
      var settings = drupalSettings.aiDashboard || {};
      $.ajax({
        url: '/ai-dashboard/api/assign-issue',
        method: 'POST',
        headers: {
          'X-CSRF-Token': settings.csrfToken
        },
        data: {
          issue_id: issueId,
          developer_id: developerId,
          week_offset: settings.weekOffset || 0
        },
        success: function(response) {
          callback(response.success || false);
        },
        error: function(xhr, status, error) {
          console.error('Assignment failed:', error, xhr.responseText);
          callback(false);
        }
      });
    },

    /**
     * Unassign issue (move back to backlog).
     */
    unassignIssue: function(issueId, callback) {
      var settings = drupalSettings.aiDashboard || {};
      $.ajax({
        url: '/ai-dashboard/api/unassign-issue',
        method: 'POST',
        headers: {
          'X-CSRF-Token': settings.csrfToken
        },
        data: {
          issue_id: issueId,
          week_offset: settings.weekOffset || 0
        },
        success: function(response) {
          callback(response.success || false);
        },
        error: function(xhr, status, error) {
          console.error('Unassignment failed:', error, xhr.responseText);
          callback(false);
        }
      });
    },

    /**
     * Copy assignments from one week to another.
     */
    copyWeekAssignments: function(fromWeekOffset, toWeekOffset, callback) {
      var settings = drupalSettings.aiDashboard || {};
      $.ajax({
        url: '/ai-dashboard/api/copy-week',
        method: 'POST',
        headers: {
          'X-CSRF-Token': settings.csrfToken
        },
        data: {
          from_week: fromWeekOffset,
          to_week: toWeekOffset
        },
        success: function(response) {
          callback(response.success || false);
        },
        error: function(xhr, status, error) {
          console.error('Copy week failed:', error, xhr.responseText);
          callback(false);
        }
      });
    },

    /**
     * Update the backlog count display.
     */
    updateBacklogCount: function() {
      var visibleCount = $('.backlog-issue:not(.hidden)').length;
      var totalCount = $('.backlog-issue').length;
      
      $('#showing-count').text(visibleCount);
      $('#show-backlog').text('📋 Backlog (' + totalCount + ')');
    },

    /**
     * Initialize remove buttons for issues.
     */
    initRemoveButtons: function() {
      var self = this;
      
      $(document).on('click', '.issue-remove-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $button = $(this);
        var issueId = $button.data('issue-id');
        
        if (!issueId) {
          console.error('No issue ID found for remove button');
          return;
        }
        
        // Confirm the action
        if (!confirm('Remove this issue from the current week? (The issue will not be deleted, just removed from this week)')) {
          return;
        }
        
        // Disable button during operation
        $button.prop('disabled', true);
        
        self.unassignIssue(issueId, function(success) {
          if (success) {
            // Remove the issue card from the UI
            $button.closest('.issue-card').fadeOut(300, function() {
              $(this).remove();
            });
            self.showMessage('Issue removed from this week', 'success');
          } else {
            self.showMessage('Failed to remove issue', 'error');
            $button.prop('disabled', false);
          }
        });
      });
    },

    /**
     * Initialize sync all button for drupal.org assignments.
     */
    initSyncAllButton: function() {
      var self = this;
      
      // Remove any existing event handlers to prevent double modals
      $('#sync-all-drupal-assignments').off('click').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var weekOffset = parseInt(drupalSettings.aiDashboard.weekOffset || 0);
        
        if (!confirm('Sync all assigned issues from drupal.org for this week? This will add any issues currently assigned to contributors with drupal.org usernames.')) {
          return;
        }
        
        // Show loading state
        $button.addClass('syncing').prop('disabled', true);
        var originalText = $button.text();
        $button.text('🔄 Syncing...');
        
        // Get CSRF token
        var csrfToken = drupalSettings.aiDashboard ? drupalSettings.aiDashboard.csrfToken : null;
        if (!csrfToken) {
          console.error('CSRF token not available');
          $button.removeClass('syncing').prop('disabled', false).text(originalText);
          return;
        }
        
        // Make API request to sync all developers
        $.ajax({
          url: '/ai-dashboard/api/sync-all-drupal-assignments',
          method: 'POST',
          headers: {
            'X-CSRF-Token': csrfToken
          },
          data: {
            week_offset: weekOffset
          },
          success: function(response) {
            if (response.success) {
              // Show success message
              self.showMessage('✅ ' + response.message, 'success');
              
              // Reload the page to show updated assignments
              setTimeout(function() {
                window.location.reload();
              }, 1500);
            } else {
              self.showMessage('❌ ' + (response.message || 'Sync failed'), 'error');
            }
          },
          error: function(xhr, status, error) {
            console.error('Sync all request failed:', error);
            self.showMessage('❌ Failed to sync assignments from drupal.org', 'error');
          },
          complete: function() {
            $button.removeClass('syncing').prop('disabled', false).text(originalText);
          }
        });
      });
    },

    /**
     * Initialize remove all issues button.
     */
    initRemoveAllButton: function() {
      var self = this;
      
      // Remove any existing event handlers to prevent double modals
      $('#remove-all-week-issues').off('click').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var weekOffset = parseInt(drupalSettings.aiDashboard.weekOffset || 0);
        
        if (!confirm('Remove ALL issues from this week? This will unassign all issues from all developers for this week only. The issues will not be deleted, just moved back to the backlog.')) {
          return;
        }
        
        // Show loading state
        $button.addClass('removing').prop('disabled', true);
        var originalText = $button.text();
        $button.text('🗑️ Removing...');
        
        // Get CSRF token
        var csrfToken = drupalSettings.aiDashboard ? drupalSettings.aiDashboard.csrfToken : null;
        if (!csrfToken) {
          console.error('CSRF token not available');
          $button.removeClass('removing').prop('disabled', false).text(originalText);
          return;
        }
        
        // Make API request to remove all issues from this week
        $.ajax({
          url: '/ai-dashboard/api/remove-all-week-issues',
          method: 'POST',
          headers: {
            'X-CSRF-Token': csrfToken
          },
          data: {
            week_offset: weekOffset
          },
          success: function(response) {
            if (response.success) {
              // Show success message
              self.showMessage('✅ ' + response.message, 'success');
              
              // Reload the page to show updated assignments
              setTimeout(function() {
                window.location.reload();
              }, 1500);
            } else {
              self.showMessage('❌ ' + (response.message || 'Remove all failed'), 'error');
            }
          },
          error: function(xhr, status, error) {
            console.error('Remove all request failed:', error);
            self.showMessage('❌ Failed to remove issues from this week', 'error');
          },
          complete: function() {
            $button.removeClass('removing').prop('disabled', false).text(originalText);
          }
        });
      });
    },

    /**
     * Show a temporary message to the user.
     */
    showMessage: function(message, type) {
      var $message = $('<div>')
        .addClass('backlog-message')
        .addClass('message-' + type)
        .text(message)
        .css({
          position: 'fixed',
          top: '20px',
          right: '20px',
          background: type === 'success' ? '#22c55e' : '#dc2626',
          color: 'white',
          padding: '1rem',
          borderRadius: '0.5rem',
          zIndex: 9999,
          maxWidth: '300px',
          boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)'
        });
      
      $('body').append($message);
      
      setTimeout(function() {
        $message.fadeOut(function() {
          $message.remove();
        });
      }, 3000);
    }
  };

  /**
   * Utility functions for backlog management.
   */
  Drupal.aiCalendarBacklog = {
    
    /**
     * Get issue priority numeric value for sorting.
     */
    getPriorityValue: function(priority) {
      var priorities = {
        'critical': 0,
        'major': 1,
        'normal': 2,
        'minor': 3,
        'trivial': 4
      };
      return priorities[priority] || 2;
    },

    /**
     * Format issue data for display.
     */
    formatIssueCard: function(issueData) {
      return {
        id: issueData.id,
        title: issueData.title || 'Untitled Issue',
        number: issueData.number || 'N/A',
        priority: issueData.priority || 'normal',
        status: issueData.status || 'active',
        module: issueData.module || 'Unknown',
        tags: issueData.tags || []
      };
    },

    /**
     * Check if issue matches filter criteria.
     */
    matchesFilters: function(issueData, filters) {
      if (filters.module && issueData.module_id != filters.module) {
        return false;
      }
      
      if (filters.tag && issueData.tags && issueData.tags.indexOf(filters.tag) === -1) {
        return false;
      }
      
      if (filters.priority && issueData.priority !== filters.priority) {
        return false;
      }
      
      return true;
    },

    /**
     * Sort issues by priority and creation date.
     */
    sortIssues: function(issues) {
      var self = this;
      return issues.sort(function(a, b) {
        var aPriority = self.getPriorityValue(a.priority);
        var bPriority = self.getPriorityValue(b.priority);
        
        if (aPriority !== bPriority) {
          return aPriority - bPriority;
        }
        
        return (b.created || 0) - (a.created || 0);
      });
    },


  };

})(jQuery, Drupal);