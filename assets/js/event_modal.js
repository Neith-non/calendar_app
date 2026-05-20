// assets/js/event_modal.js

function openModal(element) {
    const eventModal = document.getElementById('eventModal');
    const modalContent = document.getElementById('modalContent');

    if (!eventModal || !modalContent) return;

    // 1. Grab Main Event Details
    let mainStartTime = element.dataset.time || '';
    let mainEndTime = element.dataset.endTime || '';

    if (mainEndTime.toUpperCase() === '11:59 PM' || mainEndTime.toUpperCase() === '12:00 AM') mainEndTime = 'All Day';
    if (mainStartTime.toUpperCase() === '12:00 AM') mainStartTime = 'All Day';

    // Populate Top Header
    if (document.getElementById('modalTitle')) document.getElementById('modalTitle').innerText = element.dataset.title;
    if (document.getElementById('modalDesc')) document.getElementById('modalDesc').innerHTML = element.dataset.desc ? element.dataset.desc.replace(/\n/g, '<br>') : '<em class="text-slate-400">No description provided.</em>';
    if (document.getElementById('modalDate')) document.getElementById('modalDate').innerText = element.dataset.date;
    if (document.getElementById('modalTime')) document.getElementById('modalTime').innerText = mainStartTime;
    if (document.getElementById('modalEndDate')) document.getElementById('modalEndDate').innerText = element.dataset.endDate;
    if (document.getElementById('modalEndTime')) document.getElementById('modalEndTime').innerText = mainEndTime; 
    if (document.getElementById('modalCategory')) document.getElementById('modalCategory').innerText = element.dataset.category || 'Not categorized';
    if (document.getElementById('modalVenue')) document.getElementById('modalVenue').innerText = element.dataset.venue || 'Not specified';

    // --- HOLIDAY CONFLICT MODAL NOTIFICATION ---
    const existingAlert = document.getElementById('modalHolidayAlert');
    if (existingAlert) existingAlert.remove();

    const holidayName = element.dataset.holidayTitle;
    let safeHolidayString = holidayName ? holidayName.replace(/'/g, "\\'") : ''; // Safe for JS passing

    if (holidayName && holidayName.trim() !== '') {
        const isMultiple = holidayName.includes(',');
        const labelText = isMultiple ? "official holidays" : "an official holiday";

        const alertBox = document.createElement('div');
        alertBox.id = "modalHolidayAlert";
        alertBox.className = "bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/40 rounded-2xl p-4 flex items-center gap-3 text-red-700 dark:text-red-400 text-sm font-bold shadow-sm mb-4";
        alertBox.innerHTML = `
            <i class="fa-solid fa-triangle-exclamation text-lg animate-pulse"></i>
            <div>
                <p class="text-xs uppercase tracking-wider text-red-500 font-black">Scheduling Warning</p>
                <p class="mt-0.5 font-semibold">This pending request conflicts with ${labelText}: <span class="underline">${holidayName}</span>.</p>
            </div>
        `;
        
        const timingCard = document.getElementById('modalTimingCard');
        if (timingCard) timingCard.parentNode.insertBefore(alertBox, timingCard.nextSibling);
    }

    // --- DYNAMIC BUTTON LOGIC (Edit, Approve, Reject, Delete) ---
    let editBtn = document.getElementById('modalEditBtn');
    let approveBtn = document.getElementById('modalApproveBtn');
    let rejectBtn = document.getElementById('modalRejectBtn');
    let deleteBtn = document.getElementById('modalDeleteBtn');
    
    let status = element.dataset.status;
    let publishId = element.dataset.publishId;
    let isPersonal = element.dataset.isPersonal === 'true';
    let approvedBy = element.dataset.approvedBy;
    let currentUserId = document.body.dataset.userId || '';

    // Hide all buttons by default
    if (editBtn) { editBtn.classList.add('hidden'); editBtn.classList.remove('flex'); }
    if (approveBtn) { approveBtn.classList.add('hidden'); approveBtn.classList.remove('flex'); }
    if (rejectBtn) { rejectBtn.classList.add('hidden'); rejectBtn.classList.remove('flex'); }
    if (deleteBtn) { deleteBtn.classList.add('hidden'); deleteBtn.classList.remove('flex'); }

    // Personal event logic (owned by current user via approved_by)
    if (isPersonal && approvedBy == currentUserId && publishId) {
        // Show Edit button for personal events
        if (editBtn) {
            editBtn.href = 'edit_event.php?id=' + publishId;
            editBtn.classList.remove('hidden');
            editBtn.classList.add('flex');
        }
        // Show Delete button for personal events
        if (deleteBtn) {
            deleteBtn.setAttribute('onclick', `confirmDelete(${publishId})`);
            deleteBtn.classList.remove('hidden');
            deleteBtn.classList.add('flex');
        }
    } else if (status === 'pending' && publishId) {
        // Show Edit for pending events (approval workflow)
        if (editBtn) {
            editBtn.href = 'edit_event.php?id=' + publishId;
            editBtn.classList.remove('hidden');
            editBtn.classList.add('flex');
        }
        // Show Approve
        if (approveBtn) {
            approveBtn.setAttribute('onclick', `confirmAction('approve_event.php?id=${publishId}&action=approve', 'approve', '${safeHolidayString}')`);
            approveBtn.classList.remove('hidden');
            approveBtn.classList.add('flex');
        }
        // Show Reject
        if (rejectBtn) {
            rejectBtn.setAttribute('onclick', `confirmAction('approve_event.php?id=${publishId}&action=reject', 'reject')`);
            rejectBtn.classList.remove('hidden');
            rejectBtn.classList.add('flex');
        }
    }


    // 2. Locate the Participants container
    const partsDiv = document.getElementById('modalParticipants');
    
    // 3. Parse and render Participants
    if (partsDiv) {
        partsDiv.innerHTML = ''; 
        let participants = [];
        try {
            if (element.dataset.participants) participants = JSON.parse(element.dataset.participants);
        } catch (e) {
            console.error("Error parsing participants json", e);
        }

        if (participants && participants.length > 0) {
            let grouped = {};

            const timeToMinutes = (timeStr) => {
                if (!timeStr || String(timeStr).toLowerCase() === 'null') return -1;
                let s = String(timeStr).toLowerCase().trim();
                if (s === 'all day' || s === '00:00:00' || s === '23:59:59' || s === '11:59 pm' || s === '12:00 am' || s === 'n/a') return -1;
                let h = 0, m = 0;
                if (s.includes('am') || s.includes('pm')) { 
                    let isPM = s.includes('pm');
                    let parts = s.replace('am', '').replace('pm', '').trim().split(':');
                    h = parseInt(parts[0], 10);
                    m = parts.length > 1 ? parseInt(parts[1], 10) : 0;
                    if (isPM && h !== 12) h += 12;
                    if (!isPM && h === 12) h = 0;
                } else { 
                    let timePart = s.includes(' ') ? s.split(' ')[1] : s;
                    let parts = timePart.split(':');
                    h = parseInt(parts[0], 10);
                    m = parts.length > 1 ? parseInt(parts[1], 10) : 0;
                }
                return (isNaN(h) || isNaN(m)) ? -1 : (h * 60 + m);
            };

            const formatTimeDisplay = (t) => {
                if (!t || String(t).toLowerCase() === 'null' || String(t).toLowerCase() === 'all day' || String(t).startsWith('00:00') || String(t).startsWith('23:59')) return 'All Day';
                let timePart = String(t).includes(' ') ? String(t).split(' ')[1] : String(t);
                let parts = timePart.split(':');
                let h = parseInt(parts[0], 10);
                let m = parts.length > 1 ? parts[1] : '00';
                if (isNaN(h)) return 'All Day';
                let ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                return `${h}:${m.padStart(2, '0')} ${ampm}`;
            };

            const mStartMin = timeToMinutes(mainStartTime);
            const mEndMin = timeToMinutes(mainEndTime);

            participants.forEach(p => {
                if (!grouped[p.department]) grouped[p.department] = [];

                let isCustom = false;
                let timeBadge = "";

                if (p.start_time && String(p.start_time).toLowerCase() !== 'null' && p.start_time !== '') {
                    let pStartMin = timeToMinutes(p.start_time);
                    let pEndMin = timeToMinutes(p.end_time);
                    if (pStartMin !== mStartMin || pEndMin !== mEndMin) { isCustom = true; }
                }

                if (isCustom) {
                    let pStartDisplay = formatTimeDisplay(p.start_time);
                    let pEndDisplay = formatTimeDisplay(p.end_time);
                    let displayTime = (pStartDisplay === 'All Day' && pEndDisplay === 'All Day') ? 'All Day' : `${pStartDisplay} - ${pEndDisplay}`;
                    timeBadge = `<span class="bg-violet-100 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-500/30 px-2 py-0.5 rounded text-[10px] font-bold tracking-wide ml-2 whitespace-nowrap shadow-sm inline-flex items-center"><i class="fa-solid fa-clock text-[9px] mr-1"></i>Custom: ${displayTime}</span>`;
                }

                grouped[p.department].push(`
                    <div class="text-slate-700 dark:text-slate-300 font-semibold text-sm mb-2.5 mr-4 flex items-center flex-wrap sm:flex-nowrap">
                        <i class="fa-solid fa-user text-[10px] mr-2 text-emerald-400 shrink-0"></i> 
                        <span class="truncate max-w-[150px] sm:max-w-none">${p.name}</span>
                        ${timeBadge}
                    </div>
                `);
            });

            const isEmeraldTheme = document.body.classList.contains('bg-[#f4fcf7]');
            const cardBg = isEmeraldTheme ? 'bg-white dark:bg-[#07160f]' : 'bg-white dark:bg-[#111827]';
            const cardBorder = isEmeraldTheme ? 'border-[#d1f0e0] dark:border-[#123f29]' : 'border-slate-200 dark:border-slate-800';
            const headText = isEmeraldTheme ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400';
            const headBorder = isEmeraldTheme ? 'border-[#d1f0e0] dark:border-[#123f29]' : 'border-slate-100 dark:border-slate-800';

            for (const [dept, namesHTML] of Object.entries(grouped)) {
                const badge = document.createElement('div');
                badge.className = `${cardBg} border ${cardBorder} rounded-xl p-4 w-full shadow-sm mb-3`;
                badge.innerHTML = `
                    <div class="${headText} font-extrabold mb-3 text-[11px] uppercase tracking-widest border-b ${headBorder} pb-2">${dept}</div>
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
    
    if (modalContent) modalContent.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        if (eventModal) {
            eventModal.classList.remove('flex');
            eventModal.classList.add('hidden');
        }
    }, 200);
}

// --- TWO-STAGE SWEETALERT LOGIC ---
function confirmAction(url, action, holidayName = '') {
    let actionText = action === 'approve' ? 'Approve' : 'Reject';
    let confirmColor = action === 'approve' ? '#10b981' : '#ef4444'; 

    // STAGE 1: Holiday Warning Intercept
    if (action === 'approve' && holidayName && holidayName.trim() !== '') {
        Swal.fire({
            title: 'Holiday Conflict Detected!',
            html: `This event overlaps with: <strong>${holidayName}</strong>.<br><br>Are you sure you want to override this holiday and approve the event anyway?`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#ef4444', // Red for danger
            cancelButtonColor: '#64748b', 
            confirmButtonText: 'Yes, Override Holiday',
            customClass: {
                popup: 'dark:bg-slate-900 dark:border dark:border-slate-800 dark:text-white',
                title: 'dark:text-white text-red-600',
                htmlContainer: 'dark:text-slate-300'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // If they say yes to the override, immediately fire the standard approval
                fireStandardAlert(url, actionText, confirmColor);
            }
        });
    } else {
        // No conflict? Just fire the standard alert normally.
        fireStandardAlert(url, actionText, confirmColor);
    }
}

// STAGE 2: Standard Final Approval
function fireStandardAlert(url, actionText, confirmColor) {
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

// Delete personal event function
function confirmDelete(publishId) {
    Swal.fire({
        title: 'Delete Personal Event?',
        text: 'This action cannot be undone. Are you sure you want to permanently delete this personal event?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b', 
        confirmButtonText: 'Yes, Delete Permanently',
        customClass: {
            popup: 'dark:bg-slate-900 dark:border dark:border-slate-800 dark:text-white',
            title: 'dark:text-white text-red-600',
            htmlContainer: 'dark:text-slate-300'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Send delete request to server
            window.location.href = 'delete_event.php?id=' + publishId;
        }
    });
}