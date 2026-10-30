(function () {
  // Confirm before destructive actions
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  // Mobile navigation
  var btn = document.getElementById('menuBtn'), side = document.getElementById('side');
  if (btn && side) btn.addEventListener('click', function () {
    var open = side.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  // "Mark all present" on the attendance register
  document.querySelectorAll('[data-mark-all]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('input[type=radio][value="' + b.getAttribute('data-mark-all') + '"]').forEach(function (r) { r.checked = true; });
    });
  });
  // Print button
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
})();
