// assets/js/event_modal_component.js
// This file injects the event modal HTML into any page that includes it

function initializeEventModal() {
    const modalHTML = `
    <div id="eventModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[110] backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-2xl overflow-hidden border border-[#d1f0e0] dark:border-[#123f29] transform transition-all scale-95 opacity-0" id="modalContent">
            
            <div class="bg-[#f0fcf5] dark:bg-[#0a1a12] p-6 flex justify-between items-start border-b border-[#d1f0e0] dark:border-[#123f29]">
                <h2 id="modalTitle" class="text-xl font-extrabold text-emerald-900 dark:text-emerald-100 leading-tight pr-4">Event Title</h2>
                <button onclick="closeModal()" class="text-emerald-400 hover:text-red-500 transition bg-white dark:bg-[#07160f] border border-[#d1f0e0] dark:border-[#123f29] hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-6 max-h-[75vh] overflow-y-auto custom-scrollbar">
                <div class="bg-white dark:bg-[#07160f] p-5 rounded-2xl border border-[#d1f0e0] dark:border-[#123f29] shadow-sm space-y-4">
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="w-10 text-[10px] font-bold text-emerald-400 uppercase tracking-widest">Start</span>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-calendar text-emerald-600"></i>
                            <span id="modalDate">Date</span>
                        </div>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-clock text-emerald-600"></i>
                            <span id="modalTime">Time</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="w-10 text-[10px] font-bold text-emerald-400 uppercase tracking-widest">End</span>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-calendar-check text-slate-400"></i>
                            <span id="modalEndDate">Date</span>
                        </div>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-clock text-slate-400"></i>
                            <span id="modalEndTime">Time</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1.5">Category</h3>
                        <p class="text-emerald-800 dark:text-emerald-200 font-bold bg-[#f0fcf5] dark:bg-[#0a1a12] p-3.5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-tag text-emerald-500"></i>
                            <span id="modalCategory" class="truncate">Not categorized</span>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1.5">Venue</h3>
                        <p class="text-emerald-800 dark:text-emerald-200 font-bold bg-[#f0fcf5] dark:bg-[#0a1a12] p-3.5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-location-dot text-emerald-500"></i>
                            <span id="modalVenue" class="truncate">Not specified</span>
                        </p>
                    </div>
                </div>

                <div>
                    <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-2">Participants</h3>
                    <div id="modalParticipants" class="bg-[#f0fcf5] dark:bg-[#0a1a12] p-4 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] min-h-[60px] flex flex-col gap-3">
                        <span class="text-slate-400 italic text-sm">Loading participants...</span>
                    </div>
                </div>

                <div>
                    <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-2">Description</h3>
                    <p id="modalDesc" class="text-slate-600 dark:text-slate-300 text-sm whitespace-pre-line leading-relaxed bg-[#f0fcf5] dark:bg-[#0a1a12] p-5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] min-h-[100px] font-medium"></p>
                </div>
            </div>

            <div class="bg-white dark:bg-[#07160f] px-6 py-4 border-t border-[#d1f0e0] dark:border-[#123f29] flex justify-end gap-3">
                <a id="modalEditBtn" href="#" class="hidden bg-amber-500 hover:bg-amber-600 text-white font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm items-center gap-2 min-w-max">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Event
                </a>
                <button id="modalDeleteBtn" class="hidden bg-red-500 hover:bg-red-600 text-white font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm items-center gap-2 min-w-max">
                    <i class="fa-solid fa-trash"></i> Delete Event
                </button>
                <button id="modalApproveBtn" class="hidden bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm items-center gap-2 min-w-max">
                    <i class="fa-solid fa-check-circle"></i> Approve
                </button>
                <button id="modalRejectBtn" class="hidden bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm items-center gap-2 min-w-max">
                    <i class="fa-solid fa-circle-xmark"></i> Reject
                </button>
                <button onclick="closeModal()" class="bg-white dark:bg-[#0a1a12] border border-[#d1f0e0] dark:border-[#123f29] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] text-emerald-800 dark:text-emerald-200 font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm">Close Details</button>
            </div>
        </div>
    </div>
    `;

    // Check if modal already exists to avoid duplicates
    if (!document.getElementById('eventModal')) {
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
}

// Initialize modal when page loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeEventModal);
} else {
    initializeEventModal();
}
