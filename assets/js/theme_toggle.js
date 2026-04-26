
    // --- DARK MODE TOGGLE LOGIC ---
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeToggleKnob = document.getElementById('theme-toggle-knob');
    const themeToggleIcon = document.getElementById('theme-toggle-icon');
    const themeToggleText = document.getElementById('theme-toggle-text');

    function updateToggleUI() {
        if (document.documentElement.classList.contains('dark')) {
            themeToggleKnob.classList.add('translate-x-5');
            themeToggleIcon.className = 'fa-solid fa-sun text-yellow-400';
            themeToggleText.innerText = 'Light Mode';
        } else {
            themeToggleKnob.classList.remove('translate-x-5');
            themeToggleIcon.className = 'fa-solid fa-moon text-slate-400';
            themeToggleText.innerText = 'Dark Mode';
        }
    }

    updateToggleUI();

    themeToggleBtn.addEventListener('click', function() {
        document.documentElement.classList.toggle('dark');
        if (document.documentElement.classList.contains('dark')) {
            localStorage.setItem('color-theme', 'dark');
        } else {
            localStorage.setItem('color-theme', 'light');
        }
        updateToggleUI();
    });

    // --- COMBINED JAVASCRIPT FOR SEARCH AND TABS ---
    const dashSearchBar = document.getElementById('search-bar');
    const dashEventCards = document.querySelectorAll('.event-card');
    const dashEmptyMessage = document.getElementById('empty-state-message');
    const viewToggles = document.querySelectorAll('.view-toggle');
    const categoryCheckboxes = document.querySelectorAll('.category-filter');
    
    let currentTab = 'all';

    viewToggles.forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            viewToggles.forEach(t => {
                t.classList.remove('bg-sjsfi-green', 'dark:bg-emerald-600', 'text-white', 'shadow-md');
                t.classList.add('text-slate-500', 'dark:text-slate-400', 'hover:bg-slate-200', 'dark:hover:bg-slate-800');
            });
            e.currentTarget.classList.remove('text-slate-500', 'dark:text-slate-400', 'hover:bg-slate-200', 'dark:hover:bg-slate-800');
            e.currentTarget.classList.add('bg-sjsfi-green', 'dark:bg-emerald-600', 'text-white', 'shadow-md');
            
            currentTab = e.currentTarget.getAttribute('data-view');
            applyCustomFilters();
        });
    });

    function applyCustomFilters() {
        const searchTerm = dashSearchBar ? dashSearchBar.value.toLowerCase().trim() : '';
        const activeCategories = Array.from(categoryCheckboxes)
                                      .filter(cb => cb.checked)
                                      .map(cb => cb.value);
        let visibleCount = 0;

        dashEventCards.forEach(card => {
            const title = (card.getAttribute('data-title') || '').toLowerCase();
            const category = card.getAttribute('data-category') || '';
            const status = card.getAttribute('data-status') || '';
            const desc = (card.getAttribute('data-desc') || '').toLowerCase();
            let date = (card.getAttribute('data-date') || '').toLowerCase();
            date = date.replace(/\d{4}/g, ''); 
            const time = (card.getAttribute('data-time') || '').toLowerCase();

            const matchesSearch = title.includes(searchTerm) || category.toLowerCase().includes(searchTerm) || desc.includes(searchTerm) || date.includes(searchTerm) || time.includes(searchTerm);
            const matchesTab = (currentTab === 'all') || (status === currentTab);
            const matchesCategory = activeCategories.includes(category);

            if (matchesSearch && matchesTab && matchesCategory) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (dashEmptyMessage) {
            if (visibleCount === 0) {
                dashEmptyMessage.classList.remove('hidden');
                dashEmptyMessage.classList.add('flex');
            } else {
                dashEmptyMessage.classList.add('hidden');
                dashEmptyMessage.classList.remove('flex');
            }
        }
    }

    if (dashSearchBar) {
        dashSearchBar.addEventListener('input', applyCustomFilters);
    }
    categoryCheckboxes.forEach(cb => {
        cb.addEventListener('change', applyCustomFilters);
    });

