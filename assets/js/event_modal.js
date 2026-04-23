// --- Dynamic Event Modal Logic ---

function openModal(element) {
    let eventModal = document.getElementById('eventModal');
    let modalContent;

    // 1. If the modal doesn't exist yet, generate it!
    if (!eventModal) {
        eventModal = document.createElement('div');
        eventModal.id = 'eventModal';
        eventModal.className = 'fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity p-4';

        eventModal.innerHTML = `
            <div class="glass-container rounded-xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0 flex flex-col max-h-[90vh]" id="modalContent">
                
                <div class="bg-black/20 p-4 flex justify-between items-center border-b border-white/10 flex-shrink-0">
                    <h2 id="modalTitle" class="text-xl font-bold truncate text-yellow-400">Event Title</h2>
                    <button onclick="closeModal()" class="text-white/70 hover:text-white transition bg-white/10 hover:bg-white/20 rounded-full w-8 h-8 flex items-center justify-center">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="p-6 space-y-5 overflow-y-auto custom-scrollbar">
                    
                    <div class="bg-black/20 p-4 rounded-lg border border-white/10 space-y-3">
                        <div class="flex items-center gap-3 text-slate-200 font-medium">
                            <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">Start</span>
                            <i class="fa-regular fa-calendar text-emerald-500 text-lg"></i>
                            <span id="modalDate">Date</span>
                            <span class="text-white/20 mx-1">|</span>
                            <i class="fa-regular fa-clock text-emerald-500 text-lg"></i>
                            <span id="modalTime">Time</span>
                        </div>
                        <div class="h-px bg-white/10 w-full ml-12"></div>
                        <div class="flex items-center gap-3 text-slate-200 font-medium">
                            <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">End</span>
                            <i class="fa-regular fa-calendar-check text-red-400 text-lg"></i>
                            <span id="modalEndDate">Date</span>
                            <span class="text-white/20 mx-1">|</span>
                            <i class="fa-regular fa-clock text-red-400 text-lg"></i>
                            <span id="modalEndTime">Time</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Category</h3>
                            <p class="text-slate-200 text-sm font-medium bg-black/20 p-3 rounded-lg border border-white/10 flex items-center gap-3">
                                <i class="fa-solid fa-tag text-purple-400"></i>
                                <span id="modalCategory" class="truncate">Not categorized</span>
                            </p>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Venue</h3>
                            <p class="text-slate-200 text-sm font-medium bg-black/20 p-3 rounded-lg border border-white/10 flex items-center gap-3">
                                <i class="fa-solid fa-location-dot text-sky-400"></i>
                                <span id="modalVenue" class="truncate">Not specified</span>
                            </p>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Participants</h3>
                        <div class="bg-black/20 p-4 rounded-lg border border-white/10 min-h-[60px]">
                            <div id="modalParticipants" class="flex flex-wrap gap-2">
                                <span class="text-white/50 italic text-sm">Loading participants...</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Description</h3>
                        <p id="modalDesc" class="text-slate-300 text-sm whitespace-pre-line leading-relaxed bg-black/20 p-4 rounded-lg border border-white/10 min-h-[80px]"></p>
                    </div>
                </div>

                <div class="bg-black/20 px-6 py-4 border-t border-white/10 flex justify-end flex-shrink-0">
                    <button onclick="closeModal()" class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2 px-4 rounded-lg transition">Close</button>
                </div>
            </div>
        `;

        document.body.appendChild(eventModal);

        // Allow clicking outside the modal to close it
        eventModal.addEventListener('click', (e) => {
            if (e.target === eventModal) {
                closeModal();
            }
        });
    }

    modalContent = document.getElementById('modalContent');

    // 2. Populate the data
    document.getElementById('modalTitle').innerText = element.dataset.title;
    document.getElementById('modalDesc').innerText = element.dataset.desc;
    document.getElementById('modalDate').innerText = element.dataset.date;
    document.getElementById('modalTime').innerText = element.dataset.time;
    document.getElementById('modalEndDate').innerText = element.dataset.endDate;
    document.getElementById('modalEndTime').innerText = element.dataset.endTime;
    document.getElementById('modalCategory').innerText = element.dataset.category || 'Not categorized';
    document.getElementById('modalVenue').innerText = element.dataset.venue || 'Not specified';

    // 3. Parse and render Participants
    const partsDiv = document.getElementById('modalParticipants');
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
        const grouped = {};
        participants.forEach(p => {
            if (!grouped[p.department]) grouped[p.department] = [];
            
            // Helper function to convert 24h to 12h AM/PM
            const formatTime = (t) => {
                if(!t || t === '00:00:00') return 'All Day';
                let [h, m] = t.split(':');
                h = parseInt(h, 10);
                let ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                return `${h}:${m} ${ampm}`;
            };

            let timeStr = "";
            if (p.start_time && p.end_time) {
                timeStr = ` <span class="text-white/50 text-[11px] ml-1 bg-black/20 px-1.5 py-0.5 rounded border border-white/5 whitespace-nowrap"><i class="fa-regular fa-clock mr-1"></i>${formatTime(p.start_time)} - ${formatTime(p.end_time)}</span>`;
            }

            grouped[p.department].push(`<div class="text-slate-200 mb-2 mr-4 flex items-center">${p.name}${timeStr}</div>`);
        });

        // Build a nicer block layout for departments since we are showing more data now
        for (const [dept, namesHTML] of Object.entries(grouped)) {
            const badge = document.createElement('div');
            badge.className = "bg-white/10 border border-white/20 rounded p-3 text-sm mb-3 w-full";
            badge.innerHTML = `
                <div class="text-yellow-400 font-bold mb-2 text-xs uppercase tracking-wider border-b border-white/10 pb-1">${dept}</div>
                <div class="flex flex-wrap items-center mt-2">
                    ${namesHTML.join('')}
                </div>
            `;
            partsDiv.appendChild(badge);
        }
    } else {
        partsDiv.innerHTML = '<span class="text-white/50 italic text-sm">No participants specified.</span>';
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