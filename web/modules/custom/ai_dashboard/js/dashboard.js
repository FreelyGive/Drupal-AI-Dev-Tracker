(function ($, Drupal) {
  'use strict';

  /**
   * AI Dashboard JavaScript functionality.
   */
  Drupal.behaviors.aiDashboard = {
    attach: function (context, settings) {
      
      // Initialize filters
      $('.status-filter, .module-filter', context).once('ai-dashboard-filters').on('change', function() {
        // In a real implementation, this would filter the table via AJAX
      });

      // Initialize charts if data is available
      if (settings.aiDashboard && settings.aiDashboard.weeklyData) {
        this.initWeeklyChart(settings.aiDashboard.weeklyData);
      }

      // Add click handlers for dashboard cards
      $('.dashboard-card', context).once('ai-dashboard-cards').on('click', function(e) {
        if (!$(e.target).is('a')) {
          var link = $(this).find('a');
          if (link.length) {
            window.location.href = link.attr('href');
          }
        }
      });

      // Initialize tooltips and interactive elements
      this.initInteractiveElements(context);
    },

    /**
     * Initialize weekly allocation chart.
     */
    initWeeklyChart: function(weeklyData) {
      // This would use a charting library like Chart.js or D3.js
      // For now, just log the data structure
      
      // Example of what this might look like with Chart.js:
      /*
      var ctx = document.getElementById('weekly-allocation-chart');
      if (ctx) {
        new Chart(ctx, {
          type: 'line',
          data: {
            labels: weeklyData.map(item => item.week),
            datasets: [{
              label: 'Contributor 1',
              data: weeklyData.map(item => item.contributor1),
              borderColor: 'rgb(75, 192, 192)',
              tension: 0.1
            }, {
              label: 'Contributor 2', 
              data: weeklyData.map(item => item.contributor2),
              borderColor: 'rgb(255, 99, 132)',
              tension: 0.1
            }]
          },
          options: {
            responsive: true,
            plugins: {
              title: {
                display: true,
                text: 'Weekly Resource Allocation'
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: 'Days Allocated'
                }
              },
              x: {
                title: {
                  display: true,
                  text: 'Week'
                }
              }
            }
          }
        });
      }
      */
    },

    /**
     * Initialize interactive elements.
     */
    initInteractiveElements: function(context) {
      // Add status and priority classes based on content
      $('.issues-table tbody tr', context).each(function() {
        var $row = $(this);
        var status = $row.find('td:nth-child(4)').text().toLowerCase().replace(/\s+/g, '-');
        var priority = $row.find('td:nth-child(5)').text().toLowerCase();
        
        $row.find('td:nth-child(4)').addClass('status-' + status);
        $row.find('td:nth-child(5)').addClass('priority-' + priority);
      });

      // Add hover effects and click handlers
      $('.contributors-table tbody tr, .issues-table tbody tr', context).once('table-interactions').hover(
        function() {
          $(this).css('background-color', '#f8f9fa');
        },
        function() {
          $(this).css('background-color', '');
        }
      );

      // Real-time updates (would connect to WebSocket or polling in real implementation)
      if (context === document) {
        this.startRealTimeUpdates();
      }
    },

    /**
     * Start real-time updates for dashboard.
     */
    startRealTimeUpdates: function() {
      // This would implement WebSocket connections or periodic AJAX calls
      // to update dashboard data in real-time
      
      // Example periodic update:
      /*
      setInterval(function() {
        $.ajax({
          url: '/admin/ai-dashboard/api/stats',
          method: 'GET',
          success: function(data) {
            // Update dashboard statistics
            $('.stat-number').each(function(index) {
              $(this).text(data.stats[index]);
            });
          }
        });
      }, 30000); // Update every 30 seconds
      */
    }
  };

  /**
   * Utility functions for dashboard.
   */
  Drupal.aiDashboard = {
    
    /**
     * Format dates consistently.
     */
    formatDate: function(dateString) {
      var date = new Date(dateString);
      return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
      });
    },

    /**
     * Format resource allocation display.
     */
    formatAllocation: function(days) {
      if (days === 0) return 'No allocation';
      if (days < 1) return days + ' days (part-time)';
      if (days === 1) return '1 day';
      return days + ' days';
    },

    /**
     * Get status badge HTML.
     */
    getStatusBadge: function(status) {
      var badges = {
        'active': '<span class="badge badge-success">Active</span>',
        'needs_review': '<span class="badge badge-warning">Needs Review</span>',
        'needs_work': '<span class="badge badge-danger">Needs Work</span>',
        'rtbc': '<span class="badge badge-info">RTBC</span>',
        'fixed': '<span class="badge badge-secondary">Fixed</span>'
      };
      return badges[status] || '<span class="badge badge-light">' + status + '</span>';
    },

    /**
     * Filter table rows based on criteria.
     */
    filterTable: function(tableSelector, filterCriteria) {
      $(tableSelector + ' tbody tr').each(function() {
        var $row = $(this);
        var showRow = true;

        $.each(filterCriteria, function(column, value) {
          if (value && $row.find('td:nth-child(' + column + ')').text().indexOf(value) === -1) {
            showRow = false;
            return false;
          }
        });

        $row.toggle(showRow);
      });
    }
  };

})(jQuery, Drupal);