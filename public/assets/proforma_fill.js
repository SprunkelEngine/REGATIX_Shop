(function(){
  const CART_KEY = 'pv_cart_v1';
  const CUSTOMER_KEY = 'pv_customer_v1';
  const LAST_ORDER_KEY = 'pv_last_order_v1';

  function $all(sel, root=document){ return Array.from(root.querySelectorAll(sel)); }

  function toFloatDE(v){
    if(v === null || v === undefined || v === '') return null;
    if(typeof v === 'number' && Number.isFinite(v)) return v;
    let s = String(v).trim();
    if(!s) return null;
    s = s.replace(/[^\d,.\-]/g, '');
    if(s.includes('.') && s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    else s = s.replace(',', '.');
    const n = Number(s);
    return Number.isFinite(n) ? n : null;
  }

  function money(n){
    const v = toFloatDE(n);
    if(v === null) return '—';
    return v.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
  }

  function escapeHtml(s){
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#39;');
  }

  function loadJSON(key, fallback){
    try{
      const raw = localStorage.getItem(key);
      if(!raw) return fallback;
      const j = JSON.parse(raw);
      return j && typeof j === 'object' ? j : fallback;
    }catch(e){ return fallback; }
  }

  function setText(path, value){
    $all(`[data-pv-text="${path}"]`).forEach(el => { el.textContent = value; });
  }
  function setHtml(path, html){
    $all(`[data-pv-html="${path}"]`).forEach(el => { el.innerHTML = html; });
  }

  function computeTotals(items, vatRate){
    let gross = 0, anyG = false;
    let net = 0, anyN = false;
    for(const it of items){
      const q = Math.max(1, Number(it.quantity || 1));
      const ug = toFloatDE(it.price_gross_unit ?? it.price_gross ?? it.gross_unit ?? it.price);
      const un = toFloatDE(it.price_net_unit ?? it.price_net ?? it.net_unit);
      if(ug !== null){ gross += ug * q; anyG = true; }
      if(un !== null){ net += un * q; anyN = true; }
    }
    const g = anyG ? gross : null;
    const n = anyN ? net : (anyG ? (gross / (1 + vatRate)) : null);
    const v = (g !== null && n !== null) ? (g - n) : null;
    return { gross: g, net: n, vat: v };
  }

  function render(){
    const last = loadJSON(LAST_ORDER_KEY, null);
    const cart = (last && Array.isArray(last.items)) ? last.items : loadJSON(CART_KEY, []);
    const custSrc = (last && last.customer && typeof last.customer === 'object') ? last.customer : loadJSON(CUSTOMER_KEY, {});
    const shop = (window.PV_SHOP && typeof window.PV_SHOP === 'object') ? window.PV_SHOP : {};
    const vatRate = Number(shop.vat_rate ?? 0.19);
    const vatPercent = Math.round(vatRate * 100);

    // Meta
    const now = new Date();
    const invoiceNo = (last && last.invoice_no) ? String(last.invoice_no) : ('PF-VORSCHAU-' + now.toISOString().slice(0,19).replace(/[-:T]/g,''));
    const datePretty = (last && last.date_pretty) ? String(last.date_pretty) : now.toLocaleDateString('de-DE');

    setText('invoice_no', invoiceNo);
    setText('date_pretty', datePretty);
    setText('vat_percent', String(vatPercent));

    // Shop
    setText('shop.company_name', String(shop.company_name || 'REGATIX'));
    setText('shop.website', String(shop.website || 'sprunkel.net'));
    setText('shop.contact_email', String(shop.contact_email || shop.email || 'info@sprunkel.net'));

    // Customer mapping
    const customer = {
      company: String(custSrc.company || ''),
      name: (String(custSrc.first_name || '') + ' ' + String(custSrc.last_name || '')).trim() || String(custSrc.name || ''),
      street: String(custSrc.street || ''),
      zip_city: (String(custSrc.zip || '') + ' ' + String(custSrc.city || '')).trim(),
      country: String(custSrc.country || ''),
      email: String(custSrc.email || ''),
      phone: String(custSrc.phone || ''),
    };

    setText('customer.company', customer.company || '—');
    setText('customer.name', customer.name || '—');
    setText('customer.street', customer.street || '—');
    setText('customer.zip_city', customer.zip_city || '—');
    setText('customer.country', customer.country || '—');
    setText('customer.email', customer.email || '—');
    setText('customer.phone', customer.phone || '');

    // Note html (optional via shop config)
    if (shop.proforma_note_html) setHtml('note_html', String(shop.proforma_note_html));

    // Items table
    const tbody = document.querySelector('tbody[data-pv-items]');
    if(tbody){
      tbody.innerHTML = '';
      for(const it of (Array.isArray(cart) ? cart : [])){
        const q = Math.max(1, Number(it.quantity || 1));
        const article = String(it.article || it.Artiklnummer || it.sku || '');
        const desc = String(it.selection_text || it.label || it.title || '').trim();
        const ug = toFloatDE(it.price_gross_unit ?? it.price_gross ?? it.gross_unit ?? it.price);
        const line = ug !== null ? ug * q : null;

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class="mono">${escapeHtml(article)}</td>
          <td>${escapeHtml(desc || '—')}</td>
          <td class="t-right">${escapeHtml(String(q))}</td>
          <td class="t-right">${escapeHtml(money(ug))}</td>
          <td class="t-right">${escapeHtml(money(line))}</td>
        `;
        tbody.appendChild(tr);
      }
    }

    const totals = (last && last.totals && typeof last.totals === 'object')
      ? last.totals
      : computeTotals(cart, vatRate);

    setText('totals.gross', money(totals.gross));
    setText('totals.net', money(totals.net));
    setText('totals.vat', money(totals.vat));

    setText('footer_line', String(shop.company_name || 'REGATIX') + ' · ' + String(shop.website || 'sprunkel.net'));

    // Warnings
    const warn = document.getElementById('pv_warn');
    const problems = [];
    if(!Array.isArray(cart) || cart.length === 0) problems.push('Warenkorb ist leer.');
    const required = ['first_name','last_name','email','street','zip','city','country'];
    const missing = required.filter(k => !String(custSrc[k] || '').trim());
    if(missing.length) problems.push('Kundendaten sind noch nicht vollständig (Checkout ausfüllen).');

    if(warn && problems.length){
      warn.style.display = '';
      warn.innerHTML = '<b>Hinweis:</b> ' + problems.map(escapeHtml).join(' ');
    } else if(warn){
      warn.style.display = 'none';
      warn.textContent = '';
    }

    // Print button
    const pb = document.getElementById('pv_print_btn');
    if(pb){
      pb.addEventListener('click', () => window.print());
    }

    // Auto print via ?print=1
    try{
      const u = new URL(window.location.href);
      if(u.searchParams.get('print') === '1'){
        setTimeout(() => window.print(), 250);
      }
    }catch(e){}
  }

  render();
})();