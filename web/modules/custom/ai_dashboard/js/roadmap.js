/**
 * @file
 * AI Deliverables Roadmap JavaScript.
 *
 * Handles drag-drop ordering for admin users.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.aiDashboardRoadmap = {
    attach: function (context, settings) {
      // Initialize once - check if already initialized.
      if ($('.ai-roadmap', context).hasClass('roadmap-initialized')) {
        return;
      }
      $('.ai-roadmap', context).addClass('roadmap-initialized');

      // Add drag-drop functionality for admin users.
      // Check if user has admin permission (save button will be present).
      if ($('#save-roadmap-order').length) {
        // Wait for Sortable to load.
        if (typeof Sortable === 'undefined') {
          // Try again in a moment.
          setTimeout(function() {
            if (typeof Sortable !== 'undefined') {
              initializeDragDrop();
            }
          }, 500);
        } else {
          initializeDragDrop();
        }

        function initializeDragDrop() {
          var columns = document.querySelectorAll('.roadmap-column .column-content');
          var hasChanges = false;

          // Get existing save button.
          var $saveButton = $('#save-roadmap-order');

          // Add click handler to save button.
          if ($saveButton.length) {
            $saveButton.on('click', function() {
              saveRoadmapOrder();
            });
          }

          // Initialize Sortable on each column.
          columns.forEach(function(column) {
            new Sortable(column, {
              group: 'roadmap-cards',
              animation: 150,
              ghostClass: 'dragging',
              dragClass: 'dragging',
              handle: '.deliverable-card',
              draggable: '.deliverable-card',
              onStart: function(evt) {
                $(evt.item).addClass('dragging');
              },
              onEnd: function(evt) {
                $(evt.item).removeClass('dragging');
                // Show save button when order changes.
                if (!hasChanges) {
                  hasChanges = true;
                  $('#save-roadmap-order').fadeIn();
                }
              }
            });
          });

          /**
           * Save the current roadmap order via AJAX.
           */
          function saveRoadmapOrder() {
            var data = { columns: {} };

            // Collect order from each column.
            $('.roadmap-column').each(function() {
              var columnName = $(this).hasClass('complete') ? 'complete' :
                              $(this).hasClass('now') ? 'now' :
                              $(this).hasClass('next') ? 'next' : 'later';

              data.columns[columnName] = [];

              $(this).find('.deliverable-card').each(function(index) {
                var nid = $(this).data('nid');
                if (nid) {
                  data.columns[columnName].push({
                    nid: nid,
                    weight: index
                  });
                }
              });
            });

            // Send AJAX request.
            $.ajax({
              url: '/ai-dashboard/roadmap/save-order',
              method: 'POST',
              contentType: 'application/json',
              data: JSON.stringify(data),
              success: function(response) {
                showFeedback('Changes saved successfully!', 'success');
                hasChanges = false;
                $('#save-roadmap-order').fadeOut();
              },
              error: function(xhr) {
                showFeedback('Failed to save changes. Please try again.', 'error');
              }
            });
          }

          /**
           * Display a temporary feedback message.
           *
           * @param {string} message
           *   The message to display.
           * @param {string} type
           *   The type of message ('success' or 'error').
           */
          function showFeedback(message, type) {
            var feedback = $('<div class="roadmap-feedback ' + type + '">' + message + '</div>');
            $('body').append(feedback);
            feedback.fadeIn().delay(3000).fadeOut(function() {
              $(this).remove();
            });
          }
        }
      }
    }
  };

})(jQuery, Drupal);
