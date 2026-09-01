/* Robert_Khoubian_V17.2_Developer_Handoff_Package/01_Website_Baseline/z-519e3094/robert_khoubian_site_v13_1_2_v17_2/assets/script.js */
( function () {
const toggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.primary-nav');
if (toggle && nav) {
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}
const year = document.getElementById('year');
if (year) year.textContent = new Date().getFullYear();
}() );
