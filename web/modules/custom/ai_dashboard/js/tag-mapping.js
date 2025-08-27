/**
 * @file
 * JavaScript for AI Dashboard Tag Mapping interface.
 */

(function ($, Drupal, drupalSettings, once) {

  'use strict';

  /**
   * Tag Mapping behavior.
   */
  Drupal.behaviors.aiDashboardTagMapping = {
    attach: function (context, settings) {
      
      // Initialize tag mapping interface
      once('tag-mapping', '.tag-mapping-interface', context).forEach(function (element) {
        new TagMappingInterface(element);
      });
    }
  };

  /**
   * Tag Mapping Interface class.
   */
  function TagMappingInterface(element) {
    this.$element = $(element);
    this.init();
  }

  TagMappingInterface.prototype = {
    init: function () {
      this.attachEventListeners();
      this.initializeCSRFToken();
      
    },

    initializeCSRFToken: function () {
      // Get CSRF token for AJAX requests
      var self = this;
      $.ajax({
        url: '/session/token',
        type: 'GET',
        success: function (token) {
          self.csrfToken = token;
        },
        error: function () {
        }
      });
    },

    attachEventListeners: function () {
      var self = this;

      // Quick map button clicks (new simplified version)
      this.$element.on('click', '.quick-map-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var tag = $button.data('tag');
        var type = $button.data('type');
        var value = $button.data('value');
        
        if (value && value.trim()) {
          self.saveMapping(tag, type, value.trim(), $button);
        } else {
          self.showError($button, 'No value to map');
        }
      });

      // Edit mapping button clicks
      this.$element.on('click', '.edit-mapping-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var tag = $button.data('tag');
        var type = $button.data('type');
        var currentValue = $button.data('current');

        self.enableEditMode(tag, type, currentValue, $button);
      });

      // Enter key in input fields
      this.$element.on('keypress', '.mapping-input', function (e) {
        if (e.which === 13) { // Enter key
          var $input = $(this);
          var tag = $input.data('tag');
          var type = $input.data('type');
          var $row = $input.closest('.tag-mapping-row');
          var $button = $row.find('.save-mapping-btn[data-tag="' + tag + '"][data-type="' + type + '"]');
          
          if ($button.length > 0) {
            $button.click();
          }
        }
      });

      // Ignore tag button clicks
      this.$element.on('click', '.ignore-tag-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var tag = $button.data('tag');
        
        if (confirm('Are you sure you want to ignore the tag "' + tag + '"? It will be hidden from this list.')) {
          self.ignoreTag(tag, $button);
        }
      });

      // Toggle ignored tags list
      this.$element.on('click', '.toggle-ignored-btn', function (e) {
        e.preventDefault();
        var $list = $('#ignored-tags-list');
        var $button = $(this);
        
        if ($list.is(':visible')) {
          $list.slideUp(300);
          $button.text($button.text().replace('Hide', 'Show'));
        } else {
          $list.slideDown(300);
          $button.text($button.text().replace('Show', 'Hide'));
        }
      });

      // Restore tag button clicks
      this.$element.on('click', '.restore-tag-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var tag = $button.data('tag');
        
        if (confirm('Restore the tag "' + tag + '"? It will reappear in the main list.')) {
          self.restoreTag(tag, $button);
        }
      });

      // Stats filter button clicks
      this.$element.on('click', '.filter-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var filter = $button.data('filter');
        
        self.filterTags(filter, $button);
      });

      // Remove mapping button clicks
      this.$element.on('click', '.remove-mapping-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var tag = $button.data('tag');
        var type = $button.data('type');
        
        if (confirm('Remove the ' + type + ' mapping for tag "' + tag + '"?')) {
          self.removeMapping(tag, type, $button);
        }
      });

      // Bulk update all mappings button
      this.$element.on('click', '.update-all-mappings-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        
        if (confirm('Apply current tag mappings to all existing AI Issues? This will update track and workstream fields based on your current mappings.')) {
          self.updateAllMappings($button);
        }
      });
    },

    saveMapping: function (tag, type, value, $button) {
      var self = this;
      var $container = $button.closest('.mapping-form');
      
      // Show loading state
      $button.addClass('loading').prop('disabled', true);
      $container.addClass('mapping-loading');

      // Prepare data
      var data = {
        source_tag: tag,
        mapping_type: type,
        mapped_value: value
      };

      // Make AJAX request
      $.ajax({
        url: '/ai-dashboard/api/save-tag-mapping',
        type: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken
        },
        success: function (response) {
          if (response.success) {
            self.handleSaveSuccess(tag, type, value, $button);
          } else {
            self.showError($button, response.message || 'Failed to save mapping');
          }
        },
        error: function (xhr) {
          var message = 'Failed to save mapping';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
          }
          self.showError($button, message);
        },
        complete: function () {
          $button.removeClass('loading').prop('disabled', false);
          $container.removeClass('mapping-loading');
        }
      });
    },

    enableEditMode: function (tag, type, currentValue, $button) {
      var $container = $button.closest('.mapping-display');
      
      // Create edit form HTML
      var editForm = $('<div class="mapping-form ' + type + '">' +
        '<label>Edit ' + type + ':</label>' +
        '<input type="text" class="mapping-input" data-type="' + type + '" data-tag="' + tag + '" value="' + this.escapeHtml(currentValue) + '">' +
        '<button class="save-mapping-btn" data-type="' + type + '" data-tag="' + tag + '">Save ' + type + '</button>' +
        '<button class="cancel-edit-btn" data-type="' + type + '" data-tag="' + tag + '">Cancel</button>' +
        '</div>');

      // Replace display with edit form
      $container.replaceWith(editForm);

      // Focus the input
      editForm.find('.mapping-input').focus().select();

      // Handle cancel button
      var self = this;
      editForm.find('.cancel-edit-btn').on('click', function (e) {
        e.preventDefault();
        self.cancelEdit(tag, type, currentValue, editForm);
      });
    },

    cancelEdit: function (tag, type, currentValue, $editForm) {
      // Restore original display
      var displayHtml = '<div class="mapping-display ' + type + '">' +
        '<label>' + type + ':</label>' +
        '<span class="mapped-value">' + this.escapeHtml(currentValue) + '</span>' +
        '<button class="edit-mapping-btn" data-type="' + type + '" data-tag="' + tag + '" data-current="' + this.escapeHtml(currentValue) + '">Edit</button>' +
        '</div>';

      $editForm.replaceWith($(displayHtml));
    },

    handleSaveSuccess: function (tag, type, value, $button) {
      // Show success message
      this.showSuccess(tag, 'Mapping saved successfully!');
      
      // Refresh page after a short delay to show the updated interface  
      setTimeout(function() {
        location.reload();
      }, 800);
    },

    showSuccess: function (tag, message) {
      var $row = this.$element.find('[data-tag="' + tag + '"]');
      var $successMsg = $('<div class="mapping-success">' + this.escapeHtml(message || 'Mapping saved successfully!') + '</div>');
      
      $row.append($successMsg);
      
      // Remove message after 3 seconds
      setTimeout(function () {
        $successMsg.fadeOut(300, function () {
          $successMsg.remove();
        });
      }, 3000);
    },

    showError: function ($button, message) {
      var $container = $button.closest('.mapping-form, .mapping-display');
      
      // Remove any existing error messages
      $container.find('.mapping-error').remove();
      
      // Add error message
      var $error = $('<div class="mapping-error">' + this.escapeHtml(message) + '</div>');
      $container.append($error);
      
      // Remove error after 5 seconds
      setTimeout(function () {
        $error.fadeOut(300, function () {
          $error.remove();
        });
      }, 5000);
    },

    updateMappingStats: function () {
      var trackCount = this.$element.find('.mapping-display.track').length;
      var workstreamCount = this.$element.find('.mapping-display.workstream').length;
      
      this.$element.find('.stat.track').text(trackCount + ' Track Mappings');
      this.$element.find('.stat.workstream').text(workstreamCount + ' Workstream Mappings');
    },

    ignoreTag: function (tag, $button) {
      var self = this;
      var $row = $button.closest('.tag-mapping-row');
      
      // Show loading state
      $button.addClass('loading').prop('disabled', true).text('Ignoring...');
      
      // Make AJAX request
      $.ajax({
        url: '/ai-dashboard/api/ignore-tag',
        type: 'POST',
        data: JSON.stringify({ tag: tag }),
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken
        },
        success: function (response) {
          if (response.success) {
            // Fade out and remove the row
            $row.fadeOut(300, function () {
              $row.remove();
              self.updateMappingStats();
              // Refresh page to show updated ignored count
              location.reload();
            });
          } else {
            self.showError($button, response.message || 'Failed to ignore tag');
            $button.removeClass('loading').prop('disabled', false).text('Ignore');
          }
        },
        error: function (xhr) {
          var message = 'Failed to ignore tag';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
          }
          self.showError($button, message);
          $button.removeClass('loading').prop('disabled', false).text('Ignore');
        }
      });
    },

    restoreTag: function (tag, $button) {
      var self = this;
      var $item = $button.closest('.ignored-tag-item');
      
      // Show loading state
      $button.addClass('loading').prop('disabled', true).text('Restoring...');
      
      // Make AJAX request
      $.ajax({
        url: '/ai-dashboard/api/restore-tag',
        type: 'POST',
        data: JSON.stringify({ tag: tag }),
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken
        },
        success: function (response) {
          if (response.success) {
            // Fade out the restored item and refresh page
            $item.fadeOut(300, function () {
              $item.remove();
              // Refresh page to show the restored tag
              location.reload();
            });
          } else {
            self.showError($button, response.message || 'Failed to restore tag');
            $button.removeClass('loading').prop('disabled', false).text('Restore');
          }
        },
        error: function (xhr) {
          var message = 'Failed to restore tag';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
          }
          self.showError($button, message);
          $button.removeClass('loading').prop('disabled', false).text('Restore');
        }
      });
    },

    escapeHtml: function (text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },

    filterTags: function (filter, $button) {
      var $rows = this.$element.find('.tag-mapping-row');
      var $filterButtons = this.$element.find('.filter-btn');
      
      // Update active button state
      $filterButtons.removeClass('active');
      $button.addClass('active');
      
      // Show/hide tags based on filter
      $rows.each(function () {
        var $row = $(this);
        var hasTrackMapping = $row.find('.mapping-display.track').length > 0;
        var hasWorkstreamMapping = $row.find('.mapping-display.workstream').length > 0;
        var showRow = false;
        
        switch (filter) {
          case 'all':
            showRow = true;
            break;
          case 'track':
            showRow = hasTrackMapping;
            break;
          case 'workstream':
            showRow = hasWorkstreamMapping;
            break;
          case 'unmapped':
            showRow = !hasTrackMapping && !hasWorkstreamMapping;
            break;
          case 'ignored':
            // Ignored tags are not in the main list, so hide all
            showRow = false;
            break;
        }
        
        if (showRow) {
          $row.show();
        } else {
          $row.hide();
        }
      });
      
      // Special handling for ignored filter - show ignored tags section
      var $ignoredSection = this.$element.find('.ignored-tags-management');
      if (filter === 'ignored' && $ignoredSection.length > 0) {
        $ignoredSection.show();
        // Automatically open ignored tags list if it's closed
        var $ignoredList = $('#ignored-tags-list');
        if (!$ignoredList.is(':visible')) {
          $ignoredList.slideDown(300);
          var $toggleBtn = this.$element.find('.toggle-ignored-btn');
          $toggleBtn.text($toggleBtn.text().replace('Show', 'Hide'));
        }
      }
      
      // Update displayed count
      var visibleCount = $rows.filter(':visible').length;
      this.updateFilterStatus(filter, visibleCount);
    },
    
    updateFilterStatus: function (filter, count) {
      var statusMessage = '';
      
      switch (filter) {
        case 'all':
          statusMessage = 'Showing all ' + count + ' tags';
          break;
        case 'track':
          statusMessage = 'Showing ' + count + ' tags with track mappings';
          break;
        case 'workstream':
          statusMessage = 'Showing ' + count + ' tags with workstream mappings';
          break;
        case 'unmapped':
          statusMessage = 'Showing ' + count + ' unmapped tags';
          break;
        case 'ignored':
          var $ignoredSection = this.$element.find('.ignored-tags-management');
          var ignoredCount = $ignoredSection.find('.ignored-tag-item').length;
          statusMessage = 'Showing ' + ignoredCount + ' ignored tags';
          break;
      }
      
      // Remove any existing status message
      this.$element.find('.filter-status').remove();
      
      // Add new status message
      if (statusMessage) {
        var $statusDiv = $('<div class="filter-status">' + statusMessage + '</div>');
        this.$element.find('.mapping-stats').after($statusDiv);
      }
    },

    removeMapping: function (tag, type, $button) {
      var self = this;
      var $container = $button.closest('.mapping-display');
      
      // Show loading state
      $button.addClass('loading').prop('disabled', true).text('Removing...');
      $container.addClass('mapping-loading');

      // Prepare data
      var data = {
        source_tag: tag,
        mapping_type: type
      };

      // Make AJAX request
      $.ajax({
        url: '/ai-dashboard/api/remove-mapping',
        type: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken
        },
        success: function (response) {
          if (response.success) {
            self.handleRemoveSuccess(tag, type, $button);
          } else {
            self.showError($button, response.message || 'Failed to remove mapping');
          }
        },
        error: function (xhr) {
          var message = 'Failed to remove mapping';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
          }
          self.showError($button, message);
        },
        complete: function () {
          $button.removeClass('loading').prop('disabled', false).text('Remove');
          $container.removeClass('mapping-loading');
        }
      });
    },

    handleRemoveSuccess: function (tag, type, $button) {
      // Show success message
      this.showSuccess(tag, type + ' mapping removed successfully');
      
      // Immediately refresh the page to ensure clean state
      setTimeout(function() {
        location.reload();
      }, 800);
    },

    updateAllMappings: function ($button) {
      var self = this;
      
      // Show loading state
      $button.addClass('loading').prop('disabled', true).text('Updating all issues...');
      
      // Make AJAX request
      $.ajax({
        url: '/ai-dashboard/api/update-all-mappings',
        type: 'POST',
        data: JSON.stringify({}),
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken
        },
        success: function (response) {
          if (response.success) {
            alert('Success: ' + response.message);
            // Refresh page to show updated data
            location.reload();
          } else {
            alert('Error: ' + (response.message || 'Failed to update mappings'));
          }
        },
        error: function (xhr) {
          var message = 'Failed to update mappings';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
          }
          alert('Error: ' + message);
        },
        complete: function () {
          $button.removeClass('loading').prop('disabled', false).text('Apply Current Mappings to All Existing Issues');
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);