(() => {
  'use strict';

  const qs = (s, r = document) => r.querySelector(s);
  const el = (tag, cls, html = '') => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (html) node.innerHTML = html;
    return node;
  };

  function buildHeader() {
    const oldLogo = qs('#logo');
    const oldHeader = qs('.container > .header');
    if (!oldLogo || !oldHeader || qs('.rgx-header')) return;

    const header = el('header', 'rgx-header');
    const top = el('div', 'rgx-headbar');
    const logo = el('a', 'rgx-logo');
    logo.href = 'https://www.regatix.com';
    logo.innerHTML = '<img src="https://www.regatix.com/media/regatixshoplogo.png" alt="REGATIX Shop">';

    const search = el('form', 'rgx-search');
    search.role = 'search';
    search.innerHTML = '<input type="search" placeholder="Produkte oder Artikelnummer suchen"><button type="submit" aria-label="Suchen">⌕</button>';
    search.addEventListener('submit', e => e.preventDefault());

    const actions = el('div', 'rgx-actions');
    const productForm = oldHeader.querySelector('form');
    const productSelect = productForm ? productForm.closest('form') : null;
    const cart = oldHeader.querySelector('a[href="cart.php"]');
    const withdrawal = [...oldHeader.querySelectorAll('a')].find(a => /widerrufen/i.test(a.textContent || ''));
    const layout = qs('#pv_layout_toggle');

    if (productSelect) {
      productSelect.classList.add('rgx-product-switch');
      actions.appendChild(productSelect);
    }
    if (cart) {
      cart.className = 'rgx-action';
      actions.appendChild(cart);
    }
    if (withdrawal) {
      withdrawal.className = 'rgx-action';
      actions.appendChild(withdrawal);
    }
    if (layout) {
      layout.className = 'rgx-action';
      actions.appendChild(layout);
    }

    const mobile = el('button', 'rgx-mobile-menu', '☰');
    mobile.type = 'button';
    mobile.setAttribute('aria-label', 'Navigation öffnen');

    top.append(logo, search, actions, mobile);

    const nav = el('nav', 'rgx-nav');
    const navInner = el('div', 'rgx-nav-inner');
    ['Fachbodenregale','Palettenregale','Kragarmregale','Betriebseinrichtung','Angebote','Kontakt'].forEach(label => {
      const a = el('a', '', label);
      a.href = '#';
      navInner.appendChild(a);
    });
    nav.appendChild(navInner);
    mobile.addEventListener('click', () => nav.classList.toggle('open'));

    header.append(top, nav);
    document.body.insertBefore(header, document.body.firstChild);
    oldLogo.remove();
    oldHeader.remove();
  }

  function buildProductLayout() {
    const container = qs('.container');
    const mainCard = qs('.container > .card:last-of-type');
    const grid = mainCard ? qs('.grid', mainCard) : null;
    if (!container || !mainCard || !grid || qs('.rgx-grid')) return;

    container.classList.add('rgx-shell');

    const title = (qs('.h-title')?.textContent || document.body.dataset.productLabel || 'Produkt').trim();
    const breadcrumb = el('div', 'rgx-breadcrumb', `Startseite &nbsp;›&nbsp; Regalsysteme &nbsp;›&nbsp; <strong>${title}</strong>`);
    container.insertBefore(breadcrumb, container.firstChild);

    const left = grid.children[0];
    const right = grid.children[1];
    if (!left || !right) return;

    const gallery = el('section', 'rgx-card rgx-gallery');
    const hero = qs('#pv_hero', left);
    const thumbs = qs('#pv_thumbs', left);
    if (hero) gallery.appendChild(hero);
    if (thumbs) gallery.appendChild(thumbs);

    const details = el('section', 'rgx-card rgx-details');
    const detailsPad = el('div', 'rgx-card-pad');
    detailsPad.innerHTML = `<span class="rgx-kicker">REGATIX PROFI-SYSTEM</span><h1 class="rgx-title">${title}</h1><div class="rgx-rating">★★★★★ <span>4,9 / 5</span></div>`;

    const meta = el('div', 'rgx-meta');
    meta.innerHTML = '<div>✓ Industriequalität</div><div>✓ Persönliche Beratung</div><div>✓ Schnelle Lieferung</div><div>✓ 5 Jahre Garantie</div>';
    detailsPad.appendChild(meta);

    const infoCard = qs('#pv_info_card', left);
    if (infoCard) {
      infoCard.className = 'rgx-info';
      const info = qs('#pv_info_html', infoCard);
      if (info) detailsPad.appendChild(info);
      infoCard.remove();
    }

    const features = el('div', 'rgx-feature-grid');
    features.innerHTML = '<div class="rgx-feature">✓ Stabil und langlebig</div><div class="rgx-feature">✓ Erweiterbar</div><div class="rgx-feature">✓ Werkzeuglose Montage</div><div class="rgx-feature">✓ B2B-Fachberatung</div>';
    detailsPad.appendChild(features);
    details.appendChild(detailsPad);

    const config = el('aside', 'rgx-card rgx-config');
    const configHead = el('div', 'rgx-config-head', '<h2>Konfigurator</h2>');
    const configBody = el('div', 'rgx-config-body');
    const dims = qs('#pv_dims', right);
    const priceGross = qs('#pv_price_gross', right);
    const priceNet = qs('#pv_price_net', right);
    const qty = qs('#pv_qty', right);
    const infoFields = qs('#pv_info_fields', right);
    const warn = qs('#pv_warn_box', right);

    if (dims) configBody.appendChild(dims);
    const priceBox = el('div', 'rgx-pricebox');
    if (priceGross) priceBox.appendChild(priceGross);
    if (priceNet) priceBox.appendChild(priceNet);
    configBody.appendChild(priceBox);

    const buy = el('div', 'rgx-buy');
    if (qty) buy.appendChild(qty);
    const addButton = warn ? qs('#pv_add_to_cart', warn) : null;
    if (addButton) {
      addButton.className = 'rgx-cart';
      buy.appendChild(addButton);
    }
    configBody.appendChild(buy);

    if (warn) {
      warn.classList.add('rgx-warning');
      const ack = qs('#pv_warn_ack', warn);
      if (ack) ack.classList.add('rgx-secondary');
      configBody.appendChild(warn);
    }
    if (infoFields) {
      const tech = el('div', 'rgx-card-pad');
      tech.appendChild(infoFields);
      details.appendChild(tech);
    }

    config.append(configHead, configBody);

    const newGrid = el('div', 'rgx-grid');
    newGrid.append(gallery, details, config);
    mainCard.replaceWith(newGrid);

    addTechnicalDrawingSection(container);
    addTabs(container);
  }

  function addTechnicalDrawingSection(container) {
    if (qs('.rgx-drawings')) return;
    const source = qs('#pv_warn_box img[src*="legenderegatix"]');
    if (!source) return;

    const section = el('section', 'rgx-drawings');
    const head = el('div', 'rgx-section-title', '<h2>Technische Zeichnungen</h2><span>Gesamtübersicht</span>');
    const grid = el('div', 'rgx-drawing-grid');

    const tile = el('a', 'rgx-drawing-tile');
    tile.href = source.src;
    tile.target = '_blank';
    const img = source.cloneNode(true);
    img.removeAttribute('style');
    tile.appendChild(img);
    grid.appendChild(tile);

    section.append(head, grid);
    container.appendChild(section);
    const paragraph = source.closest('p');
    if (paragraph) paragraph.remove();
  }

  function addTabs(container) {
    if (qs('.rgx-tabs')) return;
    const tabs = el('section', 'rgx-tabs');
    const nav = el('div', 'rgx-tabnav');
    const panels = [];
    const labels = ['Beschreibung','Technische Daten','Downloads'];

    labels.forEach((label, index) => {
      const button = el('button', index === 0 ? 'active' : '', label);
      button.type = 'button';
      const panel = el('div', `rgx-card rgx-card-pad rgx-tabpanel${index === 0 ? ' active' : ''}`);
      panel.innerHTML = index === 0
        ? '<h2>Produktbeschreibung</h2><p>Alle Informationen werden aus den vorhandenen JSON-Produktdaten übernommen.</p>'
        : index === 1
          ? '<h2>Technische Daten</h2><p>Die gewählte Variante und ihre Eigenschaften werden oben live angezeigt.</p>'
          : '<h2>Downloads</h2><p>Datenblätter und Montageunterlagen können hier produktbezogen ergänzt werden.</p>';
      button.addEventListener('click', () => {
        [...nav.children].forEach(x => x.classList.remove('active'));
        panels.forEach(x => x.classList.remove('active'));
        button.classList.add('active');
        panel.classList.add('active');
      });
      nav.appendChild(button);
      panels.push(panel);
    });

    tabs.appendChild(nav);
    panels.forEach(panel => tabs.appendChild(panel));
    container.appendChild(tabs);
  }

  function enhanceFooter() {
    const footer = qs('#footer');
    if (!footer || footer.classList.contains('rgx-footer')) return;
    footer.className = 'rgx-footer';
    const inner = el('div', 'rgx-footer-inner');
    while (footer.firstChild) inner.appendChild(footer.firstChild);
    footer.appendChild(inner);
  }

  function run() {
    document.body.classList.add('rgx-shop2');
    buildHeader();
    buildProductLayout();
    enhanceFooter();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();