document.addEventListener('DOMContentLoaded', () => {
        // Grab our elements
        const checkboxes = document.querySelectorAll('.category-filter');
        const filterButtonText = document.getElementById('filter-button-text');
        const searchBar = document.getElementById('search-bar');
        const calendarEvents = document.querySelectorAll('.calendar-event-item');
        const emptyStateMessage = document.getElementById('empty-state-message'); 

        // 1. Update the text on the dropdown button
        function updateFilterButton() {
            if (!filterButtonText) return;
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            if (checkedCount === 0) {
                filterButtonText.innerText = 'No Categories';
            } else if (checkedCount === checkboxes.length) {
                filterButtonText.innerText = 'All Categories';
            } else {
                filterButtonText.innerText = `${checkedCount} Categories Selected`;
            }
        }

        // 2. The Master Filter Engine! (Now tracks visible events)
        function filterEvents() {
            const searchTerm = searchBar ? searchBar.value.toLowerCase().trim() : '';
            
            // Get an array of whatever category checkboxes are currently ticked
            const activeCategories = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value.toLowerCase());

            let visibleCount = 0; // Keep track of how many events are showing

            // Loop through every single event on the calendar
            calendarEvents.forEach(event => {
                const title = (event.getAttribute('data-title') || '').toLowerCase();
                const desc = (event.getAttribute('data-desc') || '').toLowerCase();
                const category = (event.getAttribute('data-category') || '').toLowerCase();
                
                // THE FIX: Grab the date, but REMOVE the 4-digit year (like 2026) 
                let eventDate = (event.getAttribute('data-date') || '').toLowerCase();
                eventDate = eventDate.replace(/\d{4}/g, '');
                
                // Check if the search text matches the Title, Description, Category, OR the Date!
                const matchesSearch = title.includes(searchTerm) || 
                                      desc.includes(searchTerm) || 
                                      category.includes(searchTerm) || 
                                      eventDate.includes(searchTerm);
                
                // Check if the event's category is currently checked in the dropdown
                const matchesCategory = activeCategories.includes(category);

                // If it passes BOTH tests, show it and increase the count
                if (matchesSearch && matchesCategory) {
                    event.style.display = 'block'; 
                    visibleCount++; 
                } else {
                    event.style.display = 'none'; 
                }
            });

            // Show or hide the empty state message based on the count
            if (emptyStateMessage) {
                if (visibleCount === 0) {
                    emptyStateMessage.classList.remove('hidden');
                    emptyStateMessage.classList.add('flex');
                } else {
                    emptyStateMessage.classList.add('hidden');
                    emptyStateMessage.classList.remove('flex');
                }
            }
        }

        // --- Event Listeners ---
        
        // Listen for typing in the search bar
        if (searchBar) {
            searchBar.addEventListener('input', filterEvents);
        }

        // Listen for checking/unchecking boxes
        checkboxes.forEach(box => {
            box.addEventListener('change', () => {
                updateFilterButton();
                filterEvents();
            });
        });

        // Run once on page load to set the initial state
        updateFilterButton();
        filterEvents(); 
});