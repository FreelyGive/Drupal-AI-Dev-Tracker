(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.dailyDigest = {
    attach: function (context) {
      // Tab switching.
      once('digest-tabs', '.daily-digest__tabs', context).forEach(function (tablist) {
        const tabs = tablist.querySelectorAll('.daily-digest__tab');

        tabs.forEach(function (tab) {
          tab.addEventListener('click', function () {
            const panelId = tab.getAttribute('aria-controls');
            const target = document.getElementById(panelId);

            tabs.forEach(function (t) {
              t.classList.remove('daily-digest__tab--active');
              t.setAttribute('aria-selected', 'false');
              t.setAttribute('tabindex', '-1');
            });
            document.querySelectorAll('.daily-digest__panel').forEach(function (p) {
              p.classList.add('daily-digest__panel--hidden');
              p.setAttribute('hidden', '');
            });

            tab.classList.add('daily-digest__tab--active');
            tab.setAttribute('aria-selected', 'true');
            tab.removeAttribute('tabindex');
            if (target) {
              target.classList.remove('daily-digest__panel--hidden');
              target.removeAttribute('hidden');
            }
          });
        });
      });

      // Build table of contents inside each panel.
      once('digest-toc', '.daily-digest__toc', context).forEach(function (toc) {
        const panel = toc.closest('.daily-digest__panel');
        if (!panel) { toc.remove(); return; }

        const isExecutive = panel.id === 'digest-panel-executive';
        const modules = panel.querySelectorAll('.digest-module');
        if (!modules.length) { toc.remove(); return; }

        // Hide all project sections in the executive panel by default.
        if (isExecutive) {
          modules.forEach(function (section) {
            section.classList.add('digest-module--collapsed');
          });
        }

        const ul = document.createElement('ul');

        modules.forEach(function (section) {
          // Project title is the first h3 (or h2 on older nodes) in the section.
          const heading = section.querySelector('h3') || section.querySelector('h2');
          if (!heading) return;

          if (!section.id) {
            section.id = 'digest-module-' + heading.textContent.trim()
              .toLowerCase()
              .replace(/[^a-z0-9]+/g, '-')
              .replace(/^-|-$/g, '');
          }

          const li = document.createElement('li');
          const a = document.createElement('a');
          a.textContent = heading.textContent.trim();

          if (isExecutive) {
            // Toggle section visibility on click instead of jumping to anchor.
            a.href = '#' + section.id;
            a.setAttribute('aria-expanded', 'false');
            a.addEventListener('click', function (e) {
              e.preventDefault();
              const isCollapsed = section.classList.contains('digest-module--collapsed');
              // Close all modules first.
              panel.querySelectorAll('.digest-module').forEach(function (s) {
                s.classList.add('digest-module--collapsed');
              });
              ul.querySelectorAll('a[aria-expanded]').forEach(function (link) {
                link.setAttribute('aria-expanded', 'false');
              });
              // Open this one if it was previously closed.
              if (isCollapsed) {
                section.classList.remove('digest-module--collapsed');
                a.setAttribute('aria-expanded', 'true');
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
              }
            });
          } else {
            a.href = '#' + section.id;
          }

          li.appendChild(a);
          ul.appendChild(li);
        });

        if (ul.children.length) {
          toc.appendChild(ul);
        } else {
          toc.remove();
          return;
        }

        // Move the TOC so it sits between capabilities and the first module.
        // Template puts toc after .daily-digest__content; reposition it here.
        const firstModule = modules[0];
        const capabilities = panel.querySelector('.digest-capabilities-2026');
        const insertAfter = capabilities || panel.querySelector('.daily-digest__tldr');
        if (insertAfter && insertAfter.nextSibling) {
          insertAfter.parentNode.insertBefore(toc, insertAfter.nextSibling);
        } else if (firstModule && firstModule.parentNode) {
          firstModule.parentNode.insertBefore(toc, firstModule);
        }
      });
    }
  };

}(Drupal, once));
