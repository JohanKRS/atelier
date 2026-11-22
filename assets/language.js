// The language menus, on the sign-in screen and in Settings. The choice is a cookie that the server reads
// (I18n::detect), so the page loads again in the new language; the session stays unlocked. A plain script, apart from
// the modules of the app, so the menu works as soon as the page shows: in a language other than English the modules
// wait for the file of translations before they run.
for (const menu of document.querySelectorAll('[data-language]')) menu.addEventListener('change', () => {
  const folder = location.pathname.replace(/[^/]*$/, '') || '/';
  document.cookie = `atelier_lang=${encodeURIComponent(menu.value)}; Path=${folder}; Max-Age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  location.reload();
});
