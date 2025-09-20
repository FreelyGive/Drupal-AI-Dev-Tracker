/**
 * @file
 * AI Deliverables Roadmap JavaScript.
 * Handles drag-drop ordering for admin users.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.aiDashboardRoadmap = {
    attach: function (context, settings) {
      // Initialize once - check if already initialized
      if ($('.ai-roadmap', context).hasClass('roadmap-initialized')) {
        return;
      }
      $('.ai-roadmap', context).addClass('roadmap-initialized');

      // Make cards with projects clickable
      $('.deliverable-card.has-project', context).not('.card-click-initialized').each(function() {
        var $card = $(this);
        $card.addClass('card-click-initialized');
        var projectUrl = $card.data('project-url');

        if (projectUrl) {
          $card.css('cursor', 'pointer');

          // Add click handler to card (except on direct links)
          $card.on('click', function(e) {
            // Don't navigate if clicking on a link inside the card
            if (!$(e.target).closest('a').length) {
              window.location.href = projectUrl;
            }
          });
        }
      });

      // Add drag-drop functionality for admin users
      // Check if user has admin permission (save button will be present)
      if ($('#save-roadmap-order').length) {
        // Wait for Sortable to load
        if (typeof Sortable === 'undefined') {
          // Try again in a moment
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

          // Get existing save button
          var $saveButton = $('#save-roadmap-order');

          // Add click handler to save button
          if ($saveButton.length) {
            $saveButton.on('click', function() {
              saveRoadmapOrder();
            });
          }

          // Initialize Sortable on each column
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
                // Show save button when order changes
                if (!hasChanges) {
                  hasChanges = true;
                  $('#save-roadmap-order').fadeIn();
                }
              }
            });
          });

          // Save order function
          function saveRoadmapOrder() {
            var data = { columns: {} };

            // Collect order from each column
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

            // Send AJAX request
            $.ajax({
              url: '/ai-dashboard/roadmap/save-order',
              method: 'POST',
              contentType: 'application/json',
              data: JSON.stringify(data),
              success: function(response) {
                // Show success message
                showFeedback('Changes saved successfully!', 'success');
                hasChanges = false;
                $('#save-roadmap-order').fadeOut();
              },
              error: function(xhr) {
                showFeedback('Failed to save changes. Please try again.', 'error');
              }
            });
          }

          // Show feedback message
          function showFeedback(message, type) {
            var feedback = $('<div class="roadmap-feedback ' + type + '">' + message + '</div>');
            $('body').append(feedback);
            feedback.fadeIn().delay(3000).fadeOut(function() {
              $(this).remove();
            });
          }
        } // End of initializeDragDrop
      } // End of admin check
    }
  };

})(jQuery, Drupal);