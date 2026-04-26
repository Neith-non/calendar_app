function openModal(element) {
    const eventModal = document.getElementById('eventModal');
    const modalContent = document.getElementById('modalContent');

    if (!eventModal || !modalContent) {
        console.error("Modal elements not found. Make sure the HTML exists in index.php/calendar.php");
        return;
    }

    // 1. Grab Main Event Details
    let mainStartTime = element.dataset.time;
    let mainEndTime = element.dataset.endTime;

    // --- FIX: Normalize the "All Day" End Time ---
    // PHP converts 23:59:59 to '11:59 PM'. We intercept that and convert it back to 'All Day'.
    if (mainEndTime === '11:59 PM' || mainEndTime === '12:00 AM') {
        mainEndTime = 'All Day';
    }
    if (mainStartTime === '12:00 AM') {
        mainStartTime = 'All Day';
    }

    // Populate Top Header Info
    if (document.getElementById('modalTitle')) document.getElementById('modalTitle').innerText = element.dataset.title;
    if (document.getElementById('modalDesc')) document.getElementById('modalDesc').innerText = element.dataset.desc || 'No description provided.';
    if (document.getElementById('modalDate')) document.getElementById('modalDate').innerText = element.dataset.date;
    if (document.getElementById('modalTime')) document.getElementById('modalTime').innerText = mainStartTime;
    if (document.getElementById('modalEndDate')) document.getElementById('modalEndDate').innerText = element.dataset.endDate;
    if (document.getElementById('modalEndTime')) document.getElementById('modalEndTime').innerText = mainEndTime; // Now correctly displays 'All Day'
    if (document.getElementById('modalCategory')) document.getElementById('modalCategory').innerText = element.dataset.category || 'Not categorized';
    if (document.getElementById('modalVenue')) document.getElementById('modalVenue').innerText = element.dataset.venue || 'Not specified';

    // 2. Locate the Participants container
    const partsDiv = document.getElementById('modalParticipants');
    
    // 3. Parse and render Participants
    if (partsDiv) {
        partsDiv.innerHTML = ''; 
        let participants = [];
        try {
            if (element.dataset.participants) {
                participants = JSON.parse(element.dataset.participants);
            }
        } catch (e) {
            console.error("Error parsing participants json", e);
        }

        if (participants && participants.length > 0) {
            let grouped = {};

            participants.forEach(p => {
                if (!grouped[p.department]) grouped[p.department] = [];

                // Format 24h SQL time to 12h AM/PM
                const formatTime = (t) => {
                    if(!t) return 'All Day';
                    // Catch database full-day markers
                    if (t.startsWith('00:00') || t.startsWith('23:59')) return 'All Day';
                    
                    let [h, m] = t.split(':');
                    h = parseInt(h, 10);
                    let ampm = h >= 12 ? 'PM' : 'AM';
                    h = h % 12 || 12;
                    return `${h}:${m} ${ampm}`;
                };

                let pStart = formatTime(p.start_time);
                let pEnd = formatTime(p.end_time);

                // SMART LOGIC: Does this person have a custom time block?
                let isCustom = (pStart !== mainStartTime || pEnd !== mainEndTime);
                let timeBadge = "";

                // ONLY show a time badge if they are on a custom schedule
                if (isCustom) {
                    // FIX: Prevent "All Day - All Day" visual bug
                    let displayTime = (pStart === 'All Day' && pEnd === 'All Day') ? 'All Day' : `${pStart} - ${pEnd}`;
                    
                    timeBadge = `<span class="bg-violet-100 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-500/30 px-2 py-0.5 rounded text-[10px] font-bold tracking-wide ml-2 whitespace-nowrap shadow-sm"><i class="fa-solid fa-bolt mr-1"></i>Custom: ${displayTime}</span>`;
                }

                // Add participant to their department list
                grouped[p.department].push(`
                    <div class="text-slate-700 dark:text-slate-300 font-semibold text-sm mb-2.5 mr-4 flex items-center">
                        <i class="fa-solid fa-user text-[10px] mr-2 text-emerald-400"></i> 
                        ${p.name} 
                        ${timeBadge}
                    </div>
                `);
            });

            // Build Department Cards specifically tailored for the new Mint theme
            for (const [dept, namesHTML] of Object.entries(grouped)) {
                const badge = document.createElement('div');
                badge.className = "bg-white dark:bg-[#07160f] border border-[#d1f0e0] dark:border-[#123f29] rounded-xl p-4 w-full shadow-sm mb-3";
                badge.innerHTML = `
                    <div class="text-emerald-700 dark:text-emerald-400 font-extrabold mb-3 text-[11px] uppercase tracking-widest border-b border-[#d1f0e0] dark:border-[#123f29] pb-2">${dept}</div>
                    <div class="flex flex-wrap items-center mt-2">
                        ${namesHTML.join('')}
                    </div>
                `;
                partsDiv.appendChild(badge);
            }

        } else {
            partsDiv.innerHTML = '<span class="text-slate-400 dark:text-slate-500 italic text-sm font-medium">No participants specified for this event.</span>';
        }
    }

    // 4. Animate it in
    eventModal.classList.remove('hidden');
    eventModal.classList.add('flex');
    
    setTimeout(() => {
        eventModal.classList.remove('opacity-0');
        modalContent.classList.remove('scale-95', 'opacity-0');
    }, 10);
}

function closeModal() {
    const eventModal = document.getElementById('eventModal');
    const modalContent = document.getElementById('modalContent');
    
    if (eventModal && modalContent) {
        eventModal.classList.add('opacity-0');
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            eventModal.classList.add('hidden');
            eventModal.classList.remove('flex');
        }, 200); 
    }
}

// --- Approve/Reject SweetAlert Confirmation ---
function confirmAction(url, action) {
    let actionText = action === 'approve' ? 'Approve' : 'Reject';
    let confirmColor = action === 'approve' ? '#10b981' : '#ef4444'; 

    Swal.fire({
        title: 'Are you sure?',
        text: `You are about to ${actionText.toLowerCase()} this event request.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: confirmColor,
        cancelButtonColor: '#64748b', 
        confirmButtonText: `Yes, ${actionText} it!`,
        customClass: {
            popup: 'dark:bg-slate-900 dark:border dark:border-slate-800 dark:text-white',
            title: 'dark:text-white',
            htmlContainer: 'dark:text-slate-400'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}