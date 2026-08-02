// Disable a form's submit button until its required fields are filled in.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form').forEach(function (form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (!submitBtn || !form.querySelector('[required]')) return;

    function updateState() {
      submitBtn.disabled = !form.checkValidity();
    }

    form.addEventListener('input', updateState);
    form.addEventListener('change', updateState);
    updateState();
  });
});
