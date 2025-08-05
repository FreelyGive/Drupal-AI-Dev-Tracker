/**
 * @file
 * Bulk operations functionality for module import configurations.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Bulk operations behavior.
   */
  Drupal.behaviors.aiDashboardBulkOperations = {
    attach: function (context, settings) {
      // Select all functionality.
      $('.js-select-all', context).once('bulk-select-all').on('click', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        $(target).prop('checked', true);
        updateBulkFormVisibility();
      });

      // Select none functionality.
      $('.js-select-none', context).once('bulk-select-none').on('click', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        $(target).prop('checked', false);
        updateBulkFormVisibility();
      });

      // Monitor checkbox changes to show/hide bulk operations.
      $('.bulk-select', context).once('bulk-select-monitor').on('change', function () {
        updateBulkFormVisibility();
      });

      // Initial visibility check.
      updateBulkFormVisibility();

      /**
       * Update bulk operations form visibility based on selections.
       */
      function updateBulkFormVisibility() {
        var hasSelected = $('.bulk-select:checked').length > 0;
        var $bulkContainer = $('.bulk-operations-container');
        
        if (hasSelected) {
          $bulkContainer.show();
          // Update the form submission to include selected items.
          updateSelectedItems();
        } else {
          $bulkContainer.hide();
        }
      }

      /**
       * Update hidden form fields with selected items.
       */
      function updateSelectedItems() {
        // The form submission will handle the selected items via the checkbox values.
        // No need to create hidden fields as Drupal will process the table structure.
      }

      // Handle bulk form submission.
      $('#ai-dashboard-module-import-bulk-form', context).once('bulk-form-submit').on('submit', function (e) {
        var selectedCount = $('.bulk-select:checked').length;
        var action = $('select[name="action"]').val();
        
        if (selectedCount === 0) {
          e.preventDefault();
          alert(Drupal.t('Please select at least one configuration.'));
          return false;
        }

        // Confirm destructive actions.
        if (action === 'disable_selected') {
          if (!confirm(Drupal.t('Are you sure you want to disable @count configuration(s)? This will stop any scheduled imports.', {'@count': selectedCount}))) {
            e.preventDefault();
            return false;
          }
        }

        if (action === 'run_selected') {
          if (!confirm(Drupal.t('Are you sure you want to run @count import(s)? This may take some time to complete.', {'@count': selectedCount}))) {
            e.preventDefault();
            return false;
          }
        }

        // Update selected items before submission.
        updateSelectedItems();
      });
    }
  };

})(jQuery, Drupal);