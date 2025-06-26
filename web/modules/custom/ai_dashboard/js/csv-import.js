(function ($, Drupal, drupalSettings) {
  'use strict';

  console.log('CSV Import JavaScript loaded');

  Drupal.behaviors.csvImport = {
    attach: function (context, settings) {
      console.log('CSV Import behavior attaching');
      console.log('Context:', context);
      console.log('Settings:', settings);
      console.log('drupalSettings:', drupalSettings);
      
      $('.contributor-csv-import-form', context).once('csv-import').each(function () {
        console.log('Found CSV import form:', this);
        var $form = $(this);
        var $submitBtn = $form.find('.import-btn');
        var $fileInput = $form.find('input[type="file"]');
        var $resultsContainer = $('#import-results');
        
        console.log('Submit button found:', $submitBtn.length);
        console.log('File input found:', $fileInput.length);
        console.log('Results container found:', $resultsContainer.length);
        
        // Handle form submission
        $submitBtn.on('click', function (e) {
          console.log('Import button clicked!');
          e.preventDefault();
          
          var file = $fileInput[0].files[0];
          if (!file) {
            alert('Please select a CSV file to upload.');
            return;
          }
          
          // Validate file type
          if (!file.name.toLowerCase().endsWith('.csv')) {
            alert('Please select a CSV file.');
            return;
          }
          
          // Show loading state
          $submitBtn.prop('disabled', true);
          $submitBtn.text('Processing...');
          $resultsContainer.removeClass('hidden').html('<div class="loading">Processing CSV file...</div>');
          
          // Create FormData
          var formData = new FormData();
          formData.append('csv_file', file);
          
          // Get CSRF token
          var csrfToken = drupalSettings.aiDashboard.csvImport.csrfToken;
          
          // Add CSRF token to FormData
          formData.append('_token', csrfToken);
          
          console.log('Uploading to:', drupalSettings.aiDashboard.csvImport.uploadUrl);
          console.log('File:', file);
          console.log('CSRF Token:', csrfToken);
          
          // Upload file
          $.ajax({
            url: drupalSettings.aiDashboard.csvImport.uploadUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
              'X-CSRF-Token': csrfToken
            },
            success: function (response) {
              console.log('Success response:', response);
              displayResults(response);
            },
            error: function (xhr, status, error) {
              console.log('Error response:', xhr, status, error);
              var errorMsg = 'Upload failed. Please try again.';
              if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
              } else if (xhr.responseText) {
                try {
                  var errorResponse = JSON.parse(xhr.responseText);
                  if (errorResponse.message) {
                    errorMsg = errorResponse.message;
                  }
                } catch (e) {
                  errorMsg = 'Server error: ' + xhr.status + ' ' + xhr.statusText;
                }
              }
              displayResults({
                success: false,
                message: errorMsg
              });
            },
            complete: function () {
              // Reset button
              $submitBtn.prop('disabled', false);
              $submitBtn.text('Import Contributors');
            }
          });
        });
        
        function displayResults(response) {
          var $results = $resultsContainer;
          $results.removeClass('hidden');
          
          if (response.success) {
            $results.html(
              '<div class="alert alert-success">' +
              '<h3>Import Successful!</h3>' +
              '<p>' + response.message + '</p>' +
              (response.results && response.results.error_details && response.results.error_details.length > 0 ? 
                '<details><summary>Error Details</summary><ul>' +
                response.results.error_details.map(function(error) {
                  return '<li>' + error + '</li>';
                }).join('') +
                '</ul></details>' : '') +
              '</div>'
            );
            
            // Clear the file input
            $fileInput.val('');
            
            // Optionally refresh the page after a short delay
            setTimeout(function() {
              if (confirm('Import completed successfully. Would you like to view the contributors list?')) {
                window.location.href = '/ai-dashboard/admin/contributors';
              }
            }, 2000);
            
          } else {
            $results.html(
              '<div class="alert alert-error">' +
              '<h3>Import Failed</h3>' +
              '<p>' + response.message + '</p>' +
              '</div>'
            );
          }
        }
        
        // Handle file selection
        $fileInput.on('change', function () {
          var file = this.files[0];
          if (file) {
            var fileName = file.name;
            var fileSize = (file.size / 1024).toFixed(1) + ' KB';
            
            // Show file info
            var $fileInfo = $form.find('.file-info');
            if ($fileInfo.length === 0) {
              $fileInfo = $('<div class="file-info"></div>');
              $fileInput.after($fileInfo);
            }
            
            $fileInfo.html(
              '<div class="selected-file">' +
              '<strong>Selected:</strong> ' + fileName + ' (' + fileSize + ')' +
              '</div>'
            );
          }
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);