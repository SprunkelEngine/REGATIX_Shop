(function(){
  const elDims = document.getElementById('pv_dims');
  const elQty = document.getElementById('pv_qty');
  const elGross = document.getElementById('pv_price_gross');
  const elNet = document.getElementById('pv_price_net');

  const elInfoFields = document.getElementById('pv_info_fields');
  const elInfoHtml = document.getElementById('pv_info_html');

  const elWarn = document.getElementById('pv_warn_fields');
  const elWarnBtn = document.getElementById('pv_warn_ack');
  const elCartBtn = document.getElementById('pv_add_to_cart');

  const elHero = document.getElementById('pv_hero');
  const elThumbs = document.getElementById('pv_thumbs');

  let cfg = null;
  let data = null;
  let selection = {};
  let warningConfirmed = false;

  const CART_KEY = 'pv_cart_v1';

  function escapeHtml(s){
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#39;');
  }

  function norm(s){
    return String(s == null ? '' : s)
      .replace(/\u00A0/g, ' ')
      .replace(/[\u200B-\u200D\uFEFF]/g, '')
      .trim()
      .toLowerCase();
  }

  function compact(s){
    return norm(s).replace(/[\s\-_\.]+/g, '');
  }

  function fmtNumber(v, unit){
    if(v === null || v === undefined || v === '') return '';
    const n = Number(v);
    if(Number.isNaN(n)) return String(v);
    const isInt = Math.abs(n - Math.round(n)) < 1e-9;
    const s = isInt
      ? String(Math.round(n))
      : n.toLocaleString('de-DE', {minimumFractionDigits: 0, maximumFractionDigits: 2});
    return unit ? (s + ' ' + unit) : s;
  }

  function toFloatDE(v){
    if(v === null || v === undefined || v === '') return null;
    if(typeof v === 'number' && Number.isFinite(v)) return v;

    let s = String(v).trim();
    if(s === '') return null;

    s = s.replace(/[^\d,.\-]/g, '');
    if(s.includes('.') && s.includes(',')) {
      s = s.replace(/\./g, '').replace(',', '.');
    } else {
      s = s.replace(',', '.');
    }

    const n = Number(s);
    return Number.isFinite(n) ? n : null;
  }

  function money(v){
    const n = toFloatDE(v);
    if(n === null) return '';
    return n.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
  }

  function pickFirstValid(arr){
    for(const v of arr){
      if(v !== '' && v !== null && v !== undefined) return String(v);
    }
    return arr[0] !== undefined ? String(arr[0]) : '';
  }

  function aliasMap(){
    return (window.PV_CONFIG_FIELD_ALIASES && typeof window.PV_CONFIG_FIELD_ALIASES === 'object')
      ? window.PV_CONFIG_FIELD_ALIASES
      : {};
  }

  function getAliases(labelOrKey){
    const map = aliasMap();
    const direct = map?.[labelOrKey];
    if(Array.isArray(direct) && direct.length){
      return direct.map(String);
    }
    return [String(labelOrKey ?? '')];
  }

  function canonicalFieldOrder(){
    const order = Array.isArray(window.PV_CONFIG_ORDER) ? window.PV_CONFIG_ORDER : [];
    return order.map(String).filter(Boolean);
  }

  function isAliasOf(labelOrKey, testValue){
    const t = norm(testValue);
    return getAliases(labelOrKey).some(a => norm(a) === t);
  }

  function isProduktartKey(k){
    return isAliasOf('Produkt Art', k) || norm(k) === 'produktart';
  }

  function getProductKey(){
    return document.body?.getAttribute('data-product-key') || window.PV_CURRENT_PRODUCT_KEY || '';
  }

  function getPrimaryKey(){
    return String(cfg?.variant?.primary_key || 'Artikelnummer');
  }

  function rowGet(row, candidates){
    if(!row || typeof row !== 'object') return '';
    const wanted = {};
    candidates.forEach(function(c){
      wanted[norm(c)] = true;
    });

    for(const k in row){
      if(!Object.prototype.hasOwnProperty.call(row, k)) continue;
      if(wanted[norm(k)]){
        const val = String(row[k] == null ? '' : row[k]).trim();
        if(val !== '') return val;
      }
    }
    return '';
  }

  function rowFindKey(row, candidates){
    if(!row || typeof row !== 'object') return '';
    const wanted = {};
    candidates.forEach(function(c){
      wanted[norm(c)] = true;
    });

    for(const k in row){
      if(!Object.prototype.hasOwnProperty.call(row, k)) continue;
      if(wanted[norm(k)]){
        return k;
      }
    }
    return '';
  }

  function getValueByCandidates(v, candidates){
    return rowGet(v, candidates);
  }

  function getCurrentArticleFromVariant(v){
    const primary = getPrimaryKey();
    return rowGet(v, [
      primary,
      'Artikelnummer',
      'artikelnummer',
      'Artikelnr.',
      'Artikelnr',
      'ArtNr',
      'Artikel-Nr.',
      'SKU',
      'sku'
    ]);
  }

  function getCurrentArticleHint(){
    return String(
      window.PV_CURRENT_ARTICLE ||
      window.PV_OFFER_ARTICLE ||
      document.body?.getAttribute('data-current-article') ||
      ''
    ).trim();
  }

  function syncCurrentVariantState(v){
    if(!v) return;

    const article = getCurrentArticleFromVariant(v);

    window.PV_CURRENT_VARIANT = v;
    window.PV_CURRENT_ARTICLE = article;
    window.PV_OFFER_ARTICLE = article;

    const hidden = document.getElementById('pv_article');
    if(hidden) hidden.value = article;

    if(document.body){
      document.body.setAttribute('data-current-article', article);
    }
  }

  function detectDimensionKeyInVariants(candidates){
    const variants = Array.isArray(data?.variants) ? data.variants : [];
    for(const v of variants){
      const found = rowFindKey(v, candidates);
      if(found) return found;
    }
    return '';
  }

  function hasDimensionByAliases(candidates){
    const dims = Array.isArray(cfg?.variant?.dimensions) ? cfg.variant.dimensions : [];
    return dims.some(d => {
      const dk = String(d?.key || '');
      return candidates.some(c => norm(c) === norm(dk));
    });
  }

  function ensureProduktartDimension(){
    if(!cfg || !cfg.variant) return;
    if(!Array.isArray(cfg.variant.dimensions)) cfg.variant.dimensions = [];

    const aliases = getAliases('Produkt Art');
    const has = hasDimensionByAliases(aliases);
    if(has) return;

    const detectedKey = detectDimensionKeyInVariants(aliases) || 'Produktart';
    cfg.variant.dimensions.unshift({ key: detectedKey, label: 'Produkt Art' });
  }

  function canonicalLabelForDimension(dim){
    const key = String(dim?.key || '');
    const label = String(dim?.label || key || '');
    for(const canon of canonicalFieldOrder()){
      if(isAliasOf(canon, key) || isAliasOf(canon, label)){
        return canon;
      }
    }
    return label || key;
  }

  function getDependencyKeysFor(fieldKey){
    const mode = String(cfg?.variant?.dependency_mode || 'auto');
    const keys = (cfg?.variant?.dimensions || []).map(d => d.key);
    const idx = keys.indexOf(fieldKey);

    if(mode === 'sequential'){
      return idx > 0 ? keys.slice(0, idx) : [];
    }
    if(mode === 'manual'){
      const deps = cfg?.variant?.dependencies?.[fieldKey];
      return Array.isArray(deps) ? deps : [];
    }
    return idx > 0 ? keys.slice(0, idx) : [];
  }

  function matchesVariant(v, sel, ignoreKey, allowedKeys){
    for(const d of (cfg?.variant?.dimensions || [])){
      const k = d.key;
      if(k === ignoreKey) continue;
      if(Array.isArray(allowedKeys) && !allowedKeys.includes(k)) continue;

      const s = sel[k];
      if(s === undefined || s === null || s === '') continue;
      if(String(v?.[k] ?? '') !== String(s)) return false;
    }
    return true;
  }

  function uniqSorted(values, key){
    const set = new Set(values.map(x => String(x ?? '')).filter(x => x !== ''));
    const arr = Array.from(set);

    if(isProduktartKey(key)){
      const rank = (s) => {
        const v = String(s || '').toLowerCase();
        if(v.includes('grund')) return 0;
        if(v.includes('anbau')) return 1;
        if(v.includes('eck')) return 2;
        return 99;
      };
      arr.sort((a,b)=>{
        const ra = rank(a), rb = rank(b);
        if(ra !== rb) return ra - rb;
        return String(a).localeCompare(String(b), 'de');
      });
      return arr;
    }

    const allNumeric = arr.every(a => !Number.isNaN(Number(a)));
    if(allNumeric){
      arr.sort((a,b)=>Number(a)-Number(b));
    } else {
      arr.sort((a,b)=>a.localeCompare(b, 'de'));
    }
    return arr;
  }

  function orderedDimensions(){
    const dims = Array.isArray(cfg?.variant?.dimensions) ? [...cfg.variant.dimensions] : [];
    const order = canonicalFieldOrder();
    if(!order.length) return dims;

    return dims.sort((a, b) => {
      const la = canonicalLabelForDimension(a);
      const lb = canonicalLabelForDimension(b);

      const ia = order.findIndex(x => norm(x) === norm(la));
      const ib = order.findIndex(x => norm(x) === norm(lb));

      const va = ia === -1 ? Number.MAX_SAFE_INTEGER : ia;
      const vb = ib === -1 ? Number.MAX_SAFE_INTEGER : ib;

      if(va !== vb) return va - vb;
      return String(la).localeCompare(String(lb), 'de');
    });
  }

  function normalizeImagePath(src){
    src = String(src || '').trim().replace(/\\/g, '/');
    if(!src) return '';

    const base = String(window.PV_IMAGE_BASE_URL || '').replace(/\/+$/, '');

    for(let i = 0; i < 10; i++){
      const old = src;

      src = src.replace(/^(?:https?:\/\/[^/]+\/images\/)+(https?:\/\/.+)$/i, '$1');

      if(base){
        const esc = base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const rx = new RegExp('^(?:' + esc + '/)+(https?:\\/\\/.+)$', 'i');
        src = src.replace(rx, '$1');
      }

      if(src === old) break;
    }

    if(/^https?:\/\//i.test(src)) return src;

    return base + '/' + src.replace(/^\/+/, '');
  }

  function buildSelectionFromVariant(v){
    const out = {};
    for(const d of (cfg?.variant?.dimensions || [])){
      const key = d.key;
      out[key] = String(v?.[key] ?? '');
    }
    return out;
  }

  function findVariantByArticle(article){
    const variants = Array.isArray(data?.variants) ? data.variants : [];
    if(!article) return null;

    const wanted = String(article).trim();
    if(!wanted) return null;

    return variants.find(v => String(getCurrentArticleFromVariant(v) || '').trim() === wanted) || null;
  }

  function rebuildSelects(){
    if(!elDims) return;
    elDims.innerHTML = '';

    const dims = orderedDimensions();

    for(const d of dims){
      const k = d.key;
      const label = canonicalLabelForDimension(d);

      let possible;
      if(isProduktartKey(k) || isProduktartKey(label)){
        possible = (data?.variants || []).map(v => v?.[k]);
      } else {
        const deps = getDependencyKeysFor(k);
        possible = (data?.variants || [])
          .filter(v => matchesVariant(v, selection, k, deps))
          .map(v => v?.[k]);
      }

      let options = uniqSorted(possible, k);

      if((isProduktartKey(k) || isProduktartKey(label)) && options.length === 0){
        options = uniqSorted((data?.variants || []).map(v => v?.[k]), k);
      }

      if(selection[k] && !options.includes(String(selection[k]))){
        selection[k] = '';
      }

      if(!selection[k]){
        selection[k] = pickFirstValid(options);
      }

      const selVal = String(selection[k] ?? '');
      const isEmpty = options.length === 0;

      const optsHtml = isEmpty
        ? `<option value="">— keine Auswahl möglich —</option>`
        : options.map(v => {
            const s = String(v);
            const isSel = s === selVal ? 'selected' : '';
            const pretty = /(^|[^a-z])mm([^a-z]|$)/i.test(String(k)) || /höhe|tiefe|länge|breite/i.test(label)
              ? fmtNumber(s, 'mm')
              : escapeHtml(s);
            return `<option value="${escapeHtml(s)}" ${isSel}>${pretty}</option>`;
          }).join('');

      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div>
          <label>${escapeHtml(label)}</label>
          <select data-key="${escapeHtml(k)}" ${isEmpty ? 'disabled' : ''}>${optsHtml}</select>
        </div>
      `;
      elDims.appendChild(wrap.firstElementChild);
    }

    elDims.querySelectorAll('select').forEach(sel => {
      sel.addEventListener('change', () => {
        const key = sel.getAttribute('data-key');
        selection[key] = sel.value;

        const keys = orderedDimensions().map(d => d.key);
        const idx = keys.indexOf(key);
        for(let i = idx + 1; i < keys.length; i++){
          selection[keys[i]] = '';
        }

        warningConfirmed = false;
        updateButtons();
        rebuildSelects();
        renderVariant();
      });
    });
  }

  function currentVariant(){
    const variants = data?.variants || [];
    const dims = (cfg?.variant?.dimensions || []).map(d => d.key);

    if(!variants.length) return null;

    const exact = variants.find(v =>
      dims.every(k => String(v?.[k] ?? '') === String(selection[k] ?? ''))
    );
    if(exact) return exact;

    const looseMatches = variants.filter(v => matchesVariant(v, selection, null));
    if(looseMatches.length === 1) return looseMatches[0];

    if(window.PV_CURRENT_VARIANT && typeof window.PV_CURRENT_VARIANT === 'object'){
      const currentArticle = getCurrentArticleFromVariant(window.PV_CURRENT_VARIANT);
      const foundByGlobal = looseMatches.find(v => String(getCurrentArticleFromVariant(v)) === String(currentArticle));
      if(foundByGlobal) return foundByGlobal;
    }

    const hintedArticle = getCurrentArticleHint();
    const foundByHint = looseMatches.find(v => String(getCurrentArticleFromVariant(v)) === String(hintedArticle));
    if(foundByHint) return foundByHint;

    if(looseMatches.length > 0) return looseMatches[0];

    const hintedFallback = findVariantByArticle(hintedArticle);
    if(hintedFallback) return hintedFallback;

    return variants[0] || null;
  }

  function updateButtons(){
    if(!elWarnBtn || !elCartBtn) return;
    elWarnBtn.textContent = warningConfirmed ? 'Warnhinweis bestätigt' : 'Warnhinweis bestätigen';
    elWarnBtn.disabled = warningConfirmed;
    elCartBtn.disabled = !warningConfirmed;
  }

  function getArticleContent(v){
    const art = getCurrentArticleFromVariant(v);
    return data?.content?.articles?.[art] || {};
  }

  function getDisplayValue(v, key){
    const ov = getArticleContent(v)?.overrides || {};
    if(ov[key] !== undefined) return ov[key];
    return v?.[key];
  }

  function getDisplayValueCandidates(v, candidates){
    const ov = getArticleContent(v)?.overrides || {};

    for(const key of candidates){
      if(ov[key] !== undefined && ov[key] !== null && ov[key] !== '') return ov[key];
    }

    for(const key of candidates){
      const val = v?.[key];
      if(val !== undefined && val !== null && val !== '') return val;
    }

    const fromRow = getValueByCandidates(v, candidates);
    if(fromRow !== '') return fromRow;

    return '';
  }

  function ensureArticleNumberRow(v){
    if(!elInfoFields || !v) return;

    const article = getCurrentArticleFromVariant(v);
    if(!article) return;

    let row = null;
    elInfoFields.querySelectorAll('.kv-row').forEach(function(r){
      const k = r.querySelector('.kv-k');
      if(!k) return;
      const keyText = compact(k.textContent || '');
      if(
        keyText === 'artikelnummer' ||
        keyText === 'artikelnr' ||
        keyText === 'artnr' ||
        keyText === 'sku'
      ){
        row = r;
      }
    });

    if(!row){
      row = document.createElement('div');
      row.className = 'kv-row';
      row.innerHTML = `<div class="kv-k">Artikelnummer</div><div class="kv-v">${escapeHtml(article)}</div>`;
      elInfoFields.insertBefore(row, elInfoFields.firstChild);
      return;
    }

    const vEl = row.querySelector('.kv-v');
    if(vEl) vEl.textContent = article;
  }

  function renderInfoFields(v){
    if(!elInfoFields) return;

    const order = canonicalFieldOrder();
    const fields = order.length
      ? order.map(label => ({ label, keys: getAliases(label) }))
      : [
          {label:'Produkt Art', keys:['Produktart', 'Produkt Art']},
          {label:'Feld Anzahl', keys:['FELD ANZAHL', 'Feld Anzahl', 'Felder Anzahl']},
          {label:'Ebenen', keys:['Ebenen']},
          {label:'Platz KG', keys:['PLATZ KG', 'Platz KG', 'Platz Kg']},
          {label:'Nennhöhe', keys:['Nennhöhe mm', 'Nennhöhe']},
          {label:'Nenntiefe', keys:['Nenntiefe mm', 'Nenntiefe']},
          {label:'Nennlänge', keys:['Nennlänge mm', 'Nennlänge']}
        ];

    const html = fields.map(f => {
      let candidates = Array.isArray(f.keys) ? [...f.keys] : [String(f.label || '')];

      if(norm(f.label) === norm('Nennhöhe')) candidates.unshift('Nennhöhe mm');
      if(norm(f.label) === norm('Nenntiefe')) candidates.unshift('Nenntiefe mm');
      if(norm(f.label) === norm('Nennlänge')) candidates.unshift('Nennlänge mm');

      const val = getDisplayValueCandidates(v, candidates);

      if(val === '' || val === null || val === undefined) return '';

      let pretty = escapeHtml(val);
      const labelLower = String(f.label || '').toLowerCase();

      if(
        labelLower.includes('höhe') ||
        labelLower.includes('tiefe') ||
        labelLower.includes('länge') ||
        labelLower.includes('breite')
      ){
        pretty = fmtNumber(val, 'mm');
      } else if(
        labelLower.includes('kg') ||
        labelLower.includes('gewicht')
      ){
        pretty = fmtNumber(val, 'kg');
      }

      return `<div class="kv-row"><div class="kv-k">${escapeHtml(f.label)}</div><div class="kv-v">${pretty}</div></div>`;
    }).filter(Boolean).join('');

    elInfoFields.innerHTML = html || '<div class="small">—</div>';
    ensureArticleNumberRow(v);
  }

  function getInfoHtmlForVariant(v){
    const articleContent = getArticleContent(v);
    const productContent = data?.content?.product || {};
    const rootContent = data?.content || {};

    const candidates = [
      articleContent.info_html,
      articleContent.infotext,
      articleContent.info,
      articleContent.description_html,
      articleContent.description,

      productContent.info_html,
      productContent.infotext,
      productContent.info,
      productContent.description_html,
      productContent.description,

      rootContent.info_html,
      rootContent.infotext,
      rootContent.info,
      rootContent.description_html,
      rootContent.description,

      window.PV_INFO_HTML_INITIAL
    ];

    for(const candidate of candidates){
      const html = String(candidate == null ? '' : candidate).trim();
      if(html !== '') return html;
    }

    return '';
  }

  function renderInfoHtml(v){
    if(!elInfoHtml) return;

    const out = getInfoHtmlForVariant(v);
    elInfoHtml.innerHTML = out !== '' ? out : '<div class="small">—</div>';

    const card = document.getElementById('pv_info_card');
    if(card){
      card.hidden = false;
      card.style.display = '';
      card.style.visibility = '';
    }
  }

  function getPlaceholderImage(){
    const fromCfg = cfg?.ui?.placeholder_image;
    if(typeof fromCfg === 'string' && fromCfg.trim() !== '') return normalizeImagePath(fromCfg.trim());
    return normalizeImagePath('placeholder.svg');
  }

  function setHero(src){
    if(!elHero) return;

    const img = src && String(src).trim() !== '' ? normalizeImagePath(src) : getPlaceholderImage();

    elHero.innerHTML = img
      ? `<img src="${escapeHtml(img)}" alt="">`
      : `<div class="small">Kein Bild</div>`;
  }

  function renderImages(v){
    if(!elThumbs) return;

    const content = getArticleContent(v);

    const imgsArt = Array.isArray(content.images) ? content.images : [];
    const imgsProduct = Array.isArray(data?.content?.product?.images) ? data.content.product.images : [];

    const imgs = imgsArt.length ? imgsArt : imgsProduct;
    const hero = imgs[0] || null;

    setHero(hero);

    const thumbs = (imgs || []).slice(0, 12);
    elThumbs.innerHTML = thumbs.map(src => {
      const url = normalizeImagePath(src);
      return `
        <div class="thumb" data-src="${escapeHtml(url)}"><img src="${escapeHtml(url)}" alt=""></div>
      `;
    }).join('');

    elThumbs.querySelectorAll('.thumb').forEach(t => {
      t.addEventListener('click', () => setHero(t.getAttribute('data-src')));
    });
  }

  function renderVariant(){
    const v = currentVariant();
    if(!v) return;

    // Wichtig für Palettenregale:
    // die ermittelte Variante wieder als vollständige Auswahl übernehmen
    selection = buildSelectionFromVariant(v);

    syncCurrentVariantState(v);

    const qty = Math.max(1, parseInt(String(elQty?.value || '1'), 10) || 1);

    const grossUnit = toFloatDE(v?.[cfg?.variant?.price_gross]);
    const netUnit = toFloatDE(v?.[cfg?.variant?.price_net]);

    const grossTotal = (grossUnit !== null) ? (grossUnit * qty) : null;
    const netTotal = (netUnit !== null) ? (netUnit * qty) : null;

    if(elGross) elGross.textContent = (grossTotal !== null) ? money(grossTotal) : '—';
    if(elNet) elNet.textContent = (netTotal !== null) ? (money(netTotal) + ' ohne MwSt.') : '';

    renderInfoFields(v);
    renderInfoHtml(v);

    const warnFields = (cfg?.display?.warning_fields || []).map(f => {
      const key = f.key;
      const label = f.label || key;
      const val = getDisplayValue(v, key);
      if(val === '' || val === null || val === undefined) return '';
      const pretty = key.includes('mm') ? fmtNumber(val, 'mm') : escapeHtml(val);
      return `<div class="kv-row"><div class="kv-k">${escapeHtml(label)}</div><div class="kv-v">${pretty}</div></div>`;
    }).filter(Boolean).join('');

    if(elWarn) elWarn.innerHTML = warnFields || '<div class="small">—</div>';

    renderImages(v);
    updateButtons();

    try{
      document.dispatchEvent(new CustomEvent('pv:variant-changed', {
        detail: {
          variant: v,
          article: getCurrentArticleFromVariant(v)
        }
      }));
    }catch(e){
      document.dispatchEvent(new Event('pv:variant-changed'));
    }
  }

  function onWarnAck(){
    warningConfirmed = true;
    updateButtons();
  }

  function loadCart(){
    try{
      const j = JSON.parse(localStorage.getItem(CART_KEY) || '[]');
      return Array.isArray(j) ? j : [];
    }catch(e){
      return [];
    }
  }

  function saveCart(items){
    localStorage.setItem(CART_KEY, JSON.stringify(items));
  }

  function updateCartBadge(){
    const el = document.getElementById('pv_cart_badge');
    if(!el) return;

    const cart = loadCart();
    const qty = cart.reduce((a,it)=>a + Math.max(1, Number(it.quantity || 1)), 0);

    if(qty > 0){
      el.hidden = false;
      el.textContent = String(qty);
    } else {
      el.hidden = true;
      el.textContent = '';
    }
  }

function onAddToCart(){
    const v =
      (window.PV_CURRENT_VARIANT && typeof window.PV_CURRENT_VARIANT === 'object')
        ? window.PV_CURRENT_VARIANT
        : currentVariant();
  
    if(!v) return;
  
    const realSelection = buildSelectionFromVariant(v);
    selection = {...realSelection};
    syncCurrentVariantState(v);
  
    const qty = Math.max(1, parseInt(String(elQty?.value || '1'), 10) || 1);
  
    const grossUnit = toFloatDE(v?.[cfg?.variant?.price_gross]);
    const netUnit = toFloatDE(v?.[cfg?.variant?.price_net]);
  
    const dimsText = orderedDimensions().map(d => {
      const key = d.key;
      const label = canonicalLabelForDimension(d);
      const val = realSelection[key];
      if(val === undefined || val === null || val === '') return '';
      return `${label}: ${val}`;
    }).filter(Boolean).join(' · ');
  
    const article = getCurrentArticleFromVariant(v);
  
    const keyParts = [article];
    for(const d of orderedDimensions()){
      const k = d.key;
      keyParts.push(k + '=' + String(realSelection[k] ?? ''));
    }
    const itemKey = keyParts.join('|');
  
    const item = {
      key: itemKey,
      article: article,
      artikelnummer: article,
      sku: article,
      title: (data?.content?.product?.title) || '',
      product_key: getProductKey(),
      product_label: document.body?.getAttribute('data-product-label') || '',
      selection: {...realSelection},
      selection_text: dimsText,
      quantity: qty,
      price_gross_unit: grossUnit,
      price_net_unit: netUnit
    };
  
    const cart = loadCart();
    const idx = cart.findIndex(x => x && x.key === item.key);
  
    if(idx >= 0){
      cart[idx].quantity = Math.max(1, Number(cart[idx].quantity || 1)) + qty;
      cart[idx].price_gross_unit = grossUnit;
      cart[idx].price_net_unit = netUnit;
      cart[idx].article = article;
      cart[idx].artikelnummer = article;
      cart[idx].sku = article;
      cart[idx].selection = {...realSelection};
      cart[idx].selection_text = dimsText;
      cart[idx].product_key = getProductKey();
      cart[idx].product_label = document.body?.getAttribute('data-product-label') || '';
    } else {
      cart.push(item);
    }
  
    saveCart(cart);
    updateCartBadge();
  
    alert('Im Warenkorb ✅');
  }

  async function fetchBootstrapFallback(){
    const product = encodeURIComponent(getProductKey());
    const url = '../api.php?mode=bootstrap&product=' + product + '&_=' + Date.now();
    const res = await fetch(url, { cache: 'no-store' });
    return await res.json();
  }

  async function load(){
    let json = null;

    if(window.PV_BOOTSTRAP && Array.isArray(window.PV_BOOTSTRAP.variants)){
      json = window.PV_BOOTSTRAP;
    } else {
      json = await fetchBootstrapFallback();
    }

    cfg = json.config || {};
    data = json || {};

    ensureProduktartDimension();

    const hintedVariant =
      (window.PV_CURRENT_VARIANT && typeof window.PV_CURRENT_VARIANT === 'object')
        ? findVariantByArticle(getCurrentArticleFromVariant(window.PV_CURRENT_VARIANT))
        : null;

    const hintedArticle = getCurrentArticleHint();
    const articleVariant = findVariantByArticle(hintedArticle);

    const v0 = hintedVariant || articleVariant || data?.variants?.[0] || {};
    selection = buildSelectionFromVariant(v0);

    if(elQty) elQty.value = String(cfg?.variant?.quantity_default || 1);

    rebuildSelects();
    renderVariant();
    updateCartBadge();
  }

  if(elWarnBtn) elWarnBtn.addEventListener('click', onWarnAck);
  if(elCartBtn) elCartBtn.addEventListener('click', onAddToCart);

  if(elQty){
    elQty.addEventListener('input', renderVariant);
    elQty.addEventListener('change', renderVariant);
  }

  load().catch(err => {
    console.error(err);
    const elErr = document.getElementById('pv_error');
    if(elErr) elErr.textContent = 'Fehler: Prüfe Import (data/variants.json).';
  });

  window.addEventListener('pageshow', (e) => {
    if(e.persisted){
      load().catch(()=>{});
    }
  });
})();