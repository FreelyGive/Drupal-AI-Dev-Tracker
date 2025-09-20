/**
 * @file
 * Burndown chart functionality for deliverables.
 */

(function ($, Drupal) {
  'use strict';

  // Wait for Chart.js to be available
  var Chart = window.Chart;

  // Store chart instances to prevent memory leaks
  var chartInstances = {};

  Drupal.behaviors.aiDashboardBurndown = {
    attach: function (context, settings) {
      // Check if Chart.js is available
      if (!Chart) {
        console.error('Chart.js is not loaded');
        return;
      }

      // Only add burndown buttons to deliverable cards on project issues page
      $('.deliverables-section .deliverable-card', context).not('.burndown-processed').addClass('burndown-processed').each(function() {
        var $card = $(this);
        var nid = $card.data('nid');

        if (!nid) {
          console.warn('No data-nid found on deliverable card');
          return;
        }

        // Add burndown button and container with maximize button
        var $burndownButton = $('<button class="burndown-toggle" data-nid="' + nid + '">📊 <span class="toggle-text">Show</span> Burndown</button>');
        var $burndownContainer = $('<div class="burndown-container" id="burndown-' + nid + '" style="display: none;">' +
          '<div class="burndown-header">' +
          '<button class="burndown-maximize" title="Maximize chart">⛶</button>' +
          '</div>' +
          '<div class="burndown-content">' +
          '<div class="burndown-loading">Loading chart...</div>' +
          '<canvas class="burndown-chart"></canvas>' +
          '<div class="burndown-stats"></div>' +
          '</div>' +
          '</div>');

        // Insert after progress bar or at end of card details
        var $cardDetails = $card.find('.card-details, .card-progress').last();
        if ($cardDetails.length) {
          $cardDetails.after($burndownButton);
        } else {
          $card.append($burndownButton);
        }
        $burndownButton.after($burndownContainer);

        // Handle button click
        $burndownButton.on('click', function(e) {
          e.preventDefault();
          e.stopPropagation(); // Prevent card click if it has one

          var $button = $(this);
          var $container = $('#burndown-' + nid);
          var isVisible = $container.is(':visible');

          if (!isVisible) {
            // Show and load chart
            $container.slideDown(300);
            $button.find('.toggle-text').text('Hide');
            $button.addClass('active');

            // Load chart if not already loaded
            if (!chartInstances['burndown-' + nid]) {
              loadBurndownChart(nid);
            }
          } else {
            // Hide chart
            $container.slideUp(300);
            $button.find('.toggle-text').text('Show');
            $button.removeClass('active');
          }
        });

        // Handle maximize button click
        $burndownContainer.find('.burndown-maximize').on('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          maximizeBurndown(nid);
        });
      });

      // Add modal container if it doesn't exist
      if (!$('#burndown-modal').length) {
        $('body').append(
          '<div id="burndown-modal" class="burndown-modal" style="display: none;">' +
          '<div class="burndown-modal-content">' +
          '<div class="burndown-modal-header">' +
          '<h2 class="burndown-modal-title">Burndown Chart</h2>' +
          '<button class="burndown-modal-close">×</button>' +
          '</div>' +
          '<div class="burndown-modal-body">' +
          '<canvas class="burndown-modal-chart"></canvas>' +
          '<div class="burndown-modal-stats"></div>' +
          '</div>' +
          '</div>' +
          '</div>'
        );

        // Close modal handlers
        $('#burndown-modal .burndown-modal-close').on('click', function() {
          closeBurndownModal();
        });

        $('#burndown-modal').on('click', function(e) {
          if (e.target === this) {
            closeBurndownModal();
          }
        });

        // ESC key to close
        $(document).on('keydown', function(e) {
          if (e.key === 'Escape' && $('#burndown-modal').is(':visible')) {
            closeBurndownModal();
          }
        });
      }
    }
  };

  /**
   * Load and render burndown chart.
   */
  function loadBurndownChart(nid) {
    var containerId = 'burndown-' + nid;
    var $container = $('#' + containerId);
    var $canvas = $container.find('.burndown-chart');
    var $loading = $container.find('.burndown-loading');
    var $stats = $container.find('.burndown-stats');

    // Fetch burndown data
    $.ajax({
      url: '/ai-dashboard/deliverable/' + nid + '/burndown-data',
      method: 'GET',
      success: function(data) {
        $loading.hide();

        // Prepare chart data
        var labels = data.actual.map(function(point) {
          return formatDate(point.date);
        });

        var actualData = data.actual.map(function(point) {
          return point.remaining;
        });

        var idealData = data.ideal.map(function(point) {
          return point.remaining;
        });

        // Create or update chart
        var ctx = $canvas[0].getContext('2d');

        if (chartInstances[containerId]) {
          chartInstances[containerId].destroy();
        }

        chartInstances[containerId] = new Chart(ctx, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [{
              label: 'Actual Progress',
              data: actualData,
              borderColor: '#9333ea',
              backgroundColor: 'rgba(147, 51, 234, 0.1)',
              tension: 0.1,
              borderWidth: 2,
              pointRadius: 3,
              pointHoverRadius: 5
            }, {
              label: 'Ideal Burndown',
              data: idealData,
              borderColor: '#10b981',
              backgroundColor: 'transparent',
              borderDash: [5, 5],
              tension: 0,
              borderWidth: 1,
              pointRadius: 0,
              pointHoverRadius: 0
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              intersect: false,
              mode: 'index'
            },
            plugins: {
              legend: {
                display: true,
                position: 'bottom',
                labels: {
                  padding: 15,
                  font: {
                    size: 12
                  }
                }
              },
              tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleFont: {
                  size: 13
                },
                bodyFont: {
                  size: 12
                },
                padding: 10,
                callbacks: {
                  label: function(context) {
                    var label = context.dataset.label || '';
                    if (label) {
                      label += ': ';
                    }
                    label += context.parsed.y + ' issues remaining';
                    return label;
                  }
                }
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: 'Issues Remaining',
                  font: {
                    size: 12
                  }
                },
                ticks: {
                  stepSize: 1,
                  font: {
                    size: 11
                  }
                }
              },
              x: {
                title: {
                  display: true,
                  text: 'Date',
                  font: {
                    size: 12
                  }
                },
                ticks: {
                  maxRotation: 45,
                  minRotation: 45,
                  font: {
                    size: 10
                  }
                }
              }
            }
          }
        });

        // Add stats below chart
        var statsHtml = '<div class="burndown-stats-content">';
        statsHtml += '<div class="stat-item"><strong>Total Issues:</strong> ' + data.total + '</div>';
        statsHtml += '<div class="stat-item"><strong>Completed:</strong> ' + data.completed + ' (' + Math.round((data.completed / data.total) * 100) + '%)</div>';
        statsHtml += '<div class="stat-item"><strong>Remaining:</strong> ' + data.remaining + '</div>';

        if (data.velocity.weekly > 0) {
          statsHtml += '<div class="stat-item"><strong>Weekly Velocity:</strong> ' + data.velocity.weekly + ' issues/week</div>';
          if (data.velocity.projected_completion) {
            statsHtml += '<div class="stat-item"><strong>Projected Completion:</strong> ' + formatDate(data.velocity.projected_completion) + '</div>';
          }
        }

        if (data.dates.due) {
          statsHtml += '<div class="stat-item"><strong>Due Date:</strong> ' + formatDate(data.dates.due) + '</div>';
        }

        statsHtml += '</div>';
        $stats.html(statsHtml);

        // Set canvas height
        $canvas.css('height', '250px');
      },
      error: function(xhr) {
        $loading.text('Failed to load burndown chart');
        console.error('Burndown chart error:', xhr);
      }
    });
  }

  /**
   * Format date for display.
   */
  function formatDate(dateStr) {
    var date = new Date(dateStr);
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[date.getMonth()] + ' ' + date.getDate();
  }

  /**
   * Maximize burndown chart in modal.
   */
  function maximizeBurndown(nid) {
    var $modal = $('#burndown-modal');
    var $modalCanvas = $modal.find('.burndown-modal-chart');
    var $modalStats = $modal.find('.burndown-modal-stats');
    var $modalTitle = $modal.find('.burndown-modal-title');

    // Get the deliverable title
    var $card = $('.deliverable-card[data-nid="' + nid + '"]');
    var title = $card.find('h4 a').text() || 'Burndown Chart';
    $modalTitle.text(title + ' - Burndown Chart');

    // Show modal
    $modal.fadeIn(300);
    $('body').addClass('burndown-modal-open');

    // Fetch and render larger chart
    $.ajax({
      url: '/ai-dashboard/deliverable/' + nid + '/burndown-data',
      method: 'GET',
      success: function(data) {
        // Prepare chart data
        var labels = data.actual.map(function(point) {
          return formatDate(point.date);
        });

        var actualData = data.actual.map(function(point) {
          return point.remaining;
        });

        var idealData = data.ideal.map(function(point) {
          return point.remaining;
        });

        // Create larger chart
        var ctx = $modalCanvas[0].getContext('2d');

        // Destroy existing modal chart if it exists
        if (chartInstances['modal-burndown']) {
          chartInstances['modal-burndown'].destroy();
        }

        chartInstances['modal-burndown'] = new Chart(ctx, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [{
              label: 'Actual Progress',
              data: actualData,
              borderColor: '#9333ea',
              backgroundColor: 'rgba(147, 51, 234, 0.1)',
              tension: 0.1,
              borderWidth: 3,
              pointRadius: 4,
              pointHoverRadius: 6
            }, {
              label: 'Ideal Burndown',
              data: idealData,
              borderColor: '#10b981',
              backgroundColor: 'transparent',
              borderDash: [5, 5],
              tension: 0,
              borderWidth: 2,
              pointRadius: 0,
              pointHoverRadius: 0
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              intersect: false,
              mode: 'index'
            },
            plugins: {
              legend: {
                display: true,
                position: 'bottom',
                labels: {
                  padding: 20,
                  font: {
                    size: 14
                  }
                }
              },
              tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleFont: {
                  size: 14
                },
                bodyFont: {
                  size: 13
                },
                padding: 12,
                callbacks: {
                  label: function(context) {
                    var label = context.dataset.label || '';
                    if (label) {
                      label += ': ';
                    }
                    label += context.parsed.y + ' issues remaining';
                    return label;
                  }
                }
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: 'Issues Remaining',
                  font: {
                    size: 14,
                    weight: 'bold'
                  }
                },
                ticks: {
                  stepSize: 1,
                  font: {
                    size: 12
                  }
                }
              },
              x: {
                title: {
                  display: true,
                  text: 'Date',
                  font: {
                    size: 14,
                    weight: 'bold'
                  }
                },
                ticks: {
                  maxRotation: 45,
                  minRotation: 45,
                  font: {
                    size: 11
                  }
                }
              }
            }
          }
        });

        // Copy stats to modal
        var $originalStats = $('#burndown-' + nid + ' .burndown-stats').html();
        $modalStats.html($originalStats);

        // Set canvas height for modal
        $modalCanvas.css('height', '500px');
      },
      error: function(xhr) {
        $modalStats.html('<div class="error">Failed to load burndown chart</div>');
      }
    });
  }

  /**
   * Close burndown modal.
   */
  function closeBurndownModal() {
    var $modal = $('#burndown-modal');
    $modal.fadeOut(300);
    $('body').removeClass('burndown-modal-open');

    // Destroy modal chart to free memory
    if (chartInstances['modal-burndown']) {
      chartInstances['modal-burndown'].destroy();
      delete chartInstances['modal-burndown'];
    }
  }

})(jQuery, Drupal);