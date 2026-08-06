const openBtn = document.getElementById('openSheet');
const closeBtn = document.getElementById('closeSheet');
const bottomSheet = document.getElementById('bottomSheet');
const overlay = document.getElementById('overlay');

function openSheet() {
  bottomSheet.classList.add('active');
  overlay.classList.add('active');
}

function closeSheet() {
  bottomSheet.classList.remove('active');
  overlay.classList.remove('active');
}

openBtn.addEventListener('click', (e) => {
  e.preventDefault();
  openSheet();
});

closeBtn.addEventListener('click', closeSheet);

overlay.addEventListener('click', closeSheet);

// Optional: ESC-Taste schließt Panel
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && bottomSheet.classList.contains('active')) {
	closeSheet();
  }
});
