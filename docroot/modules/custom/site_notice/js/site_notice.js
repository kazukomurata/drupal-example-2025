(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.siteNotice = {
    attach: function (context) {
      const key = drupalSettings?.siteNotice?.storageKey || 'site_notice_default';
      const closable = !!drupalSettings?.siteNotice?.closable;
      const root = context.querySelector('[data-site-notice]');
      if (!root) return;

      // 既に閉じていれば非表示
      try {
        if (localStorage.getItem(key) === '1') {
          root.style.display = 'none';
          return;
        }
      } catch (e) {
        // localStorage使えない環境は何もしない
      }

      if (closable) {
        const btn = root.querySelector('[data-site-notice-close]');
        if (btn) {
          btn.addEventListener('click', function () {
            root.style.display = 'none';
            try { localStorage.setItem(key, '1'); } catch (e) {}
          });
        }
      }
    }
  };
})(Drupal, drupalSettings);
