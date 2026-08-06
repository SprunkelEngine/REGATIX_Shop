(function(){
  function initRegister(root){
	const panel = root.querySelector('[data-reg="panel"]');
	const openBtn = root.querySelector('[data-reg="open"]');
	const closeBtn = root.querySelector('[data-reg="close"]');

	if(!panel || !openBtn || !closeBtn) return;

	function openPanel(){
	  panel.classList.add('open');
	  panel.setAttribute('aria-hidden','false');
	}
	function closePanel(){
	  panel.classList.remove('open');
	  panel.setAttribute('aria-hidden','true');
	}

	openBtn.addEventListener('click', () => {
	  panel.classList.contains('open') ? closePanel() : openPanel();
	});

	closeBtn.addEventListener('click', closePanel);

	document.addEventListener('keydown', (e) => {
	  if(e.key === 'Escape') closePanel();
	});
  }

  document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('[data-register]').forEach(initRegister);
  });
})();
