(() => {
  function initializeCaptchaStep() {
    const form = document.querySelector('#quote form');
    const captchaBox = form?.querySelector('.captchaBox');
    const captchaAnswer = form?.querySelector('#captchaAnswer');

    if (!form || !captchaBox || !captchaAnswer || form.dataset.captchaStepReady === 'true') return;

    form.dataset.captchaStepReady = 'true';
    captchaBox.hidden = true;
    captchaAnswer.disabled = true;

    form.addEventListener('submit', (event) => {
      if (!captchaBox.hidden) return;

      event.preventDefault();
      event.stopImmediatePropagation();
      captchaBox.hidden = false;
      captchaAnswer.disabled = false;

      const captchaImage = captchaBox.querySelector('#captchaImage');
      if (captchaImage) captchaImage.src = `/api/captcha.php?refresh=${Date.now()}`;

      captchaBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(() => captchaAnswer.focus(), 350);
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeCaptchaStep, { once: true });
  } else {
    initializeCaptchaStep();
  }
})();
