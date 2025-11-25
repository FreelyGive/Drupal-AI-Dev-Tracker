(function ($, Drupal) {
  'use strict';

  /**
   * Focus tooltips using PopperJS for proper positioning
   */
  Drupal.behaviors.aiFocusTooltips = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }

      var self = this;
      
      // Initialize tooltips when PopperJS is available
      if (typeof Popper !== 'undefined') {
        self.initPopperTooltips();
      } else {
        // PopperJS not available - tooltips will use native browser behavior
      }
    },

    /**
     * Initialize PopperJS-based tooltips
     */
    initPopperTooltips: function() {
      var tooltipInstances = [];

      $(document).on('mouseenter', '.developer-focus, .issue-blocked, .column-title', function(e) {
        var $element = $(this);
        var tooltipText = $element.attr('title');
        
        if (!tooltipText) return;
        
        // Remove title to prevent browser tooltip
        $element.removeAttr('title').attr('data-original-title', tooltipText);
        
        // Create tooltip element
        var tooltip = $('<div class="popper-tooltip">' + tooltipText + '</div>');
        $('body').append(tooltip);
        
        // Create PopperJS instance
        var popperInstance = Popper.createPopper(this, tooltip[0], {
          placement: 'top',
          modifiers: [
            {
              name: 'offset',
              options: {
                offset: [0, 8],
              },
            },
            {
              name: 'preventOverflow',
              options: {
                boundary: 'viewport',
              },
            },
            {
              name: 'flip',
              options: {
                fallbackPlacements: ['bottom', 'left', 'right'],
              },
            },
          ],
        });
        
        // Store instance for cleanup
        tooltipInstances.push({
          element: this,
          tooltip: tooltip[0],
          popper: popperInstance
        });
        
        // Show tooltip
        tooltip.addClass('show');
      });

      $(document).on('mouseleave', '.developer-focus, .issue-blocked, .column-title', function(e) {
        var $element = $(this);
        
        // Restore original title
        var originalTitle = $element.attr('data-original-title');
        if (originalTitle) {
          $element.attr('title', originalTitle).removeAttr('data-original-title');
        }
        
        // Find and destroy tooltip instance
        var instanceIndex = tooltipInstances.findIndex(function(instance) {
          return instance.element === e.currentTarget;
        });
        
        if (instanceIndex !== -1) {
          var instance = tooltipInstances[instanceIndex];
          instance.popper.destroy();
          $(instance.tooltip).remove();
          tooltipInstances.splice(instanceIndex, 1);
        }
      });
    }
  };

})(jQuery, Drupal);