// filter.js
document.addEventListener('DOMContentLoaded', () => {
    // 1. Grab all the checkboxes and event cards
    const checkboxes = document.querySelectorAll('.category-filter');
    const eventCards = document.querySelectorAll('.event-card');
    const counterBadge = document.getElementById('event-counter');

    // 2. Listen for clicks on any checkbox
    checkboxes.forEach(box => {
        box.addEventListener('change', () => {
            
            // 3. Make a list of which categories are currently checked
            const checkedCategories = Array.from(checkboxes)
                                         .filter(cb => cb.checked)
                                         .map(cb => cb.value);
            
            // 4. Show or hide the cards based on the checked list
            let visibleCount = 0;
            eventCards.forEach(card => {
                if (checkedCategories.includes(card.dataset.category)) {
                    card.style.display = 'flex'; // Show it
                    visibleCount++;
                } else {
                    card.style.display = 'none'; // Hide it
                }
            });

            // 5. Update the total counter at the top
            if(counterBadge) counterBadge.innerText = `Total: ${visibleCount}`;
        });
    });
});

// --- Modal Logic ---
const modal = document.getElementById('eventModal');
const modalContent = document.getElementById('modalContent');

function openModal(cardElement) {
    // 1. Grab data from the clicked card
    const title = cardElement.getAttribute('data-title');
    const desc = cardElement.getAttribute('data-desc');
    const date = cardElement.getAttribute('data-date');
    const time = cardElement.getAttribute('data-time');
    
    // Grab the End Date, End Time, Category, and Venue
    const endDate = cardElement.getAttribute('data-end-date');
    const endTime = cardElement.getAttribute('data-end-time');
    
    // CHANGE THESE TWO LINES:
    const category = cardElement.getAttribute('data-category') || 'Not categorized';
    const venue = cardElement.getAttribute('data-venue') || 'Not specified';

    // 2. Put the data into the modal elements
    document.getElementById('modalTitle').innerText = title;
    document.getElementById('modalDesc').innerText = desc || 'No description provided.';
    document.getElementById('modalDate').innerText = date;
    document.getElementById('modalTime').innerText = time;
    document.getElementById('modalEndDate').innerText = endDate;
    document.getElementById('modalEndTime').innerText = endTime;
    document.getElementById('modalCategory').innerText = category;
    document.getElementById('modalVenue').innerText = venue;

    // 3. Show the modal with a smooth animation
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    // Slight delay to allow CSS transitions to trigger
    setTimeout(() => {
        modalContent.classList.remove('scale-95', 'opacity-0');
        modalContent.classList.add('scale-100', 'opacity-100');
    }, 10);
}

function closeModal() {
    // 1. Hide the modal with a smooth animation
    modalContent.classList.remove('scale-100', 'opacity-100');
    modalContent.classList.add('scale-95', 'opacity-0');
    
    // Wait for the animation to finish before actually hiding the div
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }, 200); // 200ms matches standard Tailwind transition duration
}

// Close modal if user clicks outside of the white box
window.onclick = function(event) {
    if (event.target == modal) {
        closeModal();
    }
}

// --- SweetAlert Confirm Action ---
function confirmAction(url, action) {
    // Customize text and colors based on the action
    const isApprove = action === 'approve';
    const titleText = isApprove ? 'Approve this event?' : 'Reject this event?';
    const detailText = isApprove ? 'It will be officially added to the calendar.' : 'It will be removed from the system permanently.';
    const btnColor = isApprove ? '#10b981' : '#ef4444'; // Emerald for approve, Red for reject
    const btnText = isApprove ? 'Yes, approve it!' : 'Yes, reject it!';

    Swal.fire({
        title: titleText,
        text: detailText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: btnColor,
        cancelButtonColor: '#64748b', // Slate
        confirmButtonText: btnText
    }).then((result) => {
        if (result.isConfirmed) {
            // If they click yes, send them to the URL!
            window.location.href = url;
        }
    });
}