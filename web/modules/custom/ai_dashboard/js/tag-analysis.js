(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.tagAnalysis = {
    attach: function (context, settings) {
      // Filter functionality
      $('.filter-btn', context).once('tag-filter').on('click', function() {
        var $btn = $(this);
        var filter = $btn.data('filter');
        
        // Update active state
        $('.filter-btn').removeClass('active');
        $btn.addClass('active');
        
        // Filter tag items
        $('.tag-item').each(function() {
          var $item = $(this);
          var shouldShow = true;
          
          switch (filter) {
            case 'unmapped':
              shouldShow = $item.data('mapped') === false;
              break;
            case 'track':
              shouldShow = $item.data('has-track') === true;
              break;
            case 'workstream':
              shouldShow = $item.data('has-workstream') === true;
              break;
            case 'all':
            default:
              shouldShow = true;
              break;
          }
          
          if (shouldShow) {
            $item.removeClass('hidden');
          } else {
            $item.addClass('hidden');
          }
        });
      });

      // Quick mapping functionality with buttons
      $('.quick-map-btn', context).once('quick-mapping').on('click', function() {
        var $btn = $(this);
        var tag = $btn.data('tag');
        var type = $btn.data('type');
        var $input = $btn.siblings('.quick-input');
        var value = $input.val().trim();
        
        if (value) {
          // Create the mapping via AJAX
          createTagMapping(tag, type, value, $btn);
        } else {
          Drupal.announce('Please enter a ' + type + ' name', 'assertive');
          $input.focus();
        }
      });

      // Allow Enter key to trigger mapping
      $('.quick-input', context).once('quick-input').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
          $(this).siblings('.quick-map-btn').click();
        }
      });
    }
  };

  function createTagMapping(tag, type, value, $element) {
    // Show loading state
    $element.prop('disabled', true);
    var $input = $element.siblings('.quick-input');
    $input.prop('disabled', true);
    
    // Get CSRF token and create mapping
    $.get('/session/token').done(function(token) {
      $.ajax({
        url: '/ai-dashboard/api/create-quick-mapping',
        method: 'POST',
        headers: {
          'X-CSRF-Token': token
        },
        data: {
          tag: tag,
          type: type,
          value: value
        },
        success: function(response) {
          if (response.success) {
            // Show success message
            Drupal.announce('Tag mapping created successfully');
            
            // Hide the input and show a success indicator
            var $container = $element.closest('.quick-mapping');
            $container.html('<span class="mapping-created">✓ ' + type.charAt(0).toUpperCase() + type.slice(1) + ': ' + value + '</span>');
            
            // Optionally reload the page after a delay to show updated mappings
            setTimeout(function() {
              location.reload();
            }, 2000);
          } else {
            throw new Error(response.message || 'Unknown error');
          }
        },
        error: function(xhr) {
          var errorMsg = 'Error creating tag mapping. Please try manually.';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMsg = xhr.responseJSON.message;
          }
          
          // Show error and re-enable
          $element.prop('disabled', false);
          $input.prop('disabled', false);
          $input.val('');
          Drupal.announce(errorMsg, 'assertive');
        }
      });
    });
  }

})(jQuery, Drupal);