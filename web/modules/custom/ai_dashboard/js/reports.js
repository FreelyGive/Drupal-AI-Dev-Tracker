/**
 * @file
 * JavaScript for AI Dashboard Reports.
 */

(function ($, Drupal, once) {
  'use strict';

  // Global function for toggling custom date fields
  window.toggleCustomDates = function(select) {
    var customDates = document.getElementById("custom-dates");
    if (customDates) {
      customDates.style.display = select.value === "custom" ? "inline-block" : "none";
    }
  };

  Drupal.behaviors.aiDashboardReports = {
    attach: function (context, settings) {
      // Handle filter form submission
      once('filter-form', '#apply-filter-btn', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();

          // Get form values
          var dateFilter = document.getElementById('date-filter').value;
          var params = new URLSearchParams();

          if (dateFilter !== 'all') {
            params.append('date_filter', dateFilter);
          }

          if (dateFilter === 'custom') {
            var startDate = document.getElementById('start-date').value;
            var endDate = document.getElementById('end-date').value;

            if (startDate) {
              params.append('start_date', startDate);
            }
            if (endDate) {
              params.append('end_date', endDate);
            }
          }

          // Build URL and navigate
          var baseUrl = '/ai-dashboard/reports/untracked-users';
          var queryString = params.toString();
          window.location.href = queryString ? baseUrl + '?' + queryString : baseUrl;
        });
      });

      // Initialize copy to clipboard functionality
      once('copy-csv', '#copy-csv-btn', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const csvOutput = document.querySelector('.csv-output');
          if (csvOutput) {
            // Create a temporary textarea to copy from
            const textarea = document.createElement('textarea');
            textarea.value = csvOutput.textContent;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);

            // Select and copy
            textarea.select();
            document.execCommand('copy');

            // Remove temporary element
            document.body.removeChild(textarea);

            // Update button text temporarily
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.add('button--success');

            setTimeout(function () {
              button.textContent = originalText;
              button.classList.remove('button--success');
            }, 2000);
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);