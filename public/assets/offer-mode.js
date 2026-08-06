(function(){
  const p = new URLSearchParams(location.search);

  // Angebot-Modus nur, wenn offer Parameter da ist
  const offer = (p.get('offer') || '').trim();
  if(!offer) return;

  // --- helpers ---
  function findButtonByText(re){
	const btns = Array.from(document.querySelectorAll('button, a.btn, input[type="button"], input[type="submit"]'));
	return btns.find(b => re.test((b.textContent || b.value || '').trim())) || null;
  }
  function findNodeContainingText(re){
	const nodes = Array.from(document.querySelectorAll('h1,h2,h3,h4,div,span,p,strong'));
	return nodes.find(n => re.test((n.textContent || '').trim())) || null;
  }
  function hide(el){
	if(el) el.style.display = 'none';
  }

  // Produkt/Artikel aus URL oder aus Angebotstext im Banner ziehen
  function extractProductArticle(){
	let product = (p.get('product') || '').trim();
	let article = (p.get('article') || '').trim();

	const bannerNode = findNodeContainingText(/Angebot-ID/i);
	const txt = (bannerNode ? bannerNode.textContent : document.body.textContent) || '';

	if(!article){
	  const m = txt.match(/Artikel:\s*([A-Za-z0-9_\-]+)/i);
	  if(m) article = m[1];
	}
	if(!product){
	  const m = txt.match(/Produkt\s*\(URL\)\s*:\s*([A-Za-z0-9_\-]+)/i);
	  if(m) product = m[1];
	}
	return {product, article};
  }

  // --- 1) UI: Konfiguration + Infotext ausblenden, Warnhinweis drin lassen ---
  // 1a) Infotext-Block entfernen
  const infotextTitle = findNodeContainingText(/^Infotext$/i) || findNodeContainingText(/Infotext/i);
  if(infotextTitle){
	const card = infotextTitle.closest('section, .card, .box, .panel, div');
	if(card) card.remove();
  }

  // 1b) Konfiguration so reduzieren, dass nur "Wichtiger Hinweis" sichtbar bleibt
  const hintTitle = findNodeContainingText(/Wichtiger Hinweis/i);
  if(hintTitle){
	// "Hint-Card" ist der rote Kasten
	const hintCard = hintTitle.closest('section, .card, .box, .panel, div');

	if(hintCard){
	  // Alles im gleichen "rechten Bereich" außer Warnhinweis ausblenden
	  const rightCol = hintCard.parentElement;
	  if(rightCol){
		Array.from(rightCol.children).forEach(ch => {
		  if(ch !== hintCard) hide(ch);
		});

		// Linke Spalte (Bild/Dropdowns) ebenfalls ausblenden
		const row = rightCol.parentElement;
		if(row){
		  Array.from(row.children).forEach(ch => {
			if(ch !== rightCol) hide(ch);
		  });
		}
	  }

	  // ggf. Überschrift "Konfiguration" verstecken
	  const cfgTitle = findNodeContainingText(/^Konfiguration$/i) || findNodeContainingText(/Konfiguration/i);
	  if(cfgTitle){
		// meist im selben Card-Header
		const cfgHeader = cfgTitle.closest('header, .card-h, .box-h, .panel-h, div');
		if(cfgHeader) hide(cfgHeader);
	  }
	}
  }

  // --- 2) Flow: erst bestätigen, dann in den Warenkorb ---
  const btnConfirm = findButtonByText(/Warnhinweis bestätigen/i);
  const btnCart = findButtonByText(/In den Warenkorb/i);

  if(btnCart){
	// initial sperren (falls dein Code es nicht schon tut)
	btnCart.setAttribute('aria-disabled', 'true');
	btnCart.classList.add('is-disabled');
	if('disabled' in btnCart) btnCart.disabled = true;
  }

  let confirmed = false;

  if(btnConfirm){
	btnConfirm.addEventListener('click', () => {
	  confirmed = true;

	  if(btnCart){
		btnCart.removeAttribute('aria-disabled');
		btnCart.classList.remove('is-disabled');
		if('disabled' in btnCart) btnCart.disabled = false;
	  }
	}, {passive:true});
  }

  if(btnCart){
	btnCart.addEventListener('click', (e) => {
	  // wenn noch nicht bestätigt: nichts tun
	  if(!confirmed){
		e.preventDefault();
		e.stopPropagation();
		return;
	  }

	  e.preventDefault();
	  e.stopPropagation();

	  const {product, article} = extractProductArticle();
	  if(!product || !article){
		alert('Angebot-Produkt/Artikel konnte nicht ermittelt werden.');
		return;
	  }

	  // ✅ Redirect in Warenkorb. Dort wird dann via api.php + offer die richtigen Werte gezogen.
	  const target = '/public/cart.php?' + new URLSearchParams({
		product,
		article,
		offer,
		qty: '1'
	  }).toString();

	  location.href = target;
	});
  }
})();
