(() => {
  const translations = {
    pt: {
      phoneLabel: 'Telefone / WhatsApp internacional',
      phonePlaceholder: '+ código do país + número',
      units: ['MT — Tonelada métrica', 'Kg — Quilogramas', 'M³ — Metro cúbico', 'M² — Metro quadrado', 'Saca (60 kgs)', 'FCL', 'Outros (detalhe nos comentários)']
    },
    en: {
      phoneLabel: 'International phone / WhatsApp',
      phonePlaceholder: '+ country code + number',
      units: ['MT — Metric ton', 'Kg — Kilograms', 'M³ — Cubic metre', 'M² — Square metre', 'Bag (60 kg)', 'FCL', 'Other (detail in comments)']
    },
    es: {
      phoneLabel: 'Teléfono / WhatsApp internacional',
      phonePlaceholder: '+ código de país + número',
      units: ['MT — Tonelada métrica', 'Kg — Kilogramos', 'M³ — Metro cúbico', 'M² — Metro cuadrado', 'Saco (60 kg)', 'FCL', 'Otros (detalle en los comentarios)']
    },
    zh: {
      phoneLabel: '国际电话 / WhatsApp',
      phonePlaceholder: '+ 国家代码 + 电话号码',
      units: ['MT — 公吨', 'Kg — 千克', 'M³ — 立方米', 'M² — 平方米', '袋装（60千克）', 'FCL', '其他（请在备注中说明）']
    }
  };

  const unitValues = ['MT', 'Kg', 'M3', 'M2', 'Saca (60 kgs)', 'FCL', 'Outros (detalhe nos comentários)'];
  let scheduled = false;

  function currentLanguage() {
    const title = document.querySelector('.langs button.on')?.getAttribute('title') || 'Português';
    if (title === 'English') return 'en';
    if (title === 'Español') return 'es';
    if (title === '中文') return 'zh';
    return 'pt';
  }

  function syncPhone(copy) {
    const input = document.querySelector('#quote input[type="tel"]');
    if (!input) return;

    input.placeholder = copy.phonePlaceholder;
    input.setAttribute('inputmode', 'tel');
    input.setAttribute('pattern', '[+][0-9 ()-]{7,24}');
    input.maxLength = 25;
    if (!input.value) input.value = '+';

    const label = input.closest('label');
    const textNode = label && Array.from(label.childNodes).find(node => node.nodeType === Node.TEXT_NODE);
    if (textNode && textNode.nodeValue !== copy.phoneLabel) textNode.nodeValue = copy.phoneLabel;
  }

  function syncUnits(copy) {
    document.querySelectorAll('#quote .product select').forEach(select => {
      const values = Array.from(select.options).map(option => option.value);
      if (!values.includes('MT') || !values.includes('Kg')) return;

      const current = select.value.startsWith('FCL') ? 'FCL' : select.value;
      const expected = unitValues.join('|') + '::' + copy.units.join('|');
      const actual = Array.from(select.options).map(option => `${option.value}|${option.textContent}`).join('|');
      if (select.dataset.unitsVersion === expected && actual.includes('M3|') && actual.includes('M2|')) return;

      select.replaceChildren(...unitValues.map((value, index) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = copy.units[index];
        return option;
      }));
      select.value = unitValues.includes(current) ? current : 'MT';
      select.dataset.unitsVersion = expected;
    });
  }

  function sync() {
    scheduled = false;
    const copy = translations[currentLanguage()];
    syncPhone(copy);
    syncUnits(copy);
  }

  function scheduleSync() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(sync);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleSync, { once: true });
  } else {
    scheduleSync();
  }

  new MutationObserver(scheduleSync).observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class']
  });
})();
